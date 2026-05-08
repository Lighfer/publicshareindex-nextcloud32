/**
 * 公开共享浏览器前端入口（v0.4）。
 *
 * 职责变更：
 *   - 不再做任何文件内联预览 / 下载，所有"打开"操作一律跳转回 Nextcloud
 *     原生公开分享页面（在新标签打开）。
 *   - 仅承担：① 顶层目录索引浏览；② 全局搜索全部公开文件 / 目录；
 *           ③ 给每个节点提供一个"在 Nextcloud 中打开"的入口。
 */
(function () {
    'use strict';

    /** ---------------- 工具函数 ---------------- */

    function $(id) { return document.getElementById(id); }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>'"]/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }
    var escapeAttr = escapeHtml;

    function formatTime(ts) {
        if (!ts) return '—';
        var d = typeof ts === 'number' ? new Date(ts * 1000) : new Date(ts);
        if (isNaN(d.getTime())) return '—';
        return d.toLocaleString();
    }

    function typeLabel(type) {
        if (type === 'folder') return '目录';
        if (type === 'file') return '文件';
        if (type === 'owner') return '共享人';
        return '—';
    }

    function iconHtml(type, mime) {
        var folderSvg = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h4l2 2H18.5A2.5 2.5 0 0 1 21 9.5v8A2.5 2.5 0 0 1 18.5 20h-13A2.5 2.5 0 0 1 3 17.5z"/></svg>';
        var ownerSvg  = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/></svg>';
        var fileSvg   = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6.5A1.5 1.5 0 0 0 5 4.5v15A1.5 1.5 0 0 0 6.5 21h11A1.5 1.5 0 0 0 19 19.5V8z"/><path d="M14 3v5h5"/></svg>';
        if (type === 'owner')  return '<span class="psi-icon psi-icon-owner" aria-hidden="true">' + ownerSvg + '</span>';
        if (type === 'folder') return '<span class="psi-icon psi-icon-folder" aria-hidden="true">' + folderSvg + '</span>';
        // file 按 mime 着色，但形状统一
        var m = (mime || '').toLowerCase();
        var cls = 'psi-icon psi-icon-file';
        if (m.indexOf('image/') === 0) cls = 'psi-icon psi-icon-image';
        else if (m.indexOf('video/') === 0) cls = 'psi-icon psi-icon-video';
        else if (m.indexOf('audio/') === 0) cls = 'psi-icon psi-icon-audio';
        else if (m === 'application/pdf') cls = 'psi-icon psi-icon-pdf';
        else if (m.indexOf('text/') === 0 || m.indexOf('application/json') === 0 || m.indexOf('xml') >= 0) cls = 'psi-icon psi-icon-text';
        return '<span class="' + cls + '" aria-hidden="true">' + fileSvg + '</span>';
    }

    var externalLinkSvg = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M20 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h5"/></svg>';

    /** ---------------- 网络层 ---------------- */

    function fetchJson(baseUrl, params) {
        if (!baseUrl) {
            return Promise.reject(new Error('未获取到接口地址，请刷新页面或联系管理员'));
        }
        var url = new URL(baseUrl, window.location.origin);
        Object.keys(params || {}).forEach(function (k) {
            var v = params[k];
            if (v !== undefined && v !== null && v !== '') {
                url.searchParams.set(k, v);
            }
        });
        return fetch(url.toString(), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        }).then(function (res) {
            return res.text().then(function (text) {
                var data = null;
                try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
                if (!res.ok) {
                    var msg = data && data.error ? data.error : ('请求失败（HTTP ' + res.status + '）');
                    throw new Error(msg);
                }
                if (data && data.error) throw new Error(data.error);
                return data || {};
            });
        });
    }

    /** ---------------- 状态 ---------------- */

    var cfg = {};
    var state = {
        bootstrap: null,
        treeRoot: null,
        currentSelectionKey: null,
        searchTimer: null,
        searchActive: false,
    };
    var el = {};

    function setStatus(message) { if (el.status) el.status.textContent = message; }

    function renderError(message) {
        if (el.fileList) {
            el.fileList.innerHTML = '<tr><td colspan="5" class="psi-empty psi-empty-error">' + escapeHtml(message) + '</td></tr>';
        }
    }

    /** ---------------- 树构建 ---------------- */

    function createTreeNode(id, label, type, extra) {
        return Object.assign({
            id: id, label: label, type: type,
            children: [], childMap: {}, parent: null,
            shareRoots: [],
        }, extra || {});
    }

    function buildTree(bootstrap) {
        var root = createTreeNode('root', '公开共享', 'root');
        (bootstrap.owners || []).forEach(function (owner) {
            var ownerNode = createTreeNode('owner:' + owner.ownerId, owner.directoryName, 'owner', { owner: owner });
            ownerNode.parent = root;
            root.children.push(ownerNode);
            (owner.roots || []).forEach(function (shareRoot) {
                var segments = shareRoot.segments && shareRoot.segments.length ? shareRoot.segments : [shareRoot.name];
                var current = ownerNode;
                segments.forEach(function (segment, idx) {
                    var isLast = idx === segments.length - 1;
                    var key = segment.toLowerCase();
                    if (!current.childMap[key]) {
                        var nodeType = isLast ? shareRoot.type : 'folder';
                        var node = createTreeNode(current.id + '/' + encodeURIComponent(segment), segment, nodeType, { segmentName: segment });
                        node.parent = current;
                        current.childMap[key] = node;
                        current.children.push(node);
                    }
                    current = current.childMap[key];
                    if (isLast) {
                        current.shareRoots.push(shareRoot);
                        current.shareRoot = current.shareRoot || shareRoot;
                        current.type = shareRoot.type === 'folder' || current.children.length > 0 ? 'folder' : (shareRoot.type || current.type);
                    }
                });
            });
        });

        function sortNode(node) {
            node.children.sort(function (a, b) {
                if (a.type !== b.type) return a.type === 'folder' || a.type === 'owner' ? -1 : 1;
                return a.label.localeCompare(b.label, 'zh-Hans-CN');
            });
            node.children.forEach(sortNode);
        }
        sortNode(root);
        return root;
    }

    function renderSidebar() {
        el.tree.innerHTML = '';
        var fragment = document.createDocumentFragment();
        state.treeRoot.children.forEach(function (ownerNode) {
            fragment.appendChild(renderTreeNode(ownerNode, 0));
        });
        el.tree.appendChild(fragment);
    }

    function renderTreeNode(node, depth) {
        var wrapper = document.createElement('div');
        wrapper.className = 'psi-tree-node';
        wrapper.classList.add(depth === 0 ? 'psi-tree-node-root' : 'psi-tree-node-child');
        wrapper.dataset.key = node.id;

        // 注意：这里刻意不再使用 <button>，因为 Nextcloud guest 布局会给原生 button 注入默认样式。
        // 改用 <div role="button" tabindex="0">，配合键盘事件实现可达。
        var item = document.createElement('div');
        item.className = 'psi-tree-item';
        item.setAttribute('role', 'treeitem');
        item.setAttribute('tabindex', '0');
        item.setAttribute('aria-expanded', node._expanded ? 'true' : 'false');
        if (state.currentSelectionKey === node.id) {
            item.classList.add('active');
            item.setAttribute('aria-current', 'true');
        }

        var caret = document.createElement('span');
        caret.className = 'psi-tree-caret';
        if (node.children.length > 0) {
            caret.innerHTML = '<svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>';
        } else {
            caret.innerHTML = '<span class="psi-tree-caret-empty"></span>';
        }
        if (node._expanded) caret.classList.add('expanded');

        var label = document.createElement('span');
        label.className = 'psi-tree-label';
        label.textContent = node.label;

        item.append(caret, label);

        function trigger() {
            if (node.children.length > 0) node._expanded = !node._expanded;
            activateNode(node);
        }
        item.addEventListener('click', trigger);
        item.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                e.preventDefault();
                trigger();
            }
        });
        wrapper.appendChild(item);

        if (node.children.length > 0 && node._expanded) {
            var childWrap = document.createElement('div');
            childWrap.className = 'psi-tree-children';
            childWrap.setAttribute('role', 'group');
            node.children.forEach(function (child) {
                childWrap.appendChild(renderTreeNode(child, depth + 1));
            });
            wrapper.appendChild(childWrap);
        }
        return wrapper;
    }

    function findNodeById(node, id) {
        if (node.id === id) return node;
        var children = node.children || [];
        for (var i = 0; i < children.length; i++) {
            var found = findNodeById(children[i], id);
            if (found) return found;
        }
        return null;
    }

    /** ---------------- 面包屑 ---------------- */

    function renderBreadcrumbs(items) {
        el.breadcrumbs.innerHTML = '';
        items.forEach(function (item, index) {
            // 用 <span> 而不是 <button>，避免 Nextcloud 全局 button 样式渗透。
            var crumb = document.createElement('span');
            crumb.className = 'psi-crumb';
            crumb.textContent = item.label;
            if (item.kind === 'node' && item.key && state.treeRoot) {
                crumb.classList.add('psi-crumb-clickable');
                crumb.setAttribute('role', 'button');
                crumb.setAttribute('tabindex', '0');
                var trigger = function () {
                    var node = findNodeById(state.treeRoot, item.key);
                    if (node) activateNode(node);
                };
                crumb.addEventListener('click', trigger);
                crumb.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                        e.preventDefault();
                        trigger();
                    }
                });
            } else {
                crumb.classList.add('psi-crumb-static');
            }
            el.breadcrumbs.appendChild(crumb);
            if (index < items.length - 1) {
                var sep = document.createElement('span');
                sep.className = 'psi-crumb-sep';
                sep.setAttribute('aria-hidden', 'true');
                sep.textContent = '/';
                el.breadcrumbs.appendChild(sep);
            }
        });
    }

    function getBreadcrumbsFromNode(node) {
        var crumbs = [];
        var cur = node;
        while (cur && cur.type !== 'root') {
            crumbs.unshift({ label: cur.label, key: cur.id, kind: 'node' });
            cur = cur.parent;
        }
        crumbs.unshift({ label: '公开共享', key: 'root', kind: 'static' });
        return crumbs;
    }

    /** ---------------- 主面板渲染 ---------------- */

    function activateNode(node) {
        state.searchActive = false;
        state.currentSelectionKey = node.id;
        renderSidebar();
        renderListing(node);
    }

    function renderListing(node) {
        if (!node) return;
        renderBreadcrumbs(getBreadcrumbsFromNode(node));
        el.currentTitle.textContent = node.type === 'root' ? '公开共享' : node.label;
        el.currentSubtitle.textContent = subtitleFor(node);

        // share root（leaf）：显示一行卡片 + 直接打开按钮
        if (node.shareRoot && node.children.length === 0) {
            renderShareRootDetail(node);
            return;
        }
        // owner / 中间虚拟目录 / root：显示子项列表
        renderChildrenAsRows(node);
    }

    function subtitleFor(node) {
        if (node.type === 'root') return '当前 Nextcloud 实例上的全部公开链接共享。点击共享人 / 共享根可在右侧查看，"在 Nextcloud 中打开"会跳转到原生公开分享页面。';
        if (node.type === 'owner') return '该共享人公开的全部链接共享。';
        if (node.shareRoot && node.children.length === 0) return '该共享在 Nextcloud 中的原生公开分享地址，点击下方按钮跳转打开。';
        return '此目录下聚合的共享条目。';
    }

    function renderChildrenAsRows(node) {
        setStatus('显示 ' + node.children.length + ' 个条目');
        if (!node.children.length) {
            el.fileList.innerHTML = '<tr><td colspan="5" class="psi-empty">此处暂无可展示的共享条目</td></tr>';
            return;
        }
        var rows = node.children.map(function (child) {
            return rowForTreeNode(child);
        }).join('');
        el.fileList.innerHTML = rows;
        bindRowActions();
    }

    /**
     * 把一棵树节点渲染为一行：
     *   - share root（leaf）：操作 = 在 Nextcloud 中打开（<a>）
     *   - owner / 中间虚拟目录：操作 = 进入（<span role="button">）
     *
     * 不使用 <button>，避免 Nextcloud guest 布局给原生 button 注入默认样式。
     */
    function rowForTreeNode(node) {
        var ownerName = ownerNameOf(node);
        var meta = node.type === 'owner' ? '—' : ownerName;
        var iconType = node.type === 'owner' ? 'owner' : (node.shareRoot && node.shareRoot.type === 'file' && node.children.length === 0 ? 'file' : 'folder');
        var typeText = node.type === 'owner' ? '共享人' : (node.shareRoot && node.children.length === 0 ? typeLabel(node.shareRoot.type) : '目录');
        var time = node.shareRoot && node.shareRoot.shareTime ? formatTime(node.shareRoot.shareTime) : '—';

        var nameLink = '<span class="psi-linklike" role="button" tabindex="0" data-action="enter-node" data-key="' + escapeAttr(node.id) + '">'
            + iconHtml(iconType) + '<span>' + escapeHtml(node.label) + '</span></span>';

        var actionEl;
        if (node.shareRoot && node.children.length === 0) {
            actionEl = '<a class="psi-btn psi-btn-action" target="_blank" rel="noopener noreferrer" href="' + escapeAttr(node.shareRoot.nextcloudUrl) + '">在 Nextcloud 中打开 ' + externalLinkSvg + '</a>';
        } else {
            actionEl = '<span class="psi-btn psi-btn-action" role="button" tabindex="0" data-action="enter-node" data-key="' + escapeAttr(node.id) + '">进入</span>';
        }

        return '<tr>'
            + '<td>' + nameLink + '</td>'
            + '<td class="psi-cell-meta">' + escapeHtml(meta) + '</td>'
            + '<td>' + escapeHtml(typeText) + '</td>'
            + '<td>' + time + '</td>'
            + '<td class="psi-col-actions">' + actionEl + '</td>'
            + '</tr>';
    }

    function ownerNameOf(node) {
        var cur = node;
        while (cur && cur.type !== 'owner' && cur.parent) cur = cur.parent;
        if (cur && cur.type === 'owner' && cur.owner) return cur.owner.directoryName || cur.owner.ownerId || '';
        if (node.shareRoot && (node.shareRoot.ownerName || node.shareRoot.ownerId)) return node.shareRoot.ownerName || node.shareRoot.ownerId;
        return '—';
    }

    function renderShareRootDetail(node) {
        var sr = node.shareRoot;
        if (!sr) return;
        setStatus('共享根详情');
        var owner = ownerNameOf(node);
        var meta = [];
        if (sr.label) meta.push('标签：' + sr.label);
        if (sr.shareTime) meta.push('分享时间：' + formatTime(sr.shareTime));
        if (sr.expiration) meta.push('到期时间：' + formatTime(sr.expiration));
        if (sr.passwordProtected) meta.push('受密码保护');
        if (sr.hideDownload) meta.push('隐藏下载');

        var rows = ''
            + '<tr>'
            + '  <td>' + iconHtml(sr.type === 'file' ? 'file' : 'folder') + '<span class="psi-row-name">' + escapeHtml(node.label) + '</span></td>'
            + '  <td class="psi-cell-meta">' + escapeHtml(owner) + '</td>'
            + '  <td>' + escapeHtml(typeLabel(sr.type)) + '</td>'
            + '  <td>' + escapeHtml(sr.shareTime ? formatTime(sr.shareTime) : '—') + '</td>'
            + '  <td class="psi-col-actions"><a class="psi-btn psi-btn-action" target="_blank" rel="noopener noreferrer" href="' + escapeAttr(sr.nextcloudUrl) + '">在 Nextcloud 中打开 ' + externalLinkSvg + '</a></td>'
            + '</tr>';
        if (meta.length) {
            rows += '<tr><td colspan="5" class="psi-detail-meta">' + escapeHtml(meta.join('  ·  ')) + '</td></tr>';
        }
        el.fileList.innerHTML = rows;
        bindRowActions();
    }

    function bindRowActions() {
        el.fileList.querySelectorAll('[data-action]').forEach(function (node) {
            var trigger = function () {
                var action = node.getAttribute('data-action');
                if (action === 'enter-node') {
                    var n = findNodeById(state.treeRoot, node.getAttribute('data-key'));
                    if (n) {
                        if (n.children.length > 0) n._expanded = true;
                        activateNode(n);
                    }
                }
            };
            node.addEventListener('click', trigger);
            node.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                    e.preventDefault();
                    trigger();
                }
            });
        });
    }

    /** ---------------- 搜索 ---------------- */

    function runSearch(query) {
        if (!query || query.trim().length < 2) {
            state.searchActive = false;
            var node = state.currentSelectionKey ? findNodeById(state.treeRoot, state.currentSelectionKey) : state.treeRoot;
            if (node) renderListing(node);
            return;
        }
        state.searchActive = true;
        setStatus('搜索中…');
        fetchJson(cfg.searchUrl, { q: query.trim() })
            .then(function (data) {
                renderSearchResults(data);
                setStatus('搜索到 ' + data.total + ' 条结果');
            })
            .catch(function (err) {
                setStatus('搜索失败');
                renderError(err.message || '搜索时出错');
            });
    }

    function renderSearchResults(data) {
        el.currentTitle.textContent = '搜索：' + data.query;
        el.currentSubtitle.textContent = '共 ' + data.total + ' 条结果' + (data.limit && data.total >= data.limit ? '（已截断到上限 ' + data.limit + '）' : '') + '。点击结果会在新标签打开 Nextcloud 原生公开分享页面。';
        renderBreadcrumbs([
            { label: '公开共享', kind: 'static' },
            { label: '搜索：' + data.query, kind: 'static' },
        ]);

        if (!data.items.length) {
            el.fileList.innerHTML = '<tr><td colspan="5" class="psi-empty">没有找到匹配的文件或目录</td></tr>';
            return;
        }

        var rows = data.items.map(function (item) {
            var owner = '—';
            var locationParts = [];
            var sr = findShareRoot(item.shareId);
            if (sr) {
                owner = sr.ownerName || sr.ownerId || '—';
                // 把共享人放在路径最前面，让用户一眼看到这是谁的文件
                locationParts.push(owner);
                if (sr.segments && sr.segments.length) locationParts = locationParts.concat(sr.segments);
                if (item.relativePath) locationParts = locationParts.concat(item.relativePath.split('/'));
            }
            var locationLine = locationParts.filter(Boolean).join(' / ');
            var nameLink = '<a class="psi-linklike" target="_blank" rel="noopener noreferrer" href="' + escapeAttr(item.nextcloudUrl) + '">'
                + iconHtml(item.type, item.mimeType) + '<span>' + escapeHtml(item.name) + '</span></a>';
            var pathLine = locationLine ? '<div class="psi-result-path" title="' + escapeAttr(locationLine) + '">' + escapeHtml(locationLine) + '</div>' : '';
            return '<tr>'
                + '<td>' + nameLink + pathLine + '</td>'
                + '<td class="psi-cell-meta">' + escapeHtml(owner) + '</td>'
                + '<td>' + escapeHtml(typeLabel(item.type)) + '</td>'
                + '<td>' + formatTime(item.mtime) + '</td>'
                + '<td class="psi-col-actions"><a class="psi-btn psi-btn-action" target="_blank" rel="noopener noreferrer" href="' + escapeAttr(item.nextcloudUrl) + '">在 Nextcloud 中打开 ' + externalLinkSvg + '</a></td>'
                + '</tr>';
        }).join('');
        el.fileList.innerHTML = rows;
    }

    function findShareRoot(shareId) {
        var owners = (state.bootstrap && state.bootstrap.owners) || [];
        for (var i = 0; i < owners.length; i++) {
            var roots = owners[i].roots || [];
            for (var j = 0; j < roots.length; j++) {
                if (roots[j].id === shareId) return roots[j];
            }
        }
        return null;
    }

    /** ---------------- 启动 ---------------- */

    function readConfigFromDataset() {
        var root = $('publicshareindex-app');
        if (!root) return false;
        var ds = root.dataset || {};
        cfg = {
            bootstrapUrl: ds.bootstrapUrl || '',
            childrenUrl: ds.childrenUrl || '',
            searchUrl: ds.searchUrl || '',
        };
        return Boolean(cfg.bootstrapUrl && cfg.searchUrl);
    }

    function loadBootstrap() {
        setStatus('加载共享索引中…');
        if (el.fileList) {
            el.fileList.innerHTML = ''
                + '<tr><td colspan="5"><div class="psi-skeleton"></div></td></tr>'
                + '<tr><td colspan="5"><div class="psi-skeleton"></div></td></tr>'
                + '<tr><td colspan="5"><div class="psi-skeleton"></div></td></tr>';
        }
        return fetchJson(cfg.bootstrapUrl)
            .then(function (data) {
                state.bootstrap = data;
                state.treeRoot = buildTree(data);
                state.treeRoot.children.forEach(function (owner) { owner._expanded = true; });
                renderSidebar();
                el.sidebarStats.textContent = (data.owners.length || 0) + ' 位共享人 · ' + (data.totalShares || 0) + ' 个共享根';
                if (state.treeRoot.children[0]) {
                    activateNode(state.treeRoot.children[0]);
                } else {
                    state.currentSelectionKey = 'root';
                    renderListing(state.treeRoot);
                    setStatus('暂无共享');
                }
            })
            .catch(function (err) {
                setStatus('加载失败');
                el.sidebarStats.textContent = '加载失败';
                renderError(err.message || '加载失败');
            });
    }

    function init() {
        el.tree = $('psi-tree');
        el.fileList = $('psi-file-list');
        el.currentTitle = $('psi-current-title');
        el.currentSubtitle = $('psi-current-subtitle');
        el.breadcrumbs = $('psi-breadcrumbs');
        el.status = $('psi-status');
        el.searchInput = $('psi-search-input');
        el.sidebarStats = $('psi-sidebar-stats');

        if (!readConfigFromDataset()) {
            renderError('未读取到接口配置。请检查应用是否已正确启用，或刷新页面重试。');
            setStatus('配置缺失');
            return;
        }

        var refreshBtn = $('psi-refresh');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', loadBootstrap);
            refreshBtn.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                    e.preventDefault();
                    loadBootstrap();
                }
            });
        }

        if (el.searchInput) {
            el.searchInput.addEventListener('input', function () {
                var value = this.value;
                clearTimeout(state.searchTimer);
                state.searchTimer = setTimeout(function () { runSearch(value); }, 250);
            });
            el.searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    this.value = '';
                    runSearch('');
                }
            });
        }

        loadBootstrap();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
