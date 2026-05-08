# 公开共享浏览器（Public Share Index）

> 一个面向 NextCloud 32 的自定义应用，提供一个全屏、扁平、克制的匿名公开页面，用来**集中索引**当前实例上所有活跃的公开链接共享，并支持**全局搜索**、**「显示所有文件」开关**与**直接下载**。"预览 / 查看"统一跳回 NextCloud 原生公开分享页面；"下载"由本应用以**强制 attachment + 流式输出**直接提供，避免内联预览风险与 OOM。

- 页面以**共享人显示名**作为顶级目录，避免不同用户同路径冲突。
- 用真实**目录树结构**展示（而不是平铺 `/路径`），便于一眼看清结构。
- 支持**全局搜索**，覆盖每个共享根**内部**的全部文件 / 目录；搜索结果文件名下方的灰色小字会同时显示**共享人**与完整路径。
- **显示所有文件**：搜索框下方一个 checkbox。开启后可在本应用内进入共享内部，按层浏览目录与文件——每次只显示当前层的直接子项，**不递归扫整棵子树**；目录类型点名称在本应用内继续下钻；文件类型提供「下载」（直接拉文件）和「在 NextCloud 中打开」两个按钮。
- **服务端缓存**：bootstrap 索引默认 60 秒 TTL，所有匿名访客共用一份；顶部「强制刷新」按钮可绕过缓存重新扫描；侧栏底部显示"上次扫描时间 + 是否来自缓存"。
- **侧栏图标 + caret**：每个节点带类型图标（用户 / 文件夹 / 文件），目录类型节点都显示 caret，下钻一目了然。
- 默认排除密码保护、已过期、隐藏下载的共享，安全为先。

---

## 目录

