# ErcerLink

handsome 快速申请友链插件（v1.4.0 安全重写版）

## 功能特性

- 🚀 快速申请友链：访客在友链页直接提交，无需进入后台
- 🔒 多层提交防护：同源校验 + 双提交 Cookie CSRF + 频率限制
- 🛡️ 严格输入规范化：链接仅允许 HTTP(S)，文本剔除全部标签与引号类字符
- 🔐 Cloudflare Turnstile 验证码（可选，校验 action）
- 📧 邮件通知（可选，依赖 CommentToMail 插件）
- ⚡ PJAX 支持，`pjax_Link()` 可重复调用
- 🧩 零 jQuery 依赖，纯原生 JS
- 📱 响应式表单，跟随站点暗色主题

## 安装方法

1. 下载插件，解压后将文件夹重命名为 `ErcerLink`
2. 上传到 Typecho 插件目录 `usr/plugins/`
3. 在 Typecho 后台启用插件

> 升级安装：直接覆盖 `ErcerLink/` 目录后在后台停用再启用插件，即可刷新路由。
> 原有配置项（CDN 地址、Turnstile 密钥、邮件通知）全部兼容，无需重新填写。

## 文件结构

| 文件 | 职责 |
| --- | --- |
| `Plugin.php` | 插件生命周期、后台配置表单、前端脚本注入 |
| `Action.php` | `/link_add` 路由处理：校验 → 写库 → 邮件入队 |
| `Util.php` | 公共工具：CSRF、同源、限流、清洗、URL 规范化、客户端 IP |
| `js/ErcerLinks.js` | 前端表单渲染与提交，原生 JS |
| `tests/` | CLI 验证脚本，不参与运行时 |

## 配置说明

### 基础设置

- **使用说明**：插件使用指南（隐藏项，仅展示）

### 资源加载

- **CDN加速地址**：留空使用插件自带脚本；填写时必须是 **以 `.js` 结尾的 HTTP(S) 地址**，其他协议（`javascript:`、`data:` 等）与非法后缀会被拒绝

### 安全设置

- **Turnstile Site Key**：Cloudflare Turnstile 前端站点密钥
- **Turnstile Secret Key**：Cloudflare Turnstile 后端验证密钥（留空即关闭验证码）
- **客户端 IP 来源**：默认 `NONE`（只信任 `REMOTE_ADDR`）。站点挂在 CDN 或反向代理后，选择对应请求头（`CF-CONNECTING-IP` / `X-FORWARDED-FOR` / `X-REAL-IP`），否则所有访客会共享同一个限流计数

### 通知设置

