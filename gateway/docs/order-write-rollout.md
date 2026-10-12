# 订单写灰度发布与旧 API 绕过边界验收计划（GW-309）

- 状态：**已提出，待评审**（设计文档，未实施任何放量）
- 日期：2026-10-11
- 责任：GW（Gateway 开关与观测）+ TX（Laravel 幂等实现）+ DEP（边缘分流与回滚）
- 关联任务：GW-302（[订单幂等 ADR](./adr-order-idempotency.md)）、GW-303（事务幂等实现）、GW-304（Gateway 适配层，`routes/orders-write.ts` 已实现默认关闭）、GW-305（[checkout/cancel 状态机 ADR](./adr-checkout-cancel.md)）、GW-306（支付回调沙箱）、GW-308（SDK 重试策略）
- 硬性前置：**GW-303/305/306 未验收通过之前，本文档的一切百分比都是零——`GATEWAY_ORDER_WRITE_ENABLED` 不得在生产/预发为 true。** GW-309 设计的是"放量怎么放、怎么停"，不豁免任何前置。

## 1. 目标与边界

**验收原话（task-backlog GW-309）**："新旧调用同一幂等规则；真实沙箱走完、成功禁入条件解除、回滚演练。"

本文档回答四个问题：

1. 灰度流量如何分级放量（1% → 5% → 25% → 100%）；
2. 新路径 `POST /gateway/v1/orders` 与旧路径 `POST /api/v1/user/order/save` 的等价性如何证明（尤其"旧路径绕过 Gateway 直达 Laravel"的边界）；
3. 放量期间与回滚时看什么指标、触发什么动作；
4. 回滚演练具体怎么做、做到什么程度算通过。

**不包含**：checkout/cancel 放量（GW-305 路由尚未实现，本文档的灰度机制为其预留同构扩展位，但不在本次范围）；访客快速购买 GW-310（不同幂等键 scope）。

## 2. 新旧 API 路径对比

| 维度 | 新路径（Gateway） | 旧路径（直连 Laravel） |
| --- | --- | --- |
| 端点 | `POST /gateway/v1/orders` | `POST /api/v1/user/order/save` |
| 实现 | `apps/gateway/src/routes/orders-write.ts`（策略 `orderWrite`） | Laravel `User\OrderController` |
| 默认姿态 | `disabledWrite` → **405、零上游调用**；仅 `GATEWAY_ORDER_WRITE_ENABLED=true` 提升为 `orderWrite` | 现存生产路径，主题/原生前端在用 |
| 认证 | 透传用户 Bearer（Sanctum token 原样），**不接受管理员 token** | 同 |
| 幂等键（权威） | **同一个**：Laravel 以认证 user_id 派生 `SHA-256(user_id ‖ "order.create" ‖ canonical body)`，MySQL `idempotency_keys` 唯一约束 `uk_idem(user_id, operation, key_hash)` | 同 |
| 幂等键（关联） | Gateway 自派生 `X-Idempotency-Key-Hint`（不含 user_id，仅日志关联，非权威）；客户端 `Idempotency-Key` 头必填（1–128 位 URL 安全）且仅透传 | 客户端直连时可无 `Idempotency-Key` 头；幂等判定不依赖它（派生键方案，ADR §3.2） |
| 业务规则 | **零**：不持有管理 token、不重算价格、不看余额、不判优惠券、不做本地去重 | 全部业务规则所在 |
| 限流 | IP + 账号双层（`orderWrite` 策略，`public` limiter 档，fail-open 只读档之外的保守值，GW-208 调参流程） | Laravel 侧原有策略（最终权威） |
| 观测 | Gateway 结构化脱敏日志 + `requestId` + `/metrics` 计数；`X-Idempotency-Key-Hint` 落日志 | Laravel 应用日志 |

**关键点：新旧两侧的幂等权威是同一个（Laravel `idempotency_keys`），不是两套。** 灰度的安全论证完全依赖这一点——切换比例只改变"请求从哪个入口进来"，不改变"谁能成单"。这正是 implementation-plan §3.2 第 7 条"旧路由也必须受同一 Laravel 幂等规则保护"的落地要求。

## 3. 灰度分级与流量分配

### 3.1 分级表

| 阶段 | 流量 | 最小观测时长 | 晋级指标（全部满足） | 回退指标（任一触发） |
| --- | --- | --- | --- | --- |
| G0 预演 | 0%（仅 staging） | 至少 1 个完整演练日 | §6 全部沙箱用例绿；禁入条件清单逐项解除 | 任一禁入条件未解除 → 不放行 |
| G1 金丝雀 | **1%** | 24h | 写成功率 ≥ 旧路径基线 −1pp；p95 增量 ≤ 50ms；幂等冲突率 ≈ 0（见 §5.2）；4xx 零增长 | 任一回退指标超限、或 1 起用户可见重复成单 |
| G2 小流量 | **5%** | 48h | 同上，且覆盖 ≥ 2 个自然日（覆盖隔夜重试/TTL 边界） | 同 G1，或任何资金对账差异 |
| G3 中流量 | **25%** | 72h | 同上，且沙箱回归复跑一次全绿 | 同 G2 |
| G4 全量 | **100%** | — | 旧路径调用量自然衰减（见 §7 退役） | 随时可回退至 0%（§8） |

