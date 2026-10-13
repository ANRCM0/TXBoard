# Core Internal API v1

> 状态：Target / Draft，B0 设计基线。完整 B5 尚未交付；已有默认关闭、仅 local/testing 的 [当前只读切片](current-readonly-slice-v1.md)、[公共展示读取](local-public-display-v1.md)、另行隔离的 [订单创建切片](local-user-order-create-v1.md)、[原生账户登录](local-isolated-login-v1.md)和[内存邮件验证码](local-isolated-email-code-v1.md)和[合成账户注册](local-isolated-registration-v1.md)和[密码恢复](local-isolated-password-reset-v1.md)和[当前用户会话](local-isolated-sessions-v1.md)，其已注册路径和验收范围以 Current 文件为准。

当前两个 User operation 的显式 `contract_version:"2"` 扩展见 [B5.2 User DTO](current-user-dto-v2.md)，保留 v1 与 Admin，不代表 Draft 其他操作已开放。

B5.3 增量 [有界订单列表](current-order-list-v2.md) 复用原 owner/query 与分页，仅扩展 local/testing User 服务白名单；生产私网与其余 Operation 门禁不变。

B5.14 的独立 [Gateway/SDK 会话消费者](local-isolated-session-consumer-v1.md)接通本地当前用户会话 list/revoke/logout；额外开关、HPKE 与 Redis 门禁不因 Core operation 存在而自动开放。

## 1. 业务与安全权威

Gateway 负责 Web 传输、SC-v2、请求结构及 DTO 适配。Core 负责登录验证、账户归属、管理员身份、RBAC、价格、优惠、余额、订单状态、节点权限、幂等与审计。Gateway 不使用共享管理员 Token，不缓存业务授权结果为最终判定，不访问业务 MySQL 或发布节点控制消息。

目标内部入口原拟为 `POST /internal/v1/operations/{operation}`，只监听受控私网；它是显式 Operation 分发器，不是任意 URL、控制器名或方法调用代理。当前只读切片依 AGENTS.md 改为已注册的 `POST /txapi/internal/v1/operations/{id}`，默认关闭且限制 local/testing；它不改变其余 Draft 操作的未实现状态，也不能通过公网反代 wildcard 暴露。

服务身份和最终用户身份必须同时成立：

1. mTLS 校验受信 CA、有效期、吊销策略及服务 SAN allow-list；User Gateway 与 Admin Gateway 使用不同身份和 Operation allow-list。
2. 登录等匿名 Operation 仍需有效服务身份，由 Core 验证密码、验证码、限流和账户状态。
3. 用户 Operation 由 Core 验证透传的用户会话凭据，并从凭据得到 actor；Gateway 提供的 `user_id`、role、tenant 或 `X-Actor-*` 不具授权效力。
4. 管理 Operation 由 Core 验证管理员会话、RBAC 和当前动态管理路径约束；普通用户、Node、Agent、Provider 凭据均拒绝。
5. 服务证书是调用者证明，不能替代上述用户校验。禁止 Gateway 伪造 actor 的授权断言；未来若引入委托令牌须另立版本化契约。

若 TLS 在受控代理终止，应用仅接受已配置受信代理的验证结果，代理必须删除所有客户端同名身份头。来源 allow-list、代理与应用之间的保护、证书校验失败拒绝均要有负向验收；不允许仅相信公网送来的 `X-Client-Cert`。

## 2. Operation Registry

每个 Operation 必须声明：`id`、`version`、`security_domain`、`actor_kind`、`service_allowlist`、Core permission、输入/输出 Schema、请求大小、超时、限流、幂等模式、错误集合、审计脱敏规则及对应领域服务。声明只描述策略，执行时重新验证。未知字段、未知 Operation、错误安全域直接拒绝。

| 拟议 Operation | Actor | Core 权威 | 幂等/重试 |
| --- | --- | --- | --- |
| `identity.session.create` | anonymous | 登录、CAPTCHA、会话发行 | 不自动重试；会话发行与限流由 Core 控制 |
| `identity.admin-session.create` | anonymous → admin | 管理员登录、CAPTCHA、路径 | 与用户登录分开策略；不自动重试 |
| `catalog.offers.list` | anonymous/user | 当前可售版本、销售方案 | 只读、可有界重试 |
| `subscription.mine.list` | user | actor 的订阅与权益 | 只读；跨用户对象拒绝 |
| `subscription.links.issue` | user | 链接发行及凭据 Provider 委托 | Core 持久幂等 |
| `commerce.orders.create` | user | 权益目标、价格快照、订单 | Core 持久幂等 |
| `commerce.orders.checkout` | user | 订单状态、余额、支付尝试 | Core 持久幂等；外部支付有独立稳定 attempt ID |
| `commerce.orders.cancel` | user | 订单取消和冲突判定 | Core 持久幂等 |
| `billing.wallet.get` | user | 余额与账本投影 | 只读，不泄露其他账户 |
| `platform.modules.list` | admin | 只读 Registry、权限过滤 | 只读，不能加载时修改生命周期 |
| `platform.modules.lifecycle` | admin | 固定生命周期命令、审批及适配器 | 固定 allow-list、Core 持久任务 ID |
| `network.nodes.list` | admin | Node/Machine 范围与秘密过滤 | 只读，有界分页 |

