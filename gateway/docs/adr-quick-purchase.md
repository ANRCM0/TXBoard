# ADR：快速购买一体化（GW-310）

- 状态：**已提出，待评审**
- 日期：2026-10-11
- 责任：TX（Laravel 主仓库实现：快速购买编排、认领上下文、订单绑定）+ GW（Gateway 适配：会话令牌校验、编排路由透传、幂等键传递）+ SDK（恢复页与状态轮询）
- 关联任务：[task-backlog.md](./task-backlog.md) GW-310（本文档）；依赖 GW-302（[订单幂等 ADR](./adr-order-idempotency.md)）、GW-303（事务幂等）、GW-304（Gateway 写适配，已实现）、GW-306（支付回调沙箱）、GW-307（HPKE 注册/邮箱验证码，已实现）、GW-309（灰度验收）；下游 GW-311（公开支付目录）、GW-312（交易邮件模板）
- 前置依赖：M2 多实例安全基础（限流、可信入口、Redis 安全态）与 M3 交易闭环（幂等 + 状态机 + 支付沙箱）先行完成；**GW-310 属于附加能力，只有交易与邮件/验证码策略独立验收后才能 opt-in**

## 1. 背景与问题

- 需求（借鉴点 C，[airbuddy-design-reference.md](./airbuddy-design-reference.md) §4）：访客在同一个界面里完成「选套餐 → 注册 → 邮箱验证 → 受控认证 → 下单」，体验上接近"免登录购买"。
- 但体验目标**不得**转化为安全让渡：TXBoard 的邮箱验证、邀请码、注册风控、Laravel CAPTCHA 权威必须全部生效；不得调用管理员代建用户接口；老用户不得被冒名认领；幂等与支付回调纪律不得弱于 GW-302/305/306。
- 当前代码边界：
  - `POST /gateway/v1/orders`（GW-304）**强制要求用户 Bearer + 客户端 `Idempotency-Key`**，访客请求 401/400 被零上游调用拒绝——快速购买不能改造这条既有路由，必须新增独立编排路由，避免降低现有交易入口的防线。
  - `secure/auth/register` 与 `secure/auth/email-code`（GW-307）已存在且默认关闭（HPKE + Redis + `GATEWAY_ACCOUNT_WORKFLOWS_ENABLED`），快速购买应**复用**而非新建认证通道。
  - Gateway 无任何业务幂等状态，`idempotency_keys` 表是唯一权威（GW-302 §4）。
- 核心风险：
  1. **冒领**：访客仅凭"邮箱 + 优惠码"取得老用户身份（等价于账号接管）；
  2. **绕过验证**：为了一体化体验偷偷关闭邮箱验证/邀请码；
  3. **重复下单/重复扣款**：重放、关闭页面、重复点击、重复支付回调；
  4. **账号枚举**：快速购买流程泄露"该邮箱是否已注册"。

## 2. 决策（Decision）

采用 **Laravel 编排 + Gateway 透传 + 会话购买令牌（Purchase Ticket）** 方案：

1. **新增单一编排端点**：`POST /gateway/v1/quick-purchase`（Gateway 侧），默认 `disabledWrite`（405），flag `GATEWAY_QUICK_PURCHASE_ENABLED` 显式开启，且**仅在 `GATEWAY_ORDER_WRITE_ENABLED=true`（GW-304 已灰度）时才允许评估开启**——快速购买不得先于普通下单上线。
2. **Gateway 只做编排透传**：一处校验（HPKE 信封 + 限流 + CAPTCHA 挑战 + Idempotency-Key 格式），然后转发用户自己的 sealed 载荷给 Laravel `quickPurchase` 编排端点。Gateway **不持有管理 token、不重算价格、不判断账号存在性、不做本地去重**。
3. **幂等沿用 GW-302**：operation 枚举新增 `quickpurchase.checkout`（附录 A.1），键 `SHA-256(user_id ‖ operation ‖ canonical_json(validated_body))`，同一张 `idempotency_keys` 表、同一三态状态机、同一 24h TTL。**不新建第二套幂等机制**。
4. **注册成功 ≠ 已支付**：编排端点返回 `{ trade_no, auth_token, order_state: 'pending' }`；订单支付由 GW-306 的支付回调独立路由完成并验签。快速购买返回的 `auth_token` 是**一次性签发、用户本人、无管理权限**的普通登录令牌，与"订单是否付款"无关。
5. **老用户路径（冒领防线）**：编排端点在**同一事务内**判断邮箱状态并走两条互斥分支（§4）：未注册 → 现场注册（邀请码/邮箱验证/风控全开）；已注册 → **不自动登录、不返回任何 token**，仅返回 `LOGIN_REQUIRED`（409/401 语义，附录 A.3），引导用户走受控登录（HPKE 登录 + GW-203/209 防护）后，用携带的 `Idempotency-Key` 再对普通 `POST /orders` 下单。两种分支对同邮箱返回**字节级一致**的响应外壳（§5.3），不存在"猜邮箱换身份"的信息差。
6. **邮箱验证强制完成**：若 TXBoard 站点策略开启邮箱验证，现场注册分支返回的 `auth_token` 为**未验证态令牌**（`email_verified=false`），下单前 Laravel 强制要求 `email_code` 已验证（即 §4 分支 B），否则返回 `EMAIL_VERIFICATION_REQUIRED`（428）并携带 `challenge_id`（复用 GW-209 挑战机制）。**绝不为快速购买关闭验证**。

