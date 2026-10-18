# Canonical user operation access

This opt-in browser surface preserves the generated Core data contracts. Existing
theme routes and their display DTOs continue to work. Formal TX-F pages and
credential delivery are separate batches.

`GET /gateway/v1/core/user/capabilities` returns the intersection of the compiled
20-operation user list, Core's current service grants and local feature gates.
The Core service must grant `system.capabilities.get` for discovery. Discovery
does not replace authorization: Core checks the actual service and original user
session on every operation.

`POST /gateway/v1/core/user/operations/<operation>` accepts exactly
`{"input":{...}}`. Query parameters, additional envelope fields, duplicate JSON
keys and invalid generated input are rejected. The route is a literal reviewed
allowlist; it cannot proxy arbitrary internal operations or domains. All user
operations except the anonymous offer catalog require an original user Bearer.
Every command requires an `Idempotency-Key` UUID for the logical action.

Responses contain `ok`, canonical `data`, and `meta` with Gateway `requestId`,
`coreRequestId`, and generated `coreMeta`. Monetary values and identifiers remain
decimal strings. Order pagination, recharge/usage cursors and bounded session
list `has_more` are validated separately from the data.

## Operator admission

The namespace defaults off. Set `GATEWAY_CORE_USER_OPERATIONS_ENABLED=true` and
configure the isolated user service using `GATEWAY_CORE_USER_ENABLED=true`,
`GATEWAY_CORE_USER_SERVICE_ID`, `GATEWAY_CORE_USER_CREDENTIAL`,
`GATEWAY_CORE_USER_EPOCH`, `GATEWAY_CORE_USER_CA_FILE`,
`GATEWAY_CORE_USER_CERT_FILE`, and `GATEWAY_CORE_USER_KEY_FILE`.
The fixed `TXBOARD_UPSTREAM_URL` must be a clean HTTPS origin with normal server
verification. Core must be ready in native mode, admit this exact certificate,
and trust the actual TLS proxy peer. The proxy strips client TLS identity headers
and supplies only verified connection information.

Financial commands additionally require `GATEWAY_ORDER_WRITE_ENABLED=true`.
Session revocation and logout remain available without the financial switch.
Core's owner rules, current provider availability and user ownership still apply.
`compose.core-user.yaml` is an optional overlay for operator-provisioned read-only
TLS files. Set `GATEWAY_CORE_USER_TLS_DIR` to the directory containing `ca.pem`,
`client.pem` and `client.key`; protect the service credential in the operator's
environment. Enabling this overlay does not provision certificates or grants.

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
Service authentication failures map to 503; expired user sessions map to 401.

CI covers generated contracts, SDK and policy boundaries, payment query recovery,
and real native Core/MySQL through Gateway mTLS and the browser SDK. Its isolated
TLS termination adapter connects to PHP's loopback development server; this is
not a production reverse-proxy or PKI deployment test.
