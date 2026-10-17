# TXBoard Gateway HTTP API — v1

> **CURRENT / 当前 Gateway 适配合同：/gateway/v1。** `/txapi/bff/v1` 未注册。公开目录和订单创建使用当前 Native 已发布 Offer 契约；参见 [Native Offer Commerce v1](native-offer-commerce-v1.md)。
>
> **验证范围：**2026-10-12 隔离 HTTP 结果只覆盖其记录的 Core/Gateway 版本（PHP 8.4、MariaDB 11.4、Redis），不代表后续 Native 业务改动已验收。服务端 mTLS 通道与当前限制见 [Native 接入](../docs/native-core-integration.md)。

## 请求与错误格式

- Base URL `/gateway/v1`，仅 Gateway 存活检查位于 `GET /healthz`。
- UTF-8 JSON、无 cookie；用户/敏感响应 no-store，`public-short` 成功 GET 可短缓存（含 `/crypto/key`，轮换/吊销须考虑缓存传播）。Origin 必须严格匹配已配置名单，它不是服务器认证机制。
- 成功：`{"ok":true,"data":{},"meta":{"version":"1","requestId":"..."}}`。
- 失败：`{"ok":false,"error":{"code":"...","message":"..."},"meta":{"version":"1","requestId":"..."}}`。
- 核心错误：400（入参）、401（缺 Token/上游未授权）、403（Origin）、404（未找到）、405（订单写入禁用）、409（HPKE 重放）、413（请求过大）、422（Laravel 校验）、429（账号限流或上游限流）、502/504（坏网关/超时）、503（Redis 安全存储不可用）、500（内部错误）。
- 上游仅接受 Native `{data,meta?,request_id}` / `{error:{code,message,fields?},request_id}` 信封，并按操作校验 DTO；退休的 `status:success` 与旧 paginator 不再接受。错误正文不公开上游内部信息。
- **权限判断始终以 Laravel 为准**。用户 GET 路由需 `Authorization: Bearer <user-token>`；无用户身份的页面不允许向 Gateway 提交管理员 Token。
- 普通适配使用固定 Core `/txapi/*`；配置独立 User service 后，`login`、`guestConfig` 可选真实 mTLS operation 通道。服务端另有独立 Admin client，但没有管理员浏览器路由，不能向 SDK 注入服务凭据。

## 固定路由矩阵

| 方法和路径（均加 `/gateway/v1` 前缀） | 认证 / 业务含义 | Core Native 上游 |
| --- | --- | --- |
| GET `/bootstrap` | 公共站点、主题、CAPTCHA 公开元数据、能力清单 | `/txapi/public/site-config` |
| GET `/theme/config` | 公开主题配置 | `/txapi/public/site-config` |
| GET `/plans` | 公开不可变已发布 Offer 列表；标识、金额、流量和限制保留十进制字符串 | `/txapi/plans` |
| POST `/auth/login` | 邮箱/密码、可选 CAPTCHA；兼容普通登录 | `/txapi/auth/login` |
| GET `/user/profile` | 当前账户身份 `{id,email,telegram_id}` | `/txapi/me` |
| GET `/user/subscription/summary` | `core-entitlement/v1` 当前权益摘要，十进制字符串保留精度，无订阅凭据 | `/txapi/me/subscription` |
| GET `/dashboard/stats` | `{unpaidOrders,openTickets,invitedUsers}` | `/txapi/me/dashboard-stats` |
| GET `/orders` | 当前用户订单数组；可选 `status=0..4,current=1..9999,page_size=1..100`，默认第 1 页 100 条 | `/txapi/orders?page=&per_page=&status=` |
| GET `/orders/page` | 同样查询；返回 `{items,current,pageSize,total,lastPage}`，能力 `orders.page` | `/txapi/orders?page=&per_page=&status=` |
| GET `/orders/:tradeNo` | 当前用户的订单详情 | `/txapi/orders/{tradeNo}/detail` |
| GET `/orders/:tradeNo/status` | `{tradeNo,status}`，仅查询状态 | `/txapi/orders/{tradeNo}` |
| GET `/payments` | 已启用的支付方式**展示字段**，非交易入口 | `/txapi/billing/payment-methods` |
| GET `/notices?current=1&pageSize=5` | 当前用户通知分页 `{data,total}`；最大 pageSize=100 | `/txapi/notices?page=&per_page=` |
| GET `/crypto/key` | 仅启用 HPKE 时公开 kid/suite/公钥/scope | Gateway 本地 |
| POST `/secure/auth/login` | 启用 HPKE+Redis 时密文登录 | `/txapi/auth/login` |
| POST `/secure/auth/register` | **默认关闭**，需 HPKE+Redis 和账户开关 | `/txapi/auth/register` |
| POST `/secure/auth/email-code` | **默认关闭**，需 HPKE+Redis 和账户开关 | `/txapi/auth/email-code` |
| POST `/orders` | **默认 405、零上游调用**；显式开关后仅接受 `{offer_id:string}` 和用戶 Bearer + `Idempotency-Key` | `/txapi/orders`（仅 `orderWrite` 策略） |

