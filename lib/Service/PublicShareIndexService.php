<?php

declare(strict_types=1);

namespace OCA\PublicShareIndex\Service;

use OCA\PublicShareIndex\AppInfo\Application;
use OCA\PublicShareIndex\Security\PathGuard;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as ShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * 公开共享浏览器核心业务服务（v0.4 起仅承担索引 + 搜索职责）。
 *
 * 关键改动：
 *   - 移除流式下载相关接口（getFileForStreaming）
 *   - 所有节点描述符增加 nextcloudUrl 字段，前端用它跳转回 Nextcloud 原生公开分享页
 */
class PublicShareIndexService {
    /** @var list<IShare>|null 请求级缓存：单次请求内只过滤一次 */
    private ?array $filteredSharesCache = null;

    /** @var array<string, bool> 请求级缓存：用户是否启用 */
    private array $userEnabledCache = [];

    public function __construct(
        private ShareManager $shareManager,
        private IURLGenerator $urlGenerator,
        private IUserManager $userManager,
        private IConfig $config,
        private LoggerInterface $logger,
        private IRootFolder $rootFolder,
        private PathGuard $pathGuard,
    ) {
    }

    public function getBootstrapIndex(): array {
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
     * 计算指向 Nextcloud 原生公开分享页面的 URL。
     *
     * 规则（Nextcloud 公开分享页面使用 `dir` 参数，而非 `path`）：
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
     * Nextcloud 公开分享页对 `dir` 参数采用整体百分号编码、保留 "/" 不编码的形式。
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
