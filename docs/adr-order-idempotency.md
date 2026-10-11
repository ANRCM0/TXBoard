# ADR：订单写入幂等（GW-302）

- 状态：**已提出，待评审**
- 日期：2026-10-11
- 责任：TX（Laravel 主仓库实现）+ GW（Gateway 适配）
- 关联任务：[task-backlog.md](./task-backlog.md) GW-302（本文档）、GW-303（事务幂等实现）、GW-304（Gateway 适配）、GW-305（交易状态机）、GW-306（支付回调）、GW-309（灰度验收）
- 前置依赖：M2 多实例安全基础（限流、可信入口、Redis 安全态）已完成代码交付

## 1. 背景与问题

- 订单/支付写入当前**永久禁用**：`apps/gateway/src/routes/orders.ts` 的 `POST /gateway/v1/orders` 固定返回 405（`disabledWrite` 路由策略，零上游调用）。任何交易副作用都不可能经由 Gateway 发生。
- Gateway 上游白名单（`services/upstream.ts` 的 `allowlistedPaths`）中**不存在**任何订单创建/支付端点，只有只读的 `userOrders / userOrderDetail / userOrderStatus / userPaymentMethods`。
- M3 目标是在 TXBoard（Laravel + MySQL）实现订单创建幂等后，再经 Gateway 灰度开放 `POST /gateway/v1/orders`（GW-303→GW-304→GW-309）。
- 核心风险：重复下单导致重复扣款/重复交付；100 并发同键同 body 必须只产生一单；进程重启、超时、失败重试不得产生第二单；同键不同 body 必须拒绝（防止键碰撞被利用改单）。

## 2. 决策（Decision）

采用**服务端派生幂等键 + MySQL 唯一约束 + 状态机**的方案：

1. **幂等键 = userId + operation + 请求内容哈希**，由 Laravel 服务端从已校验的请求体派生，客户端不可指定或猜测。
2. **持久化存储窗口 24 小时**（可配置），存于 MySQL 业务表，而非 Redis。
3. **冲突语义**：同键同参数返回首次的同一结果；同键不同参数（哈希不同）视为不同请求，正常处理；同键重放且状态为 `in_progress` 时拒绝并提示重试；状态为 `failed_retryable` 时允许重试。
4. **状态机三态**：`in_progress` / `succeeded` / `failed_retryable`。
5. **幂等判断发生在 Laravel 事务内**，Gateway 只做透传与错误映射（GW-304），不持有、不复制业务幂等规则。

## 3. 幂等键格式

### 3.1 结构

```
idem_key := SHA-256( user_id ‖ ":" ‖ operation ‖ ":" ‖ canonical_json(validated_body) )
```

- `user_id`：Laravel 认证用户的整数 ID（字符串化）。访客/未认证请求不在本 ADR 范围（GW-310 快速购买单独评审，且仍必须绑定账号）。
- `operation`：受控枚举，白名单化，不接受自由文本：
  - `order.create`、`order.checkout`、`order.cancel`
- `canonical_json`：**校验之后、进入业务逻辑之前**对请求体做规范化：
  - 键按 Unicode 码点升序排序，去空白分隔，UTF-8 编码；
  - 未知字段已在 strict schema 层拒绝（Gateway 与 Laravel 均为 strictObject），不存在"多一个字段绕过校验"的路径；
  - 数值不做字符串化歧义处理以外的任何改动，浮点金额一律以分为单位的整数传入（TXBoard 现有金额约定）。

存储列使用 `key_hash`（64 位十六进制字符串）作为唯一约束载体；原始参数**不落库**（只落状态与结果快照），避免明文 body 入库带来的合规面。

### 3.2 为什么不由客户端提供 Idempotency-Key

- 客户端键（Stripe 式）需要服务端按"键+账号"存储并防御跨账号占用、需要额外的键格式校验与滥用面；
- 本方案用 `userId + operation + body 哈希` 已经天然满足"同人同操作同参数=幂等"，且无需客户端改造、无需 SDK 新参数；
- **保留扩展**：若未来网关/主题需要显式键（如离线重放），可在后续 ADR 追加"客户端键优先、缺失时回退派生键"，不阻塞当前设计。

## 4. 持久化数据结构（TXBoard Laravel 迁移）

表：`idempotency_keys`（MySQL/InnoDB）

| 列 | 类型 | 说明 |
| --- | --- | --- |
| `id` | bigint unsigned PK | 自增 |
| `user_id` | bigint unsigned NOT NULL | 键的一部分 |
| `operation` | varchar(32) NOT NULL | 枚举字符串（`order.create` 等） |
| `key_hash` | char(64) NOT NULL | `SHA-256(user_id‖operation‖canonical body)` 十六进制 |
| `state` | enum('in_progress','succeeded','failed_retryable') NOT NULL | 状态机当前态 |
| `trade_no` | varchar(64) NULL | 成功时关联的交易号 |
| `result_snapshot` | json NULL | 首次成功的响应 DTO 快照（脱敏，仅订单公开字段） |
| `expires_at` | timestamp NOT NULL | `created_at + TTL`，GC 依据 |
| `created_at` / `updated_at` | timestamp | 审计 |

