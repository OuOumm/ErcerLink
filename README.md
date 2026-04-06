# ErcerLink
handsome快速申请友链插件

## 功能特性
- 🚀 快速申请友链功能
- 🔒 Cloudflare Turnstile 验证码支持
- 📧 邮件通知功能（需安装 CommentToMail 插件）
- 📱 响应式设计
- ⚡ PJAX 支持
- 🎨 简洁美观的表单样式

## 安装方法
1. 下载插件，解压后将文件夹重命名为 `ErcerLink`
2. 上传到 Typecho 插件目录 `usr/plugins/`
3. 在 Typecho 后台启用插件

## 配置说明

### 基础设置
- **使用说明**：插件使用指南

### 资源加载
- **CDN加速地址**：默认使用本地JS文件，如需CDN加速，请填写完整URL

### 安全设置
- **Turnstile Site Key**：Cloudflare Turnstile 前端站点密钥
- **Turnstile Secret Key**：Cloudflare Turnstile 后端验证密钥

### 通知设置
- **邮件通知**：开启后，当有新链接提交时，将通过 CommentToMail 插件发送邮件通知
  - 提示：需安装并启用 [CommentToMail](https://github.com/ououmm/CommentToMail) 插件才能使用此功能

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

### 3. 验证码配置
1. 前往 [Cloudflare Dashboard](https://dash.cloudflare.com/) 获取 Turnstile 密钥
2. 在插件配置中填写 `Turnstile Site Key` 和 `Turnstile Secret Key`
3. 保存配置后，验证码将自动启用

## 依赖说明
- **jQuery**：用于DOM操作和AJAX请求
- **CommentToMail**（可选）：用于发送邮件通知
- **Cloudflare Turnstile**（可选）：用于验证码验证

## 效果图
![效果图](https://github.com/OuOumm/ErcerLink/assets/43441064/c2581a2c-7a30-42fa-a667-5382a0001c60)

## 更新日志
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

## 原作者
[二C](https://blog.ccdalao.cn/archives/197/)

## 许可证
GNU General Public License v3.0
