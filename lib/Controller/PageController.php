<?php

declare(strict_types=1);

namespace OCA\PublicShareIndex\Controller;

use OCA\PublicShareIndex\AppInfo\Application;
use OCA\PublicShareIndex\Http\StreamingFileResponse;
use OCA\PublicShareIndex\Service\PublicShareIndexService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * 公开共享浏览器的 HTTP 入口。
 *
 * 端点说明（v0.5）：
 *   GET /                页面入口，渲染模板
 *   GET /api/bootstrap   顶层目录树（共享人 → 共享根）
 *   GET /api/children    列出共享根下指定相对路径的"一层"子节点
 *   GET /api/search      全局搜索文件名 / 路径
 *   GET /api/list-all    递归把单个 share 内的全部内容平铺成列表（"显示所有文件"开关）
 *   GET /api/download    流式下载共享内的具体文件（受 hideDownload / download_enabled 限制）
 *
 * 设计原则：
 *   - 所有匿名 API 都加 AnonRateLimit 限流，避免被脚本扫描 / DOS。
 *   - 下载走 StreamingFileResponse，永远以 attachment 头送出，杜绝内联脚本执行风险。
 *   - 任何"按 shareId + relativePath"调用都让 Service / PathGuard 做范围校验，
 *     Controller 只负责协议层。
 */
class PageController extends Controller {
    public function __construct(
        IRequest $request,
        private PublicShareIndexService $indexService,
        private IConfig $config,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        if (!$this->isPublicPageEnabled()) {
            return new TemplateResponse('core', '404', [], 'guest');
        }

        Util::addStyle(Application::APP_ID, 'style');
        Util::addScript(Application::APP_ID, 'filter');

        return new TemplateResponse(Application::APP_ID, 'index', [
            'title' => $this->config->getAppValue(Application::APP_ID, 'title', '公开共享资源浏览器'),
            'bootstrapUrl' => $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.bootstrap'),
            'childrenUrl' => $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.children'),
            'searchUrl' => $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.search'),
            'listAllUrl' => $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.listAll'),
            'downloadUrl' => $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.download'),
            'downloadEnabled' => $this->isDownloadEnabled() ? 'yes' : 'no',
        ], 'guest');
    }