- **邮件通知**：开启后，新链接提交时通过 CommentToMail 插件入队发信
  - 提示：需安装并启用 [CommentToMail](https://github.com/ououmm/CommentToMail) 插件；未安装时开启该项会被配置校验拦下

## 使用教程

### 1. 添加友链申请区域

在友链页面添加以下代码：

```html
<div id="postLink"></div>
```

### 2. PJAX 支持

如使用 pjax，请在回调函数内添加以下代码：

```javascript
pjax_Link();
```

`pjax_Link()` 是幂等的，重复调用不会重复渲染表单或重复绑定事件。也可以调用 `ErcerLink.refresh()` 强制重建。

### 3. 验证码配置

1. 前往 [Cloudflare Dashboard](https://dash.cloudflare.com/) 获取 Turnstile 密钥
2. 在插件配置中填写 `Turnstile Site Key` 和 `Turnstile Secret Key`
3. 保存配置后，验证码自动启用

前端以 `render=explicit` 方式渲染，`action` 固定为 `link_add`，服务端会校验该字段。

## 依赖说明

- **jQuery**：不需要（v1.4.0 起已移除依赖；若主题提供 `$.message` 则复用它弹出提示）
- **CommentToMail**（可选）：用于发送邮件通知
- **Cloudflare Turnstile**（可选）：用于验证码验证
- **PHP 5.6+**、Typecho 1.1+

## 效果图

![效果图](https://github.com/OuOumm/ErcerLink/assets/43441064/c2581a2c-7a30-42fa-a667-5382a0001c60)

## 更新日志

### v1.4.0（安全重写）

**修复的漏洞**

- **存储型 XSS**：站点名称、描述等字段不再直接入库原始 HTML，统一走 `cleanText()` 剥离标签并剔除 `< > " ' \` \\` 与控制字符；前端也不再拼接 `innerHTML`
- **CSRF 防护失效**：弃用自建表单场景下无效的 `Widget_Security::protect()`，改为双提交 Cookie 方案（页面注入 token → 前端写同站 Cookie → 提交时通过 `X-ErcerLink-CSRF` 头回传 → 服务端 `hash_equals` 比对）
- **CDN 地址注入**：`path` 配置项只接受以 `.js` 结尾的 HTTP(S) 地址，`javascript:`/`data:` 等伪协议不再进入 `<script src>`
- **链接协议注入**：友链 URL 与头像 URL 仅允许 HTTP(S)，阻止 `javascript:`、`data:`、含空白/引号/反斜杠的载荷
- **限流绕过**：默认只信任 `REMOTE_ADDR`，伪造 `X-Forwarded-For` 不再能绕过频率限制
- **敏感信息泄露**：前端配置 JSON 不再包含 Turnstile Secret，且以 `JSON_HEX_TAG` 输出，防止 `</script>` 提前闭合
- **错误信息泄露**：所有异常统一 `catch (Throwable)`，只回固定文案，不再向浏览器输出堆栈与路径

**修复的功能缺陷**

- **路由类未加载**：`Plugin.php` 补齐 `Util.php` / `Action.php` 的 `require_once`，解决部分环境下路由 500
- **子目录部署 404**：前端 endpoint 由后端注入绝对地址，不再硬编码 `/link_add`
- **无 jQuery 主题表单不显示**：前端改为原生 JS，任何主题都能渲染
- **中文长度误判**：长度校验改用 `mb_strlen`，中文站点名不再被误判超长
- **双重转义**：放弃 `FILTER_SANITIZE_FULL_SPECIAL_CHARS`，入库为原始文本（输出交给主题转义 + 入库前已剔除危险字符）
- **查重失效**：URL 规范化（scheme/host 小写、去端口差异、根路径归一、去 fragment）后再比对，并兼容旧数据的尾斜杠
- **邮件管理链接错误**：通知邮件中的管理链接由 `manage-comments.php` 修正为 `manage-links.php`
- **邮件通知开关恒失效**：依赖判断改为读 `Typecho_Plugin::export()['activated']`，并在未装插件时于配置表单直接拦下
- **`where()` 多占位符崩溃**：查重改为逐条查询，规避 Typecho 单占位符替换限制
- **重复提交**：前端加 `data-submitting` 锁与服务端限流双层保护

**代码优化**

- 抽出 `Util.php`，消除 `Plugin.php` 与 `Action.php` 各自实现一套清洗逻辑的重复
- 删除冗余分支与未被引用的方法（如 `isHttps()`），配置读取统一走 `ErcerLink_Util::options()` 单点缓存
- 前端改为原生 JS，去掉对 `$.ajax` / `$.message` 的强依赖，仅在主题已提供时复用其提示组件

### v1.3.0 (安全与性能增强)

- 🔒 **安全性提升**：修复了多个高危安全漏洞（包括 XSS、CSRF、对象注入等）
- ⚡ **性能优化**：使用 cURL 替代 file_get_contents，降低超时时间，添加查询缓存
- 🛡️ **输入验证**：增强了所有用户输入的验证和过滤机制
- 📝 **输出编码**：对所有动态输出内容进行转义处理
- ✨ **代码重构**：优化了代码结构，提高了可维护性

### v1.2.0

- 🔧 修复了验证码渲染时的类型错误
- 📧 添加了邮件通知功能
- ✨ 优化了验证码交互体验
- 🎨 精简化了代码结构
- 📖 更新了README文档

## 验证

仓库内置 CLI 测试（需要 PHP 8.0+）：

```bash
php tests/runtime_check.php   # 工具层：URL 规范化、清洗、CSRF、同源、限流、IP（47 项）
php tests/action_check.php    # 接口层：23 个提交场景的响应
php tests/plugin_check.php    # 输出层：路由注册、footer 注入、配置校验（35 项）
```

## 原作者

[二C](https://blog.ccdalao.cn/archives/197/)

## 许可证

GNU General Public License v3.0