## 3. 流程设计

### 3.1 主流程（新用户，事务内分支 A）

```text
访客选套餐（公开目录，GW-311）
  → 客户端 HPKE 封装 { email, password, invite_code?, email_code?,
                       plan_id, period, coupon_code?, turnstile_token? }
  → POST /gateway/v1/quick-purchase （sealed，自带 Idempotency-Key 头）
     Gateway：信封校验 → 限流(register/checkout 双层) → CAPTCHA 挑战消费
              → Idempotency-Key 格式校验 → 透传 X-Idempotency-Key-Hint
     Laravel：同一事务内
       1. 校验站点注册策略（邀请码/邮箱验证/风控/Laravel CAPTCHA 权威）
       2. INSERT idempotency_keys（quickpurchase.checkout, key_hash）
       3. 判断邮箱：未注册 → 创建用户（普通权限，无任何代管）
                    已注册 → 回滚，返回 LOGIN_REQUIRED（§4）
       4. 若站点要求邮箱验证且未验证 → EMAIL_VERIFICATION_REQUIRED + challenge
       5. 派生 auth_token（一次性签发，普通登录令牌）
       6. 调 userOrderCreate（幂等，与 GW-304 同一上游 operation）
       7. 事务提交，幂等行 succeeded + result_snapshot
  → 返回 { trade_no, auth_token, order_state: 'pending', payment: <GW-311 目录 DTO> }
  → 客户端携带 auth_token 走 GW-306 支付回调（与普通用户完全同一路径）
```

### 3.2 老用户路径（分支 B，受控认领）

```text
POST /gateway/v1/quick-purchase（老邮箱）
  → 409 LOGIN_REQUIRED（不泄露是否已注册，见 §5.3）
  → 客户端转受控登录：POST /gateway/v1/secure/auth/login（HPKE + GW-203/209）
  → 登录成功后用同一 Idempotency-Key 调 POST /gateway/v1/orders
     （普通下单入口，幂等键 → Laravel 同一幂等层，同键返回同一结果）
  → 老用户走完与普通用户一致的支付回调路径
```

**要点**：老用户的"认领"= 用自己的密码走正常登录，**不是**"填邮箱即取得身份"。Gateway/Laravel 任何环节都不接受"邮箱 + 其他凭据"直接换 token。

## 4. 事务内分支判定（冒领防线核心）

Laravel `quickPurchase` 的**单一事务**内（顺序固定，任一步失败整体回滚）：

| 步骤 | 判定 | 失败动作 |
| --- | --- | --- |
| 1 | 邀请码要求时 `invite_code` 有效 | `INVITE_CODE_REQUIRED`(422)，回滚 |
| 2 | 邮箱验证要求时 `email_code` 有效 | `EMAIL_VERIFICATION_REQUIRED`(428) + challenge |
| 3 | 风控/CAPTCHA（Laravel 权威，GW-203/209 计数器已在上游推进） | `CAPTCHA_REQUIRED`(428)/`RATE_LIMITED`(429) |
| 4 | `users` 表按邮箱 SELECT（`SELECT … FOR UPDATE` 或唯一索引冲突捕获） | 已注册 → 回滚 → `LOGIN_REQUIRED` |
| 5 | INSERT 幂等行（步骤 2 之前或同时，保证唯一约束先占位，GW-302 §5.1 设计） | 唯一冲突 → 走幂等语义（§5.1） |
| 6 | INSERT 用户（普通权限）+ INSERT 订单 | 任一失败 → 回滚，幂等行 `failed_retryable` |
| 7 | 事务提交 | — |

