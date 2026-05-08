<?php

declare(strict_types=1);

namespace OCA\PublicShareIndex\Security;

use OCP\Files\Node;

/**
 * 集中处理相对路径规范化与节点是否位于共享根目录之内的硬校验。
 * 单一职责：仅做路径与边界检查，不持有任何业务状态，便于复用与单元测试。
 */
class PathGuard {
    /**
     * 规范化前端传入的相对路径，禁止 ".." / "." / 反斜杠 / 空段等可疑形态。
     *
     * @throws \RuntimeException 路径包含不合法段时抛出
     */
    public function normalizeRelative(string $path): string {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        // 反斜杠在跨平台路径里可能被解释为分隔符，直接拒绝以避免歧义。
        if (str_contains($path, '\\')) {
            throw new \RuntimeException('相对路径包含非法字符');
        }
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new \RuntimeException('相对路径不允许包含上级目录');
            }
            $segments[] = $segment;
        }
        return implode('/', $segments);
    }

    /**
     * 校验某个节点是否真的位于给定共享根目录之下，防止任何形式的路径穿越。
     *
     * @throws \RuntimeException 节点路径不在共享根目录之内时抛出
     */
    public function assertWithinRoot(Node $rootNode, Node $candidate): void {
        $rootPath = rtrim($rootNode->getPath(), '/');
        $candidatePath = $candidate->getPath();
        if ($candidatePath === $rootPath) {
            return;
        }
        $prefix = $rootPath . '/';
        if (!str_starts_with($candidatePath, $prefix)) {
            throw new \RuntimeException('请求的节点不在允许访问的共享根目录之内');
        }
    }

    /**
     * 把绝对节点路径转换为相对共享根的路径，越界时抛出。
     */
    public function relativeFromRoot(Node $rootNode, Node $candidate): string {
        $this->assertWithinRoot($rootNode, $candidate);
        $rootPath = rtrim($rootNode->getPath(), '/');
        $candidatePath = $candidate->getPath();
        if ($candidatePath === $rootPath) {
            return '';
        }
        return ltrim(substr($candidatePath, strlen($rootPath)), '/');
    }
}
