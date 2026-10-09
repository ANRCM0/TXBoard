# TXBoard Architecture — 文档入口

## 当前（CURRENT）与目标（TARGET）

- **CURRENT**：现行 Laravel API 路由仍包含 `/api/v1`、`/api/v2`；Module Platform v1、Plugin/Theme/Agent Ops 与 Node contracts 已运行。
- **TARGET**：统一正式 API `/txapi/*`，分阶段降低 Xboard 残留并优化业务、安全与性能；**P1/P2 部分原生接口已在 main 中实现，其他目标仍按阶段推进**。

## TXBoard Native 开发主线

- [P0 执行记录与基线](p0-execution.md) — 已实现的审计工具、兼容回归与剩余生产验收。
- [P2 阶段验收记录](p2-completion.md) — P2-A–E 合并 PR、实际 TXAPI 路由、测试证据与未退役的兼容入口。


1. [详细重构与开发方案](txboard-native-development-plan.md) — 架构、API、领域、优化、P0–P7、数据和发布。
2. [Gateway 集成 ADR](gateway-integration.md) — 双仓职责、/txapi/bff/v1、部署、G0–G5。
3. [BFF Target Contract](../../contracts/http/txapi-bff-target-v1.md) — 未来协议，未上线。
4. [遗留依赖盘点](legacy-inventory-and-work-packages.md) — 首批审计、跨仓库消费端和 PR 分类。
3. [TXAPI Contract v1](../../contracts/http/txapi-target-v1.md) — 已实现 P1/P2 接口与剩余目标路径，部署状态取决于实际版本。
4. [运行/发布手册](../operations/README.md)；[安全基线](../security/README.md)；[扩展运行时](extension-runtime-policy.md)。

## 保留的已实现架构文档

- [Module Platform v1](module-platform-v1.md)、[Module Platform 开发指南](module-platform-development-guide.md)。
- [Agent Ops / MCP](agent-ops.md)、[Agent Ops 开发指南](agent-ops-development-guide.md)。
- [跨仓库现行契约](../../contracts/README.md)、[插件开发指南](../../api/docs/en/development/plugin-development-guide.md)、[AGENTS 开发规则](../../AGENTS.md)。

## 不变的边界

TXBoard 是 Control Plane，TX-Node 是独立 Agent/Data Plane。Vue/React 消费 HTTP 契约、域层拥有业务真相；Module Runtime 不复制 PluginManager/ThemeService，Agent/MCP 不绕过权限、审批、审计；节点安装与升级仍由 TX-Node Installer 负责。
