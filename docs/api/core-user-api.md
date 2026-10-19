# Canonical user operation access

This browser surface preserves the generated Core operation contracts. The user
application lives in the same TXBoard workspace; new user product pages remain
deferred. Old theme REST DTO and encrypted Gateway routes are retired; current
themes use the canonical Core operations through Theme SDK 0.2.0. Subscription delivery uses its separately authorized Provider flow.

`GET /gateway/v1/core/user/capabilities` returns the intersection of the compiled
user-operation list and current browser feature gates. Discovery does not replace
authorization: Core re-resolves the original user session and current account
state on every authenticated operation.

`POST /gateway/v1/core/user/operations/<operation>` accepts exactly
`{"input":{...}}`. Query parameters, additional envelope fields, duplicate JSON
keys and invalid generated input are rejected. The route is a literal reviewed
allowlist; it cannot proxy arbitrary internal operations or domains. Operations declared with an anonymous actor admit login and the offer catalog;
other user operations require an original user Bearer.
Every command requires an `Idempotency-Key` UUID for the logical action.

Responses contain `ok`, canonical `data`, and `meta` with Core browser ingress `requestId`,
`coreRequestId`, and generated `coreMeta`. Monetary values and identifiers remain
decimal strings. Order pagination, recharge/usage cursors and bounded session
list `has_more` are validated separately from the data.

## Operator admission

The namespace is enabled by default. `CORE_BROWSER_ENABLED`,
`CORE_BROWSER_USER_ENABLED` and `CORE_BROWSER_COMMERCE_ENABLED` control admission.
Financial commands require commerce admission; session revocation and logout do
not depend on it. Current owner rules, provider availability and user ownership
still apply. No separate Gateway service, service credential or client TLS
certificate is required for browser requests.

`CORE_BROWSER_ORIGIN` defaults to `APP_URL` and must match the public HTTPS origin
except loopback development. Caddy overwrites the browser client IP header and
Core trusts it only from configured local proxy peers. Redis limit failures fail
closed. Internal service endpoints retain their separate mTLS policy.

## SDK and recovery

```ts
import { createCoreUserClient } from '@txboard/core-sdk'
const core = createCoreUserClient({ getToken: () => session.bearer })
const result = await core.callResult('commerce.orders.list', { page: 1, per_page: 20 })
// result.data and result.meta are independently validated; result.requestId is Core's trace.
```

`CoreClient.call` continues to return data only. `callResult` requires a result
transport and retains metadata. The browser factory supplies both transports and
offers `capabilities()`. It bounds response size and lifetime, omits cookies,
rejects redirects, and performs no automatic retries. Absolute origins require
HTTPS except local development on localhost or 127.0.0.1.

After an uncertain command result, preserve the original UUID and exact input.
Use the owner read or explicitly repeat the original action. In particular,
`billing.payment-attempts.query` may advance financial state: it re-enters Billing
outside an enclosing transaction, and same-key replay reads the owner's current
status. `needsStateConfirmation` on SDK errors signals uncertainty; it is not
evidence of rollback. `identity.session.end` may revoke a session before its
response is lost; a subsequent 401 requires clearing the old session context.
Ingress availability failures map to 503; expired user sessions map to 401.

CI covers generated contracts, SDK and policy boundaries, payment query recovery,
and real native Core/MySQL through the Core browser HTTP ingress and browser SDK.
Provider calls retain their dedicated TLS transport. Separate image acceptance
exercises Caddy and the actual PHP runtime. Synthetic web fixtures do not prove
production browser deployment.
