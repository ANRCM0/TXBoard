# Contracts

`contracts/` 是 TXBoard 与其他组件之间的兼容边界。

- `http/xboard-api-contract-audit.md`：Admin/User Web 与 API 的兼容记录。
- `node-protocol/README.md`：TXBoard 与独立 TX-Node 之间的协议入口。
- `plugin-package/README.md`：TXBoard 与独立插件仓库之间的 Plugin Package v1 / Admin Bridge 契约。\n- `module-package/README.md`：Module Platform v1 的统一 Module Manifest、Capability 与运行时 Descriptor 契约。

Laravel routes 是服务器端可执行事实来源；`contracts/` 用于记录跨仓库、跨前端需要稳定维护的契约。

TXBoard 和 TX-Node 不互相导入源码；独立插件也不应依赖 TXBoard Admin 源码。修改核心节点协议时，应同时更新契约、TXBoard 测试，并在 TX-Node 仓库完成对应兼容验证。修改 Plugin Package / Admin Bridge 时，应保证已发布插件的兼容窗口，并更新参考插件验证。修改 Module Package v1 时，应同步更新 JSON Schema、PHP DTO/value object 与契约测试，避免多语言实现漂移。