仅支付方式展示使用用户 Bearer，不代表任何“访客支付方式”已开放。

## 关键输入/输出约束

**bootstrap** 的 CAPTCHA 结构：
`security.captcha={enabled:boolean,type:'turnstile'|'recaptcha'|'recaptcha-v3'|null,siteKey:string|null}`。当 enabled=true 但类型/key 缺失，前端必须阻止未经验证码的登录，而不是静默禁用。

**明文登录**：`{email,password,turnstile_token?,recaptcha_v3_token?,recaptcha_data?,email_code?}`。当前通用 Native 登录只返回用户 `auth_data`；不返回管理员标志、后台路径或订阅凭据。

**密文账户操作**：Gateway 公钥发现返回 `{protocol,suite,keys[]}`，含当前 key 和未过期/未吊销 previous key。HPKE 请求体为 `{kid,ts,nonce,enc,ct}`，`ts` 为 Unix 毫秒、接受 ±60000 毫秒；SDK nonce 为 16 随机字节（128 bit）。AAD 绑定协议、POST、固定 `login/register/email-code` 路径、kid、ts、nonce 及单步 `-` 占位。Redis replay TTL 121 秒，重放 409，故障 503，不回退本地存储。注册字段 `{email,password,invite_code?,email_code?,turnstile_token?,recaptcha_v3_token?,recaptcha_data?}`，发邮箱码字段 `{email,turnstile_token?,recaptcha_v3_token?,recaptcha_data?}`。成功注册只返回用户 `auth_data`；邮箱码返回 `{sent:true}`。SDK 不把失败自动降级为明文注册/发码。完整字节及轮换/缓存限制见 [当前加密说明](../docs/app-crypto-preview.md)。

解密后的 JSON 拒绝重复解码键（包括转义后的同名键）及超过 30 层的嵌套，解析或 DTO 校验失败返回 400。密码恢复、会话管理和订单取消的隔离原型路由及 SDK 方法已退役。

**公开 Offer 目录**：每一项是当前可见的不可变已发布 Offer，包含 `offer_id`、`plan_id`、`plan_revision_id`、名称/内容/tags、`offer_code`、`purchase_kind`、币种与 `price_minor`、`quota_bytes`、限制、sell/renew 和 publication/availability 证据。64 位标识、金额和字节数保留规范十进制字符串；Gateway 不将金额/额度转换为 JavaScript Number，也不合并 Offer 或计算购买资格。只映射明确 allowlist 字段；未知字段不透传，畸形 Offer、重复 ID 或超过 256 个结果时关闭失败。Core 仍在购买时重新验证 Offer 与可售状态。

**其他只读接口**：用户需 email；订单需 trade_no/status；付款仅公开 id/name/icon/payment/手续费字段；通知页限制 current 和 pageSize；统计固定是三个非负整数。错误或格式不符时拒绝而非强行透传。

**订单写入（默认关闭，`GATEWAY_ORDER_WRITE_ENABLED=true` 启用）**：