- **禁止**：分支 B 在任何情况下签发 token、返回 `auth_token`、或暴露"该邮箱已注册"的可区分信号（响应外壳与分支 A 的 428/409 分支字节级一致，仅错误码位不同——见 §5.3 与附录 A.3）。
- 用户创建与订单创建在同一事务：不存在"注册成功但无订单"的中间态，重放同键要么全部发生要么全部回滚。

## 5. 幂等设计（复用 GW-302，不新建机制）

### 5.1 键与冲突语义

- 键：`idem_key := SHA-256(user_id ‖ "quickpurchase.checkout" ‖ canonical_json(validated_body))`。
  - 分支 A 的 `user_id`：Laravel 事务内**先解析/先建**用户 ID 再派生键（新用户 = 即将创建的用户 ID，用 `INSERT users` 返回 ID 或预占位）；分支 B 老用户键由 Laravel 用其真实 user_id 派生。
  - 客户端不可指定键，`Idempotency-Key` 头仅透传用于日志关联（与 GW-304 语义完全一致）。
- 冲突语义（与 GW-302 §2.3 逐条对齐）：

| 幂等行状态 | 同键到达行为 |
| --- | --- |
| `in_progress`（新鲜） | 409 `IDEMPOTENCY_IN_PROGRESS` + `Retry-After: 2` |
| `in_progress`（陈旧 >15min） | CAS 接管重试（GW-302 §5.1） |
| `succeeded` | 200 + **首次同一快照**（同一 trade_no、同一 auth_token 快照；auth_token 本身只存哈希引用，重放返回**首次快照中记录的 token 引用/重签等价结果**，见 §5.3 说明） |
| `failed_retryable` | 允许重置重试（保留失败计数用于观测） |
| 已过期（>24h） | 按新请求处理 |

### 5.2 场景矩阵（验收输入）

| 场景 | 期望行为 |
| --- | --- |
| 重复点击提交（同键同参数） | 第二键 409 IN_PROGRESS 或 200 同快照；**仅一单** |
| 关闭页面后重开重放（同键） | 200 同快照；不产生第二单 |
| 注册成功、下单后断网 | 客户端凭 `Idempotency-Key` 重试；`succeeded` 返回同快照（含 trade_no） |
| 100 并发同键 | 恰 1 单落库；其余 99 得 409 或同一 trade_no |
| 进程重启后重放 | 不产生第二单（新鲜 409 / 陈旧 CAS 接管） |
| 支付成功但前端超时 | 客户端用只读 `GET /orders/:tradeNo/status` 补拉真实状态（GW-301 只读接口），**不重放下单** |
| 重复支付回调 | 由 GW-306 回调验签 + 订单状态机（0→1 终态锁定）吸收，快速购买不改变该路径 |
| 幂等路径 | **不绕过限流/CAPTCHA**：限流与挑战判定在幂等之前（GW-302 §6 末行同款约束） |

### 5.3 快照脱敏与 token 引用

- `result_snapshot` 字段白名单（≤4KB）：`trade_no`、`order_state`、`plan_id`、`period`、`amount_cents`、`coupon_applied`、`created_at`。
- `auth_token` **不落快照明文**（快照只存 `token_ref`，即令牌哈希的前 8 位 + 签发时间）。重放同键返回 200 时：
  - 若首次会话令牌仍在有效期内且请求来源指纹一致 → Laravel **重新签发同一用户的等价令牌**（同 user、同 scope），客户端拿到的仍是可用令牌；
  - 不返回首次 token 明文，避免快照表成为凭据库。
- 响应外壳统一（分支 A 成功 / 分支 B 拒绝 / 验证未完成）：错误码位在附录 A.3 冻结，**响应体不含"该邮箱是否已注册"的可区分字段**——存在性泄漏的唯一例外是 `LOGIN_REQUIRED` 错误码本身，评审需确认该码位对枚举攻击的信息量可接受（缓解：对未注册但同样触发验证失败的场景也返回 428 而非 409，制造时序/码位噪声，见 §8 风险）。

