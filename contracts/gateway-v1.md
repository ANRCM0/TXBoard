# TXBoard Gateway HTTP API — v1

> **CURRENT / 已实现合同：/gateway/v1。** 未来 /txapi/bff/v1 见 [目标合同](./txapi-bff-target-v1.md)，目前尚未实施。
>
> **实现状态：**2026-10-12 已完成当前 14 项操作的 Native 适配与隔离 Core/Gateway HTTP 联调；环境为 PHP 8.4、MariaDB 11.4 和 Redis，生产部署、MySQL 8.4、目标 BFF 与 B5/B6 总验收尚未完成。详见 [联调记录](../docs/native-core-integration.md)。

## 请求与错误格式

- Base URL `/gateway/v1`，仅 Gateway 存活检查位于 `GET /healthz`。
- UTF-8 JSON、无 cookie、no-store；Origin 必须严格匹配已配置名单。它不是服务器认证机制。
- 成功：`{"ok":true,"data":{},"meta":{"version":"1","requestId":"..."}}`。
- 失败：`{"ok":false,"error":{"code":"...","message":"..."},"meta":{"version":"1","requestId":"..."}}`。
- 核心错误：400（入参）、401（缺 Token/上游未授权）、403（Origin）、404（未找到）、405（订单写入禁用）、409（HPKE 重放）、413（请求过大）、422（Laravel 校验）、429（账号限流或上游限流）、502/504（坏网关/超时）、503（Redis 安全存储不可用）、500（内部错误）。
- 上游仅接受 Native `{data,meta?,request_id}` / `{error:{code,message,fields?},request_id}` 信封，并按操作校验 DTO；退休的 `status:success` 与旧 paginator 不再接受。错误正文不公开上游内部信息。
- **权限判断始终以 Laravel 为准**。用户 GET 路由需 `Authorization: Bearer <user-token>`；无用户身份的页面不允许向 Gateway 提交管理员 Token。
- 默认 Native 保持上述当前行为；仅 local/testing 明确启用 `GATEWAY_INTERNAL_USER_ENABLED=true` 时，现有受保护只读操作使用版本2 Internal mTLS 上游，DTO不变，失败无fallback。配置与限制见 [B5.2–B5.6 Current记录](../docs/internal-user-integration.md)，没有新增管理员 Gateway。

## 固定路由矩阵

| 方法和路径（均加 `/gateway/v1` 前缀） | 认证 / 业务含义 | Core Native 上游 |
| --- | --- | --- |
| GET `/bootstrap` | 公共站点、主题、CAPTCHA 公开元数据、能力清单 | `/txapi/public/site-config` |
| GET `/theme/config` | 公开主题配置 | `/txapi/public/site-config` |
| GET `/plans` | 公开套餐列表；金额遵循 Laravel 分单位 | `/txapi/plans` |
| POST `/auth/login` | 邮箱/密码、可选 CAPTCHA；兼容普通登录 | `/txapi/auth/login` |
| GET `/user/profile` | 用户资料 | `/txapi/me` |
| GET `/user/subscription/summary` | 订阅使用概览，不返回 token/uuid/subscribe_url | `/txapi/me/subscription` |
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
| POST `/orders` | **默认 405、零上游调用**；`GATEWAY_ORDER_WRITE_ENABLED=true` 时启用订单写入适配层（GW-304），需 Bearer + `Idempotency-Key` 头 | `/txapi/orders`（仅 `orderWrite` 策略） |

仅支付方式展示使用用户 Bearer，不代表任何“访客支付方式”已开放。

## 关键输入/输出约束

**bootstrap** 的 CAPTCHA 结构：
`security.captcha={enabled:boolean,type:'turnstile'|'recaptcha'|'recaptcha-v3'|null,siteKey:string|null}`。当 enabled=true 但类型/key 缺失，前端必须阻止未经验证码的登录，而不是静默禁用。

**明文登录**：`{email,password,turnstile_token?,recaptcha_v3_token?,recaptcha_data?,email_code?}`。当前通用 Native 登录只返回用户 `auth_data`；不返回管理员标志、后台路径或订阅凭据。

**密文账户操作**：Gateway 公钥发现后使用 HPKE 请求体 `{kid,ts,nonce,enc,ct}`。AAD 绑定 `login`/`register`/`email-code` 的固定路径；密文不可跨操作直接重放。注册字段 `{email,password,invite_code?,email_code?,turnstile_token?,recaptcha_v3_token?,recaptcha_data?}`，发邮箱码字段 `{email,turnstile_token?,recaptcha_v3_token?,recaptcha_data?}`。成功注册只返回用户 `auth_data`；邮箱码返回 `{sent:true}`。SDK 不把失败自动降级为明文注册/发码。