    /**
     * 取顶层共享索引。默认走 TTL 缓存；?force=1 强制重新扫描并写回。
     * 缓存命中时响应里会带 fromCache=true，前端用来显示「来自缓存」徽标。
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 30, period: 60)]
    public function bootstrap(string $force = ''): DataResponse {
        if (!$this->isPublicPageEnabled()) {
            return new DataResponse(['error' => '公开共享浏览器已被管理员禁用'], Http::STATUS_NOT_FOUND);
        }
        try {
            $forceRefresh = in_array(strtolower($force), ['1', 'true', 'yes', 'on'], true);
            return new DataResponse($this->indexService->getBootstrapIndex($forceRefresh));
        } catch (\Throwable $e) {
            $this->logger->warning('加载公开共享索引失败：' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'exception' => $e,
            ]);
            return new DataResponse(['error' => '加载公开共享索引时出错'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 120, period: 60)]
    public function children(string $shareId = '', string $path = ''): DataResponse {
        if (!$this->isPublicPageEnabled()) {
            return new DataResponse(['error' => '公开共享浏览器已被管理员禁用'], Http::STATUS_NOT_FOUND);
        }
        try {
            return new DataResponse($this->indexService->listChildren($shareId, $path));
        } catch (\Throwable $e) {
            $this->logger->info('列出共享子节点失败：' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'shareId' => $shareId,
                'path' => $path,
            ]);
            return new DataResponse(['error' => $this->safeErrorMessage($e, '加载目录时出错')], Http::STATUS_BAD_REQUEST);
        }
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 30, period: 60)]
    public function search(string $q = ''): DataResponse {
        if (!$this->isPublicPageEnabled()) {
            return new DataResponse(['error' => '公开共享浏览器已被管理员禁用'], Http::STATUS_NOT_FOUND);
        }
        try {
            return new DataResponse($this->indexService->search($q));
        } catch (\Throwable $e) {
            $this->logger->info('搜索公开共享失败：' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'query' => $q,
            ]);
            return new DataResponse(['error' => $this->safeErrorMessage($e, '搜索时出错')], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * 「显示所有文件」开关下使用：把单个 share 的全部内容（受上限保护）一次性返回给前端。
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 30, period: 60)]
    public function listAll(string $shareId = '', string $path = ''): DataResponse {
        if (!$this->isPublicPageEnabled()) {
            return new DataResponse(['error' => '公开共享浏览器已被管理员禁用'], Http::STATUS_NOT_FOUND);
        }
        try {
            return new DataResponse($this->indexService->listAll($shareId, $path));
        } catch (\Throwable $e) {
            $this->logger->info('listAll 失败：' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'shareId' => $shareId,
                'path' => $path,
            ]);
            return new DataResponse(['error' => $this->safeErrorMessage($e, '加载完整列表时出错')], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * 流式下载共享内的具体文件。
     *
     * 关键安全约束：
     *   - 必须由 Service 完整校验 share 可见性、hideDownload、download_enabled、路径越界、必须为文件
     *   - 全程 chunked 输出，避免 OOM
     *   - 永远以 attachment 头送出，永远 application/octet-stream（不让浏览器内联展示）
     *   - 关闭浏览器 / 代理缓存
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 60, period: 60)]
    public function download(string $shareId = '', string $path = ''): Response {
        if (!$this->isPublicPageEnabled()) {
            return new DataResponse(['error' => '公开共享浏览器已被管理员禁用'], Http::STATUS_NOT_FOUND);
        }
        if (!$this->isDownloadEnabled()) {
            return new DataResponse(['error' => '管理员已禁用本应用的下载功能'], Http::STATUS_FORBIDDEN);
        }
        try {
            $download = $this->indexService->prepareDownload($shareId, $path);
        } catch (\Throwable $e) {
            $this->logger->info('准备下载失败：' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'shareId' => $shareId,
                'path' => $path,
            ]);
            return new DataResponse(['error' => $this->safeErrorMessage($e, '下载时出错')], Http::STATUS_BAD_REQUEST);
        }

        return new StreamingFileResponse(
            $download['resource'],
            (string)$download['name'],
            $download['size'],
            // 强制 application/octet-stream：杜绝浏览器内联展示 HTML / SVG / PDF 等带脚本风险的格式
            'application/octet-stream',
            $this->getDownloadChunkBytes(),
        );
    }

    private function isPublicPageEnabled(): bool {
        $value = strtolower((string)$this->config->getAppValue(Application::APP_ID, 'public_enabled', 'yes'));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function isDownloadEnabled(): bool {
        $value = strtolower((string)$this->config->getAppValue(Application::APP_ID, 'download_enabled', 'yes'));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function getDownloadChunkBytes(): int {
        $value = (int)$this->config->getAppValue(Application::APP_ID, 'download_chunk_bytes', '8192');
        // 范围限制：最小 1KB，最大 1MB，避免极端值
        if ($value < 1024) {
            return 8192;
        }
        if ($value > 1048576) {
            return 1048576;
        }
        return $value;
    }

    /**
     * 把内部异常文案过滤成对外安全的中文用户提示，
     * 已知的中文业务错误透传，其它统一回退到 fallback。
     */
    private function safeErrorMessage(\Throwable $e, string $fallback): string {
        $msg = trim($e->getMessage());
        if ($msg === '') {
            return $fallback;
        }
        if (preg_match('/^[\x{4e00}-\x{9fff}\d\s\.,;:：；，。、（）()\-_\/]+$/u', $msg) === 1) {
            return $msg;
        }
        return $fallback;
    }
}