## 6. Gateway 侧适配（默认关闭）

新增路由策略（`config/policies.ts` 增补，评审冻结后再实现路由代码）：

| 项 | 设计 |
| --- | --- |
| 路径 | `POST /gateway/v1/quick-purchase` |
| 默认策略 | `disabledWrite`（405，零上游调用），与 `POST /orders` 当前形态一致 |
| 启用条件 | `GATEWAY_QUICK_PURCHASE_ENABLED=true` **且** `GATEWAY_ORDER_WRITE_ENABLED=true`（boot 时校验，否则路由缺失 = 404） |
| 入参 | HPKE sealed 信封（复用 GW-307 `secure` 封装与 AAD 绑定，operation = `quickpurchase`）；Bearer **不需要**（访客场景） |
| 限流 | 账号维度（规范化邮箱）+ IP 维度，register/checkout 档位（fail-closed，Redis 断连 503） |
| CAPTCHA | GW-209 一次性挑战（purpose=`checkout`，15min TTL 档），消费在调用上游之前 |
| 幂等 | `Idempotency-Key` 头格式校验（GW-304 `validateIdempotencyKey`，复用 `middleware/idempotency.ts`），`X-Idempotency-Key-Hint` 自派生透传；Gateway 零本地去重 |
| 上游 operation | `quickPurchase`（`services/upstream.ts` `allowlistedPaths` 增补，随 flag 关闭时路由不存在） |
| 错误映射 | 附录 A.3 冻结码位；上游 4xx/5xx 透传语义，不吞、不降级为 502 |
| 响应 | Laravel 编排结果 DTO（快照即此 DTO），Gateway 不再二次加工 |

**边界重申**：Gateway 不持有管理 token、不代建用户、不复制业务规则、不做本地去重——与 GW-304 三条规则逐字同构。

## 7. 状态机与支付路径

- 订单业务态沿用 GW-305（0 pending → 1 paid → 3 completed，终态锁定）；快速购买创建的订单与普通订单**同表同状态机**，无特殊订单类型、无特殊支付通道。
- 支付回调（GW-306）完全不经 Gateway、不经本端点：Laravel 验签 → 状态条件 UPDATE（`status=0` 守卫）→ 幂等表吸收重复回调。快速购买只是**多了一条订单创建入口**，不触碰支付路径任何一行。
- 快速购买返回的 `auth_token` 与支付结果解耦：`order_state: 'pending'` 不等于已支付；客户端恢复页必须轮询只读状态接口（GW-301），不得把"拿到 token"显示为"购买成功"。

## 8. 被否决的备选方案

| 备选 | 否决理由 |
| --- | --- |
| 管理员代建用户（AirBuddy 原实现） | 明文密码邮件风险 + 越权创建 + 审计黑洞；任务矩阵明确禁止（借鉴点 C 不变量 1） |
| "填邮箱即取得身份"的免密认领 | 等价于账号接管；邮箱可枚举、邮箱可被冒填；任务验收原话禁止（不变量 3） |
| 为快速购买关闭邮箱验证/邀请码 | 直接违反任务验收条件与借鉴点 C 不变量 1 |
| Gateway 侧编排（BFF 调 register→login→create 三次） | Gateway 持有凭据编排、跨请求状态、无事务性；违反"不复制业务规则"边界 |
| 快速购买改造现有 `POST /orders`（免 Bearer） | 降低既有交易入口防线；GW-304 验收要求 Bearer 必填，不能为访客开口子 |
| 新开一张幂等表 / 客户端 Idempotency-Key 权威化 | GW-302 已论证唯一约束 + 派生键是地基；两套幂等必漂移 |
| auth_token 存 `result_snapshot` 明文 | 快照表变凭据库；改为 token_ref + 重签等价令牌（§5.3） |

## 9. 验收标准（输入 GW-309 灰度）

