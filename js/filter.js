/**
 * 公开共享浏览器前端入口（v0.6.1）。
 *
 * 职责：
 *   ① 顶层目录索引浏览：以共享人为顶级目录、以真实路径作为子级
 *   ② 全局搜索：搜索所有共享根**内**的全部文件 / 目录
 *   ③ 显示所有文件（开关）：开启后允许在本应用内进入共享根 / 共享内部目录，
 *      每次只**按层加载**当前目录的直接子项（调用 listChildren，不再递归 BFS）：
 *      a. listChildren 返回的 type=folder 子项会被增量挂到对应 share root 节点之下，
 *         用户在侧栏可以看到完整目录层级、点击下钻；shareFolder 节点为懒加载，
 *         每个父目录第一次进入时才请求一次后端；
 *      b. 主区列表里目录类型的"名称"不再跳 NextCloud，而是触发 enter-folder
 *         在本应用内继续进入子目录；
 *      c. 文件类型仍提供「下载」（强制 attachment + 流式 + application/octet-stream）
 *         和「在 NextCloud 中打开」两种操作；
 *      d. 顶层 root 显式提示"请先选择共享人或共享根"。
 *   ④ 服务端缓存：bootstrap 默认 60s TTL；顶部"强制刷新"按钮调用 `?force=1` 强制重新扫描。
 *   ⑤ 不做任何文件内容内联展示，所有需要预览 / 查看的场景跳转回 NextCloud 原生公开分享页。
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
        // showAll：是否启用「显示所有文件」开关；持久化到 sessionStorage
        showAll: false,
        // 当前 showAll 模式下的请求生命周期 id，用于"老请求结果到达后忽略"
        showAllRequestSeq: 0,
    };
    var el = {};

    function downloadEnabled() { return String(cfg.downloadEnabled || 'yes').toLowerCase() !== 'no'; }
    function formatBytes(size) {
        if (size === undefined || size === null || isNaN(size)) return '—';
        var n = Number(size);
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        if (n < 1024 * 1024 * 1024) return (n / (1024 * 1024)).toFixed(1) + ' MB';
        return (n / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
    }
    function buildDownloadUrl(item) {
        if (!cfg.downloadUrl) return '';
        var u = new URL(cfg.downloadUrl, window.location.origin);
        u.searchParams.set('shareId', item.shareId);
        if (item.relativePath) u.searchParams.set('path', item.relativePath);
        return u.toString();
    }

    function setStatus(message) { if (el.status) el.status.textContent = message; }

    function renderError(message) {
        if (el.fileList) {
            el.fileList.innerHTML = '<tr><td colspan="5" class="psi-empty psi-empty-error">' + escapeHtml(message) + '</td></tr>';
        }
    }

    /** ---------------- 树构建 ---------------- */

    /**
     * 树节点结构：
     *   - kind === 'static'：buildTree 阶段构造的节点（owner / 路径中间段 / share root 叶子）
     *   - kind === 'shareFolder'：进入 showAll 模式后由 mergeShareFolderTree 增量挂到 share root
     *     之下的"share 内部目录"节点；带 shareIdRef + shareFolderRelativePath
     */
    function createTreeNode(id, label, type, extra) {
        return Object.assign({
            id: id, label: label, type: type,
            kind: 'static',
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

    /** 节点是否属于"目录类型"——决定是否显示 caret 与文件夹图标。 */
    function isFolderLike(node) {
        return node && (node.type === 'folder' || node.type === 'owner' || node.kind === 'shareFolder');
    }

    /**
     * 节点是否还可能有未加载的子节点（用于决定 caret 是否显示成"可展开"）：
     *   - shareFolder 节点：第一次点开前 children 是空的，但实际可能有子目录；
     *     由 _childrenLoaded 标记是否已经至少加载过一次（loadShowAllOneLevel 会设此标记）
     *   - 其他静态节点：以 children.length 判断即可
     */
    function maybeHasChildren(node) {
        if (!isFolderLike(node)) return false;
        if (node.children && node.children.length > 0) return true;
        if (node.kind === 'shareFolder' && !node._childrenLoaded) return true;
        // share root 叶子（buildTree 阶段没挂子节点）：开启 showAll 后也要让用户能下钻
        if (node.shareRoot && !node._childrenLoaded && state.showAll) return true;
        return false;
    }

    function renderTreeNode(node, depth) {
        var wrapper = document.createElement('div');
        wrapper.className = 'psi-tree-node';
        wrapper.classList.add(depth === 0 ? 'psi-tree-node-root' : 'psi-tree-node-child');
        wrapper.dataset.key = node.id;

        // 注意：这里刻意不再使用 <button>，因为 NextCloud guest 布局会给原生 button 注入默认样式。
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
        var canExpand = maybeHasChildren(node);
        if (canExpand) {
            caret.innerHTML = '<svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>';
        } else {
            caret.innerHTML = '<span class="psi-tree-caret-empty"></span>';
        }
        if (node._expanded) caret.classList.add('expanded');

        // 节点前的小图标：目录用文件夹（实心 / 描边二选一），其他用占位
        var iconSpan = document.createElement('span');
        iconSpan.className = 'psi-tree-icon';
        if (node.type === 'owner') {
            iconSpan.classList.add('psi-tree-icon-owner');
            iconSpan.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c1.2-3.4 4-5 7-5s5.8 1.6 7 5"/></svg>';
        } else if (isFolderLike(node)) {
            iconSpan.classList.add('psi-tree-icon-folder');
            iconSpan.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7.5A1.5 1.5 0 0 1 4.5 6h4.4l2 2h8.6A1.5 1.5 0 0 1 21 9.5V18a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18z"/></svg>';
        } else {
            iconSpan.classList.add('psi-tree-icon-file');
            iconSpan.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6.5A1.5 1.5 0 0 0 5 4.5v15A1.5 1.5 0 0 0 6.5 21h11A1.5 1.5 0 0 0 19 19.5V8z"/><path d="M14 3v5h5"/></svg>';
        }

        var label = document.createElement('span');
        label.className = 'psi-tree-label';
        label.textContent = node.label;

        item.append(caret, iconSpan, label);

        function trigger() {
            // 只有真有 children 时才切换展开状态；否则只激活（让 activateNode 去触发懒加载）
            if (node.children.length > 0) node._expanded = !node._expanded;
            else if (canExpand) node._expanded = true;
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
            // 用 <span> 而不是 <button>，避免 NextCloud 全局 button 样式渗透。
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

        // 「显示所有文件」开关打开时改走另一条渲染路径
        if (state.showAll) {
            renderShowAll(node);
            return;
        }

        // share root（leaf）：显示一行卡片 + 直接打开按钮
        if (node.shareRoot && node.children.length === 0) {
            renderShareRootDetail(node);
            return;
        }
        // owner / 中间虚拟目录 / root：显示子项列表
        renderChildrenAsRows(node);
    }

    function subtitleFor(node) {
        if (state.showAll) {
            if (node.type === 'root') return '请先在左侧选择一个共享人或具体共享，再开启「显示所有文件」。';
            if (node.type === 'owner') return '已开启「显示所有文件」：选择下面任一共享根进入查看；点目录可在本应用内继续下钻。';
            return '已开启「显示所有文件」：仅显示该目录下的直接子项；点目录名可在本应用内继续下钻。';
        }
        if (node.type === 'root') return '当前 NextCloud 实例上的全部公开链接共享。点击共享人 / 共享根可在右侧查看，"在 NextCloud 中打开"会跳转到原生公开分享页面。';
        if (node.type === 'owner') return '该共享人公开的全部链接共享。';
        if (node.shareRoot && node.children.length === 0) return '该共享在 NextCloud 中的原生公开分享地址，点击下方按钮跳转打开。';
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
     *   - share root（leaf）：操作 = 在 NextCloud 中打开（<a>）
     *   - owner / 中间虚拟目录：操作 = 进入（<span role="button">）
     *
     * 不使用 <button>，避免 NextCloud guest 布局给原生 button 注入默认样式。
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
            actionEl = '<a class="psi-btn psi-btn-action" target="_blank" rel="noopener noreferrer" href="' + escapeAttr(node.shareRoot.nextcloudUrl) + '">在 NextCloud 中打开 ' + externalLinkSvg + '</a>';
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
            + '  <td class="psi-col-actions"><a class="psi-btn psi-btn-action" target="_blank" rel="noopener noreferrer" href="' + escapeAttr(sr.nextcloudUrl) + '">在 NextCloud 中打开 ' + externalLinkSvg + '</a></td>'
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
                } else if (action === 'enter-folder') {
                    var sid = node.getAttribute('data-share-id');
                    var rel = node.getAttribute('data-relative-path') || '';
                    enterShareFolder(sid, rel);
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

    /** ---------------- 「显示所有文件」开关 ---------------- */

    /**
     * 在 sidebar 中按 shareId 找到该 share 对应的 share root 节点（即 buildTree
     * 时构造的 node.shareRoot.id === shareId 的那个节点）。
     * 用于 mergeShareFolderTree 时定位挂载点。
     */
    function findShareRootNodeByShareId(shareId) {
        if (!state.treeRoot || !shareId) return null;
        function walk(n) {
            if (n.shareRoot && n.shareRoot.id === shareId) return n;
            var children = n.children || [];
            for (var i = 0; i < children.length; i++) {
                var found = walk(children[i]);
                if (found) return found;
            }
            return null;
        }
        return walk(state.treeRoot);
    }

    /**
     * 在 share root 节点下"懒挂载"一条目录路径，返回最深的那个 shareFolder 节点。
     * 节点 id 形如 `<shareRootId>/sf/<seg1>/sf/<seg2>...`，不会与 buildTree 的静态节点冲突。
     */
    function ensureFolderPath(shareRootNode, relativePath, shareId) {
        var segs = (relativePath || '').split('/').filter(Boolean);
        var cur = shareRootNode;
        var acc = '';
        for (var i = 0; i < segs.length; i++) {
            var seg = segs[i];
            acc = acc ? (acc + '/' + seg) : seg;
            var key = '__sf__:' + seg.toLowerCase();
            if (!cur.childMap[key]) {
                var nodeId = cur.id + '/sf/' + encodeURIComponent(seg);
                var n = createTreeNode(nodeId, seg, 'folder', {
                    kind: 'shareFolder',
                    shareIdRef: shareId,
                    shareFolderRelativePath: acc,
                });
                n.parent = cur;
                cur.childMap[key] = n;
                cur.children.push(n);
            }
            cur = cur.childMap[key];
        }
        return cur;
    }

    /**
     * 把"某个目录的直接子目录"增量挂到 sidebar 上。仅挂 type=folder，不挂文件——
     * 文件由主区表格展示。同名节点不会重复创建。
     *
     * @param shareRootNode buildTree 阶段挂上的 share root 节点
     * @param parentRelativePath 当前正在加载的那一层的相对路径（'' 表示 share root 本身）
     * @param items listChildren 返回的直接子项（type='folder' 才会挂）
     * @param shareId 用于标记每个挂上的 shareFolder 节点的 shareIdRef
     */
    function mergeShareFolderChildren(shareRootNode, parentRelativePath, items, shareId) {
        if (!shareRootNode || !items) return;
        var parent = parentRelativePath ? ensureFolderPath(shareRootNode, parentRelativePath, shareId) : shareRootNode;
        items.forEach(function (item) {
            if (item.type !== 'folder') return;
            var seg = item.name;
            var key = '__sf__:' + seg.toLowerCase();
            if (!parent.childMap[key]) {
                var nodeId = parent.id + '/sf/' + encodeURIComponent(seg);
                var rel = item.relativePath || (parentRelativePath ? (parentRelativePath + '/' + seg) : seg);
                var n = createTreeNode(nodeId, seg, 'folder', {
                    kind: 'shareFolder',
                    shareIdRef: shareId,
                    shareFolderRelativePath: rel,
                });
                n.parent = parent;
                parent.childMap[key] = n;
                parent.children.push(n);
            }
        });
        // 标记当前层已加载，下次进入不再误判为"未知是否有子目录"
        parent._childrenLoaded = true;
        sortShareSubtree(parent);
    }

    /** 仅排当前层（一层加载策略下不能递归排子层，否则空 children 不影响但不必要遍历）。 */
    function sortShareSubtree(node) {
        if (!node || !node.children) return;
        node.children.sort(function (a, b) {
            if (a.type !== b.type) {
                return (a.type === 'folder' || a.type === 'owner') ? -1 : 1;
            }
            return a.label.localeCompare(b.label, 'zh-Hans-CN');
        });
    }

    /**
     * 收集某个树节点下挂着的全部 share roots（递归）。
     * - share root 节点本身：返回 [shareRoot]
     * - owner / 中间虚拟目录：递归子树聚合
     * 注意：这里只看 buildTree 时挂上的 shareRoot，不会返回 shareFolder 类型节点。
     */
    function collectShareRootsFromNode(node) {
        var roots = [];
        var seen = {};
        function walk(n) {
            if (!n) return;
            if (n.shareRoot && !seen[n.shareRoot.id]) {
                roots.push(n.shareRoot);
                seen[n.shareRoot.id] = true;
            }
            (n.children || []).forEach(walk);
        }
        walk(node);
        return roots;
    }

    /**
     * showAll 模式下选中节点 → 显示该节点直接子项（一层）。
     *
     * 三种入口：
     *   - shareFolder：已经被挂到 sidebar 的 share 内部目录
     *   - share root：buildTree 阶段挂的叶子节点
     *   - owner / 中间虚拟目录：不调后端，直接复用 renderChildrenAsRows
     *     展示其下的 share roots 列表（这是 share root 的封面卡片）
     */
    function renderShowAll(node) {
        if (!node || !cfg.childrenUrl) {
            renderError('未配置 children 接口');
            return;
        }
        if (node.type === 'root') {
            el.fileList.innerHTML = '<tr><td colspan="5" class="psi-empty">请先在左侧选择共享人或具体共享，再开启「显示所有文件」</td></tr>';
            setStatus('待选择');
            return;
        }

        // skeleton 占位
        el.fileList.innerHTML = ''
            + '<tr><td colspan="5"><div class="psi-skeleton"></div></td></tr>'
            + '<tr><td colspan="5"><div class="psi-skeleton"></div></td></tr>'
            + '<tr><td colspan="5"><div class="psi-skeleton"></div></td></tr>';
        setStatus('加载中…');

        var seq = ++state.showAllRequestSeq;

        if (node.kind === 'shareFolder') {
            return loadShowAllOneLevel(node.shareIdRef, node.shareFolderRelativePath || '', node, seq);
        }
        if (node.shareRoot) {
            return loadShowAllOneLevel(node.shareRoot.id, '', node, seq);
        }
        // owner / 中间虚拟目录：showAll 不改变其语义，沿用普通子节点列表（=共享根列表）。
        // 注意 seq 已经自增，旧的 fetch 回调如果回来会因为 seq 不匹配被丢弃，无需回滚。
        renderChildrenAsRows(node);
    }

    /**
     * 拉一层（listChildren）：返回当前目录的直接子项。
     * 同时把当前层的 type=folder 子目录挂到 sidebar 上对应位置（按需懒加载）。
     */
    function loadShowAllOneLevel(shareId, relativePath, node, seq) {
        fetchJson(cfg.childrenUrl, { shareId: shareId, path: relativePath })
            .then(function (data) {
                if (seq !== state.showAllRequestSeq) return;
                var sr = findShareRootNodeByShareId(shareId);
                var srMeta = (sr && sr.shareRoot) || (data && data.share) || null;

                if (sr && data && Array.isArray(data.items)) {
                    mergeShareFolderChildren(sr, relativePath, data.items, shareId);
                    // 当前节点本身（无论是 share root 还是某个 shareFolder）也已经"知道自己有没有子"
                    if (node) node._childrenLoaded = true;
                    renderSidebar();
                }
                var enriched = (data && data.items ? data.items : []).map(function (it) {
                    it._shareRootMeta = srMeta;
                    return it;
                });
                renderShowAllRows(node, srMeta ? [srMeta] : [], enriched);
            })
            .catch(function (err) {
                if (seq !== state.showAllRequestSeq) return;
                renderError(err && err.message ? err.message : '加载失败');
            });
    }

    function renderShowAllRows(node, shareRoots, items) {
        setStatus('已显示 ' + items.length + ' 个条目');

        if (!items.length) {
            el.fileList.innerHTML = '<tr><td colspan="5" class="psi-empty">该目录下没有任何文件 / 目录</td></tr>';
            return;
        }
        el.fileList.innerHTML = items.map(showAllRowFor).join('');
        bindRowActions();
    }

    /**
     * showAll 模式的单行：
     *   - 目录类型：名称用 <span role="button" data-action="enter-folder"> 在本应用内继续下钻
     *   - 文件类型：名称用 <a href="nextcloudUrl" target="_blank">；下载按钮直接下载
     */
    function showAllRowFor(item) {
        var sr = item._shareRootMeta || {};
        var owner = sr.ownerName || sr.ownerId || '—';
        var locationParts = [];
        locationParts.push(owner);
        if (sr.segments && sr.segments.length) locationParts = locationParts.concat(sr.segments);
        if (item.relativePath) locationParts = locationParts.concat(item.relativePath.split('/'));
        if (locationParts.length && locationParts[locationParts.length - 1] === item.name) {
            locationParts = locationParts.slice(0, -1);
        }
        var locationLine = locationParts.filter(Boolean).join(' / ');
        var pathLine = locationLine
            ? '<div class="psi-result-path" title="' + escapeAttr(locationLine) + '">' + escapeHtml(locationLine) + '</div>'
            : '';

        var nameCell;
        if (item.type === 'folder') {
            nameCell = '<span class="psi-linklike" role="button" tabindex="0"'
                + ' data-action="enter-folder"'
                + ' data-share-id="' + escapeAttr(item.shareId) + '"'
                + ' data-relative-path="' + escapeAttr(item.relativePath || '') + '">'
                + iconHtml('folder') + '<span>' + escapeHtml(item.name) + '</span></span>';
        } else {
            nameCell = '<a class="psi-linklike" target="_blank" rel="noopener noreferrer" href="' + escapeAttr(item.nextcloudUrl) + '">'
                + iconHtml(item.type, item.mimeType) + '<span>' + escapeHtml(item.name) + '</span></a>';
        }

        var typeText = typeLabel(item.type);
        if (item.type === 'file' && item.size !== undefined && item.size !== null) {
            typeText += ' · ' + formatBytes(item.size);
        }

        var actions = '';
        if (item.type === 'file' && downloadEnabled() && !sr.hideDownload) {
            actions += '<a class="psi-btn psi-btn-action psi-btn-download" rel="noopener noreferrer" href="' + escapeAttr(buildDownloadUrl(item)) + '" download="' + escapeAttr(item.name) + '" title="直接下载该文件">下载</a>';
        }
        actions += '<a class="psi-btn psi-btn-action" target="_blank" rel="noopener noreferrer" href="' + escapeAttr(item.nextcloudUrl) + '">在 NextCloud 中打开 ' + externalLinkSvg + '</a>';

        return '<tr>'
            + '<td>' + nameCell + pathLine + '</td>'
            + '<td class="psi-cell-meta">' + escapeHtml(owner) + '</td>'
            + '<td>' + escapeHtml(typeText) + '</td>'
            + '<td>' + formatTime(item.mtime) + '</td>'
            + '<td class="psi-col-actions"><div class="psi-action-group">' + actions + '</div></td>'
            + '</tr>';
    }

    /**
     * 主区点击目录：在 sidebar 上挂载并展开父链，然后激活该目录节点（renderListing 会
     * 走 renderShowAll → loadShowAllSingle 重新拉数据）。
     */
    function enterShareFolder(shareId, relativePath) {
        if (!shareId) return;
        var sr = findShareRootNodeByShareId(shareId);
        if (!sr) return;
        var folderNode = relativePath ? ensureFolderPath(sr, relativePath, shareId) : sr;
        // 沿父链展开，让用户在 sidebar 看到自己当前位置
        var cur = folderNode;
        while (cur && cur.type !== 'root') {
            cur._expanded = true;
            cur = cur.parent;
        }
        activateNode(folderNode);
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
        el.currentSubtitle.textContent = '共 ' + data.total + ' 条结果' + (data.limit && data.total >= data.limit ? '（已截断到上限 ' + data.limit + '）' : '') + '。点击结果会在新标签打开 NextCloud 原生公开分享页面。';
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
                + '<td class="psi-col-actions"><a class="psi-btn psi-btn-action" target="_blank" rel="noopener noreferrer" href="' + escapeAttr(item.nextcloudUrl) + '">在 NextCloud 中打开 ' + externalLinkSvg + '</a></td>'
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
            listAllUrl: ds.listAllUrl || '',
            downloadUrl: ds.downloadUrl || '',
            downloadEnabled: ds.downloadEnabled || 'yes',
        };
        return Boolean(cfg.bootstrapUrl && cfg.searchUrl);
    }

    /**
     * 加载 bootstrap 索引。`forceRefresh` 会向后端发 ?force=1 绕过 TTL 缓存重新扫描。
     * 加载后会重建整棵 treeRoot（清掉 showAll 时挂上的 shareFolder 子节点）。
     */
    function loadBootstrap(forceRefresh) {
        setStatus(forceRefresh ? '强制重新扫描中…' : '加载共享索引中…');
        if (el.fileList) {
            el.fileList.innerHTML = ''
                + '<tr><td colspan="5"><div class="psi-skeleton"></div></td></tr>'
                + '<tr><td colspan="5"><div class="psi-skeleton"></div></td></tr>'
                + '<tr><td colspan="5"><div class="psi-skeleton"></div></td></tr>';
        }
        var params = forceRefresh ? { force: 1 } : null;
        return fetchJson(cfg.bootstrapUrl, params)
            .then(function (data) {
                state.bootstrap = data;
                state.treeRoot = buildTree(data);
                state.treeRoot.children.forEach(function (owner) { owner._expanded = true; });
                renderSidebar();
                updateSidebarStats(data);
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

    /**
     * 把"上次扫描时间 + 是否来自缓存"显示在侧栏底部，
     * 让用户对"为什么数据可能不是实时的"有明确预期。
     */
    function updateSidebarStats(data) {
        if (!el.sidebarStats) return;
        if (!data) {
            el.sidebarStats.textContent = '加载失败';
            return;
        }
        var basics = (data.owners ? data.owners.length : 0) + ' 位共享人 · '
            + (data.totalShares || 0) + ' 个共享根';
        var time = data.generatedAt ? formatTime(data.generatedAt) : '';
        var freshness = data.fromCache ? '来自缓存' : '已实时扫描';
        var detail = (time ? freshness + ' · ' + time : freshness);
        el.sidebarStats.innerHTML = escapeHtml(basics) + '<br><span class="psi-sidebar-stats-meta">'
            + escapeHtml(detail) + '</span>';
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
            // 刷新按钮永远走 force=1，强制后端绕过 TTL 缓存重新扫描整个实例
            var triggerRefresh = function () { loadBootstrap(true); };
            refreshBtn.addEventListener('click', triggerRefresh);
            refreshBtn.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                    e.preventDefault();
                    triggerRefresh();
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

        // 「显示所有文件」开关：从 sessionStorage 恢复，与搜索互斥（搜索时不影响开关，但搜索结果走自己的视图）
        var showAllCb = $('psi-show-all');
        if (showAllCb) {
            try {
                state.showAll = sessionStorage.getItem('psi.showAll') === '1';
            } catch (e) { /* 隐私模式下忽略 */ }
            showAllCb.checked = state.showAll;
            showAllCb.addEventListener('change', function () {
                state.showAll = this.checked;
                try { sessionStorage.setItem('psi.showAll', state.showAll ? '1' : '0'); } catch (e) {}
                state.showAllRequestSeq++;

                // 关闭开关时：重建 treeRoot 把之前挂上的 shareFolder 子节点清掉，
                // 让 sidebar 回到 buildTree 的原始结构。
                if (!state.showAll && state.bootstrap) {
                    var prevSelectedShareRootId = null;
                    var prevSelectedNode = state.currentSelectionKey ? findNodeById(state.treeRoot, state.currentSelectionKey) : null;
                    // 选中的是 shareFolder 时，向上找最近的 share root，关闭后选中它
                    var hint = prevSelectedNode;
                    while (hint && hint.kind === 'shareFolder') hint = hint.parent;
                    if (hint && hint.shareRoot) prevSelectedShareRootId = hint.shareRoot.id;

                    state.treeRoot = buildTree(state.bootstrap);
                    state.treeRoot.children.forEach(function (o) { o._expanded = true; });

                    // 重新激活原来的 share root（如果还存在）；找不到就回落到第一个 owner
                    var newTarget = null;
                    if (prevSelectedShareRootId) {
                        newTarget = findShareRootNodeByShareId(prevSelectedShareRootId);
                    }
                    if (!newTarget) newTarget = state.treeRoot.children[0] || state.treeRoot;
                    activateNode(newTarget);
                    return;
                }

                if (state.searchActive) {
                    // 搜索激活时不直接覆盖搜索结果视图，等用户清空搜索后再生效
                    return;
                }
                var node = state.currentSelectionKey ? findNodeById(state.treeRoot, state.currentSelectionKey) : state.treeRoot;
                if (node) renderListing(node);
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
