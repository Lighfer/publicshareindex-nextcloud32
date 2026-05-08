# 变更日志

本应用遵循 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/) 风格，并采用 [Semantic Versioning](https://semver.org/lang/zh-CN/) 语义化版本号。

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
