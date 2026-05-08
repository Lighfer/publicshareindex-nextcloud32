# 公开共享浏览器（Public Share Index）

> 一个面向 Nextcloud 32 的自定义应用，提供一个全屏、扁平、克制的匿名公开页面，用来**集中索引**当前实例上所有活跃的公开链接共享，并支持**全局搜索**。所有"打开 / 预览 / 下载 / 子目录浏览"操作都会跳转到 Nextcloud 原生公开分享页面，本应用本身不做任何文件内容处理。

- 页面以**共享人显示名**作为顶级目录，避免不同用户同路径冲突。
- 用真实**目录树结构**展示（而不是平铺 `/路径`），便于一眼看清结构。
- 支持**全局搜索**，覆盖每个共享根**内部**的全部文件 / 目录；搜索结果文件名下方的灰色小字会同时显示**共享人**与完整路径。
- 点击任何条目都会在新标签里打开 Nextcloud 原生公开分享页（`/s/<token>?dir=/...`），由 Nextcloud 自身处理预览、下载、子目录浏览。
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
| 当前版本    | `0.4.2`                           |
| Nextcloud   | `>=32, <=32`（v32 锁定）          |
| PHP         | 跟随 Nextcloud 32 要求（PHP 8.1+） |
| 运行时依赖  | 仅 Nextcloud 内置 OCP API         |

> 升级到 Nextcloud 33 之前，请先确认本应用对应版本是否已发布。

---

## 二、目录结构

```text
publicshareindex/
├── appinfo/
│   ├── info.xml          # App 元数据，版本号在此
│   └── routes.php        # 路由：index / api/bootstrap / api/children / api/search
├── css/
│   └── style.css         # 扁平浅色现代主题 + fixed 全屏覆盖
├── js/
│   └── filter.js         # 前端逻辑（仅索引 + 搜索，所有打开均跳 NC 原生页）
├── lib/
│   ├── AppInfo/Application.php
│   ├── Controller/PageController.php
│   ├── Security/PathGuard.php          # 路径规范化与越界校验
│   └── Service/PublicShareIndexService.php
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

> 如果你的容器里 Nextcloud 不在 `/var/www/html`，请按实际路径替换。

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

### 4.0 v0.4.x 内部小版本升级（v0.4.0 / 0.4.1 → 0.4.2）

无破坏性变更，仅 UI 层面：

- 所有 `<button>` 替换为 `<div role="button" tabindex="0">` / `<span role="button" tabindex="0">` / `<a>`，不再被 Nextcloud guest 全局样式污染。
- 目录树用子容器左侧虚线分支线表达层级，一级 owner 字重和字色更突出。

升级方式：

```bash
sudo rm -rf /var/www/html/custom_apps/publicshareindex
sudo cp -a publicshareindex /var/www/html/custom_apps/publicshareindex
sudo chown -R www-data:www-data /var/www/html/custom_apps/publicshareindex
sudo -u www-data php /var/www/html/occ app:disable publicshareindex
sudo -u www-data php /var/www/html/occ app:enable  publicshareindex
```

浏览器 Ctrl+F5 强制刷新即可看到新样式。

### 4.1 v0.3 → v0.4 推荐路径

v0.4 把"在我们的页面里预览 / 下载文件"的能力**完全移除**，改为统一跳转回 Nextcloud 原生公开分享页面。**不需要数据迁移**。

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
# 期望输出包含 "0.4.2"

# 5. 浏览器强制刷新：Ctrl+F5 / Cmd+Shift+R
```

升级后请确认：

1. 顶部不再有 Nextcloud 大 logo（fixed 全屏覆盖延续 v0.3）。
2. 不再有右侧"预览面板"，页面变为两栏（侧栏 + 主区）。
3. 列表 / 搜索结果里点击"在 Nextcloud 中打开"会在新标签打开 NC 原生公开分享页面。
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
| `search_max_visited` | `20000` | 单次搜索最多遍历的节点数（性能保护） |
| `search_max_results` | `200` | 单次搜索最多返回的结果数（默认 200，硬上限 500） |

示例：

```bash
sudo -u www-data php /var/www/html/occ config:app:set publicshareindex title --value="公开共享资源浏览器"
sudo -u www-data php /var/www/html/occ config:app:set publicshareindex public_enabled --value="yes"
sudo -u www-data php /var/www/html/occ config:app:set publicshareindex search_max_visited --value="20000"
sudo -u www-data php /var/www/html/occ config:app:set publicshareindex search_max_results --value="200"
```