**唯一约束（核心）**：

```sql
UNIQUE KEY uk_idem (user_id, operation, key_hash)
```

该约束是整个方案的地基：

- 并发 100 次同键同 body 时，只有一个 `INSERT` 成功，其余在唯一键冲突处串行化，走"读状态 → 返回已有结果/拒绝"路径；
- 无 `SELECT` 预检竞态，无 Redis 丢状态风险（M3 Go/No-Go 明确：**Redis 去重不能替代 Laravel 持久化幂等**）；
- 索引同时服务于按 `expires_at` 的定期 GC（可加二级索引或按分区，视数据量再定）。

**快照大小上限**：`result_snapshot` 强制 ≤ 4KB（与 Gateway `maxResponseBytes` 量级匹配），超限只存 `trade_no + state`，客户端用只读接口补拉详情。

## 5. 状态机

```
                ┌─────────────────────────────────────────────┐
                │                                             │
   INSERT ──► in_progress ──事务提交成功──► succeeded            │
                │  │                          （锁定，返回快照）  │
                │  └──业务失败（可重试）──► failed_retryable ───┘ 下次同键到达时
                │                               允许重新进入 in_progress
                └──进程崩溃/连接中断──► 停留在 in_progress（陈旧锁）
```

| 当前状态 | 同键到达时的行为 |
| --- | --- |
| `in_progress`（新鲜） | 拒绝：`409 IDEMPOTENCY_IN_PROGRESS`，响应带 `Retry-After`（默认 2s，指数建议客户端退避） |
| `in_progress`（陈旧，`updated_at` 超过 `idem_in_progress_timeout`，默认 15 分钟） | 视为持有者已死亡：以 `updated_at` 为 CAS 守卫接管为 `in_progress`（条件 UPDATE，失败则转拒绝路径），允许重试 |
| `succeeded` | 返回**首次的同一结果**（快照 + 同一 `trade_no`），HTTP 状态与首次一致 |
| `failed_retryable` | 允许重试：删除/重置该行并重新执行（保留首次失败计数用于观测） |
| 已过期（`expires_at` 已过） | 视为不存在，重新开始完整流程；GC 定时任务物理删除 |

状态迁移仅允许：

```
∅ → in_progress → succeeded（终态）
∅ → in_progress → failed_retryable → in_progress → succeeded
```

不允许 `succeeded → 任何其他状态`；不允许跳过 `in_progress` 直接写入终态。所有迁移在**同一事务**内与业务写操作一起提交。

### 5.1 陈旧 in_progress 的处理（崩溃恢复）

- 持有者崩溃时事务回滚，行可能停留在 `in_progress`（若插入在事务外先行落库）或整体回滚消失（若整体在同一事务）。**设计选择：先 INSERT 幂等行再开业务事务**，保证唯一约束先占位；业务失败时显式回写 `failed_retryable`。
- 崩溃后行滞留 `in_progress`：超过 15 分钟（可配）视为陈旧，CAS 接管，避免用户永久卡死。
- 15 分钟参数与 24h TTL 互相独立：TTL 管"结果复用期"，timeout 管"锁租约"。

## 6. TTL 与并发决策（评审要点）

| 决策点 | 选择 | 理由 |
| --- | --- | --- |
| 存储介质 | MySQL 唯一约束（持久化） | 任务矩阵明确 Redis 去重不能替代持久化幂等；Redis 异步故障转移可丢数据 |
| TTL | 24h（`idempotency.ttl_hours` 配置） | 覆盖用户重试窗口与跨天支付回调恢复窗口；短于 24h 会漏掉"隔夜重试"，长于 24h 使冲突误判窗口变大 |
| 冲突返回 | 同键同参数 → 200 + 首次快照；不同参数 → 哈希不同即不同键（正常处理） | "同键不同参数"在本设计下不会发生：参数是键的一部分 |
| in_progress 并发 | 409 + Retry-After，客户端退避重试 | 不做服务端排队（避免把 BFF 变成任务队列）；客户端 SDK 对幂等 409 自动重试（GW-308） |
| 陈旧锁 | 15 分钟 CAS 接管 | 崩溃/部署中断后用户不会被永久拒绝 |
| 过期 GC | Laravel 定时命令 + `expires_at` 索引，批量删除 | 与业务写入解耦，删除失败不影响幂等正确性（过期行天然视为不存在） |
| CAPTCHA / 限流 | **不因幂等绕过**：幂等判定在限流与 CAPTCHA 之后 | 幂等是正确性机制，不是免检通道（GW-203/209 的限额与挑战必须仍生效） |

## 7. Gateway 侧适配方案（GW-304 预览）

