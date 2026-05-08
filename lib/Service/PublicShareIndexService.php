<?php

declare(strict_types=1);

namespace OCA\PublicShareIndex\Service;

use OCA\PublicShareIndex\AppInfo\Application;
use OCA\PublicShareIndex\Security\PathGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as ShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * 公开共享浏览器核心业务服务。
 *
 * 职责（v0.6）：
 *   - 索引 + 搜索（一直就有）
 *   - 单 share 内的递归平铺列表（listAll）：用于「显示所有文件」开关，受节点数 / 结果数双上限保护
 *   - 单 share 内的下载句柄准备（prepareDownload）：受 hideDownload / download_enabled 约束
 *   - bootstrap 索引服务端缓存：所有匿名访客共享一份缓存，TTL 默认 60s，避免每个用户都触发全实例扫描
 *
 * 安全要点：
 *   - 所有"按 shareId + relativePath"的入口均先 findShareById（已校验 isShareVisible），
 *     再用 PathGuard 规范化 + 越界检查，确保不会读到 share 之外的任何文件。
 *   - 流式下载在更上层（Controller + StreamingFileResponse）实现，避免 OOM。
 *   - bootstrap 缓存使用 NC 的分布式缓存（redis / apcu / 内置），命名空间隔离。
 */
class PublicShareIndexService {
    /** 缓存键：完整的 bootstrap 响应。 */
    private const CACHE_KEY_BOOTSTRAP = 'bootstrap_v1';

    /** @var list<IShare>|null 请求级缓存：单次请求内只过滤一次 */
    private ?array $filteredSharesCache = null;

    /** @var array<string, bool> 请求级缓存：用户是否启用 */
    private array $userEnabledCache = [];

    private ICache $cache;

    public function __construct(
        private ShareManager $shareManager,
        private IURLGenerator $urlGenerator,
        private IUserManager $userManager,
        private IConfig $config,
        private LoggerInterface $logger,
        private IRootFolder $rootFolder,
        private PathGuard $pathGuard,
        ICacheFactory $cacheFactory,
    ) {
        // createDistributed 在生产环境（redis / memcached）会跨进程共享；
        // 单实例无外部缓存时退化为本进程缓存，TTL 仍生效。
        $this->cache = $cacheFactory->createDistributed(Application::APP_ID . '.');
    }

    /**
     * 取 bootstrap 索引（共享列表）。默认走 TTL 缓存，跨匿名访客共享一份；
     * 当 $forceRefresh = true 时绕过缓存重新扫描并写回。
     */
    public function getBootstrapIndex(bool $forceRefresh = false): array {
        if (!$forceRefresh) {
            $cached = $this->cache->get(self::CACHE_KEY_BOOTSTRAP);
            if (is_array($cached)) {
                $cached['fromCache'] = true;
                return $cached;
            }
        }

        $result = $this->buildBootstrapIndex();
        $result['fromCache'] = false;

        $ttl = $this->getBootstrapCacheTtl();
        if ($ttl > 0) {
            try {
                $this->cache->set(self::CACHE_KEY_BOOTSTRAP, $result, $ttl);
            } catch (\Throwable $e) {
                $this->logger->info('写入 bootstrap 缓存失败（不影响功能）：' . $e->getMessage(), [
                    'app' => Application::APP_ID,
                ]);
            }
        }
        return $result;
    }

