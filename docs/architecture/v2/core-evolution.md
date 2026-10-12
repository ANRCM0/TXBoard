# Core 增量演进与恢复

> 状态：Target / Draft · 更新：2026-10-12 · 本文定义实施与迁移约束，不提供已可执行的 v2 升级命令。

## 权威来源与演进次序

[Core First ADR](core-boundaries.md) 确定方向，[数据模型](core-domain-model.md)定义不变量，[实施计划](implementation-plan.md)维护 B0—B8 门禁。当前 [OrderService](../../../api/app/Services/OrderService.php)、[UserService](../../../api/app/Services/UserService.php) 和各专用运行时仍是权威；不能增加适配器后再把规则复制成另一套服务。

实施按 `契约 → DTO/model → adapter → runtime delegation → API → UI → migration`。本轮 B0 只形成可核查的规格，不创建表、不修改路由、不迁移秘密、不拆除主题/Agent，不把文档检查算作运行时验收。B1 先做订阅读影子与对账；B2 提供恢复编排；B3/B4 完成流量与 Provider 切换前置；B5/B6 才迁移统一 Web 入口。

## 迁移状态机（Draft）

| 阶段 | 唯一写入权威 | 允许操作与持久检查点 |
| --- | --- | --- |
| `legacy` | 当前 User/Plan/Order/旧流量路径 | 验证备份可恢复、代码/镜像/Schema/配置版本、迁移租约和兼容矩阵 |
| `expanded` | legacy | 只添加表/索引/nullable 兼容列；记录每项 schema 操作及 checksum，不运行删除 |
| `backfilling` | legacy | 固定高水位、分块游标、source hash、映射 ID、校验结果；事务锁定读取业务聚合，增量变化须有可恢复捕获机制 |
| `shadow_verified` | legacy | 新读路径只影子比较；记录差异、不向用户交付新授权，不让两套 Worker 都计费 |
| `cutover_prepared` | legacy，短暂冻结受影响写入 | 停止/排空相关 Worker，持久 fence，清空未捕获差量，最终逐户/财务对账，记录队列水位 |
| `v2_authoritative` | 新 Core 领域服务 | 原子发布 authority_epoch 与路由/Worker fence；旧字段只由新权威生成兼容投影；恢复并验证请求 |
| `contracted` | v2 | 全消费者、旧节点、旧插件和回退窗口关闭后才分批删除兼容代码；删除旧字段是后续独立版本 |

authority_epoch 和 migration run/checkpoint 需要持久化，不能仅依赖进程内开关或 Redis TTL；物理实现与 [Upgrade Manifest](../../../contracts/v2/upgrade-manifest/README.md) 对齐。任何 User/Admin/Agent/订单/充值/重置/节点 Worker/插件写入路径必须遵守同一 fence。无法证明旧插件不直接写 User 字段时，阻断切换或保持明确 legacy 兼容模式。

## 回填清单与保真规则

| 旧来源 | 新目标 | 保真、缺失与对账要求 |
| --- | --- | --- |
| `tx_plan` + prices | PlanRevision、PlanOffer | Plan 流量从 GiB 转为 bytes；价格主单位转换到 minor，以整数十进制方式校验；解析实际 reset setting/timezone；禁止修改既有 Plan |
| `User.plan_id/group_id/expired_at` + 个性化 speed/device/quota | Subscription + Terms + slot | 使用 User 当前实际生效值，不能用当前 Plan 覆盖历史个性化权益；null 到期、已过期、封禁/暂停分别保留；缺 Plan 建迁移例外而非套用默认套餐 |
| `User.transfer_enable/u/d/last_reset_at/next_reset_at/reset_count` | UsagePeriod + migration opening entry | 原 bytes 原计数保留，包括超过配额；无法证明周期起点时标记 `legacy_boundary_unknown`；期初迁移记录不是伪造的逐节点历史 |
| `User.uuid` | Provider-owned legacy import + Subject mapping | 秘密通过限定迁移桥直接进入 Provider；Core 仅保存导入回执、非秘密 provider ref、User→Subscription→Subject 映射；逐个去重且可重试 |
| `User.token` + 私密订阅 URL | SubscriptionLink + legacy URL adapter | 保留旧 URL 的摘要验证与唯一归属；旧明文兼容保留期限明确；不得在移除前强迫全部用户重发链接 |
| `tx_order/payment/callback_no/surplus_order_ids` | 保留 Order + OrderItem + legacy PaymentAttempt 标记 | 当前已完成订单不开通第二次；旧价格/权益没有历史证据时标记 `legacy_snapshot_unknown`；不使用当前 Plan 编造旧合同 |
| `User.balance/commission_balance`、充值、佣金及折抵 | money accounts + opening journals | 在一致快照边界建立每用户每货币期初 journal，对应 migration clearing account；保留原流水用于审计，不伪造历史复式分录；待支付预扣/取消恢复须显式归属 |
| 旧 `tx_traffic_batch`、未决队列/节点 pending batches | legacy dedupe + usage migration boundary | 不清除去重键；识别已结算/已入队/未确认范围，禁止同一历史 batch 在新 Worker 再计费 |

