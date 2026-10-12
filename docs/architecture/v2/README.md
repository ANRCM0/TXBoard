# Core First v2：B0 架构与实施基线

> 2026-10-12。方向：Core First。B0 基线与多条隔离切片已实现，实际提交、验证和剩余门禁见 [实施台账](implementation-plan.md)。各整阶段仍待逐项验收。

这轮建设从现有 Laravel、前端、Gateway、Node 和 Deploy 演进。Core 是唯一业务与数据权威；先补齐契约、兼容检测和恢复能力，再切换写入与调用，最后清理没有消费者的旧实现。

## 首批六项交付

| 任务 | 本批产物 | 实施边界 |
| --- | --- | --- |
| CORE-001 当前业务与数据库清查 | [完整静态清单](baseline-inventory.md)、[JSON](baseline-inventory.json)、[基线验证](baseline-verification.md) | 静态扫描不代替真实路由注册表、数据库与动态调用图 |
| CORE-002 Core v2 领域边界 | [Core First ADR 与边界](core-boundaries.md)、[运行角色](runtime-topology.md) | 当前权限和 v1 包格式继续生效 |
| CORE-003 数据模型 v2 | [模型规范](core-domain-model.md)、[增量演进](core-evolution.md)、[B1.1 实现](subscription-shadow-implementation.md) | 新模型只用于隔离回填，不切换生产权威 |
| CORE-004 Credential Provider v1 | [插件平台](plugin-platform.md)、[Provider 契约](../../../contracts/v2/credential-provider/README.md) | 合成发行/撤销进程已实现；生产 OS 隔离、秘密交付与 Node 收敛待验收 |
| CORE-005 版本和升级契约 | [兼容矩阵](compatibility-matrix.md)、[Upgrade Manifest](../../../contracts/v2/upgrade-manifest/README.md) | 订阅、商品、财务单机影子 CLI 已实现；完整生产切换仍为 Target |
| CORE-006 Core Internal API v1 | [Internal API](../../../contracts/v2/core-internal-api/README.md)、[SC-v2 边界](../../../contracts/v2/secure-channel/README.md) | 本地只读 mTLS 服务身份已实现；管理员 Gateway 与正式 SC-v2 未启用 |

## 当前需先解决的跨仓差异

1. Gateway Native 适配已改为真实 `/txapi/*` 与 DTO/envelope；Internal API 逐 operation 迁移和 User/Admin 完整授权仍需验证。
2. 默认 Node 仍使用 v1 queued ACK；隔离 v2 已有 SQL 持久回执、整数结算和 spool，runtime 租约、完整计数覆盖及权威交接未启用。
3. User 仍持有套餐、配额、用量、到期时间和 UUID；当前影子 Schema、商品/财务证据和 Terms 转换尚未接管 live writer。
4. 当前 PHP 插件仍在进程内运行；固定可信合成 Provider 能发行/撤销并保留清理证据，不能代替生产隔离和 Node 撤销 ACK。
5. 单机影子升级已有签名、持久检查点和提交后恢复；部分 DDL 仍需调查，集群 fencing 与生产 writer switch 尚未实现。

详见[逐项兼容矩阵](compatibility-matrix.md)。设计先行不解除数据库、支付、权限和生产发布的验收要求。

## 推进与来源

[实施台账与下一批](implementation-plan.md) 记录依赖、验收与退出条件；[假设及证据](assumptions.md) 单列尚未验证的事项。跨仓引用本目录及 [v2 契约](../../../contracts/v2/README.md)，当前行为仍引用对应 Current 契约。

静态清单刷新/漂移检查命令会记录在 [CORE-001 清单](baseline-inventory.md) 中。本地验证结果和未运行项目以 [baseline-verification.md](baseline-verification.md) 为准，不能从文档数量推断 B0 或生产验收全部完成。