Gateway 的约束不变：**不持有管理 token、不复制业务规则、不做本地去重**。适配仅是"透传 + 映射"：

1. **入参**：解除 `disabledWrite` 之前，`POST /gateway/v1/orders` 仍 405；开放时请求体经 strict schema 校验（未知字段 400），Bearer 必填（401），限流与 CAPTCHA 照常。
2. **幂等键传递**：Gateway 计算 `key_hint = SHA-256(operation ‖ canonical_json(body))`（**不含 user_id**，Gateway 不持有可信 user_id，只有 Bearer），通过 `X-Idempotency-Key-Hint` 头透传给 Laravel；Laravel 侧用认证 user_id 重新派生最终键，**提示头仅用于日志关联与快速比对，不作为权威**。若提示头与 Laravel 派生不一致，以 Laravel 为准（日志记差异）。
3. **错误映射**（`GatewayFailureCode` 增补）：

| Laravel 响应 | Gateway 映射 | 说明 |
| --- | --- | --- |
| 409 `IDEMPOTENCY_IN_PROGRESS` | `409 IDEMPOTENCY_IN_PROGRESS` + `Retry-After` | 透传上游语义，不吞掉 |
| 409 `IDEMPOTENCY_CONFLICT`（预留：同键不同参数，若未来启用客户端键） | `409 IDEMPOTENCY_CONFLICT` | 当前派生键设计下不会出现，保留字典位 |
| 上游 4xx/5xx（非幂等） | 现有映射不变（502/504/429 等） | 不改动既有错误字典 |
| 上游超时/断连 | `UPSTREAM_UNAVAILABLE` 502/504 | 客户端**不得自动重试 POST**，除非收到明确幂等信号（SDK 默认不自动重试写请求，GW-308） |

4. **响应**：成功时返回上游订单 DTO（快照即此 DTO）；`result_snapshot` 的脱敏职责在 Laravel，Gateway 不再二次加工。
5. **路由与开关**：新增 `orders.create` 上游 operation（`allowlistedPaths` 增补），默认 flag `off`，与 GW-309 灰度一致；开启前必须 GW-303/305/306 验收通过。

## 8. 被否决的备选方案

| 备选 | 否决理由 |
| --- | --- |
| Redis `SET NX PX` 去重（复用 GW-202 引擎） | Redis 异步故障转移可丢状态；M3 门槛明文要求持久化幂等；与支付资金安全不匹配 |
| 仅业务列唯一索引（如 `user_id + plan_id + period`） | 无法表达优惠券/折扣码组合差异，误拒合法订单或漏放重复订单 |
| 客户端提供 Idempotency-Key | 需管理跨账号键空间与格式校验，滥用面更大；派生键零客户端成本已满足需求 |
| 数据库乐观锁（版本号列）代替唯一约束 | 仍需要唯一约束防止并发双插入，冗余 |
| 幂等放在 Gateway（BFF 持有状态） | 违反 Gateway 不复制业务规则的边界；多实例下退化为 Redis 方案 |

## 9. 验收标准（输入 GW-303）

1. 100 并发同键同 body：恰有 1 单落库，其余 99 返回同一 `trade_no` 或 `409 IDEMPOTENCY_IN_PROGRESS`；唯一约束冲突零数据损坏。
2. 重启/进程杀死后重放同键：不产生第二单（新鲜 in_progress → 409；陈旧 → 接管后仍只一单）。
3. 同键不同参数：视为不同键，各自成单（证明键含内容哈希）。
4. 失败重试：`failed_retryable` 后同键可再次成功；`succeeded` 后同键永远返回首次结果。
5. TTL 到期后同键：按新请求处理。
6. 幂等路径不跳过限流/CAPTCHA 的负例测试存在。
7. Gateway 侧（GW-304）：无幂等提示头/头不一致时以 Laravel 派生为准；`IDEMPOTENCY_IN_PROGRESS` 透传不降级为 502。

## 10. 风险与回滚

- **风险**：`result_snapshot` 存了不该存的数据 → 快照字段白名单评审 + ≤4KB 上限 + 只读接口补拉。
- **风险**：`in_progress` 租约误判（长事务 >15min）→ 租约可配，且业务事务必须短小；支付回调异步路径不进本表（GW-306 单独设计）。
- **回滚**：本 ADR 与 GW-303 的迁移可独立回滚（删表/删 flag）；Gateway 侧关闭 `orders.create` flag 即恢复全 405，不动任何现有只读接口。

## 11. 未决问题（评审输入）

1. `operation` 是否需要覆盖"取消订单"（`order.cancel`）与"改单"？当前枚举已预留，范围以 M3 只放开 `order.create` 起步。
2. 访客/快速购买（GW-310）是否沿用同一张表？建议沿用，但 `user_id` 前增加 scope 前缀，评审确认。
3. `idempotency.ttl_hours` 的运维配置载体（Laravel config vs 部署 env），评审确认。