**只读接口**：计划需 id/name；用户需 email；订单需 trade_no/status；付款仅公开 id/name/icon/payment/手续费字段；通知页限制 current 和 pageSize；统计固定是三个非负整数。错误或格式不符时拒绝而非强行透传。

**订单写入（GW-304，默认关闭，`GATEWAY_ORDER_WRITE_ENABLED=true` 启用）**：

同时启用 local/testing Internal User transport 时，订单创建选择固定 `user.order.create`；Core 的独立写入开关与隔离标记仍须成立，HTTP201/写入 DTO 严格校验、失败无 Native 回退。未启用 Internal 时保留现有 Native 上游。B5.7 已通过隔离验收，配置/提交未知恢复边界见 [当前接入记录](../docs/internal-user-integration.md)。

- 请求头 `Idempotency-Key` **必填**，格式为 1–128 位 URL 安全字符（`[A-Za-z0-9._~-]`）；缺失或非法返回 `400 VALIDATION_ERROR`，**零上游调用**。
- 请求体 strict schema：`{plan_id:正整数, period:枚举, coupon_code?:1–64位}`；未知字段 400。
- Gateway 透传用户 Bearer（**不接受管理员 Token**），并追加自派生 `X-Idempotency-Key-Hint`（仅作日志关联，Laravel 以认证 user_id 重新派生权威键）。
- 错误映射：上游 `IDEMPOTENCY_IN_PROGRESS` → `409 IDEMPOTENCY_IN_PROGRESS`（带 `Retry-After`，客户端退避后以**同一键**重试）；`IDEMPOTENCY_CONFLICT` → `409 IDEMPOTENCY_CONFLICT`；余额/套餐/优惠券类业务拒绝 → `422 ORDER_REJECTED`（不回显上游原文）。
- 成功返回 `201` + 上游订单 DTO（至少含 `trade_no`）。SDK（`orders.create`）对写请求**永不自动重试**。
- 幂等、余额、套餐、金额规则**全部由 Laravel 决定**；Gateway 不持有管理 token、不复制业务规则、不做本地去重。启用前需 GW-303/305/306 验收通过（见 [订单幂等 ADR](../docs/adr-order-idempotency.md)）。

## Native 字段与分页迁移

15 个历史操作中 14 个有当前 Native 对应项；匿名 `guestPaymentMethods` 没有对应项，`/catalog/payment-methods` 固定 404、零上游调用，即使历史开关为 true 也不声明该能力。受保护 `/payments` 只转发调用用户的 Bearer。

Native 金额已是分，不再次乘 100；ISO 时间转现有 Unix 秒；套餐流量由字节转 GiB，用户流量保留字节。未知 DTO 字段被过滤，用户 UUID 与订阅私密 URL 不输出。订单详情和状态必须匹配请求标识。

状态原样支持 0 待支付、1 开通中、2 已取消、3 已完成、4 已折抵；未知状态拒绝，不转换成其他状态。原 `/orders` 仍是数组，默认只含第 1 页最多 100 条。读取完整历史应在 bootstrap 发现 `orders.page` 后使用 SDK `orders.page({current,page_size})` 并根据 `lastPage` 翻页；该分页 DTO 放在 `data` 内，外部 `{ok,data,meta}` 信封不变。上游分页必须与请求页码、页大小、总数和返回条数一致。

## 界限

- Gateway 不代理任意 host/path，不直连数据库，不碰管理员/节点/插件/支付回调和订阅原文 Token。
- Redis nonce 是有限时间窗的防重放，不是 Laravel 交易幂等、不提供跨 Redis failover 的强一致承诺。
- 当前隔离 Core fixtures 与 HTTP 正负例已验证；OpenAPI 3.1、完整安全审查、目标 BFF、正式部署与 SDK 发布仍待完成。

[当前代码/CI 状态](../docs/development-status.md) · [HPKE/Redis 设计](../docs/app-crypto-preview.md) · [主题 SDK](../packages/theme-sdk/README.md)。

B5.4 local Internal opt-in additionally maps protected `/dashboard/stats` and
`/payments` to fixed empty-input User operations, retaining their public DTOs.
Native remains the default. Invalid actor, service trust, overflow, unexpected
fields or transport failure does not trigger a Native fallback. This supplies
no checkout authorization or production PKI/SC-v2 acceptance.

B5.5 extends that local opt-in to the authenticated subscription summary and
notices. Public DTOs, native visible-only content and bounded pages remain;
credentials are excluded. Missing plan, invalid actor/trust/DTO or transport
errors retain refusal without Native fallback. Native stays the default.