    /**
     * 主动让缓存失效——下次访问会强制重新扫描。
     */
    public function invalidateBootstrapCache(): void {
        try {
            $this->cache->remove(self::CACHE_KEY_BOOTSTRAP);
        } catch (\Throwable $e) {
            $this->logger->info('清除 bootstrap 缓存失败：' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        }
    }

    private function getBootstrapCacheTtl(): int {
        $ttl = (int)$this->config->getAppValue(Application::APP_ID, 'bootstrap_cache_ttl', '60');
        // 0 表示禁用缓存；负值视为默认 60 秒；过大值上限 24 小时
        if ($ttl < 0) {
            return 60;
        }
        if ($ttl > 86400) {
            return 86400;
        }
        return $ttl;
    }

    /** 真正扫描所有公开共享并构造 bootstrap 响应（不查缓存）。 */
    private function buildBootstrapIndex(): array {
        $shares = $this->getFilteredPublicShares();

        $ownersById = [];
        $displayNameCounts = [];
        foreach ($shares as $share) {
            $ownerId = $this->getOwnerId($share);
            $displayName = $this->getDisplayName($ownerId);
            if (!isset($ownersById[$ownerId])) {
                $ownersById[$ownerId] = [
                    'ownerId' => $ownerId,
                    'displayName' => $displayName,
                    'roots' => [],
                ];
                $displayNameCounts[$displayName] = ($displayNameCounts[$displayName] ?? 0) + 1;
            }
        }

        $showOwnerUid = $this->getBoolConfig('show_owner_uid', false);
        foreach ($shares as $share) {
            $ownerId = $this->getOwnerId($share);
            $directoryName = $ownersById[$ownerId]['displayName'];
            if (($displayNameCounts[$directoryName] ?? 0) > 1 || $showOwnerUid) {
                $directoryName .= ' (' . $ownerId . ')';
            }
            $ownersById[$ownerId]['directoryName'] = $directoryName;
            $ownersById[$ownerId]['roots'][] = $this->buildShareRootDescriptor($share, $directoryName);
        }

        $owners = array_values($ownersById);
        foreach ($owners as &$owner) {
            usort($owner['roots'], static function (array $a, array $b): int {
                $depth = count($a['segments']) <=> count($b['segments']);
                if ($depth !== 0) {
                    return $depth;
                }
                return strnatcasecmp((string)$a['path'], (string)$b['path']);
            });
        }
        unset($owner);

        usort($owners, static fn(array $a, array $b): int => strnatcasecmp((string)$a['directoryName'], (string)$b['directoryName']));

        return [
            'owners' => $owners,
            'totalShares' => count($shares),
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'options' => [
                'includePasswordProtected' => $this->getBoolConfig('include_password_protected', false),
                'includeExpired' => $this->getBoolConfig('include_expired', false),
                'showOwnerUid' => $showOwnerUid,
                'showHiddenDownload' => $this->getBoolConfig('show_hide_download_shares', false),
            ],
        ];
    }

    public function listChildren(string $shareId, string $relativePath = ''): array {
        $share = $this->findShareById($shareId);
        $rootNode = $this->getRootNodeForShare($share);
        $node = $this->resolveRelativeNode($rootNode, $relativePath);

        $items = [];
        if ($node instanceof Folder) {
            foreach ($node->getDirectoryListing() as $child) {
                try {
                    $items[] = $this->buildNodeDescriptor($share, $child, $this->pathGuard->relativeFromRoot($rootNode, $child));
                } catch (\Throwable $e) {
                    $this->logger->warning('跳过共享子节点：' . $e->getMessage(), [
                        'app' => Application::APP_ID,
                        'shareId' => $shareId,
                    ]);
                }
            }
        } else {
            $items[] = $this->buildNodeDescriptor($share, $node, $this->pathGuard->relativeFromRoot($rootNode, $node));
        }

        usort($items, static function (array $a, array $b): int {
            if (($a['type'] ?? '') !== ($b['type'] ?? '')) {
                return ($a['type'] ?? '') === 'folder' ? -1 : 1;
            }
            return strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });

        $normalizedPath = $this->pathGuard->normalizeRelative($relativePath);

        return [
            'share' => $this->buildShareRootDescriptor($share, null),
            'current' => [
                'path' => $normalizedPath,
                'isRoot' => $normalizedPath === '',
                'type' => $node instanceof Folder ? 'folder' : 'file',
                'name' => $node->getName(),
            ],
            'items' => $items,
        ];
    }

    /**
     * 递归把指定共享内某个相对路径下的全部文件 / 目录平铺成列表，
     * 用于前端「显示所有文件」开关。
     *
     * 安全：
     *   - findShareById 已经校验 share 是否对外可见
     *   - PathGuard 规范化 + assertWithinRoot 双重约束遍历范围
     *   - 当起点本身就是文件时，直接返回该文件这一条
     *
     * OOM 防护：
     *   - $maxVisited：BFS 遍历期间最多访问的节点数（含目录），超出立即停止
     *   - $maxResults：返回列表条数上限，超出立即停止并标记 truncated
     *   - 默认上限均可通过 occ config:app:set 覆盖
     *
     * @return array{
     *     share: array,
     *     base: array{path: string, type: string, name: string},
     *     items: list<array>,
     *     visited: int,
     *     maxVisited: int,
     *     maxResults: int,
     *     truncated: bool,
     * }
     */
    public function listAll(string $shareId, string $relativePath = '', ?int $limit = null): array {
        $share = $this->findShareById($shareId);
        $rootNode = $this->getRootNodeForShare($share);
        $base = $this->resolveRelativeNode($rootNode, $relativePath);

        // 默认放宽到 5w 节点 / 2w 结果，适合内网部署；硬上限 5w 结果以兜底响应体大小
        $maxVisited = max(500, (int)$this->config->getAppValue(Application::APP_ID, 'list_all_max_visited', '50000'));
        $hardMaxResults = max(100, min(50000, (int)$this->config->getAppValue(Application::APP_ID, 'list_all_max_results', '20000')));
        $effectiveLimit = $limit !== null ? min($hardMaxResults, max(1, $limit)) : $hardMaxResults;

        $items = [];
        $visited = 0;
        $truncated = false;

        if ($base instanceof Folder) {
            // 用 BFS，便于按"父目录优先"展示，列表顺序更直觉。
            $queue = [$base];
            while (!empty($queue)) {
                if ($visited >= $maxVisited || count($items) >= $effectiveLimit) {
                    $truncated = true;
                    break;
                }
                /** @var Folder $current */
                $current = array_shift($queue);
                try {
                    $children = $current->getDirectoryListing();
                } catch (\Throwable $e) {
                    $this->logger->warning('listAll 读取目录失败：' . $e->getMessage(), [
                        'app' => Application::APP_ID,
                        'shareId' => $shareId,
                    ]);
                    continue;
                }
                foreach ($children as $child) {
                    if ($visited >= $maxVisited || count($items) >= $effectiveLimit) {
                        $truncated = true;
                        break 2;
                    }
                    $visited++;
                    try {
                        $relative = $this->pathGuard->relativeFromRoot($rootNode, $child);
                        $items[] = $this->buildNodeDescriptor($share, $child, $relative);
                    } catch (\Throwable $e) {
                        $this->logger->info('listAll 跳过越界节点：' . $e->getMessage(), [
                            'app' => Application::APP_ID,
                            'shareId' => $shareId,
                        ]);
                        continue;
                    }
                    if ($child instanceof Folder) {
                        $queue[] = $child;
                    }
                }
            }
        } else {
            // 起点是文件 share，没有目录可遍历，仅返回该文件一条。
            $items[] = $this->buildNodeDescriptor($share, $base, $this->pathGuard->relativeFromRoot($rootNode, $base));
            $visited = 1;
        }

        // 按"目录优先 + 相对路径自然顺序"排序，保证前端展示稳定。
        usort($items, static function (array $a, array $b): int {
            if (($a['type'] ?? '') !== ($b['type'] ?? '')) {
                return ($a['type'] ?? '') === 'folder' ? -1 : 1;
            }
            return strnatcasecmp((string)($a['relativePath'] ?? ''), (string)($b['relativePath'] ?? ''));
        });

        return [
            'share' => $this->buildShareRootDescriptor($share, null),
            'base' => [
                'path' => $this->pathGuard->normalizeRelative($relativePath),
                'type' => $base instanceof Folder ? 'folder' : 'file',
                'name' => $base->getName(),
            ],
            'items' => $items,
            'visited' => $visited,
            'maxVisited' => $maxVisited,
            'maxResults' => $effectiveLimit,
            'truncated' => $truncated,
        ];
    }

    /**
     * 准备下载某个共享内的具体文件。
     *
     * 安全约束：
     *   - share 必须可见（isShareVisible）
     *   - share 没有标注 hideDownload；若有，本应用拒绝下载（让用户去 NC 原生页面，由 NC 决定）
     *   - 全局开关 download_enabled 没有被管理员关闭
     *   - 路径不越界（PathGuard）
     *   - 目标必须是文件，不能是目录
     *
     * 返回的句柄由调用方负责放入 StreamingFileResponse；失败时抛 \RuntimeException。
     *
     * @return array{
     *     resource: resource,
     *     name: string,
     *     size: ?int,
     *     mimeType: string,
     * }
     */
    public function prepareDownload(string $shareId, string $relativePath): array {
        if (!$this->getBoolConfig('download_enabled', true)) {
            throw new \RuntimeException('管理员已禁用本应用的下载功能');
        }

        $share = $this->findShareById($shareId);
        if ($share->getHideDownload()) {
            throw new \RuntimeException('该共享已隐藏下载');
        }

        $rootNode = $this->getRootNodeForShare($share);
        $node = $this->resolveRelativeNode($rootNode, $relativePath);

        if (!$node instanceof File) {
            throw new \RuntimeException('请求的目标不是文件，无法下载');
        }

        try {
            $resource = $node->fopen('rb');
        } catch (\Throwable $e) {
            $this->logger->warning('打开共享文件流失败：' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'shareId' => $shareId,
            ]);
            throw new \RuntimeException('打开文件失败');
        }
        if (!is_resource($resource)) {
            throw new \RuntimeException('打开文件失败');
        }

        $size = method_exists($node, 'getSize') ? (int)$node->getSize() : null;
        $mime = method_exists($node, 'getMimeType') ? (string)$node->getMimeType() : 'application/octet-stream';
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }

        return [
            'resource' => $resource,
            'name' => $node->getName(),
            'size' => $size,
            'mimeType' => $mime,
        ];
    }

    public function search(string $query, ?int $limit = null): array {
        $query = mb_strtolower(trim($query));
        if ($query === '' || mb_strlen($query) < 2) {
            return ['query' => $query, 'items' => [], 'total' => 0];
        }

        $hardLimit = max(20, min(500, (int)$this->config->getAppValue(Application::APP_ID, 'search_max_results', '200')));
        $effectiveLimit = $limit !== null ? min($hardLimit, max(1, $limit)) : $hardLimit;

        $results = [];
        $visited = 0;
        $maxVisited = max(1000, (int)$this->config->getAppValue(Application::APP_ID, 'search_max_visited', '20000'));

        foreach ($this->getFilteredPublicShares() as $share) {
            if (count($results) >= $effectiveLimit || $visited >= $maxVisited) {
                break;
            }

            try {
                $rootNode = $this->getRootNodeForShare($share);
                $this->searchNodeRecursive($share, $rootNode, $rootNode, $query, $results, $visited, $effectiveLimit, $maxVisited);
            } catch (\Throwable $e) {
                $this->logger->warning('搜索单个共享时失败：' . $e->getMessage(), [
                    'app' => Application::APP_ID,
                    'exception' => $e,
                ]);
            }
        }

        return [
            'query' => $query,
            'items' => $results,
            'total' => count($results),
            'visited' => $visited,
            'maxVisited' => $maxVisited,
            'limit' => $effectiveLimit,
        ];
    }

    /** @return list<IShare> */
    private function getFilteredPublicShares(): array {
        if ($this->filteredSharesCache !== null) {
            return $this->filteredSharesCache;
        }

        $filtered = [];
        foreach ($this->shareManager->getAllShares() as $share) {
            if (!$share instanceof IShare) {
                continue;
            }
            if ($this->isShareVisible($share)) {
                $filtered[] = $share;
            }
        }
        $this->filteredSharesCache = $filtered;
        return $filtered;
    }

    private function isShareVisible(IShare $share): bool {
        $includePasswordProtected = $this->getBoolConfig('include_password_protected', false);
        $includeExpired = $this->getBoolConfig('include_expired', false);
        $showHiddenDownload = $this->getBoolConfig('show_hide_download_shares', false);

        try {
            if ($share->getShareType() !== IShare::TYPE_LINK) {
                return false;
            }
            if (!$includeExpired && $share->isExpired()) {
                return false;
            }
            if (!$includePasswordProtected && $share->getPassword() !== null && $share->getPassword() !== '') {
                return false;
            }
            if (!$showHiddenDownload && $share->getHideDownload()) {
                return false;
            }
            $token = trim((string)$share->getToken());
            if ($token === '') {
                return false;
            }
            $ownerId = $this->getOwnerId($share);
            if (!$this->isUserEnabled($ownerId)) {
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('过滤公开共享时失败：' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'exception' => $e,
            ]);
            return false;
        }
    }

    private function isUserEnabled(string $ownerId): bool {
        if ($ownerId === '') {
            return false;
        }
        if (isset($this->userEnabledCache[$ownerId])) {
            return $this->userEnabledCache[$ownerId];
        }
        try {
            $user = $this->userManager->get($ownerId);
            $enabled = $user instanceof IUser && $user->isEnabled();
        } catch (\Throwable $e) {
            $this->logger->debug('解析用户启用状态失败：' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'ownerId' => $ownerId,
            ]);
            $enabled = false;
        }
        return $this->userEnabledCache[$ownerId] = $enabled;
    }

    private function findShareById(string $shareId): IShare {
        $shareId = trim($shareId);
        if ($shareId === '') {
            throw new \RuntimeException('缺少共享标识');
        }

        try {
            $share = $this->shareManager->getShareById($shareId);
        } catch (ShareNotFound $e) {
            throw new \RuntimeException('未找到指定的公开共享');
        } catch (\Throwable $e) {
            $this->logger->warning('查询共享失败：' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'shareId' => $shareId,
            ]);
            throw new \RuntimeException('查询公开共享时出错');
        }

        if (!$this->isShareVisible($share)) {
            throw new \RuntimeException('该公开共享已不可访问');
        }
        return $share;
    }

    private function getOwnerId(IShare $share): string {
        $ownerId = (string)($share->getShareOwner() ?: $share->getSharedBy());
        return $ownerId !== '' ? $ownerId : 'unknown';
    }

    /**
     * 计算指向 NextCloud 原生公开分享页面的 URL。
     *
     * 规则（NextCloud 公开分享页面使用 `dir` 参数，而非 `path`）：
     *   - 文件夹类型节点：`/s/<token>?dir=/<相对路径>`，relative 为空时不带 dir
     *   - 文件类型节点  ：跳到该文件**所在父目录**，由 NC 原生页面以列表方式展示，
     *                    若文件直接位于 share 根下，则跳到 share 根（不带 dir）。
     *   - 单文件 share  ：直接 `/s/<token>`
     */
    private function buildNextcloudUrl(IShare $share, string $type, string $relativePath): string {
        $token = trim((string)$share->getToken());
        $base = $this->urlGenerator->linkToRouteAbsolute('files_sharing.sharecontroller.showShare', ['token' => $token]);
        $relativePath = trim($relativePath, '/');
        if ($relativePath === '') {
            return $base;
        }
        if ($type === 'folder') {
            return $base . '?dir=' . $this->encodeNcDir('/' . $relativePath);
        }
        // 文件类型：跳到所在父目录
        $parent = $this->parentDir($relativePath);
        if ($parent === '') {
            return $base;
        }
        return $base . '?dir=' . $this->encodeNcDir('/' . $parent);
    }

    /**
     * NextCloud 公开分享页对 `dir` 参数采用整体百分号编码、保留 "/" 不编码的形式。
     */
    private function encodeNcDir(string $path): string {
        $segments = array_map(static fn(string $s): string => rawurlencode($s), explode('/', $path));
        return implode('/', $segments);
    }

    private function parentDir(string $relativePath): string {
        $parts = explode('/', trim($relativePath, '/'));
        if (count($parts) <= 1) {
            return '';
        }
        array_pop($parts);
        return implode('/', $parts);
    }

    private function buildShareRootDescriptor(IShare $share, ?string $directoryName): array {
        $target = $this->normalizeAbsolutePath((string)$share->getTarget());
        $segments = $this->splitAbsolutePath($target);
        $type = $this->safeGetNodeType($share);
        return [
            'id' => (string)$share->getFullId(),
            'token' => trim((string)$share->getToken()),
            'ownerId' => $this->getOwnerId($share),
            'ownerName' => $directoryName,
            'name' => $this->basename($target),
            'path' => $target,
            'segments' => $segments,
            'type' => $type,
            'label' => trim((string)$share->getLabel()),
            'nextcloudUrl' => $this->buildNextcloudUrl($share, $type === 'file' || $type === 'folder' ? $type : 'folder', ''),
            'passwordProtected' => $share->getPassword() !== null && $share->getPassword() !== '',
            'hideDownload' => $share->getHideDownload(),
            'shareTime' => $share->getShareTime()->format(\DateTimeInterface::ATOM),
            'expiration' => $share->getExpirationDate()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function getRootNodeForShare(IShare $share): Node {
        $ownerId = $this->getOwnerId($share);
        try {
            $ownerFolder = $this->rootFolder->getUserFolder($ownerId);
        } catch (\Throwable $e) {
            throw new \RuntimeException('无法访问共享拥有者的目录');
        }
        $targetPath = trim((string)$share->getTarget(), '/');
        if ($targetPath === '') {
            return $ownerFolder;
        }
        try {
            return $ownerFolder->get($targetPath);
        } catch (NotFoundException $e) {
            throw new \RuntimeException('共享根目录不存在或已被删除');
        }
    }

    private function resolveRelativeNode(Node $rootNode, string $relativePath): Node {
        $normalized = $this->pathGuard->normalizeRelative($relativePath);
        if ($normalized === '') {
            return $rootNode;
        }
        if (!$rootNode instanceof Folder) {
            throw new \RuntimeException('该共享是文件，无法浏览其内部');
        }
        try {
            $node = $rootNode->get($normalized);
        } catch (NotFoundException $e) {
            throw new \RuntimeException('请求的子路径不存在');
        }
        $this->pathGuard->assertWithinRoot($rootNode, $node);
        return $node;
    }

    private function buildNodeDescriptor(IShare $share, Node $node, string $relativePath): array {
        $type = $node instanceof Folder ? 'folder' : 'file';
        $mime = $type === 'file' && method_exists($node, 'getMimeType') ? (string)$node->getMimeType() : null;
        $shareRoot = $this->normalizeAbsolutePath((string)$share->getTarget());
        $absoluteVirtualPath = $shareRoot;
        if ($relativePath !== '') {
            $absoluteVirtualPath = rtrim($shareRoot, '/') . '/' . ltrim($relativePath, '/');
        }
        $normalizedRelative = $this->pathGuard->normalizeRelative($relativePath);

        return [
            'shareId' => (string)$share->getFullId(),
            'name' => $node->getName(),
            'type' => $type,
            'mimeType' => $mime,
            'relativePath' => $normalizedRelative,
            'absoluteVirtualPath' => $this->normalizeAbsolutePath($absoluteVirtualPath),
            'size' => method_exists($node, 'getSize') ? $node->getSize() : null,
            'mtime' => method_exists($node, 'getMTime') ? $node->getMTime() : null,
            'hasChildren' => $node instanceof Folder,
            'nextcloudUrl' => $this->buildNextcloudUrl($share, $type, $normalizedRelative),
        ];
    }

    private function searchNodeRecursive(IShare $share, Node $rootNode, Node $node, string $query, array &$results, int &$visited, int $limit, int $maxVisited): void {
        if ($visited >= $maxVisited || count($results) >= $limit) {
            return;
        }
        $visited++;

        try {
            $relativePath = $this->pathGuard->relativeFromRoot($rootNode, $node);
        } catch (\Throwable $e) {
            return;
        }
        $haystack = mb_strtolower(trim($node->getName() . ' ' . $relativePath));
        if ($haystack !== '' && str_contains($haystack, $query)) {
            $results[] = $this->buildNodeDescriptor($share, $node, $relativePath);
            if (count($results) >= $limit) {
                return;
            }
        }

        if ($node instanceof Folder) {
            foreach ($node->getDirectoryListing() as $child) {
                $this->searchNodeRecursive($share, $rootNode, $child, $query, $results, $visited, $limit, $maxVisited);
                if ($visited >= $maxVisited || count($results) >= $limit) {
                    return;
                }
            }
        }
    }

    private function getBoolConfig(string $key, bool $default): bool {
        $value = strtolower((string)$this->config->getAppValue(Application::APP_ID, $key, $default ? 'yes' : 'no'));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function getDisplayName(string $ownerId): string {
        try {
            $user = $this->userManager->get($ownerId);
            if ($user !== null) {
                $displayName = trim((string)$user->getDisplayName());
                if ($displayName !== '') {
                    return $displayName;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('解析共享拥有者显示名失败：' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'ownerId' => $ownerId,
            ]);
        }
        return $ownerId;
    }

    private function normalizeAbsolutePath(string $path): string {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }
        $path = '/' . trim($path, '/');
        return preg_replace('#/+#', '/', $path) ?: '/';
    }

    private function splitAbsolutePath(string $path): array {
        $trimmed = trim($path, '/');
        return $trimmed === '' ? [] : array_values(array_filter(explode('/', $trimmed), static fn(string $seg): bool => $seg !== ''));
    }

    private function basename(string $path): string {
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return '/';
        }
        $parts = explode('/', $trimmed);
        return end($parts) ?: $trimmed;
    }

    private function safeGetNodeType(IShare $share): string {
        try {
            $type = (string)$share->getNodeType();
            if ($type === 'file' || $type === 'folder') {
                return $type;
            }
        } catch (\Throwable $e) {
            $this->logger->debug('解析共享节点类型失败：' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        }
        return 'unknown';
    }
}