---

## 六、安全建议

v0.4 因为不再处理任何文件内容，攻击面比 v0.3 更小。当前默认启用的硬保护：

1. **路径越界硬校验**：`PathGuard::assertWithinRoot` 强制每个解析后的节点必须真实位于其共享根之内，否则抛出。
2. **跳转 URL 由后端生成**：跳转地址使用 `IURLGenerator::linkToRouteAbsolute('files_sharing.sharecontroller.showShare')`，不会被前端 JS 拼造。
3. **错误信息脱敏**：异常细节只记录到 `nextcloud.log`，对外仅返回中文用户友好文案。
4. **跳过禁用用户的共享**：被管理员禁用的用户的共享不会出现在索引中。
5. **匿名 API 限速**：`bootstrap` 30/min、`children` 120/min、`search` 30/min。
6. **noindex / nofollow**：页面 meta 与匿名响应均带 `X-Robots-Tag` / `<meta robots>`，避免被搜索引擎收录。

强烈推荐的运维侧加固：

- 把页面 URL 限制在内网或 VPN 可达。
- 在反代里再叠一层 IP 白名单或 Basic Auth。
- 日常开启 `include_password_protected=no` 与 `show_hide_download_shares=no`。
- 定期审查哪些用户被授予了创建公开链接的权限。
- 监控 `nextcloud.log` 中 `'app' => 'publicshareindex'` 的 warning / error。

---

## 七、性能调优

- **搜索遍历上限**：`search_max_visited` 越高，覆盖越全，但单次搜索越慢。中等规模建议 20000；大规模（>50 万节点）建议保持 20000，再用更精确的关键词。
- **搜索结果上限**：`search_max_results` 默认 200，硬上限 500。前端会提示"已截断到上限"。
- **请求级缓存**：`PublicShareIndexService` 在单次请求内缓存"可见公开共享列表"和"用户启用状态"。
- **HTTP 压缩**：把反代（Nginx / Apache）的 gzip / brotli 打开，对 `application/json` 收益明显。

---

## 八、故障排查

### 8.1 页面顶部仍然有 Nextcloud 大 logo

**原因**：浏览器缓存了旧 CSS。

**修复**：

```bash
sudo -u www-data php /var/www/html/occ maintenance:repair --include-expensive
```

然后浏览器 `Ctrl+F5`。v0.3 起，根元素已用 `position: fixed; inset: 0; z-index: 9999;` 覆盖整个视口。

### 8.2 点击"在 Nextcloud 中打开"提示密码 / 404

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

这是 v0.4 的预期行为：

- Nextcloud 原生公开分享页面的 URL 参数 `?dir=` 只接受**目录路径**，无法直接打开某个文件预览。
- 因此搜索到具体文件时，跳转 URL 会落在该文件**所在父目录**：`/s/<token>?dir=/<父目录>`。
- 用户在 NC 原生页面看到目录列表后，再点击文件触发预览 / 下载。

如果要直接拿到下载链接（绕过 NC 公开页面查看一步），可以手动构造：

```text
https://<your-nc>/s/<token>/download?path=/<父目录>&files=<文件名>
```

> 注意：NC 的 `download` 端点这里用的是 `path=` 而不是 `dir=`，与浏览页面参数名不同。

本应用默认不构造"直接下载"链接，避免绕过共享人在 NC 上配置的预览限制。

### 8.7 树节点 / 按钮显示成奇怪的灰底大块（被 Nextcloud 默认样式污染）

> **0.4.2 版本已修复**。本应用从 0.4.2 起不再使用任何 `<button>` 元素：
> - 树节点 / 面包屑可点击项 / "进入" / "刷新" → `<div role="button" tabindex="0">` 或 `<span role="button" tabindex="0">`，配合键盘 Enter / Space 触发
> - 列表 / 搜索结果中的"在 Nextcloud 中打开"、文件名链接 → `<a target="_blank" rel="noopener noreferrer">`
>
> 升级到 0.4.2 后即彻底规避 NC guest 布局对 `<button>` 注入的默认样式。如仍有残影，按 Ctrl+F5 强制刷新浏览器缓存。

### 8.8 查日志的最快办法

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