**禁入条件（G0 → G1 的硬门禁，逐项签署）**：

1. GW-303（Laravel 事务幂等）真实沙箱验收通过：100 并发同键同 body 恰一单、进程杀死重放不产生第二单；
2. GW-305 ADR 评审通过且 checkout/cancel 不在本次放量范围（无路由即无风险）；
3. GW-306（支付回调沙箱）通过：重复回调、异步先到、超时恢复；
4. `GATEWAY_ORDER_WRITE_ENABLED` 默认 false 的 CI 回归测试存在（现状：`disabledWrite` 405 零上游调用）；
5. 回滚演练（§8）至少完成一次，全程 < 5 分钟，记录可追踪。

### 3.2 流量分配机制

**灰度在边缘/反代层做，不在 Gateway 内做。** Gateway 是无状态模块化单体（ADR GW-210），不在代码里内置百分比分流器——理由：

- 分流器进 Gateway = 多副本各自随机 = 真实比例不可控、无审计面；
- 边缘层（1Panel / Caddy / Nginx，DEP 责任）已有按 cookie/UA/权重分流的成熟能力，且与 GW-107"通过开关可以立即禁用 Gateway"的回退模型一致。

分配方案（三选一，DEP 按部署形态决定）：

1. **按用户会话 cookie**（首选）：边缘对新主题客户端写入 `tx_gw_rollout=<阶段>`，命中比例的服务端决定路由到 `/gateway/v1/orders` 或旧路径。同一用户在整个放量期间稳定命中同一侧，**避免同一用户一半请求走新一半走旧导致幂等语义观察困难**（两侧幂等权威相同，所以安全，但观测噪声大）。
2. **按主题 feature flag**（次选）：前端主题 SDK 开关（GW-108 模式），关闭即全回旧路径。适合主题已分发的场景。
3. **按权重随机**（仅 G1 前可用）：无会话概念时按 IP hash 取模；G2 起不建议（会话稳定性差）。

**纪律**：

- 每次调比例是**独立的部署动作**，经 PR 修改边缘配置 + 台账记录；不在 Gateway 代码里放百分比。
- 调比例与回滚是同一个操作的两个方向：比例调到 0% = 等价于关闭 `GATEWAY_ORDER_WRITE_ENABLED`（后者是全局硬开关，前者是平滑过渡）。
- 任一回退事件触发时，**先做 §8.2 的 T+0 止血（比例 → 0 或 flag → false），再查原因**。止血不可等待诊断完成。

## 4. 灰度期间的监控指标

观测同时看两个入口（新旧），比较的是**同一批用户行为**在新旧两侧的表现。指标口径与现有 `/metrics`（GW-214）一致：无敏感字段、带 `requestId`。

### 4.1 核心指标

| 指标 | 口径 | 阈值（晋级/回退） |
| --- | --- | --- |
| 写错误率 | 新路径 5xx+4xx 占比 vs 旧路径基线 | 晋级：≤ 基线 +1pp；回退：> 基线 +3pp 持续 15 分钟 |
| 延迟 p95/p99 | 新路径 vs 旧路径（同操作） | 晋级：p95 增量 ≤ 50ms；回退：p95 增量 > 150ms 持续 15 分钟 |
| 幂等冲突率 | `IDEMPOTENCY_IN_PROGRESS` + `IDEMPOTENCY_CONFLICT` 占比 | 晋级：≈ 0（基线内，重试风暴前为 0）；回退：> 0.5% 或任何 `CONFLICT`（派生键设计下 CONFLICT 应为不可能出现，出现即实现 bug） |
| 重复成单率 | 人工/对账发现同一 logical 请求产生两单 | 晋级：0；回退：**任何 1 起即刻全量回退 + 事故复盘** |
| 资金对账 | 沙箱环境日终对账（订单数 = 扣款次数 = 幂等行 succeeded 数） | 晋级：零差异；回退：任何差异 |
| 401/403/405 | 误拒统计（Bearer 传递、限流误伤） | 晋级：与旧路径同量级；回退：显著增长（限流误伤，GW-208 恢复流程） |
| 429 | 双层限流触发量 | 观察项：G2 起若显著增长需调 GW-208 配额 |

### 4.2 禁止做的事