1. **E2E 全绿**：访客一体化完成注册+下单；站点开启邮箱验证/邀请码时分别正确执行（未验证 → 428 + challenge；邀请码缺失 → 422）。
2. **冒领防线**：老邮箱请求快速购买 → `LOGIN_REQUIRED`，**零 token 签发**；老用户走受控登录后同 `Idempotency-Key` 下单 → 幂等层返回同一结果；无任何路径可"凭邮箱换身份"。
3. **幂等**：100 并发同键恰 1 单；重放/关闭页面/断网恢复返回首次快照；进程重启后重放不产生第二单；TTL 到期后按新请求。
4. **支付回调**：重复回调、支付成功前端超时、失败重试三类场景与普通用户同一路径全绿（GW-306 沙箱证据）。
5. **默认关闭**：flag 未设置时 `POST /gateway/v1/quick-purchase` 为 405（零上游调用）；`GATEWAY_QUICK_PURCHASE_ENABLED=true` 但 `GATEWAY_ORDER_WRITE_ENABLED` 未设置时路由**不存在**（404，boot 校验）。
6. **无绕过**：限流/CAPTCHA 在幂等判定之前的负例测试存在；`secure/auth/register` 旧路径不被快速购买路径旁路。
7. **账号枚举**：分支 A/B 响应外壳字节级一致（除 §5.3 说明的码位噪声），不存在时序可区分的分支。

## 10. 风险与回滚

- **风险**：`LOGIN_REQUIRED` 码位成为枚举 oracle → §5.3 噪声缓解 + 评审确认；若不可接受，改为统一 428 让客户端无法区分"该登录了"与"该验证了"。
- **风险**：快照重签令牌导致会话混乱（用户已在别处登录）→ 令牌 scope 限定 + 签发审计日志；必要时重放只返回 `token_expired` 让客户端重走登录。
- **风险**：编排事务 >15min（不可能，事务短小；支付在事务外）→ 沿用 GW-302 陈旧锁 CAS。
- **回滚**：本 ADR 与 Laravel 编排端点可独立回滚（删 flag / 删 `quickPurchase` allowlist）；Gateway 关闭 flag 即恢复 405，不动 GW-304/305/306 任何既有路径。

## 11. 未决问题（评审输入）

1. 分支 B 是否返回结构化引导（`next_action: 'login'`）而不只是裸错误码？倾向返回最小引导字段但**不含**存在性信号。
2. 快速购买是否需要独立 CAPTCHA purpose（`checkout` 档 15min TTL 是否够）？
3. `auth_token` 重签策略：同用户等价令牌 vs 要求重新登录？影响恢复页 UX。
4. operation 命名：`quickpurchase.checkout` 是否与未来 `order.checkout` 语义混淆？备选 `order.create.quick`。

## 附录 A：契约冻结草案（评审后入 `contracts/`）

### A.1 幂等 operation

```
order.create          （GW-302/304，已冻结）
order.checkout        （GW-305，已冻结）
order.cancel          （GW-305，已冻结）
quickpurchase.checkout（GW-310，本文档新增；派生键与前三者同表同机制）
```

### A.2 sealed 请求体（`quickPurchase` operation，AAD 绑定）

```ts
z.strictObject({
  email: z.email().max(254),
  password: z.string().min(8).max(1024),
  invite_code: z.string().max(128).optional(),
  email_code: z.string().regex(/^\d{6}$/).optional(),
  plan_id: z.number().int().positive(),
  period: z.enum(['month_price', 'quarter_price', 'half_year_price', 'year_price',
    'two_year_price', 'three_year_price', 'onetime_price', 'reset_price']),
  coupon_code: z.string().min(1).max(64).optional(),
  turnstile_token: z.string().max(4096).optional(),
  recaptcha_v3_token: z.string().max(4096).optional(),
  recaptcha_data: z.string().max(4096).optional(),
})
```

（字段是 GW-307 `registerSchema` 与 GW-304 `orderCreateSchema` 的并集；strict，未知字段 400。）

### A.3 错误码位冻结（Gateway 字典增补）

| 上游语义 | Gateway 映射 | HTTP |
| --- | --- | --- |
| 老用户邮箱 | `LOGIN_REQUIRED` | 409 + `Retry-After: 2`（语义：请走受控登录后下单） |
| 邮箱未验证 | `EMAIL_VERIFICATION_REQUIRED` | 428 + `X-Captcha-Challenge`（复用 GW-209 挑战壳） |
| 邀请码无效/缺失 | `INVITE_CODE_REQUIRED` | 422 |
| 幂等在途 | `IDEMPOTENCY_IN_PROGRESS` | 409 + `Retry-After: 2` |
| 业务拒绝（库存/风控等） | `ORDER_REJECTED` | 422（不回显上游原文） |
| 上游 4xx/5xx 非幂等 | 现有映射不变 | — |
