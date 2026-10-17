# 插件开发入口

只维护当前插件框架，不保留旧插件兼容指南。包格式以 [Plugin Package v1](../../contracts/plugin-package/README.md)及其 [JSON Schema](../../contracts/plugin-package/schema-v1.json)为准；真实加载与生命周期行为以 [PluginManager](../../api/app/Services/Plugin/PluginManager.php)和 [AbstractPlugin](../../api/app/Services/Plugin/AbstractPlugin.php)为准。

## 按扩展类型开始

- **通用功能插件**：先按 Package 契约声明 `config.json`、`Plugin.php` 和整数 `package.schema: 1`；仅使用当前源码中真实存在的扩展点，不把旧示例当作仍可调用的 API。
- **支付插件**：按[当前支付插件开发指南](payment-plugin-development.md)和 [starter](../../contracts/plugin-package/examples/payment-starter/)实现显式 typed 接口。Core 保留订单、收款事实、钱包、账本、退款和履约权威，插件只处理渠道协议；不使用旧 `PaymentInterface`、支付注册 Hook 或旧 `pay/notify` 入口。
- **插件管理界面**：简单配置使用宿主 Schema；独立 Admin app 按 Package 契约预构建 `admin/dist`，并遵守 [Admin Bridge](../../contracts/admin-bridge/README.md)。TXBoard 不在安装插件时执行 npm/Vite。

## 开发前

- 保持 manifest、声明的当前契约、代码版本及真实消费者一致；不为旧包补字段或增加兼容转发。
- API 使用已注册的 `/txapi/*` 身份和授权边界；插件动态路由还须核对实际加载器与路由注册。不要复制旧 `/api/v1/plugin/*` 或仅凭 `web/admin` middleware 声明管理员授权。
- 为路由、权限、生命周期和插件失效后的旧请求补充测试。
- 支付插件须保留冻结验证、停用恢复及金融证据 pin；有历史 dispatch 的包不能因停用或等待超时就破坏性升级、卸载。
- 插件是可执行的可信 PHP 代码，**不是安全沙箱**；升级与回滚前保存完整快照。

[Core 与扩展边界](../architecture/v2/core-extension-boundaries.md) · [扩展安全基线](../security/README.md) · [贡献规范](../../CONTRIBUTING.md)