- 关闭时返回 `405` 且零上游调用。打开后只暴露已发布 Offer 的购买入口；`orders.create` capability 只描述该固定入口，不能作为付款/履约凭据。
- 请求头 `Idempotency-Key` **必填**，格式为 1–128 位 URL 安全字符（`[A-Za-z0-9._~-]`）；缺失或非法返回 `400 VALIDATION_ERROR`，**零上游调用**。
- 请求体 strict schema：`{offer_id:正十进制字符串}`；数值型 ID、`plan_id`、`period`、优惠券、金额和未知字段均在上游调用前返回 `400 VALIDATION_ERROR`。
- Gateway 透传用户 Bearer（**不接受管理员 Token**），并追加自派生 `X-Idempotency-Key-Hint`（仅作日志关联，Laravel 以认证 user_id 重新派生权威键）。
- 错误映射：上游 `IDEMPOTENCY_IN_PROGRESS` / `IDEMPOTENCY_CONFLICT` 保持各自 `409` code（in-progress 带 `Retry-After`）；不回显其他上游原文。
- 成功返回 `201` + 上游订单 DTO（至少含 `trade_no`）。SDK（`orders.create`）对写请求**永不自动重试**。
- 幂等、不可变 Offer、可售状态、容量、pending-order 限制和最终金额**全部由 Core 决定**；Gateway 不持有管理 token、不复制业务规则、不做本地去重。Hint 不含受信用户身份，不能作为权威幂等键；未知写结果保留原 key，不自动换 key/重发。订单创建成功不代表支付或履约成功。

## Native 字段与分页迁移

历史适配表列有 15 个 operation 槽位，其中匿名目录不可用；其他项也不能仅凭历史路径映射推断当前业务 DTO 已兼容。匿名 `guestPaymentMethods` 没有对应项，`/catalog/payment-methods` 固定 404、零上游调用，即使历史开关为 true 也不声明该能力。受保护 `/payments` 只转发调用用户的 Bearer。

Offer money/quota/identifiers remain Core minor-unit/byte/canonical-decimal strings without lossy conversion. Historical order timestamps retain the existing Unix-second projection; user UUID and private subscription URLs are not output. Order detail/status must match the requested identifier.

状态原样支持 0 待支付、1 开通中、2 已取消、3 已完成、4 已折抵；未知状态拒绝，不转换成其他状态。原 `/orders` 仍是数组，默认只含第 1 页最多 100 条。读取完整历史应在 bootstrap 发现 `orders.page` 后使用 SDK `orders.page({current,page_size})` 并根据 `lastPage` 翻页；该分页 DTO 放在 `data` 内，外部 `{ok,data,meta}` 信封不变。上游分页必须与请求页码、页大小、总数和返回条数一致。

## 界限

- Gateway 不代理任意 host/path，不直连数据库，不碰管理员/节点/插件/支付回调和订阅原文 Token。
- Redis nonce 是有限时间窗的防重放，不是 Laravel 交易幂等、不提供跨 Redis failover 的强一致承诺。
- 历史隔离 fixtures 与 HTTP 正负例仅证明原始源码；正式部署、安全审查、目标 BFF 和 SDK 发布须分别有匹配版本证据。

[当前代码/CI 状态](../docs/development-status.md) · [HPKE/Redis 设计](../docs/app-crypto-preview.md) · [主题 SDK](../packages/theme-sdk/README.md)。

## Current entitlement summary

`user.subscription()` returns the current `core-entitlement/v1` discriminated union.
Denied summaries contain only `schema`, `authorized: false` and the Core `reason`.
Authorized summaries contain `authorized: true`, `reason: ENTITLEMENT_GRANTED`,
`subscription_id`, `plan_id`, `expires_at`, `next_reset_at`, `quota_bytes`,
`consumed_bytes`, `remaining_bytes`, `upload_bytes`, `download_bytes`,
`speed_limit_mbps` and `device_limit`. Identifiers, byte counts, limits and Unix
seconds remain decimal strings; nullable timestamps and limits remain null.
No legacy plan-name/reset-day projection or reusable subscription URL is emitted.
Profile reads expose only the common account identity and do not derive balances
or credentials from entitlement. Core remains the authority for financial reads.