回填每个聚合有唯一 migration key（run/类型/legacy ID），同 key 同 source hash 返回同结果，不同 hash 重新比较并记录变化。迁移 map 是来源证据，不是另一业务写入入口。schema 扩展后发生的合法 legacy 变更必须通过同事务 outbox/change capture 或最终暂停写入与重读得到；B1 实现前选定一种可恢复方案，不能依赖“两次普通写入都应该成功”。

## 对账报告

迁移报告必须列出总数、逐对象差异、未决来源和阻断项，不只提供总余额相等。逐 User 比较套餐/组/永久期限/有效期、剩余量及原始上下载量、reset 锚点和个性化限额、有效 slot 数、旧 token/UUID 的唯一归属。逐订单比较 owner、trade_no、状态、购买类型、已履约、折扣/余额/折抵/手续费/实际应付。

财务按 currency/account 比较期初余额、迁移中增量及期末余额；未知货币、负数异常、重复 callback、无法解释的旧预扣余额均阻断。流量逐订阅周期比对旧 counters 和 opening entries + 新增量，同时核验批次去重水位。缺 Plan、未知历史快照等例外可以明确标记保留，但若影响授权、余额或重复结算，则不能用人工勾选“通过”掩盖。

## 权威切换与失败恢复

1. **切换前失败**：authority 保持 legacy；恢复同 run/checkpoint，只补未完成块。清理影子数据须限定本次 migration key 且未被业务引用，保留备份和原表。
2. **切换准备时失败**：读取 fence 与持久 checkpoint，确认哪套 Worker 已停止、队列水位和写入是否冻结；保持服务处于明确维护/受限状态，验证后继续或解除准备，不凭进程退出推断成功。
3. **v2 已写入后失败**：先停止受影响写入，保护数据库/outbox/节点重发材料；旧镜像不能直接回到 legacy 权威。优先 forward fix；回退仅在旧投影完整、反向增量转换经过验证、待处理批次无重复和回退 Schema 兼容时进行。
4. **数据库恢复**：恢复整个一致性边界（业务 DB、迁移检查点、必要 Provider 存储和版本），校验支付商外部已发生交易/节点未确认批次后再放行。外部收款不能被 DB 回滚撤销；重新核验并补偿。不得用 `migrate:rollback` 删除已产生业务数据的新表。
5. **跨进程分歧**：旧 Octane/Worker 缓存、旧 Admin 写接口、旧 Plugin hooks 必须通过 authority_epoch 拒绝 stale 写入；从 checkpoint 恢复对应版本 Worker。领域事务产生的 outbox 重发通过业务幂等键完成，不重复履约或通知状态转换。

允许保持 legacy 读兼容，不允许 legacy 和 v2 各自成为独立写权威。新 Core 产生旧字段投影的延迟或失败须可观测，并阻断依赖该投影的旧授权路径；不能让旧节点继续使用过期配额/撤销状态。

## 验收证据与待验证决定

本地 B0 交付的设计不是生产数据或恢复验收。B1/B2 进入切换前需提交匿名化真实数据副本演练、逐户/逐币对账、并发测试及失败恢复记录。测试应覆盖进程 kill、DB commit 前后中断、队列不可用、节点批次重发、外部支付已成功但本地恢复、Provider 导入部分完成和 stale Worker。

尚待实现阶段验证：Terms transition 的物理索引及有效区间查询、MySQL/SQLite 并发约束差异、change capture 方案、旧 site currency/timezone 取值、epoch 的原子发布及 worker drain 机制、Provider 秘密导入通道、晚到旧节点的保守归属规则。任何决定落地先更新相应契约和测试，再修改运行时。
