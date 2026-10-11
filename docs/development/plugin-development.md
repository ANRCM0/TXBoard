# 插件开发入口

推荐先阅读现有、已包含示例的 [Plugin Development Guide](../../api/docs/en/development/plugin-development-guide.md)。该指南目前保留在 `api/docs/en/development/`，避免因为本次文档整理改变现有第三方引用；目录名为历史路径，不能据此推断全文已经是英文。

## 开发前

- 确认插件代码、manifest 与 Package 版本兼容。
- 参考 [Plugin Package v1](../../contracts/plugin-package/README.md) 和 [Admin Bridge](../../contracts/admin-bridge/README.md) 定义前后端边界。
- 使用独立插件仓库预构建 `admin/dist`，TXBoard 不在安装时执行 npm/Vite。
- 为路由、权限、生命周期和插件失效后的旧请求补充测试。
- 插件是可执行的可信 PHP 代码，**不是安全沙箱**；升级与回滚前保存完整快照。

[插件开发指南全文](../../api/docs/en/development/plugin-development-guide.md) · [扩展安全基线](../security/README.md) · [贡献规范](../../CONTRIBUTING.md)