- **不监控 body 内容、金额明细**到日志（GW-205 脱敏纪律）；只监控计数与状态码。
- **不以"新路径零错误"单独晋级**——必须与旧路径基线对比，否则只是流量没走到。

## 5. 幂等一致性保证与验证

这是 GW-309 的核心验收。分两侧：

### 5.1 两侧同规则（设计保证）

- 权威键全部由 Laravel 以**认证 user_id** 派生（ADR §3.1），Gateway 不持有可信 user_id，新旧入口在 Laravel 层合流到同一函数、同一唯一约束。Gateway 的 `X-Idempotency-Key-Hint` 明确非权威，不一致时 Laravel 派生优先并记差异日志。
- 同一用户先在旧路径成单（幂等行 `succeeded`），再以**相同 body** 走新路径：应命中同一 `idempotency_keys` 行，返回首次同一 `trade_no`（200/201 与首次一致，按 GW-304 映射为 201 + 同一快照）。反之亦然。**这是两侧规则同一性的直接证明。**

### 5.2 冲突率为何应 ≈ 0

派生键方案（ADR §3.2）下，同键不同参数不存在（参数是键的一部分），`IDEMPOTENCY_CONFLICT` 码位保留但**应为不可能出现**。`IN_PROGRESS` 只在 100 并发洪泛时出现。因此灰度期间：任何 `CONFLICT` = 实现缺陷信号；`IN_PROGRESS` 突增 = 客户端重试风暴或陈旧锁接管异常，两者都触发回退。

### 5.3 沙箱一致性矩阵（每阶段晋级前复跑）

| 用例 | 预期 |
| --- | --- |
| 同用户同 body，旧路径 → 新路径 | 一单，同 `trade_no` |
| 同用户同 body，新路径 → 旧路径 | 一单，同 `trade_no` |
| 并发 100 同键同 body（混合两侧入口） | 恰 1 单，其余同一快照或 409 |
| 进程杀死旧路径持有者后新路径重放 | 不产生第二单（陈旧 CAS 接管） |
| 同用户不同 body | 各自成单（键含内容哈希证明） |
| 新路径无 `Idempotency-Key` 头 | 400 零上游调用（GW-304 现状） |
| 新路径传递管理员 token | 401（Gateway 透传语义，Laravel 裁决） |
| 限流/CAPTCHA | 幂等路径不跳过限流（ADR §6 负例） |

## 6. 旧 API 绕过边界测试

"旧 API 绕过 Gateway 直接调用"不是缺陷，是**必须保护的路径**——它永远不会被灰度关掉（渐进迁移，GW-606 才退役）。验收要证明：绕过 Gateway 的直连请求与经 Gateway 的请求，在幂等与资金语义上**不可区分**。

### 6.1 直接调用测试用例（沙箱，`curl` 对 Laravel）

| 用例 | 步骤 | 预期 |
| --- | --- | --- |
| BYP-1 旧路径基线 | 直接 `POST /api/v1/user/order/save`（带 Bearer，无 `Idempotency-Key` 头） | 成功成单；Laravel 派生键落 `idempotency_keys` |
| BYP-2 同 body 直连重放 | 重复 BYP-1 的同一 body | 命中首次 `succeeded`，返回同一 `trade_no`，不产生第二单 |
| BYP-3 直连成单后经 Gateway 重放 | 同用户同 body 走 `POST /gateway/v1/orders` | 命中同一 `trade_no`（两侧同规则的直接证据） |
| BYP-4 Gateway 成单后直连重放 | 反向 | 同上 |
| BYP-5 并发混合洪泛 | 50 次直连 + 50 次 Gateway，同键同 body 并发 | 恰 1 单；唯一约束冲突零数据损坏 |
| BYP-6 伪造客户端身份 | 直连时传他人 user_id 参数（若 schema 接受） | strict schema 拒绝；Laravel 以认证身份为准，不接受客户端 userId |
| BYP-7 提示头篡改 | 经 Gateway 时篡改 `X-Idempotency-Key-Hint` | Laravel 以派生键为准，提示头不一致仅记日志差异，不影响幂等判定 |
| BYP-8 过期间隙 | 等待幂等 TTL（沙箱调短）后同键重放 | 按新请求处理（ADR §6 TTL 行） |

### 6.2 边界结论（评审要点）

1. 绕过 Gateway **不改变交易语义**——这是灰度可以安全放量的前提。若 BYP-3/BYP-4 失败，说明两侧规则漂移，灰度立即冻结。
2. Gateway 不是安全边界，Laravel 是。灰度只改入口，不改权威。因此"旧路径永不被关"与"新路径可回退到 0%"可以并存。
3. 退役旧路径（GW-606）是独立决策，不在 GW-309 范围；本验收只保证并存期语义一致。

## 7. 成功禁入条件的解除流程