- [一、版本与兼容性](#一版本与兼容性)
- [二、目录结构](#二目录结构)
- [三、安装部署](#三安装部署)
- [四、升级与回滚](#四升级与回滚)
- [五、配置项详解](#五配置项详解)
- [六、安全建议](#六安全建议)
- [七、性能调优](#七性能调优)
- [八、故障排查](#八故障排查)
- [九、卸载](#九卸载)

---

## 一、版本与兼容性

| 项目        | 取值                              |
| ----------- | --------------------------------- |
| App ID      | `publicshareindex`                |
| 当前版本    | `0.6.1`                           |
| NextCloud   | `>=32, <=32`（v32 锁定）          |
| PHP         | 跟随 NextCloud 32 要求（PHP 8.1+） |
| 运行时依赖  | 仅 NextCloud 内置 OCP API         |

> 升级到 NextCloud 33 之前，请先确认本应用对应版本是否已发布。

---

## 二、目录结构

```text
publicshareindex/
├── appinfo/
│   ├── info.xml          # App 元数据，版本号在此
│   └── routes.php        # 路由：index / bootstrap / children / search / list-all / download
├── css/
│   └── style.css         # 扁平浅色现代主题 + fixed 全屏覆盖
├── js/
│   └── filter.js         # 前端逻辑（索引 + 搜索 + 侧栏深层目录树 + 内部下钻 + 下载 / 跳转）
├── lib/
│   ├── AppInfo/Application.php
│   ├── Controller/PageController.php           # bootstrap 接受 ?force=1 强制刷新
│   ├── Http/StreamingFileResponse.php          # chunked 文件流（仅供下载，强制 attachment）
│   ├── Security/PathGuard.php                  # 路径规范化与越界校验
│   └── Service/PublicShareIndexService.php     # bootstrap TTL 缓存 + listAll + prepareDownload
├── templates/
│   └── index.php
├── README.md
└── CHANGELOG.md
```

---

## 三、安装部署

### 3.1 标准 Docker 部署

```bash
# 1. 把整个 publicshareindex 目录拷贝到 custom_apps
sudo cp -a publicshareindex /var/www/html/custom_apps/publicshareindex
sudo chown -R www-data:www-data /var/www/html/custom_apps/publicshareindex

# 2. 启用应用
sudo -u www-data php /var/www/html/occ app:enable publicshareindex

# 3.（可选）设置页面标题
sudo -u www-data php /var/www/html/occ config:app:set publicshareindex title --value="公开共享资源浏览器"

# 4. 首次部署后建议修复一次缓存，避免旧 JS / CSS 残留
sudo -u www-data php /var/www/html/occ maintenance:repair --include-expensive
```

> 如果你的容器里 NextCloud 不在 `/var/www/html`，请按实际路径替换。

### 3.2 docker-compose

```yaml
services:
  app:
    image: nextcloud:32
    volumes:
      - ./data/custom_apps/publicshareindex:/var/www/html/custom_apps/publicshareindex
```

容器启动后再 `docker compose exec --user www-data app php occ app:enable publicshareindex`。

### 3.3 访问页面

```text
https://<your-nextcloud>/index.php/apps/publicshareindex/
```

匿名（未登录）即可访问。

> 强烈建议把这个 URL 限制为内网可达，或在前置反代加 IP 白名单 / Basic Auth。

---

## 四、升级与回滚

### 4.0 v0.5.x / v0.6.0 → v0.6.1（推荐）

无破坏性变更。本轮把"显示所有文件"从"一次性 BFS 整棵子树"改为"按层加载"，更接近文件管理器；侧栏节点补上类型图标 + caret，让"目录可下钻"一目了然；统一品牌拼写为 `NextCloud`。

```bash
sudo cp -a /var/www/html/custom_apps/publicshareindex \
          /var/www/html/custom_apps/publicshareindex.bak.$(date +%Y%m%d)

sudo rm -rf /var/www/html/custom_apps/publicshareindex
sudo cp -a publicshareindex /var/www/html/custom_apps/publicshareindex
sudo chown -R www-data:www-data /var/www/html/custom_apps/publicshareindex

sudo -u www-data php /var/www/html/occ app:disable publicshareindex
sudo -u www-data php /var/www/html/occ app:enable  publicshareindex

sudo -u www-data php /var/www/html/occ app:list | grep publicshareindex
# 期望输出包含 "0.6.1"
```

升级后请确认（必须强刷浏览器，`Ctrl+Shift+R`）：

1. 侧栏每一行都带一个小图标——共享人是用户头像、目录是文件夹；激活态图标颜色变蓝。
2. 开启「显示所有文件」后，选一个共享根 → 主区**只显示该共享根的直接子项**，不再一次铺所有层；点目录名进入该目录，看到那一层；再次点子目录继续下钻。每次只发一个 `GET /api/children` 请求，响应轻量。
3. 文件行的"下载"和"在 NextCloud 中打开"两个按钮**强制并排在一行**，不会被换行。

### 4.0.1 关于 NextCloud 原生预览能力（v0.6.1 调研结论）

调研了 NC 32 的官方 API：

- `OCP\IPreview` 能给图片、视频首帧、PDF 首页**生成缩略图（PNG）**——这部分自定义 app 可以调；
- 但 Office 文档（docx/xlsx/pptx）的预览由 Collabora / OnlyOffice 等独立 app 提供；视频播放、PDF 多页阅读由 NC 自带的 Viewer app 提供。**这两类都不是 OCP 公开 API**，没有把预览组件抽出来给第三方 app 用的官方接口。

结论：本应用**不集成 NC 原生预览**，"预览 / 查看"操作统一跳转 NC 原生公开分享页面，让 NC 的 Viewer / Office / 媒体播放器接管多类型预览，体验最完整。如果未来确有"图片 lightbox 速览"的需求，可以再单独走一遍 `IPreview` 加一个轻量层，但不会做成"所有类型都能内联预览"。

### 4.0.2 v0.4.x → v0.5.x 历史升级路径

如果你之前停留在 0.4.x，直接按 4.0 升到 0.6.1 即可，0.5.0 / 0.6.0 的所有改动（「显示所有文件」+ 下载 + 缓存 + 侧栏深层）会一次到位。

### 4.1 v0.3 → v0.4 推荐路径

v0.4 把"在我们的页面里预览 / 下载文件"的能力**完全移除**，改为统一跳转回 NextCloud 原生公开分享页面。**不需要数据迁移**。

```bash
# 1. 备份旧目录（强烈建议）
sudo cp -a /var/www/html/custom_apps/publicshareindex \
          /var/www/html/custom_apps/publicshareindex.bak.$(date +%Y%m%d)

# 2. 替换为新版本
sudo rm -rf /var/www/html/custom_apps/publicshareindex
sudo cp -a publicshareindex /var/www/html/custom_apps/publicshareindex
sudo chown -R www-data:www-data /var/www/html/custom_apps/publicshareindex

# 3. 重新发现 App 元数据并清缓存
sudo -u www-data php /var/www/html/occ app:disable publicshareindex
sudo -u www-data php /var/www/html/occ app:enable  publicshareindex

# 4. 验证版本
sudo -u www-data php /var/www/html/occ app:list | grep publicshareindex
# 期望输出包含 "0.6.1"

# 5. 浏览器强制刷新：Ctrl+F5 / Cmd+Shift+R
```

升级后请确认：

1. 顶部不再有 NextCloud 大 logo（fixed 全屏覆盖延续 v0.3）。
2. 不再有右侧"预览面板"，页面变为两栏（侧栏 + 主区）。
3. 列表 / 搜索结果里点击"在 NextCloud 中打开"会在新标签打开 NC 原生公开分享页面。
4. 搜索仍可以匹配共享根**内部**的文件 / 目录。

### 4.2 v0.4 的破坏性变更

| 变更 | 影响 | 备注 |
| --- | --- | --- |
| 移除 `GET /file` 端点 | 不再提供任何文件流式下载接口 | 所有打开操作改为跳转 NC 原生页面 |
| 移除前端预览面板 | 页面布局从三栏变两栏 | 自定义 CSS 选择器需重新核对 |
| 移除 `PreviewPolicy` / `StreamingFileResponse` | 安全策略相关代码已下线 | 跳转 NC 后由 NC 自身的 CSP / sandbox 接管 |
| `buildNodeDescriptor` 输出字段 | `openUrl` 字段更名为 `nextcloudUrl`，含义为"指向 NC 原生公开分享页面" | API 调用方需同步调整 |

### 4.3 v0.2 → v0.4 一步升级

参考 4.1 的脚本即可，过程一致。`v0.2` → `v0.3` 的所有改动（CSP 修复、fixed 全屏、流式安全等）以及 `v0.3` → `v0.4` 的简化（移除内联预览）都会一次到位。

### 4.4 回滚

```bash
sudo -u www-data php /var/www/html/occ app:disable publicshareindex
sudo rm -rf /var/www/html/custom_apps/publicshareindex
sudo mv /var/www/html/custom_apps/publicshareindex.bak.YYYYMMDD \
        /var/www/html/custom_apps/publicshareindex
sudo chown -R www-data:www-data /var/www/html/custom_apps/publicshareindex
sudo -u www-data php /var/www/html/occ app:enable publicshareindex
```

> 本应用没有引入任何持久化变更（不写表、不改 schema、不写 appdata），所以回滚是无损的。

---

## 五、配置项详解

所有配置项都通过 `occ config:app:set publicshareindex <key> --value=...` 设置。

| Key | 默认 | 说明 |
| --- | --- | --- |
| `title` | `公开共享资源浏览器` | 页面顶部和浏览器标签的标题 |
| `public_enabled` | `yes` | 是否对外暴露公开页面，临时下线设为 `no` |
| `include_password_protected` | `no` | **建议保持 `no`**。设为 `yes` 会把带密码保护的链接也列入索引（标题 / 路径会被泄露） |
| `include_expired` | `no` | 是否列出已过期的链接，仅排查时打开 |
| `show_hide_download_shares` | `no` | **建议保持 `no`**。设为 `yes` 会绕过共享人"隐藏下载"意图 |
| `show_owner_uid` | `no` | 始终在共享人显示名后追加 `(uid)`，避免同名歧义 |
| `bootstrap_cache_ttl` | `60` | bootstrap 索引服务端缓存 TTL（秒）。`0` 禁用缓存（每次实时扫）；上限 86400（24 小时）。所有匿名访客共用一份缓存，避免重复扫描全实例 |
| `search_max_visited` | `20000` | 单次搜索最多遍历的节点数（性能保护） |
| `search_max_results` | `200` | 单次搜索最多返回的结果数（默认 200，硬上限 500） |
| `list_all_max_visited` | `50000` | 「显示所有文件」单次最多遍历的节点数（v0.6.0 起放宽，适合内网；只扫元信息不读文件内容） |
| `list_all_max_results` | `20000` | 「显示所有文件」单次最多返回的条目数（v0.6.0 起放宽，硬上限 50000） |
| `download_enabled` | `yes` | 是否启用本应用提供的「下载」按钮。设为 `no` 后端拒绝并隐藏前端按钮，所有获取文件的操作请走 NC 原生页面 |
| `download_chunk_bytes` | `8192` | 流式下载每次写出的字节数。范围 `1024`–`1048576`，超出会回退默认 |

示例：

```bash
sudo -u www-data php /var/www/html/occ config:app:set publicshareindex title --value="公开共享资源浏览器"
sudo -u www-data php /var/www/html/occ config:app:set publicshareindex public_enabled --value="yes"
sudo -u www-data php /var/www/html/occ config:app:set publicshareindex search_max_visited --value="20000"
sudo -u www-data php /var/www/html/occ config:app:set publicshareindex search_max_results --value="200"
```

---

## 六、安全建议

v0.5 在重新引入下载能力后，依然把"内联预览"完全摒弃，攻击面控制良好。当前默认启用的硬保护：

1. **路径越界双重校验**：每个 `(shareId, relativePath)` 入口都要先 `findShareById`（校验 share 可见），再 `PathGuard.normalizeRelative` + `PathGuard.assertWithinRoot`，最终交给 NC `Folder->get()` 解析；任意一层校验失败都立即抛出。
2. **下载强制 attachment + application/octet-stream**：`StreamingFileResponse` 始终以 attachment 头送出，永远是 octet-stream，**不允许内联预览** —— 杜绝 HTML / SVG / PDF 等格式在浏览器内联触发脚本执行的 XSS 路径。
3. **OOM 防护**：
   - `listAll` 节点数 / 结果数双上限（`list_all_max_visited` / `list_all_max_results`），超限即截断并标记 `truncated`
   - 跨多 share 加载时**串行**而非并行，遇到截断立即停止后续请求
   - `download` chunked 输出（默认 8 KB / 块），传输前后清空 PHP 输出缓冲，单次内存峰值≈一个 chunk
4. **共享意图尊重**：`hideDownload=true` 的共享在前端隐藏「下载」按钮，后端二次拒绝；管理员可通过 `download_enabled=no` 全局关闭下载。
5. **跳转 URL 由后端生成**：跳转地址使用 `IURLGenerator::linkToRouteAbsolute('files_sharing.sharecontroller.showShare')`，不会被前端 JS 拼造。
6. **错误信息脱敏**：异常细节只记录到 `nextcloud.log`，对外仅返回中文用户友好文案。
7. **跳过禁用用户的共享**：被管理员禁用的用户的共享不会出现在索引中。
8. **匿名 API 限速**：`bootstrap` 30/min、`children` 120/min、`search` 30/min、`list-all` 30/min、`download` 60/min。
9. **下载响应头加固**：`Cache-Control: no-store` / `Pragma: no-cache` / `X-Content-Type-Options: nosniff` / `X-Frame-Options: DENY` / `Referrer-Policy: no-referrer`。
10. **noindex / nofollow**：页面 meta 与匿名响应均带 `X-Robots-Tag` / `<meta robots>`，避免被搜索引擎收录。

强烈推荐的运维侧加固：

- 把页面 URL 限制在内网或 VPN 可达。
- 在反代里再叠一层 IP 白名单或 Basic Auth。
- 日常开启 `include_password_protected=no` 与 `show_hide_download_shares=no`。
- 如果对外环境特别敏感，用 `download_enabled=no` 关闭本应用的直接下载，统一由 NC 原生页面处理。
- 定期审查哪些用户被授予了创建公开链接的权限。
- 监控 `nextcloud.log` 中 `'app' => 'publicshareindex'` 的 warning / error。

---

## 七、性能调优

- **bootstrap 服务端缓存**（v0.6.0 引入）：`bootstrap_cache_ttl` 默认 60 秒，所有匿名访客共用。NC 部署了 redis / apcu 时缓存跨进程共享；裸装也至少能跨同一 PHP-FPM worker 内的多次请求复用。如果你希望"每分钟最多扫一次全实例"，保持默认即可；想更新更勤可调到 30；想**总是实时**就设 0：
  ```bash
  sudo -u www-data php /var/www/html/occ config:app:set publicshareindex bootstrap_cache_ttl --value="0"
  ```
- **搜索遍历上限**：`search_max_visited` 越高，覆盖越全，但单次搜索越慢。中等规模建议 20000；大规模（>50 万节点）建议保持 20000，再用更精确的关键词。
- **搜索结果上限**：`search_max_results` 默认 200，硬上限 500。前端会提示"已截断到上限"。
- **「显示所有文件」上限**：v0.6.0 默认放宽到 `list_all_max_visited=50000`、`list_all_max_results=20000`，硬上限 50000；只扫文件元信息（路径 / 大小 / mtime），单次响应通常几 MB JSON，浏览器渲染也只是一张表格。如果你的共享根特别巨大（>5w 文件），仍建议引导用户进入更具体的子目录。
- **下载块大小**：`download_chunk_bytes` 默认 8 KB。如果服务器和客户端之间是高带宽 / 高延迟链路，可以试试 64 KB / 256 KB；对内存峰值影响很小（每次写出后立即 flush）。
- **请求级缓存**：`PublicShareIndexService` 在单次请求内缓存"可见公开共享列表"和"用户启用状态"。
- **HTTP 压缩**：把反代（Nginx / Apache）的 gzip / brotli 打开，对 `application/json` 收益明显。**注意**：下载响应是 `application/octet-stream`，反代上请关闭对其的 gzip（已经是二进制内容，压缩反而开销更大）。

---

## 八、故障排查

### 8.1 页面顶部仍然有 NextCloud 大 logo

**原因**：浏览器缓存了旧 CSS。

**修复**：

```bash
sudo -u www-data php /var/www/html/occ maintenance:repair --include-expensive
```

然后浏览器 `Ctrl+F5`。v0.3 起，根元素已用 `position: fixed; inset: 0; z-index: 9999;` 覆盖整个视口。

### 8.2 点击"在 NextCloud 中打开"提示密码 / 404

**原因**：

- 共享带密码保护，NC 原生页面会要求输入密码。
- 共享在被你访问之前刚刚过期 / 删除。

**应对**：第一种正常输入密码即可；第二种刷新本应用页面索引。

### 8.3 搜索没有结果，但目录里明显有匹配文件

**原因**：被 `search_max_visited` 截断（默认 20000 节点），或者关键词太短（< 2 字符）。

**应对**：

```bash
sudo -u www-data php /var/www/html/occ config:app:set publicshareindex search_max_visited --value="50000"
```

或者使用更精确、更长的关键词（搜索是大小写不敏感的子串匹配）。

### 8.4 接口报"请求过于频繁"/ 429

**原因**：触发匿名限速。前端搜索是 250ms 防抖，正常使用一般不会超过；如果是被刷接口，请检查日志确认来源 IP，并在反代加流控。

### 8.5 列表里某个共享显示不出来

**原因**：可能命中了默认排除规则之一：

- 共享带密码（`include_password_protected=no`）
- 共享已过期（`include_expired=no`）
- 共享隐藏下载（`show_hide_download_shares=no`）
- 共享拥有者已被禁用 / 删除

**应对**：根据实际策略调整对应配置项；不建议为了显示而关闭安全开关。

### 8.6 搜索结果里点击文件，跳到 NC 后停留在父目录

这是 v0.4 起的预期行为：

- NextCloud 原生公开分享页面的 URL 参数 `?dir=` 只接受**目录路径**，无法直接打开某个文件预览。
- 因此搜索到具体文件时，跳转 URL 会落在该文件**所在父目录**：`/s/<token>?dir=/<父目录>`。
- 用户在 NC 原生页面看到目录列表后，再点击文件触发预览 / 下载。

> v0.5 起：如果你想**绕过这一步直接拿到文件**，可以使用本应用「显示所有文件」开关里每行的「下载」按钮——它会通过 `/api/download` 流式直接把文件吐回浏览器，不再二跳。共享人若设置了 `hideDownload`，下载按钮自动隐藏。

### 8.7 树节点 / 按钮显示成奇怪的灰底大块（被 NextCloud 默认样式污染）

> **0.4.2 版本已修复**。本应用从 0.4.2 起不再使用任何 `<button>` 元素：
> - 树节点 / 面包屑可点击项 / "进入" / "刷新" → `<div role="button" tabindex="0">` 或 `<span role="button" tabindex="0">`，配合键盘 Enter / Space 触发
> - 列表 / 搜索结果中的"在 NextCloud 中打开"、文件名链接 → `<a target="_blank" rel="noopener noreferrer">`
>
> 升级到 0.4.2 后即彻底规避 NC guest 布局对 `<button>` 注入的默认样式。如仍有残影，按 Ctrl+F5 强制刷新浏览器缓存。

### 8.8 「显示所有文件」开关下点开很深的目录后，侧栏没出现新一级

**v0.6.1 改为按层加载**：每次只请求当前目录的直接子项，侧栏在你**真的下钻进入**那一层之后才出现新节点，避免一次性请求大量数据。如果你点了一个目录但**没看到新子节点出现在侧栏**：

1. 检查浏览器控制台 Network 面板，确认 `GET /apps/publicshareindex/api/children` 请求返回 200 且 `items` 不为空。
2. 如果该目录确实没有子目录，侧栏不会有新节点，主区会显示"该目录下没有任何文件 / 目录"。
3. 如果你需要"一次看到这个共享根所有层级"，目前没有这个开关——按设计你需要逐层下钻。`/api/list-all` 后端接口仍保留，但不会被前端调用；如果未来有"一键全展开"需求再加。

### 8.9 「下载」按钮没有出现

**可能原因**：

| 原因 | 排查 |
| --- | --- |
| 共享人设置了 `hideDownload` | 共享根 detail 页面会显示"隐藏下载"标签；前端会主动隐藏「下载」按钮 |
| 管理员关闭了下载 | `occ config:app:get publicshareindex download_enabled`，是 `no` 就关掉了；`yes` 才显示按钮 |
| 当前节点是目录 | 「下载」按钮仅对**文件**类型出现；目录请通过 NC 原生页面的「下载为 zip」 |
| 浏览器缓存了旧 JS | Ctrl+F5 强刷 |

### 8.10 下载大文件时 PHP 报"Allowed memory size exhausted"

**原因**：理论上 `StreamingFileResponse` 是 chunked 输出且会清空 PHP 输出缓冲，不会把整个文件加载到内存。但如果你的 NextCloud / PHP 安装中存在某些扩展或反代会强制把响应整体读完再转发，可能仍会撞内存上限。

**应对**：

1. 优先确认 `php.ini` 的 `output_buffering = Off`、`zlib.output_compression = Off`。
2. 在反代（Nginx）上对 `/apps/publicshareindex/api/download` 单独关闭 `gzip` 和 `proxy_buffering`：

   ```nginx
   location ~ ^/apps/publicshareindex/api/download {
       gzip off;
       proxy_buffering off;
       proxy_request_buffering off;
   }
   ```

3. 如果仍有问题，最简单的兜底是临时把 `download_enabled` 设为 `no`，让用户去 NC 原生页面下载（NC 自身的下载实现也是 chunked，更经过实战检验）。

### 8.11 「显示所有文件」开关左侧的方块显示成实心蓝色大块

**0.6.0 版本已修复**。NextCloud 32 的 guest 布局会给 `<input type="checkbox">` 注入一组很难完全覆盖的样式（width/height/padding/background-image 等），即使加 `appearance: none` 也未必能盖掉。本版本**完全隐藏原生 input**（绝对定位 + opacity:0 + clip），用 `<span class="psi-toggle-box">` 自行绘制方框与对勾，状态通过相邻兄弟选择器同步。升级后请 Ctrl+F5 强刷浏览器缓存。

### 8.12 改了配置但页面没立即生效

**v0.6.0 起 bootstrap 索引有 60 秒服务端缓存**。改完配置可以两种方式触发立即刷新：

1. 点页面右上角的「**强制刷新**」按钮（前端会发 `?force=1`），侧栏底部会从"来自缓存"变成"已实时扫描"。
2. 或者直接清缓存：

   ```bash
   # redis 部署：
   sudo -u www-data php /var/www/html/occ memcache:status redis
   # 直接清整个 NC 缓存（会同时清掉本应用缓存）：
   sudo -u www-data php /var/www/html/occ maintenance:repair --include-expensive
   ```

3. 也可以临时把缓存关掉：`occ config:app:set publicshareindex bootstrap_cache_ttl --value="0"`，确认改动生效后再恢复。

### 8.13 查日志的最快办法

```bash
sudo -u www-data tail -f /var/www/html/data/nextcloud.log | grep publicshareindex
```

---

## 九、卸载

```bash
sudo -u www-data php /var/www/html/occ app:disable publicshareindex
sudo -u www-data php /var/www/html/occ app:remove  publicshareindex
# 或者直接删目录
sudo rm -rf /var/www/html/custom_apps/publicshareindex
```

本应用不创建任何数据库表、不写入 appdata。卸载后只剩 `oc_appconfig` 表里若干 `publicshareindex.*` 配置项，可手动清理：

```bash
sudo -u www-data php /var/www/html/occ config:app:delete publicshareindex title
# 其余配置项同理
```

---

## 许可

AGPL-3.0-or-later
