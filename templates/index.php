<?php
/** @var array $_ */
?>
<meta name="robots" content="noindex,nofollow,noarchive" />
<div
    id="publicshareindex-app"
    class="psi-shell"
    data-bootstrap-url="<?php p($_['bootstrapUrl']); ?>"
    data-children-url="<?php p($_['childrenUrl']); ?>"
    data-search-url="<?php p($_['searchUrl']); ?>"
>
    <noscript>
        <div class="psi-noscript">
            本页面需要启用 JavaScript 才能浏览公开共享内容，请在浏览器中开启脚本支持后刷新。
        </div>
    </noscript>

    <aside class="psi-sidebar" aria-label="共享目录树">
        <div class="psi-brand">
            <h1><?php p($_['title']); ?></h1>
        </div>

        <div class="psi-search-box">
            <label class="psi-visually-hidden" for="psi-search-input">搜索</label>
            <div class="psi-search-input-wrap">
                <svg class="psi-search-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input id="psi-search-input" type="search" autocomplete="off" placeholder="搜索全部公开文件 / 目录" />
            </div>
        </div>

        <div class="psi-sidebar-section">
            <div class="psi-sidebar-title">共享人</div>
            <div id="psi-tree" class="psi-tree" role="tree" aria-live="polite"></div>
        </div>

        <div class="psi-sidebar-footer">
            <span id="psi-sidebar-stats" class="psi-sidebar-stats">加载中…</span>
        </div>
    </aside>

    <main class="psi-main" aria-label="共享内容列表">
        <header class="psi-topbar">
            <div class="psi-breadcrumbs" id="psi-breadcrumbs" aria-label="路径"></div>
            <div class="psi-topbar-actions">
                <div id="psi-refresh" class="psi-btn" role="button" tabindex="0" title="重新加载共享索引">刷新</div>
            </div>
        </header>

        <section class="psi-content">
            <div class="psi-panel-header">
                <div class="psi-panel-title">
                    <h2 id="psi-current-title">公开共享</h2>
                    <p id="psi-current-subtitle">从左侧目录树进入任意共享人，或使用顶部搜索框搜索全部公开文件 / 目录。所有"打开"操作都会跳转到 Nextcloud 原生公开分享页面。</p>
                </div>
                <div class="psi-status" id="psi-status" role="status" aria-live="polite">准备就绪</div>
            </div>

            <div class="psi-table-wrap">
                <table class="psi-table" aria-describedby="psi-current-title">
                    <thead>
                    <tr>
                        <th class="psi-col-name">名称</th>
                        <th class="psi-col-meta">所属共享人</th>
                        <th class="psi-col-type">类型</th>
                        <th class="psi-col-time">修改时间</th>
                        <th class="psi-col-actions">操作</th>
                    </tr>
                    </thead>
                    <tbody id="psi-file-list">
                    <tr><td colspan="5" class="psi-empty">正在加载…</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
