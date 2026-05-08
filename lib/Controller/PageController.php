<?php

declare(strict_types=1);

namespace OCA\PublicShareIndex\Controller;

use OCA\PublicShareIndex\AppInfo\Application;
use OCA\PublicShareIndex\Service\PublicShareIndexService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * 公开共享浏览器的 HTTP 入口。
 *
 * 设计变更（v0.4 起）：
 *   - 不再提供文件流式接口 / 内联预览，所有"打开 / 预览 / 下载 / 子目录浏览"
 *     全部跳转回 Nextcloud 原生公开分享页面（/index.php/s/<token>）。
 *   - 本应用仅承担"集中索引 + 全局搜索"职责。
 *
 * 端点说明：
 *   GET /              页面入口，渲染模板
 *   GET /api/bootstrap 顶层目录树（共享人 → 共享根）
 *   GET /api/children  列出共享根下指定相对路径的子节点（保留作为内部能力，便于未来扩展）
 *   GET /api/search    全局搜索文件名 / 路径
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
        ], 'guest');
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 30, period: 60)]
    public function bootstrap(): DataResponse {
        if (!$this->isPublicPageEnabled()) {
            return new DataResponse(['error' => '公开共享浏览器已被管理员禁用'], Http::STATUS_NOT_FOUND);
        }
        try {
            return new DataResponse($this->indexService->getBootstrapIndex());
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

    private function isPublicPageEnabled(): bool {
        $value = strtolower((string)$this->config->getAppValue(Application::APP_ID, 'public_enabled', 'yes'));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
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