这是第一轮命名和安全分类，并非全部已存在 API 的等价替代。B5 逐项映射当前 Controller → Domain/Service → Operation；未经映射和契约测试的操作保持不可用。Agent、Node HTTP/WSS、支付商/Telegram 回调仍由各自专用身份和协议处理，不进入通用 Web Operation 分发。

## 3. 请求与 DTO

请求示例（值均为非秘密样例；Authorization 通过专门头传递，不复制到日志或消息正文）：

```json
{
  "contract_version": "1",
  "request_id": "8af246b9-9404-40f4-910e-d1054363c841",
  "deadline_at": 1791763260,
  "input": {
    "plan_offer_id": "42",
    "target_subscription_id": "103",
    "quantity": 1
  }
}
```

`request_id` 只用于关联，必须校验格式且不代表幂等或身份；Core 为审计保留自己的可信执行 ID。`deadline_at` 是 UTC Unix 秒，Core 夹紧到 Operation 最大时限。所有模型 ID 为十进制字符串；金额最小单位与 bytes 为十进制字符串，符号和范围逐字段约束，常规价格与原始流量非负，不能先转成 JS Number。数量和分页等明确有界值可用安全整数。核心输入不接受客户端总价、balance、actor role 或计费主体归属覆盖。

敏感字段按明确白名单输出，User DTO 不输出数据库 password、UUID、订阅私密 Token、支付配置、管理路径或节点秘密。Core 自己管理的订阅链接 Token 只通过专用发行 Operation 向授权对象交付，须 `private, no-store`、审计脱敏且不进入公共缓存。Provider 管理的代理凭据不进入 Core DTO：Core 只返回非秘密 receipt/引用，Provider 通过受限目标通道直接交付或密封转送给授权终端；不能为简化 Gateway 适配而让 Core 解密、持久化或回显插件秘密。

## 4. 结果、错误与业务幂等

内部成功格式：`{data, request_id, meta?}`；错误：`{error:{code,message,fields?},request_id}`，沿用当前 [TXAPI 响应实现](../../../api/app/Core/Http/TxapiResponse.php) 的基本结构。Gateway 对外 envelope 是独立版本，不让 Core 返回 HTTP 200 的假成功。

| HTTP | 错误 | 语义 |
| --- | --- | --- |
| 400 | `INVALID_REQUEST` / `UNKNOWN_OPERATION` | 结构、版本或 Operation 不合法 |
| 401/403 | `SERVICE_UNAUTHORIZED` / `ACTOR_UNAUTHORIZED` / `FORBIDDEN` | 服务身份、会话或业务权限不成立 |
| 404 | `NOT_FOUND` | 目标不存在或按当前策略隐藏范围外目标 |
| 409 | `IDEMPOTENCY_CONFLICT` / `IDEMPOTENCY_IN_PROGRESS` / `STATE_CONFLICT` | 同键异内容、尚在执行或业务状态冲突 |
| 422 | `BUSINESS_REJECTED` | Core 校验拒绝，稳定非秘密错误码 |
| 429 | `RATE_LIMITED` | 有界 Retry-After，不绕过账号维度限流 |
| 503/504 | `DEPENDENCY_UNAVAILABLE` / `DEADLINE_EXCEEDED` | 结果可能未知，不宣称业务未提交 |

写 Operation 要求 `Idempotency-Key`。Core 以有效 actor、Operation 主版本和客户端键构成持久唯一身份，并存储规范化业务输入 hash、执行状态和可重放结果；Gateway 不建第二套 Redis 业务去重。执行与订单/余额/账本更新需在权威事务或明确持久工作流内；对外支付使用稳定支付尝试 ID，不能依靠 HTTP 重试消除副作用。

同键同输入返回原结果；同键异输入 409；超时后只能携原键查询/重试。Gateway 不能因 503、网络超时生成新键，不自动重试非幂等请求。幂等保留期必须覆盖业务重试/支付回调窗口；具体保留参数由对应交易契约冻结，不能采用短缓存 TTL 作为唯一依据。

## 5. 验收与开放条件

- 服务证书过期、吊销、错误 SAN、代理伪造头、内部入口公网暴露均拒绝。
- User/Admin 服务与 actor 交叉使用拒绝；账户封禁、权限撤销立即由 Core 当前状态决定。
- 跨用户订阅/订单/链接与范围外节点负例覆盖；管理路径和 RBAC 不因 Gateway 引入而失效。
- unknown operation/字段、过大请求、超时、并发同键和同键异内容均有契约测试。
- 写入提交后丢响应，再用原键重试不重复成单、扣款或发放权益。
- 真实 Core/MySQL/Redis + Gateway 验收通过，Operation allow-list 和旧调用退出清单齐备，才能迁移对应 Web 入口。

[领域模型](../../../docs/architecture/v2/core-domain-model.md) · [升级契约](../upgrade-manifest/README.md) · [SC-v2](../secure-channel/README.md)

B5.4: [local User dashboard and payment-method display reads](current-user-display-v2.md), with the same version-2 User identity and local/testing gates.

B5.5: [local subscription summary and notices](current-user-content-v2.md), preserving current User authority and public display fields.

B5.6: [local current order status](current-order-status-v2.md), preserving current ownership and missing-Plan reads.

B5.7: [isolated local order creation](local-user-order-create-v1.md), delegating
native Core business/SQL idempotency and retaining original-key recovery after
an unknown result. Additional write and isolated-copy gates default off.
