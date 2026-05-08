# 变更日志

本应用遵循 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/) 风格，并采用 [Semantic Versioning](https://semver.org/lang/zh-CN/) 语义化版本号。

## [0.6.1] - 2026-05-08

把"显示所有文件"从一次性递归改为**按层加载**（更接近文件管理器），同时给侧栏节点补全图标与 caret，让"目录可下钻"这件事在视觉上一目了然。统一品牌拼写 NextCloud。

### 行为变更

- **showAll 不再递归**：开启开关后选中一个共享根 / 内部目录，主区**只显示该目录的直接子项**（调 `GET /api/children`）；要继续往下看就点目录名下钻。原先一次性 BFS 整棵子树的做法对深目录会"刷屏 + 卡顿"，现在每次响应轻量得多。
- **侧栏懒加载**：listChildren 返回的 `type=folder` 子项会被增量挂到对应 share root 节点之下；进入更深一层时再请求一次后端、挂下一层。同名节点不会重复挂，已加载层会被标记 `_childrenLoaded`，再次进入直接展开本地结构、不会重复请求。
- 取消：原 v0.6.0 的"share root 一次性 BFS 全树构造侧栏"逻辑已删除。`/api/list-all` 接口本身仍保留在后端，未来需要"一键全展开"再启用。

### 新增

- **侧栏节点图标**：每个节点前有一个小图标——共享人是用户头像、目录是文件夹、文件是文档；激活态图标颜色与 accent 同步。配合标签 + caret 三件套，用户能立刻看出这是"什么 + 能不能展开"。
- **caret 适配懒加载**：所有"目录类型"节点（owner / folder / shareFolder / share root）都会显示 caret，即使当前 `children.length === 0`。shareFolder 节点在第一次进入前都被视为"可能有子目录"；进入后根据真实子目录数自动调整。

### 视觉调整

- **操作列两个按钮强制并排**：`.psi-action-group { flex-wrap: nowrap }` + `.psi-col-actions { white-space: nowrap; min-width: 220px }`，避免之前"下载"和"在 NextCloud 中打开"在中等宽度下被换行。
- **品牌拼写统一为 `NextCloud`**：所有用户可见文本（按钮、提示、副标题、配置说明、文档）统一为 `NextCloud`。技术接口字段名 `nextcloudUrl` 与 PHP 类引用保持原状（兼容性），不做 breaking 改动。

### 关于"原生预览能力"的结论

调研了 NC 32 的官方 API：

- `OCP\IPreview` 能给图片 / 视频首帧 / PDF 首页生成**缩略图**，自定义 app 能调；
- 但 Office 文档（docx/xlsx/pptx）预览由 Collabora / OnlyOffice 提供，视频播放 / PDF 多页阅读由 NC 自带的 Viewer app 提供——这两类**不是 OCP 公开 API**，没法在第三方 app 内复用；
- 所以无法做出和"NC 原生公开分享页"等价的多类型预览。

结论：本版本**继续保持"预览 / 查看"统一跳转 NC 原生页面**的方案。如果未来确有需要做"图片 lightbox 速览"，再走 `IPreview` 单独加一个轻量层。

### 升级

无破坏性变更，直接覆盖即可：

```bash
sudo rm -rf /var/www/html/custom_apps/publicshareindex
sudo cp -a publicshareindex /var/www/html/custom_apps/publicshareindex
sudo chown -R www-data:www-data /var/www/html/custom_apps/publicshareindex

sudo -u www-data php /var/www/html/occ app:disable publicshareindex
sudo -u www-data php /var/www/html/occ app:enable  publicshareindex
```

浏览器 Ctrl+F5 强刷，即可看到新的侧栏图标 + 一层加载行为。

---

## [0.6.0] - 2026-05-08

把「显示所有文件」从一次性平铺升级为**完整的内部文件管理器**：sidebar 能下钻、主区目录可点进去、服务端 TTL 缓存 + 强制刷新；同时修复 checkbox 被 NC 全局样式污染的问题。

### 新增

- **侧栏深层目录树**：开启「显示所有文件」后，`listAll` 返回的 items 中的目录会被增量挂到对应 share root 节点之下（`kind: 'shareFolder'`），用户在侧栏就能看到 share 内部完整层级、点击下钻；关闭开关时重建树清除这些动态节点。
- **主区目录可在本应用内进入**：showAll 模式下目录类型的"名称"改为 `<span role="button" data-action="enter-folder">`，点击直接在当前页面下钻（找/挂 sidebar 节点 + 展开父链 + 重新拉 listAll）；文件类型名称仍指向 NC 原生页面。
- **服务端 TTL 缓存**：`PublicShareIndexService` 用 `ICacheFactory.createDistributed` 给 bootstrap 索引加 60 秒缓存（可配 `bootstrap_cache_ttl`），所有匿名访客共用一份，避免每次访问都对全实例做扫描。响应里增加 `fromCache` 字段。
- **强制刷新按钮**：原"刷新"按钮改为"强制刷新"，调用 `GET /api/bootstrap?force=1` 绕过缓存重新扫描；侧栏底部显示「来自缓存 / 已实时扫描 + 上次扫描时间」。

### 修复

- **checkbox 样式被 Nextcloud guest 布局覆盖**：`appearance: none` 在 NC 32 的 guest 主题中无法完全消除原生 input 注入的样式（截图里出现一个蓝色实心方块）。本版本**完全隐藏** `<input type="checkbox">`（绝对定位 + opacity:0 + clip），用 `<span class="psi-toggle-box">` 自行绘制方框与对勾，状态通过相邻兄弟选择器同步。

### 默认值调整

| 配置项 | v0.5.0 默认 | v0.6.0 默认 | 备注 |
| --- | --- | --- | --- |
| `list_all_max_visited` | 5000 | **50000** | 内网部署，扫的是元信息不是文件内容，可以放宽 |
| `list_all_max_results` | 2000 | **20000** | 同上 |
| `list_all_max_results` 硬上限 | 10000 | **50000** | 兜底响应体大小 |

如果你之前手动调到了更高，本版本不会覆盖你的设置。

### 配置项新增

| Key | 默认 | 说明 |
| --- | --- | --- |
| `bootstrap_cache_ttl` | `60` | bootstrap 索引服务端缓存 TTL（秒）。`0` 表示禁用缓存（每次都扫）；上限 86400（24 小时） |

### 模板 / API 变更

- `GET /api/bootstrap` 接受可选查询参数 `force=1`，强制绕过缓存
- 响应新增字段 `fromCache: bool`
- `Service::getBootstrapIndex()` 签名变为 `getBootstrapIndex(bool $forceRefresh = false)`，构造方法新增 `ICacheFactory` 注入
- `Service::invalidateBootstrapCache()` 公开方法，便于外部主动清缓存

### 升级

直接覆盖目录、`occ app:disable && occ app:enable`，浏览器 Ctrl+F5 强刷即可。原有配置不会被覆盖；新增的 `bootstrap_cache_ttl` 不设置即使用 60 秒默认。

如果你想把缓存关掉（每次都实时扫）：

```bash
sudo -u www-data php /var/www/html/occ config:app:set publicshareindex bootstrap_cache_ttl --value="0"
```

---

## [0.5.0] - 2026-05-08

新增「显示所有文件」开关，并恢复了**受控的、流式的**直接下载能力。

### 新增

- **「显示所有文件」开关**：搜索框下方新增 checkbox（默认关闭，状态写入 sessionStorage）。开启后在当前选中节点下做递归平铺展示：
  - 选中**共享根**：递归列出该共享内的全部文件 / 目录
  - 选中**共享人**：串行加载该共享人下的每一个共享根，合并到一张表
  - 选中**顶层「公开共享」**：UI 显式提示"请先选择共享人或具体共享"——禁止全实例展开，防止 OOM
  - 每一行都附带共享人 / 共享段 / 相对路径作为灰色子行，以及文件大小（位于"类型"列）
- **直接下载能力**：单文件提供「下载」按钮（与「在 Nextcloud 中打开」并列）：
  - 后端走新加的 `GET /api/download` 端点，返回 `application/octet-stream` + `Content-Disposition: attachment`
  - 流式传输（默认 8 KB chunk），任意大小文件都不会把内容读进内存
  - 文件名按 RFC 5987 编码，兼容中文 / 空格 / 特殊字符
  - 受 `download_enabled` 全局开关控制；任何 `hideDownload=true` 的共享自动屏蔽下载按钮，并在服务端二次拒绝
  - 文件名 / `Content-Disposition` 头永远是 attachment：杜绝 HTML / SVG 在浏览器内联执行的 XSS 路径

### 安全

- **路径越界双重校验**：所有按 `(shareId, relativePath)` 调用的入口（`listAll` / `download`）都会先 `findShareById`（含 `isShareVisible`），再 `PathGuard.normalizeRelative` + `PathGuard.assertWithinRoot`，最终用 NC 自身的 `Folder->get()` 解析；任意层校验失败都立即抛出，不会读到共享根之外的文件。
- **OOM 防护**：
  - `listAll` 设置最大遍历节点数（`list_all_max_visited`，默认 5000）和最大结果数（`list_all_max_results`，默认 2000），超出立即截断并在响应里标记 `truncated: true`，前端显示提示
  - 跨多 share 加载时**串行**而非并行，遇到截断立即停止后续 share 的请求
  - `download` 走 chunked 输出，并在传输前后清空 PHP 输出缓冲，单次内存峰值≈chunk 大小
- **下载头部加固**：`Cache-Control: no-store` / `Pragma: no-cache` / `X-Content-Type-Options: nosniff` / `X-Frame-Options: DENY` / `Referrer-Policy: no-referrer`
- **新增匿名速率限制**：`/api/list-all` 30 次/分钟、`/api/download` 60 次/分钟

### 配置项

| Key | 默认 | 说明 |
| --- | --- | --- |
| `list_all_max_visited` | `5000` | 单次「显示所有文件」展开时最多访问的节点数 |
| `list_all_max_results` | `2000` | 单次「显示所有文件」展开返回的最多条目数 |
| `download_enabled` | `yes` | 是否启用本应用的直接下载功能。设为 `no` 后端拒绝并隐藏前端按钮 |
| `download_chunk_bytes` | `8192` | 流式下载每次写出的字节数（范围 1024–1048576） |

### 模板 / API 变更

- `templates/index.php` 新增 `data-list-all-url` / `data-download-url` / `data-download-enabled` 三个属性
- `appinfo/routes.php` 新增 `page#listAll`（`GET /api/list-all`）、`page#download`（`GET /api/download`）
- `lib/Http/StreamingFileResponse.php` 重新引入（v0.4 已删，v0.5 重新启用，仅供下载使用）

### 升级

直接覆盖目录、`occ app:disable && occ app:enable`，浏览器 Ctrl+F5 强刷即可。`oc_appconfig` 中的旧配置项不会被破坏；新配置项不设置即使用默认值。

---

## [0.4.2] - 2026-05-08

聚焦在**与 Nextcloud guest 全局样式的隔离**，以及**目录树层级感**两项体验问题。

### 视觉与交互

- **彻底放弃使用 `<button>`**：由于 Nextcloud 31/32 的 guest 布局会给原生 `<button>` 注入默认 background / border / min-height 等样式，覆盖容易出现意料外效果。本版本把所有交互元素改为：
  - 树节点 / 面包屑可点击项 / 列表"进入" / 顶栏"刷新" → `<div role="button" tabindex="0">` 或 `<span role="button" tabindex="0">`
  - 列表"在 Nextcloud 中打开"、表格内文件链接 → `<a target="_blank" rel="noopener noreferrer">`
  - 全部统一加键盘 Enter / Space 触发，焦点环用 `:focus-visible` 自定义，不会再继承 NC 全局 button 样式。
- **目录树凸显层级结构**：之前层级仅靠左 padding 增加，看不到分支线。新版本改为：
  - 取消动态 `padding-left = depth * 14`，改用子容器 `.psi-tree-children` 自带 `margin-left + padding-left + 1px dashed border-left` 形成层级分支线（类似 VS Code / Notion 的目录树）。
  - 一级 owner 节点字号 13.5px / 字重 600 / 颜色更深，与子级 13px / 字重 400 / 灰字形成对比。
  - active 状态左侧 2px 蓝色竖线在子级时正好压在 dashed 分支线上，互不干扰。
- **防御性 CSS reset**：对 `.psi-tree-item / .psi-crumb / .psi-btn / .psi-linklike / .psi-search-box input` 显式声明 `appearance / -webkit-appearance / box-shadow / margin / line-height / user-select / text-decoration`，作为最后一层保险，进一步阻断 NC 全局样式渗透。

### 升级

直接覆盖目录、`occ app:disable` + `occ app:enable` 一次即可，浏览器 Ctrl+F5 刷新缓存。

---

## [0.4.1] - 2026-05-08

围绕 v0.4 的两点小修补，没有破坏性变更。

### 修复

- **跳转 URL 参数从 `?path=` 改为 `?dir=`**：Nextcloud 原生公开分享页面浏览子目录使用的查询参数实际上是 `dir`。修正前在某些 NC 版本上点击会落到根目录而不是预期子目录。
  - 文件夹：`/s/<token>?dir=/<相对路径>`
  - 文件：跳到所在父目录 `/s/<token>?dir=/<父目录>`；若文件直接在 share 根下则直接 `/s/<token>`
  - 同时移除掉 `&scrollto=<filename>` 参数（NC 公开页并不识别它）。

### 体验

- **搜索结果的灰色路径行同时显示共享人**：之前路径行只有 `segments / relativePath`，现在前置一段共享人名（如 `张三 / 项目文档 / 2024 / 周报.docx`），让用户一眼看清这是谁的文件。同时给路径行加 `title` 属性，鼠标悬停可看完整内容。

### 升级

直接覆盖目录、`occ app:disable` + `occ app:enable` 一次即可，浏览器 Ctrl+F5 刷新缓存。

---

## [0.4.0] - 2026-05-08

一次以**职责简化**与**视觉再打磨**为核心的升级：把"在我们页面里预览 / 下载文件"完全移除，改为统一跳转回 Nextcloud 原生公开分享页面；UI 进一步克制和现代化。

### 职责变更（Breaking）

- **移除内联预览 / 下载能力**：删除 `GET /file` 路由、`PageController::stream`、`Service::getFileForStreaming`、`OCA\PublicShareIndex\Http\StreamingFileResponse`、`OCA\PublicShareIndex\Security\PreviewPolicy`。
- **所有"打开"操作改为跳转 Nextcloud 原生公开分享页面**：
  - 文件夹节点 → `/index.php/s/<token>?path=/<相对路径>`
  - 文件节点 → 跳到所在父目录，并附带 `&scrollto=<文件名>`（NC 不识别该参数也无副作用）
  - 单文件分享 → `/index.php/s/<token>`
- **API 字段变化**：`buildNodeDescriptor` 输出从 `openUrl` 重命名为 `nextcloudUrl`，含义为"指向 Nextcloud 原生公开分享页面的 URL"。
- 模板 `data-stream-url` 属性移除，前端 cfg 不再读取。

### 视觉与交互

- **目录树彻底去除背景色**：选中状态改为左侧 2px accent 竖线 + 文字色变化 + 字重 600，hover 仅文字色微调，不再有任何块状高亮。
- **主面板去除外卡片**：表格直接铺满主区，仅靠一根细线与顶栏分隔，密度更高、更克制。
- **整体布局从三栏改为两栏**：右侧预览面板移除（不再需要）。
- **配色重新校准**：`--psi-bg: #ffffff; --psi-bg-soft: #fafafb; --psi-border: #ececef; --psi-text: #18181b; --psi-accent: #2563eb;`。
- **字号 / 间距重排**：标题 22px、正文 13px、表头 12px；侧栏树行高 28px、表格行 padding 12/16；按钮高 30/28px。
- **图标统一**：新增 `共享人` 图标，文件按 mime 着色。
- **响应式**：≤900px 隐藏"所属共享人"列；≤720px 切换上下两段、隐藏"修改时间 / 类型"列。

### 新增 / 增强

- **跳转链接由后端生成**：`Service::buildNextcloudUrl` 集中处理 token + path 拼接，避免前端字符串拼接出错或被滥用。
- **搜索结果保留全路径展示**：每行 name 下方有一行 `所有者 / segments / 相对路径` 的小灰文字，点击即跳 NC。
- **键盘体验**：搜索框 `Esc` 一键清空并恢复列表；`Tab` 顺序合理。
- **错误兜底**：接口缺失或返回非 JSON 时直接显示中文友好提示，不会再出现"Unexpected token '<'"。

### 升级步骤

```bash
sudo cp -a /var/www/html/custom_apps/publicshareindex \
          /var/www/html/custom_apps/publicshareindex.bak.$(date +%Y%m%d)
sudo rm -rf /var/www/html/custom_apps/publicshareindex
sudo cp -a publicshareindex /var/www/html/custom_apps/publicshareindex
sudo chown -R www-data:www-data /var/www/html/custom_apps/publicshareindex
sudo -u www-data php /var/www/html/occ app:disable publicshareindex
sudo -u www-data php /var/www/html/occ app:enable  publicshareindex
```

> 没有数据库迁移；回滚把备份目录换回来即可。

---

## [0.3.0] - 2026-05-08

一次以**安全、稳定、视觉**为核心的重大升级，强烈建议从 v0.2 升级到本版本。

### 关键修复（Bug Fixes）

- **修复 `GET /undefined` 404 错误**：原因是 v0.2 通过模板内联 `<script>` 注入接口 URL，被 Nextcloud 32 guest 布局的严格 CSP 拦截，导致 `cfg` 全部为 `undefined`。v0.3 起接口地址改用根元素 `data-*` 属性传递，彻底回避 CSP 与内联脚本冲突。
- **修复整页垂直滚动条**：根元素改为 `position: fixed; inset: 0; height: 100%; overflow: hidden;`，所有滚动局限在子面板内部。
- **修复 Nextcloud 顶部大 Logo 占用页面空间**：根元素 fixed 全屏覆盖整个视口，直接遮挡 guest 布局自带的 logo 与 footer，无需修改任何核心模板。
- **修复列表项点击预览时 mime 缺失**：表格行的链接按钮和操作按钮上一并写入 `data-name` / `data-mime`，预览面板可以正确选择渲染方式。

### 安全加固（Security）

- **流式下载**：新增 `OCA\PublicShareIndex\Http\StreamingFileResponse`，按 64KB 分块输出，避免大文件 `getContent()` 整载入内存导致的 OOM 与 DoS。
- **路径越界硬校验**：新增 `OCA\PublicShareIndex\Security\PathGuard`，对相对路径规范化（拒绝 `..` / 反斜杠 / 空段），并对每个解析后的节点强制 `str_starts_with(rootPath + '/')` 校验。
- **预览策略集中化**：新增 `OCA\PublicShareIndex\Security\PreviewPolicy`，把 MIME 分为三档：
  - **Safe Inline**：image (非 SVG)、audio、video、PDF、text/plain、text/markdown、CSV、JSON。
  - **Sandbox Inline**：HTML / XHTML / SVG / XML / RSS / Atom，使用 `<iframe sandbox>` + 后端 `Content-Security-Policy: default-src 'none'; ... ; sandbox` 双重隔离。
  - **Force Download**：text/javascript、application/javascript、ECMAScript、WASM、`.sh`、`.exe`、`.php`，强制 `Content-Disposition: attachment` 并改写为 `application/octet-stream`。
- **公共安全响应头**：所有流式响应携带 `X-Content-Type-Options: nosniff`、`Referrer-Policy: no-referrer`、`X-Frame-Options: SAMEORIGIN`、`X-Robots-Tag: noindex, nofollow, noarchive`。
- **匿名限速**：四个 API 端点全部添加 `#[AnonRateLimit]`：`bootstrap` 30/min、`children` 120/min、`search` 30/min、`stream` 240/min。
- **错误信息脱敏**：异常细节只记录到 `nextcloud.log`，对外仅返回中文用户友好文案，避免堆栈/路径泄露。
- **跳过禁用用户的共享**：被管理员禁用的用户的共享不再出现在索引中。
- **`Content-Disposition` 兼容非 ASCII 文件名**：同时输出 `filename=` 与 `filename*=UTF-8''` 两种语法，保证中文文件名在主流浏览器都能正确显示。

### 重构 / 性能（Refactor / Perf）

- `findShareById` 改用 `IManager::getShareById($id)` 直查后再做可见性过滤，从原来 O(N) 线性扫描全表降到 O(1) 直查。
- `PublicShareIndexService` 新增请求级缓存：单次请求内 “可见公开共享列表” 与 “用户启用状态” 各只查询一次。
- `search_max_results` 新配置项（默认 200，硬上限 500），与 `search_max_visited` 配合保护服务端。
- 业务代码拆分为 `Service` / `Security` / `Http` 三层目录，单一职责，避免单文件超大。

### 视觉与交互（UI / UX）

- 全新扁平浅色现代主题（参考 Linear / Vercel）：白底、细灰边、克制阴影，去掉所有渐变与毛玻璃。
- 顶部和侧栏导航留白与字号统一在 4 / 8 / 12 / 16 / 24 网格。
- 表格行 hover 高亮、目录树键盘可达（`role="tree"`、`role="treeitem"`、`aria-expanded`、`aria-current`）。
- 加载中显示骨架占位条，替代之前的“正在加载…”空白。
- 文件类型图标统一改为 SVG（图片绿、视频橙、音频紫、PDF 红、文本青、目录蓝），不依赖系统 emoji。
- 响应式：≤1080px 自动隐藏右侧预览栏；≤720px 切换为上下两段布局，并隐藏“修改时间”列。

### 文档

- 重写 `README.md`：覆盖版本兼容、目录结构、Docker / docker-compose 部署、v0.2 → v0.3 升级与回滚、所有配置项详解、安全建议、性能调优、8 类常见故障的排查路径、卸载流程。
- 新增本变更日志 `CHANGELOG.md`。

### 破坏性变更（Breaking Changes）

- 模板根元素 ID 仍为 `publicshareindex-app`，但 **类列表 / 子结构 / 数据属性已变化**；如果你之前自定义了 CSS 选择器覆盖样式，请重新核对。
- HTML / SVG / XML / XHTML 一律走 sandbox iframe + CSP sandbox，**不能再执行 SVG 内嵌脚本或访问外联资源**。
- JavaScript / WASM / `.sh` / `.exe` 等可执行类型 **不再允许内联预览**，强制下载且改写为 `application/octet-stream`。这是不可关闭的安全策略。
- 错误响应文案改为中文友好版本，**不再回显内部异常细节**；自动化脚本若依赖原英文文案需改为按 HTTP 状态码判断。
- 各 API 端点引入匿名限速，超过阈值会返回 429。

### 升级步骤

```bash
sudo cp -a /var/www/html/custom_apps/publicshareindex \
          /var/www/html/custom_apps/publicshareindex.bak.$(date +%Y%m%d)
sudo rm -rf /var/www/html/custom_apps/publicshareindex
sudo cp -a publicshareindex /var/www/html/custom_apps/publicshareindex
sudo chown -R www-data:www-data /var/www/html/custom_apps/publicshareindex
sudo -u www-data php /var/www/html/occ app:disable publicshareindex
sudo -u www-data php /var/www/html/occ app:enable  publicshareindex
```

> 没有数据库迁移；回滚把备份目录换回来即可。

---

## [0.2.0] - 2026-05-07（历史版本）

- 初始版本：以共享人作为顶级目录、目录树展示、内联预览、全局搜索。
- 默认排除密码保护、过期、隐藏下载共享。
