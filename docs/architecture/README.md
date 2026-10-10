# TXBoard Architecture — 文档入口

## 当前（CURRENT）与目标（TARGET）

- **CURRENT**：现行 Laravel API 路由仍包含 `/api/v1`、`/api/v2`；Module Platform v1、Plugin/Theme/Agent Ops 与 Node contracts 已运行。
- **CURRENT 2026-10-10**：第一方 React Admin 后台路由已全部迁至 `/txapi/admin/{admin_path}/*`、旧 V2 Admin 注册树已删除（PR #163–#179），P1–P4 Node/账本/用户功能服务端已实现；**V1/V2 非后台外部运行接口、Agent Ops、订阅和支付商旧回调仍有效**。完整 CI 与合成恢复已通过，外部 TX-Node/支付商真实联调未验收。
- **TARGET**：逐服务版本化跨仓协议、完成 Node/Agent/独立 Gateway 适配与真实部署验收，再审议非管理 V1/V2 退役。

## 外部开发者先看（当前协议）

- [完整 Laravel HTTP 路由扫描及 CI Artifact](http-route-inventory.md) — 从 route:list 自动生成，CI 对关键方法、鉴权边界和旧管理端退役进行守卫。
- [外部服务 CURRENT API / WS 适配手册](../../contracts/http/external-adapter-current.md) — Node、Agent、Gateway、支付、订阅、插件及管理员权限的真实协议边界。
- [真实部署与发布验收](../operations/release-staging-acceptance.md) — PR #180 已提供合成备份恢复 CI，但不等于生产验收。
- [Issue #168 发布阻塞清单](https://github.com/ANRCM0/TXBoard/issues/168) — 未开发/未适配/未联调的逐项权威状态。

## TXBoard Native 开发主线

- [P0 执行记录与基线](p0-execution.md) — 已实现的审计工具、兼容回归与剩余生产验收。
- [P2 阶段验收记录](p2-completion.md) — P2-A–E 合并 PR、实际 TXAPI 路由、测试证据与未退役的兼容入口。
- [P4 阶段验收记录](p4-completion.md) — TXBoard Native Node v1、流量分批查询优化、自动化证据与外部 Node 联调后置。
- [P3 阶段验收记录](p3-completion.md) — 核心交易、双 webhook、共享 checkout、审计工件及支付商外部联调待验收项。
- [Legacy Retirement Batch 1](legacy-retirement-batch-1.md) — LR-01～LR-03 的工作范围、危险边界、测试与删除门禁。
- [Legacy Retirement Batch 3](legacy-retirement-batch-3.md) — LR-07～LR-09 原生安全认证、一次性令牌和邮箱恢复工作包；LR-04～LR-06 可在各自 PR 中追踪。


1. [详细重构与开发方案](txboard-native-development-plan.md) — 架构、API、领域、优化、P0–P7、数据和发布。
2. [Gateway 集成 ADR](gateway-integration.md) — 双仓职责、/txapi/bff/v1、部署、G0–G5。
3. [BFF Target Contract](../../contracts/http/txapi-bff-target-v1.md) — 未来协议，未上线。
4. [遗留依赖盘点](legacy-inventory-and-work-packages.md) — 首批审计、跨仓库消费端和 PR 分类。
5. [TXAPI Contract v1](../../contracts/http/txapi-target-v1.md) — 已实现 P1/P2 接口与剩余目标路径，部署状态取决于实际版本。
6. [运行/发布手册](../operations/README.md)；[安全基线](../security/README.md)；[扩展运行时](extension-runtime-policy.md)。

## 保留的已实现架构文档

- [Module Platform v1](module-platform-v1.md)、[Module Platform 开发指南](module-platform-development-guide.md)。
- [Agent Ops / MCP](agent-ops.md)、[Agent Ops 开发指南](agent-ops-development-guide.md)。
- [跨仓库现行契约](../../contracts/README.md)、[插件开发指南](../../api/docs/en/development/plugin-development-guide.md)、[AGENTS 开发规则](../../AGENTS.md)。

## 不变的边界

TXBoard 是 Control Plane，TX-Node 是独立 Agent/Data Plane。Vue/React 消费 HTTP 契约、域层拥有业务真相；Module Runtime 不复制 PluginManager/ThemeService，Agent/MCP 不绕过权限、审批、审计；节点安装与升级仍由 TX-Node Installer 负责。
