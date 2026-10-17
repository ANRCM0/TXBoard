# @txboard/theme-sdk

Source now lives at the main repository's `gateway/packages/theme-sdk`; run workspace build/pack commands from `gateway/`. The package name, version, exports and HTTP contract remain unchanged. Unified repository image tags do not publish this package to npm.

Framework-agnostic TypeScript client for the Gateway's fixed `/gateway/v1` routes (Vue, React, Next.js, SPA). The external envelope is `{ok,data,meta}`; `/txapi/bff/v1` and administrator browser routes are not registered. See [current integration](../../docs/native-core-integration.md). Currently a workspace package, **not yet published to npm**. Build with `npm run build --workspace @txboard/theme-sdk`.

```ts
import { createTXBoardClient } from '@txboard/theme-sdk'
const api = createTXBoardClient({
  baseURL: '/gateway/v1',
  getToken: () => session?.auth_data,  // caller owns storage/lifetime
  encryptedLogin: true,                // optional HPKE + Redis Gateway only
})

const { site, theme, security } = await api.bootstrap()
const plans = await api.plans.list()
const selectedOffer = plans[0]
// Order writes also require the server-side GATEWAY_ORDER_WRITE_ENABLED gate.
// if (selectedOffer) await api.orders.create({offer_id: selectedOffer.offer_id}, {idempotencyKey: key})
const profile = await api.user.profile()
const subscription = await api.user.subscription()
const orders = await api.orders.list({status: 0}) // default first page, up to 100 rows
const page = await api.orders.page({status: 4, current: 2, page_size: 20})
const detail = await api.orders.detail('ORDER-EXAMPLE')
const status = await api.orders.status('ORDER-EXAMPLE')
const stats = await api.dashboard.stats()
const notices = await api.notices.list({current: 1, pageSize: 5})
const payments = await api.payments.list()
```

`orders.list({status?,current?,page_size?})` 保留数组形状；默认仅第 1 页最多 100 条。完整历史使用新增 `orders.page()`，先发现 bootstrap 的 `orders.page` 能力；返回 `{items,current,pageSize,total,lastPage}`。`current` 为 1..9999，`page_size` 为 1..100。订单状态精确支持 0 待支付、1 开通中、2 已取消、3 已完成、4 已折抵。

登录：
```ts
const login = await api.auth.login({
  email: 'user@example.test',
  password: 'some-test-password',
  turnstile_token: 'test-captcha-token',
})
```

加密账户功能（**后端额外启用** `GATEWAY_ACCOUNT_WORKFLOWS_ENABLED=true`、Redis、HPKE，默认不可调用）：

```ts
const sent = await api.auth.sendEmailCode({
  email: 'user@example.test',
  turnstile_token: 'test-captcha-token',
})
const registered = await api.auth.register({
  email: 'user@example.test', password: 'some-test-password', email_code: '123456',
})
```

- `encryptedLogin: true` 只影响 `auth.login`；**注册/发码始终只走加密路径**，不会自动退回明文。
- SDK 不保存密码、Bearer、cookie 或用户会话；敏感 token 必须由应用自行安全管理。公开接口不携带 bearer。
- `GatewayApiError` 包含 `code`、HTTP `status`、可选 `requestId`。遇到 409/429/503 不要无脑自动重试密文写请求。
- 套餐与订单金额沿用 TXBoard 的分单位；不在 SDK 中二次转换。
- SDK 仅类型描述，不代替 Gateway/后端的运行时校验；SSR 必须为不同请求隔离 `getToken` 与客户实例。
- `plans.list()` returns published `NativeOffer[]`; IDs, `price_minor` and `quota_bytes` are exact decimal strings. Select an `offer_id`, never derive an offer from a Plan ID/period or recompute price. `orders.create({offer_id})` is strict and remains disabled by default at the Gateway. No checkout/cancel, payment callback, administrator API or raw subscription-token export is exposed.

[HTTP v1 契约](../../contracts/gateway-v1.md) · [Docker 运行方式](../../docs/docker-deployment.md) · [当前状态](../../docs/development-status.md)。

2026-10-16 已移除只服务隔离 Internal 原型的密码恢复、会话列表/撤销/登出和订单取消方法。当前 SDK 只暴露 Gateway 实际提供的操作；账户令牌的保存和清除仍由应用负责。

`user.profile()` returns only `{id,email,telegram_id}`. `user.subscription()`
returns the current `core-entitlement/v1` union; check `authorized` before reading
`subscription_id`, `remaining_bytes` or `expires_at`. Byte counts, identifiers,
limits and Unix seconds are decimal strings and remain exact across transport.
A denied summary exposes the Core reason without any credential or reusable URL.

## Offline experiments

Explicit subpaths `experimental/sc-v2-codec`, `experimental/sc-v2-crypto` and
`experimental/sc-v2-response-salt` remain offline engineering APIs, not Gateway
HTTP or Core business consumers. Base HPKE does not authenticate an actor. The
original deterministic response material requires shared single-owner safety
across openings; the Redis fixture is not an atomic crypto fence. Fresh salt
does not replace authorization, replay/recovery or key trust. Current limits
are consolidated in [crypto and experiment boundaries](../../docs/app-crypto-preview.md)
and the [SC-v2 contracts](../../../contracts/v2/secure-channel/README.md).
