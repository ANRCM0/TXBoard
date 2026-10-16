# 当前开发进度与下一步


2026-10-16 清理状态：PR #252—#258 已合并。旧 Internal API 隔离入口、旧订阅/Node 链路、隔离 UsageV2 及旧 Plugin/Theme 包入口已清理，native 订单状态机归入 Billing。C3-A 从清理后的 main 开发；详见 [Core 清理](../../development/core-cleanup.md)。
> 更新：2026-10-16 · 已合并主线基线：`015010703794ebd613c1dce5f899e9ed4879cfeb`；本分支基于 C3-A 候选 `d3349569a36528dfa1208ee1a00f711ec5909057`，依赖 PR #260。
>
> C1、C2 已完成合并（PR #247—#251）；C3/C4 正在集成，不是全量完成声明。C3-A 的 PR #260 仍为 OPEN；候选必须通过自身 CI，不能借用基线绿灯。合并不等于生产发布，C3—C5 和完整协议/平台矩阵仍未完成。

## 当前开发范围

本仓推进 Core、Gateway、Node；安装器由独立 TXBoard-Deploy 承担。新增管理/用户前端延期至 TX-F，仅本地提交、不推送；现有 UI/Theme 仍存在。Agent/MCP 已退役，不恢复。

2026-10-16 用户明确：项目仍处开发阶段，尚未接入正式测试部署，不需要旧版本过渡兼容。新功能直接以全新 native 安装为基线，允许同批修改契约及消费者、重建可丢弃开发库；旧站迁移不作为 C3—C5 门禁。不新增 legacy/shadow、双写或降级兜底；现存旧实现按关联业务清理，本次文档更新不声称已删除代码。规则见[Core 演进](core-evolution.md)。

## 已合并交付

