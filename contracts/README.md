# Contracts

`contracts/` 是 TXBoard 与其他组件之间的兼容边界。

- `http/xboard-api-contract-audit.md`：Admin/User Web 与 API 的兼容记录。
- `node-protocol/README.md`：TXBoard 与独立 TX-Node 之间的协议入口。

Laravel routes 是服务器端可执行事实来源；`contracts/` 用于记录跨仓库、跨前端需要稳定维护的契约。

TXBoard 和 TX-Node 不互相导入源码。修改核心节点协议时，应同时更新契约、TXBoard 测试，并在 TX-Node 仓库完成对应兼容验证。