"禁入条件解除" = §3.1 的五项硬门禁逐项签署。解除动作（每次晋级）：

1. 检查清单五项目均有证据链接（CI URL / 沙箱报告 / 演练记录）；
2. DEP 修改边缘分流比例为下一档（PR，独立部署动作）；
3. 台账（[development-status.md](./development-status.md)）追加一条：比例、时间、触发人、当前指标快照；
4. 进入观测窗口（§3.1 时长），窗口结束复跑 §5.3 一致性矩阵；
5. 晋级或留在当前档（指标不足时保持，不自动放量）。

G4 全量后，"成功禁入"完成；此后旧路径进入自然衰减观测（为 GW-606 退役输入数据），**G4 本身不触发任何旧路径关闭**。

## 8. 回滚演练

### 8.1 演练目标

- 从任一比例回到 0%（旧路径 100%）**≤ 5 分钟**，全程可追踪；
- 回滚**不触碰** TXBoard 生产 MySQL/Redis 数据卷（development-status §5 纪律）；
- 回滚后 `idempotency_keys` 表自然保留（GC 按 `expires_at` 过期，ADR §10），不影响任何只读接口；
- 进行中的请求：在途写请求可能处于 `in_progress`，陈旧后 CAS 接管（ADR §5.1），不产生悬挂单。

### 8.2 回滚步骤（T+0 止血，按序）

1. **比例 → 0%**（边缘配置热更新，若有）或 **`GATEWAY_ORDER_WRITE_ENABLED=false`**（Gateway 重启生效；`disabledWrite` 姿态 405 零上游调用，重启是幂等的）；
2. 验证：`curl POST /gateway/v1/orders` → 405，零上游调用（CI 有该回归用例）；
3. 观察 15 分钟：旧路径错误率回到基线、无悬挂 `in_progress` 增长；
4. 记录：触发原因、操作人、恢复时间 → 台账 + 事故记录（若发生资金差异）。

### 8.3 演练脚本要求（GW-109 fault-drill 同构，DEP 责任）

- 在 staging 演练：G3 比例运行中执行 8.2，验证耗时与步骤 3 的观察项；
- 故障注入变体：Gateway 进程 kill、Redis 断连（fail-closed 语义）、Laravel 慢响应，各自验证回滚路径不依赖上游健康；
- 演练记录模板：时间戳 / 动作 / 观察 / 结论，进入台账。

### 8.4 判据（演练通过）

| 项 | 通过标准 |
| --- | --- |
| 时效 | 止血完成 ≤ 5 分钟 |
| 数据 | 零数据卷触碰；对账零差异 |
| 恢复 | 旧路径错误率 15 分钟内回基线 |
| 可重复 | 演练可重放（脚本化，非手工记忆） |

## 9. 回退/回滚的触发条件汇总

任一触发 → 8.2 流程：

- 任何用户可见的重复成单或资金对账差异（**最高优先级，即刻**）；
- 写错误率 > 基线 +3pp 持续 15 分钟；
- p95 增量 > 150ms 持续 15 分钟；
- `IDEMPOTENCY_CONFLICT` 出现（实现 bug 信号）；
- 幂等冲突率 > 0.5%；
- 两侧一致性矩阵（§5.3）任一项失败；
- 上游 Laravel 幂等实现回滚（GW-303 迁移回滚）。

## 10. 与既有文档的衔接

- 实现计划 [implementation-plan.md](./implementation-plan.md) §3.2：本文档是其第 7 条（旧路径同规则保护）与 §3.3（场景验收）的放量侧落地。
- [adr-order-idempotency.md](./adr-order-idempotency.md) §10：本文档细化其"Gateway 关闭 flag 即恢复 405"的回滚段。
- [adr-checkout-cancel.md](./adr-checkout-cancel.md)：checkout/cancel 未实现路由，**不在本次灰度**；其 §9 未决问题 1（独立 flag）在实现时按本文档同构机制扩展（资金写建议独立 flag + 独立比例档）。
- 网关契约 [gateway-v1.md](../contracts/gateway-v1.md)：`GATEWAY_ORDER_WRITE_ENABLED` 语义不变，本文档只增加运营侧约束。

## 11. 未决问题（评审输入）

1. 边缘分流的具体组件与配置载体（1Panel vs Caddy vs Nginx）——DEP 决定，影响 §3.2 方案选择。
2. G1 金丝雀是否按用户会话 cookie 固定两侧（推荐）还是允许混流观测——推荐前者，需前端主题配合写入 rollout cookie。
3. 放量指标看板的部署位置（现有 `/metrics` 是 Gateway 侧，旧路径基线需 Laravel 侧指标，DEP/TX 配合）。
4. checkout/cancel 的灰度比例是否与 `order.create` 共用同一套分级，或资金写独立节奏（倾向独立）。