| 阶段 | 当前结果 | 证据入口 |
| --- | --- | --- |
| C1 | 不可变购买权益证据，建单/管理员分配及履约消费同一冻结事实 | PR #247；[交付计划](core-completion-plan.md) |
| C2-A / C2-B | 持久安装权威、不可变目录、真实订阅履约/生命周期、共同权益及原周期归属 | PR #248；[Subscription 契约](../../../contracts/v2/core-subscription-v1.md) |
| C2-C1 | 真实独立 Provider 的签发/查询/撤销，持久意图、未知结果恢复与补偿 | PR #249；[发行契约](../../../contracts/v2/credential-provider/native-issuance-v1.md) |
| C2-C2 | Core 签名非秘密承诺、Provider 向授权 Node 交付秘密、Node 验证并应用 | PR #250；[材料交付契约](../../../contracts/v2/credential-provider/native-node-delivery-v1.md) |
| C2 收尾 | 真实持久激活、账户与免费目录 API、即时轮换、客户端直接渲染、同一签名控制访问和计量、SQL 恢复及原周期结算 | [PR #251](https://github.com/ANRCM0/TXBoard/pull/251)；[C2 runtime](../../../contracts/v2/native-c2-runtime-v1.md) |

Core 对已持久接收的 native 批次按原 UsagePeriod 预留已验证字节，待结算和隔离状态均占额度；真实结算同事务释放预留并记入原周期。轮换、撤销后重签及新周期不能冲掉旧周期待处理证据。合法跨授权截止的完整批次保留真实隔离回执，不伪造已结算 ACK。

C2 合并基线只开放零价 Offer。当前有界支持 Linux sing-box VLESS/VMess direct TCP；现有其他协议/内核基础不等于全部 native 链路已完成验收。PHP 插件仍为可信进程内代码，不是通用沙箱。C3/C4 候选变化另列，不覆盖上述历史验收范围。

## 本分支候选

| 范围 | 已实现但仍需集成验收 | 明确未完成 |
| --- | --- | --- |
| 支付与恢复 | 独立支付插件 typed 契约、冻结 dispatch、CNY 真实验签/查询、一次获胜分配、重复/迟到实际收款负债、SQL 履约恢复；免费 checkout 永久幂等 | 付费升级折抵、手续费/非 CNY、网络建单、原路退款 |
| 钱包与退款 | 真实账户/分录、充值、钱包/渠道混合份额、取消释放、累计退款至钱包、只读有界对账 | 已履约续费/换套餐/重置的精准全退；不自动修账 |
| 支付插件生命周期 | 停用不切断原交易验证/查询，持久 mutation fence 与历史 dispatch 阻止破坏性操作 | 历史版本保留/交接未交付；使用过的插件永久保守 pin，不能正常升级/卸载 |
| 正式服务入口 | mTLS 绑定服务身份与独立 actor，固定 operation、永久 workflow 回执、边界审计、Gateway 真实服务客户端/CLI | 并非完整管理员/网络/账户管理操作集；无管理员浏览器通道，完整嵌套输出 schema 待补 |
| Provider 与分发 | 显式 hash-linked 信任转换、链接 bearer 哈希保存/单次交付、独立撤销、接收者绑定有限渲染授权 | 生产代理/PKI 与真实证书轮换、Node 信任分发和新安装/更新/恢复尚未验收 |

付费换套餐暂时明确拒绝，不能以全价替换掩盖缺失的历史余值抵扣；已履约续费等全退也明确拒绝，不能误撤销此前有效权益。上述拒绝保护不是该功能“已完成”。详细证据与关闭条件见[内核成熟度评审](core-maturity-review.md)。

## 验收与记录

C2 最终候选 `ea9d17b` 的 Core、MySQL 8.4、Node Go race 和真实流量四项专项全部通过；源码随 PR #251 合入 `main`。精确检查、修复、审阅与最终合并记录以 PR 为准。仓库中的[收尾 JSON](core-c2-runtime-verification.json)明确是提交时快照，其中 `merged=false` 不是当前合并状态。

数据库、真实进程/流量、race、完整构建与广泛回归继续只在 CI 集中运行；本地只做语法、格式、差异和必要轻量静态检查。大型本地 Linux 环境已按用户要求删除，不重建。没有新代码、实际失败或未解决问题，不重跑已通过矩阵；更新验收记录不制造文档提交触发相同组件复验。

## 下一阶段

1. **集中验证当前 C3/C4 候选。** 将专项/真实 MySQL 竞态纳入既有 CI；修复集成失败，再审阅依赖 #260 的 stacked PR，不合并 main、不发布镜像。
2. **补齐 C3 未完成业务。** 优先来源绑定的升级报价/余值折抵、单次消费与取消/退款联动；明确已履约续费/重置精准全退策略。支付插件版本交接单列关闭条件。
3. **C4 覆盖与安全通道验收。** 固定支持操作集与实际领域/API 对齐，补必要管理能力、完整输出 schema、真实服务消费者与生产信任轮换验收；不恢复已退役的隔离 dispatcher。
4. **C5：安装发布。** 新安装、正常更新、必要恢复、固定版本组合和正式发布说明；复用已验证业务，不按每个协议重复整条链。

现存 legacy/shadow 清理随直接相关实现进行，不另加过渡框架。旧站迁移、HA/Cluster、完整协议/平台及商业扩展另行安排。详细目标见[完整计划](core-completion-plan.md)和[优先级](development-priorities.md)。

## 历史证据索引

[实施台账](implementation-plan.md)、P0/P1 和各 C2 分批报告保留当时结果，仅供追溯。特别是早期“native 保持 initializing”“尚未合并”等结论属于当时切片，不能覆盖已合并 C2 的真实 preflight/activate。历史本地测试记录也不改变当前 CI-only 重型验收策略。

- [P0 账户](basic-account-authority-verification.json)、[Node 有限授权](../../../node/docs/native-authorization-verification.json)、[P1 备份恢复](p1-runtime-backup-verification.json)。
- [C2-A](core-c2a-verification.json)、[C2-B](core-c2b-verification.json)、[C2-C1](core-c2c1-verification.json)、[C2-C2](core-c2c2-verification.json)、[C2 收尾](core-c2-runtime-verification.json)。
