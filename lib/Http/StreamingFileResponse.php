<?php

declare(strict_types=1);

namespace OCA\PublicShareIndex\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

/**
 * 文件流式响应。
 *
 * 设计目的：
 *   - 把任何尺寸的文件都按固定大小分块写出，避免 PHP 进程把整个文件读进内存。
 *   - 仅作为下载通道使用，永远以 attachment Content-Disposition 头送出，
 *     不允许内联预览，从而避开浏览器对 HTML / SVG 等类型的脚本执行风险。
 *
 * 参数：
 *   - $resource 必须是 fopen($path, 'rb') 或 OCP\Files\File::fopen('r') 返回的可读流。
 *   - $contentLength 当未知时（流不可 seek）传 null，不写 Content-Length 头，让浏览器走 chunked。
 *   - $chunkSize 必须 >= 1，默认 8 KB；过大会增加峰值内存，过小会增加系统调用次数。
 */
class StreamingFileResponse extends Response implements ICallbackResponse {
    /** @var resource */
    private $resource;

    public function __construct(
        $resource,
        string $fileName,
        ?int $contentLength = null,
        string $contentType = 'application/octet-stream',
        private int $chunkSize = 8192,
    ) {
        parent::__construct();

        if (!is_resource($resource)) {
            throw new \InvalidArgumentException('StreamingFileResponse 需要一个可读资源句柄');
        }
        if ($this->chunkSize < 1) {
            $this->chunkSize = 8192;
        }
        $this->resource = $resource;

        $this->setStatus(Http::STATUS_OK);
        $this->addHeader('Content-Type', $contentType);
        // 文件名按 RFC 5987 编码，兼容含中文 / 空格 / 特殊字符的文件名。
        $asciiFallback = $this->buildAsciiFallback($fileName);
        $disposition = 'attachment; filename="' . $asciiFallback . '"; filename*=UTF-8\'\'' . rawurlencode($fileName);
        $this->addHeader('Content-Disposition', $disposition);
        // 强制不缓存，避免代理 / 浏览器缓存到敏感共享内容。
        $this->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $this->addHeader('Pragma', 'no-cache');
        $this->addHeader('Expires', '0');
        // 进一步禁止任何嵌入策略。
        $this->addHeader('X-Content-Type-Options', 'nosniff');
        $this->addHeader('X-Frame-Options', 'DENY');
        $this->addHeader('Referrer-Policy', 'no-referrer');

        if ($contentLength !== null && $contentLength >= 0) {
            $this->addHeader('Content-Length', (string)$contentLength);
        }
    }

    public function callback(IOutput $output): void {
        try {
            // 关闭输出缓冲，避免 PHP 把已经写出的字节再次缓存到内存。
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            while (!feof($this->resource)) {
                $chunk = fread($this->resource, $this->chunkSize);
                if ($chunk === false) {
                    break;
                }
                if ($chunk !== '') {
                    $output->setOutput($chunk);
                }
                if (connection_aborted()) {
                    break;
                }
            }
        } finally {
            if (is_resource($this->resource)) {
                @fclose($this->resource);
            }
        }
    }

    /**
     * 把含中文 / 特殊字符的文件名退化为可被旧浏览器识别的 ASCII 形态，
     * 真正的 UTF-8 文件名通过 filename*=UTF-8'' 给现代浏览器使用。
     */
    private function buildAsciiFallback(string $fileName): string {
        $ascii = preg_replace('/[^A-Za-z0-9._\-]+/', '_', $fileName) ?? 'download';
        $ascii = trim($ascii, '_');
        return $ascii !== '' ? $ascii : 'download';
    }
}
