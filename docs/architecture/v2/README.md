# Core First v2：B0 架构与实施基线

> 2026-10-12。方向：Core First。B0 本地基线与 B1.1 隔离订阅影子切片已实现；其余 v2 能力按各契约区分 Current / Target。

这轮建设从现有 Laravel、前端、Gateway、Node 和 Deploy 演进。Core 是唯一业务与数据权威；先补齐契约、兼容检测和恢复能力，再切换写入与调用，最后清理没有消费者的旧实现。

## 首批六项交付

| 任务 | 本批产物 | 实施边界 |
| --- | --- | --- |
| CORE-001 当前业务与数据库清查 | [完整静态清单](baseline-inventory.md)、[JSON](baseline-inventory.json)、[基线验证](baseline-verification.md) | 静态扫描不代替真实路由注册表、数据库与动态调用图 |
| CORE-002 Core v2 领域边界 | [Core First ADR 与边界](core-boundaries.md)、[运行角色](runtime-topology.md) | 当前权限和 v1 包格式继续生效 |
| CORE-003 数据模型 v2 | [模型规范](core-domain-model.md)、[增量演进](core-evolution.md)、[B1.1 实现](subscription-shadow-implementation.md) | 新模型只用于隔离回填，不切换生产权威 |
| CORE-004 Credential Provider v1 | [插件平台](plugin-platform.md)、[Provider 契约](../../../contracts/v2/credential-provider/README.md) | 新生命周期、租约与隔离运行尚未实现 |
| CORE-005 版本和升级契约 | [兼容矩阵](compatibility-matrix.md)、[Upgrade Manifest](../../../contracts/v2/upgrade-manifest/README.md) | B2.1 隔离影子 CLI 已实现；完整生产切换仍为 Target |
| CORE-006 Core Internal API v1 | [Internal API](../../../contracts/v2/core-internal-api/README.md)、[SC-v2 边界](../../../contracts/v2/secure-channel/README.md) | 尚未开放内部服务身份或管理员 Gateway |

## 当前需先解决的跨仓差异

1. Gateway 固定调用 `/api/v1/*` 并解码 legacy envelope；当前 Core 应用接口仅注册 `/txapi/*`。不能只改 URL 后认定对接完成。
2. 当前 Node 的 ACK 表示入队，客户端可在 ACK 后清除 spool；Core 还没有 v2 持久接收状态与订阅级结算。
3. User 仍持有套餐、配额、用量、到期时间和 UUID；订阅模型回填须读取真实单位、周期和订单状态。
4. 当前 PHP 插件在进程内运行；v2 隔离 Runtime 和凭据撤销是新增能力，不能用现有卸载逻辑代替。
5. 当前升级器在已尝试迁移后要求人工数据库恢复；v2 检查点与可恢复状态机尚未落地。

详见[逐项兼容矩阵](compatibility-matrix.md)。设计先行不解除数据库、支付、权限和生产发布的验收要求。

## 推进与来源

[实施台账与下一批](implementation-plan.md) 记录依赖、验收与退出条件；[假设及证据](assumptions.md) 单列尚未验证的事项。跨仓引用本目录及 [v2 契约](../../../contracts/v2/README.md)，当前行为仍引用对应 Current 契约。

静态清单刷新/漂移检查命令会记录在 [CORE-001 清单](baseline-inventory.md) 中。本地验证结果和未运行项目以 [baseline-verification.md](baseline-verification.md) 为准，不能从文档数量推断 B0 或生产验收全部完成。
