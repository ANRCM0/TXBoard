# TXAPI BFF Target v1 — proposed cross-repository protocol

> **ADR-006, 2026-10-09, TARGET / NOT LIVE.** 对应 Gateway 仓库的 [contract](https://github.com/ANRCM0/TXBoard-Gateway/blob/main/contracts/txapi-bff-target-v1.md)。

## Path ownership and Edge dispatch

- Gateway PUBLIC future prefix: `/txapi/bff/v1/*` (Hono, optional). Other `/txapi/*` is Laravel native.
- Edge **first** routes exact BFF subtree to private Gateway; **then** generic TXAPI to Laravel. Old `/gateway/v1/*` and `/api/v1/*` continue during migration.
- Gateway upstream is an allowlisted, static **private TXBoard origin**; never an arbitrary client URL or the public reverse proxy path that loops to itself.
- Admin, Node, Agent, payment webhooks, subscriptions, WebSocket and extensions never transit theme BFF.

## Candidate operation mappings (all NOT LIVE)

| Future BFF route | Laravel native operation | Identity |
|---|---|---|
| GET `/txapi/bff/v1/bootstrap` | GET `/txapi/public/config` | Public |
| GET `/txapi/bff/v1/theme/config` | GET `/txapi/public/config` | Public |
| GET `/txapi/bff/v1/plans` | GET `/txapi/plans` | Public |
| POST `/txapi/bff/v1/auth/login` | POST `/txapi/auth/login` | CAPTCHA/auth belongs Laravel |
| GET `/txapi/bff/v1/user/profile` | GET `/txapi/me` | User Bearer |
| GET `/txapi/bff/v1/orders` | GET `/txapi/orders` | User Bearer; paginated |
| GET `/txapi/bff/v1/orders/{tradeNo}` | GET `/txapi/orders/{tradeNo}` | User ownership |
| POST `/txapi/bff/v1/orders` | **none, return 405** | disabledWrite |
 
Gateway 当前还实现了 notices、order status、subscription summary、payments display 和 dashboard stats：**这些 Native endpoints 尚未定义**，需要逐条冻结映射，不能猜测上游路径。

## Distinct native / BFF wire formats

- Laravel TXAPI Native target: `{data,meta?,request_id?}` on 2xx; `{error,request_id?}` on 4xx/5xx.
- Gateway v1 BFF keeps `{ok:true,data,meta:{version:"1",requestId}}` or `{ok:false,error,meta:{version:"1",requestId}}` to protect existing SDK behavior.
- Adapter converts **only allowlisted** operation schemas, maps pagination/error semantics explicitly; no raw upstream exception or secret response. Changing v1 fields requires v2.
- Laravel owns real authentication, permissions, funds and transaction idempotency. HPKE/Redis nonce is additional envelope safety, **not** payment idempotency.
- Gateway forwards only end-user scoped Bearer; unknown methods/paths are denied; no sensitive write API enabled by adding a new proxy route.

## Acceptance

1. G0: align two repositories' target contracts/ADR/operation matrix.
2. G1: existing `/gateway/v1` real staging with Laravel/MySQL/Redis/CAPTCHA/HTTPS.
3. G2: native Laravel endpoint and schema/authorization tests.
4. G3: Gateway adapter + SDK golden fixtures, old and new path coexisting.
5. G4: opt-in Edge/Deploy/theme switch, trusted proxy CIDR, no proxy loop and tested rollback.
6. G5: retire old routes only after all supported callers migrated and actual old traffic is zero.

For deployed TXBoard entrypoints see [external interface overview](external-adapter-current.md). This proposed BFF interface is not a registered Laravel route.
