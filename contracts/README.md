# Contracts

`contracts/` 是 TXBoard 与其他组件之间的兼容边界。

- `http/txboard-api-compatibility-audit.md`：Admin/User Web 与 API 的兼容记录。
- `http/module-registry-v1.md`：Module Platform 的只读 Admin Registry HTTP 契约。
- `http/module-management-v1.md`：Module Platform 的受控 Admin lifecycle 管理 HTTP 契约。
- `node-protocol/README.md`：TXBoard 与独立 TX-Node 之间的协议入口。
- `plugin-package/README.md`：TXBoard 与独立插件仓库之间的 Plugin Package v1 / Admin Bridge 契约。
- `module-package/README.md`：Module Platform v1 的统一 Module Manifest、Capability 与运行时 Descriptor 契约。
- `module-lifecycle/README.md`：Module Lifecycle v1 的统一操作、结果、错误与 runtime delegation 契约。
- `theme-package/README.md`：Theme Package v1、主题包安全、canonical active-theme 与 Theme Runtime 生命周期契约。
- `admin-navigation/README.md`：Module Admin Navigation Registry 的统一导航投影与宿主路由契约。
- `admin-bridge/README.md`：Admin Bridge v2 可选宿主服务协议，并保持 Bridge v1 兼容。
- `agent-ops-module/README.md`：Agent Ops 作为 system Module 的能力与有界运行时健康投影契约。
- `agent-self-connect/README.md`：Agent Self-Connect v1 基线；`agent-self-connect/v2.md`：短期 pairing code、Redis TTL、一次性 token exchange 与一句话接入契约。

Laravel routes 是服务器端可执行事实来源；`contracts/` 用于记录跨仓库、跨前端需要稳定维护的契约。

TXBoard 和 TX-Node 不互相导入源码；独立插件也不应依赖 TXBoard Admin 源码。修改核心节点协议时，应同时更新契约、TXBoard 测试，并在 TX-Node 仓库完成对应兼容验证。修改 Plugin Package / Admin Bridge 时，应保证已发布插件的兼容窗口，并更新参考插件验证。修改 Module Package 或 Theme Package 契约时，应同步更新 JSON Schema、PHP DTO/value object、示例与契约测试，避免实现漂移。
