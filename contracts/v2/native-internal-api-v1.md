# Native Internal API v1

Status: current bounded service API subset, merged in PR #261.
Runtime/SQL/TLS tests run in CI; this is not complete management coverage
or a production deployment/PKI acceptance declaration.
The retired `InternalReadOnly` dispatcher and its old routes remain unavailable.

## Boundary and deployment

The only service entry is
`POST /txapi/internal/native/v1/{domain}/operations/{operation}`. `domain` is
exactly `user` or `admin`. An operation is a compiled registry entry, not a URL,
class, SQL query or function supplied by the caller. No callback, Node control
credential, retired Agent token or Provider credential can authorize this entry.

Core defaults to `CORE_INTERNAL_API_ENABLED=false`. Deployment must configure
`CORE_INTERNAL_TLS_PROXIES` with **exact actual socket peer IP addresses**, not
forwarded client addresses or a broad private CIDR. No localhost/testing bypass
exists. The trusted TLS terminator must:

1. Restrict this exact path to the service network; never public wildcard proxy.
2. Verify the service certificate chain, server/client EKUs, validity, revocation
   policy and allowed service SAN against a dedicated service CA.
3. Delete incoming `X-Core-Tls-Verified` and
   `X-Core-Tls-Certificate-Sha256`; set them from its own successful TLS
   verification (`SUCCESS`) and the SHA-256 fingerprint of the actual leaf
   certificate (64 lowercase hex characters).
4. Protect and re-encrypt its downstream connection to Core. Core requires raw
   `HTTPS=on` or `1`, ignoring `X-Forwarded-Proto`, broad public TrustProxies
   rules and `Request::isSecure()` for this decision.
5. Forward a service request only after authentication; certificate failure must
   not reach the application with fabricated success headers.

The application is deliberately unavailable when those trust conditions cannot
be provided. Configuration is not a substitute for a verified production proxy
deployment. Core additionally binds the exact leaf fingerprint to a persistent
service credential. The service secret is random 256-bit material, persisted
only as SHA-256, independently of Sanctum, Node/Machine and Provider credentials.
Every request checks its current epoch, expiry, revocation, domain and exact
operation allowlist on the writer connection. Certificate/secret rotation
increments the epoch and immediately rejects old headers.

Host-only `php artisan core:internal-service` supports `issue`, `rotate`,
`revoke`, `list`, `grant` and `revoke-grant`. Issue options are `--id`,
`--domain`, `--certificate-sha256`, repeated `--operation` and Unix
`--expires-at` (at most 366 days). Issue/rotate return the secret once to
protected operator stdout. List never returns the secret or hash. Do not place
that stdout in CI logs. Actor grant options are `--user-id`, `--permission`;
only current active administrators and compiled permissions can be granted.

## Credentials and request

Required service headers:

```text
X-Core-Service-Id: user-gateway
X-Core-Service-Epoch: 1
X-Core-Service-Credential: <64 hex characters from host provisioning>
Authorization: Bearer <actual actor Sanctum session, when required>
Idempotency-Key: <original client UUID, for declared writes>
X-Core-Admin-Path: <current secure_path, for authenticated admin operations>
```

Service TLS headers are set only by the terminator, never Gateway or browsers.
Authorization is never the service credential or a shared administrator token.
Core resolves the real Sanctum bearer afresh on every authenticated operation,
requires an actual persisted PersonalAccessToken, and rechecks current account
banned state. Admin additionally requires current `is_admin`, existing
Admin/AdminPath middleware, token ability and a live
`tx_internal_actor_grant`. An administrator role alone does not grant Internal
API permissions. User sessions and targets remain owner-scoped by the current
domain controller/service. Gateway `user_id`, role and `X-Actor-*` claims never
select the actor.

```json
{
  "contract_version": "1",
  "request_id": "11111111-1111-4111-8111-111111111111",
  "deadline_at": 1792152030,
  "input": {"offer_id": "42"}
}
```

The request is JSON, no query parameters, at most 32768 bytes. Envelope/input
must both be JSON objects, including empty input (`{}`, never `[]`). Duplicate
decoded keys at any depth reject, including escaped equivalents. Their fields
are closed; unknown fields, operation/domain mismatch, past deadlines and
deadlines beyond 30 seconds reject. IDs/amounts/bytes are canonical decimal
strings; `deadline_at` is an actual JSON integer, not a numeric string.
Pagination and enum values are bounded integers. Existing authoritative
controllers perform their own validation again. No price, balance or actor
override is accepted.

Core owns the response `request_id` and independently generates an execution
UUID; the client's request UUID is only a separately stored trace correlation.
Responses are `{data,request_id,meta?}` or
`{error:{code,message},request_id}`, `Cache-Control: private, no-store`, with
`X-Core-Execution-Id` after execution admission. DTOs select compiled fields and
remove credential-bearing nested fields. The special login response contains
the newly issued bearer only for that authorized session issuance; admin login
returns the current path only after verifying the administrator role.

## Operations, policy and unavailable scope

`OperationRegistry::all()` is the executable input/output/policy manifest.
`system.capabilities.get` returns only the calling service's configured,
domain-compatible operation inventory, field lists, required actor/permission,
idempotency mode, size and deadline. Capability discovery never grants an
operation or actor privilege.

K4 candidate `identity.account.get`, `identity.admin.get` and
`subscription.mine.get` delegate through fixed account read handlers to the
shared Identity query port instead of invoking HTTP controllers. Their existing
authentication, permissions, input/output fields, error normalization, redaction
and response bounds are unchanged. The precise candidate/acceptance boundary is
[Core Account Read Ports v1](core-account-read-ports-v1.md).
The next K4 candidate moves `commerce.orders.list` and `commerce.orders.get`
to fixed OrderReadOperations and Billing OrderQueries, preserving pagination
metadata and the existing owner restriction. Account and order read handlers
return adapter-local data/metadata; null order results map to the same 404, and
all results still pass the common executor output controls. See
[Core Order Read Ports v1](core-order-read-ports-v1.md). Remaining operations
retain their current controller delegation. No handler/class name is accepted
from a caller, and no new operation is made available.

Implemented families are account/admin login, owner session listing/revocation
and logout; published offer catalog, owner subscription entitlement; owner order
listing/status/create/checkout/cancel; native wallet, payment-method and recharge
operations; owner native UsagePeriod listing; owner subscription-link
issue/list/revoke; non-secret site settings; admin Node/Machine listing, native
plan publication/availability, subscription lifecycle, wallet refunds,
bounded financial reconciliation and a fixed non-secret site-settings subset.
Admin requires explicit permissions (`identity.read`,
`identity.sessions.manage`, `network.nodes.read`, `network.machines.read`,
`catalog.publish`, `subscription.manage`, `billing.refund`,
`billing.reconcile`, `settings.read`, `settings.write`).

`billing.wallet.get` preserves the native `{schema:"native-wallet/v1",balances}`
DTO, where each balance contains currency and decimal-string available/reserved
minor amounts. `billing.reconciliation.get` includes `account_reconciliation`
with separate `wallet` (`selected_user_wallet_accounts`) and installation-wide
`system` (`installation_all_users_system_accounts`) results. Each includes
`complete`, actual `accounts_checked`/`postings_scanned`, `posting_scan_limit`
and bounded `accounts` projections. Each account exposes decimal-string IDs,
purpose/currency, `projection_minor`, `ledger_minor`, `complete`,
`projection_checked`, `projection_matches` and `postings_scanned`. An incomplete
scan has `ledger_minor=null` and no invented match verdict. Completeness describes
scan coverage, not an assertion that the `issues` list is empty.

Operation names omitted from the manifest are unavailable, including arbitrary
module lifecycle, Node secrets, unregistered business mutations and unsupported
transport protocols. A capability never claims legacy Plan/User fields are
native state. Paid checkout and wallet/recharge operations delegate the C3
native services and remain subject to their installation/provider readiness;
this service adapter cannot bypass those gates.

## Persistent idempotency and audit

The registry declares `read`, `once`, `workflow`, `domain` or `domain-once`.

- `read`: no business idempotency key; bounded retry is a caller choice.
- `once`: login/logout issuance is never automatically retried. Secret login
  responses are not stored as generic replay receipts.
- `workflow`: requires the original UUID. Core stores an immutable
  actor + operation/v1 + key identity and canonical input hash before execution.
  A completed encrypted receipt replays without executing again. Same-key
  changed input returns `409 IDEMPOTENCY_CONFLICT`. An unfinished/unknown result
  returns `409 IDEMPOTENCY_IN_PROGRESS`; it is not automatically re-executed or
  declared rolled back. Operators reconcile that specific domain outcome.
- `domain`: the same persistent request evidence is retained, but unfinished
  calls may re-enter the **authoritative domain's existing permanent
  idempotency workflow with the original key**. No replacement key or Redis
  business lock is generated. Domain locks settle concurrent invocations.
  Purchase/recharge checkout is stricter: even a completed adapter receipt must
  re-enter that original domain workflow and recheck current plugin/payment
  gates. Signed checkout URLs are neither retained in generic receipts nor
  replayed after a channel is disabled; the permanent attempt still supports
  its separate restricted verification/query recovery.
- `domain-once`: subscription-link issuance uses the same original-key domain
  recovery, but returns its bearer only in the first domain response. The
  generic receipt stores `link_token=null` and `token_delivered=false`, never
  that bearer. A lost issuance response cannot recover a second plaintext
  copy; revoke that link and explicitly issue another when needed.

The adapter deliberately does not wrap a domain service in an outer transaction:
native financial/subscription services own installation -> account -> domain
lock order and their actual transaction/outbox. The request table is workflow
evidence, not a second funds authority. Receipt completion is separate; a crash
between business commit and receipt completion remains an explicitly unknown
outcome with the rules above. Receipts have no short TTL or automatic pruning.
5xx outcomes remain unfinished. Provider network effects use the C3 permanent
attempt/refund reference, never an HTTP execution ID.

`tx_internal_audit` persists service ID/epoch, domain, operation, actor ID after
authentication, client trace, trusted execution ID, start/completion, status and
stable error code. No input, password, authorization, service secret, Provider
secret, link token or response is stored in that audit table. Audit admission
must persist before business execution; a process crash leaves a started audit,
not a fabricated success. Audit storage failure blocks execution; completion
failure returns `503 AUDIT_UNAVAILABLE` and never claims committed domain work
rolled back. Preserve the original business key for recovery. All these error
responses are non-cacheable.
Audit state is `completed` for success, `rejected` for a known boundary/business
rejection, and conservatively `inconclusive` for 5xx or an unfinished idempotent
workflow; a 503 audit record is never evidence that funds work rolled back.

The HTTP ingress limiter precedes per-request audit admission: 600 requests per
minute per actual socket `REMOTE_ADDR`, independent of caller service IDs and
forwarded headers. It returns non-cacheable `429 RATE_LIMITED` without creating
another database audit row. Production must use a shared atomic limiter store;
this application limiter does not replace service-network ACLs, proxy mTLS or
deployment flood protection. Route mismatch and global installation/body-size/
plugin-bootstrap rejection also occur outside this per-operation audit boundary.
Once admitted, service/operation/input/actor rejection is durably audited without
untrusted input or raw identity assertions.

Core checks the deadline again after actor authorization and before dispatch.
A deadline cannot cancel a financial action already admitted to its domain or
an external payment request already in flight; transport timeout still means an
unknown outcome, never permission to generate a replacement business key.

## Real Gateway consumer

`gateway/apps/gateway/src/services/native-internal.ts` exports the production
server-side `NativeCoreClient`. It uses Node HTTPS with the configured CA,
client certificate and key, mandatory server/hostname verification, fixed
origin/path and closed operations, response byte cap, wall-clock deadline,
no redirect and no automatic retries. It keeps decimal DTO values intact.
User and Admin service configurations are separate:

```text
TXBOARD_UPSTREAM_URL=https://core.internal/
GATEWAY_CORE_USER_ENABLED=true
GATEWAY_CORE_USER_SERVICE_ID=user-gateway
GATEWAY_CORE_USER_CREDENTIAL=<injected runtime secret>
GATEWAY_CORE_USER_EPOCH=1
GATEWAY_CORE_USER_CA_FILE=/run/secrets/core-ca.pem
GATEWAY_CORE_USER_CERT_FILE=/run/secrets/user-client.pem
GATEWAY_CORE_USER_KEY_FILE=/run/secrets/user-client-key.pem
```

The equivalent `GATEWAY_CORE_ADMIN_*` configuration instantiates an independent
Admin client. Missing enabled configuration fails boot closed; HTTP is rejected.
Runtime TLS files should be replaced atomically for rotation; the client reads
them per call and holds no unmanaged global secret cache. The wall-clock timeout
includes those file reads, connection setup and the entire response, including a
peer that continuously trickles bytes. Aborts and Core 5xx results preserve an
unknown-outcome error without upstream exception text; there are no retries.

The existing Gateway login and safe site-config consumers use this channel when
configured. Native business and Admin consumption is available through this
same server-side SDK and the deployable CLI:

```text
node gateway/scripts/native-core-client.mjs system.capabilities.get user
node gateway/scripts/native-core-client.mjs commerce.orders.create user < input.json
node gateway/scripts/native-core-client.mjs network.nodes.list admin < input.json
```

Build the Gateway first. Business input comes from stdin; per-call
`CORE_ACTOR_BEARER`, `CORE_ADMIN_PATH` and original `CORE_IDEMPOTENCY_KEY` are
runtime secrets/context, never command-line arguments. Protect stdout because
login returns its new session there. This is a real API consumer, not a browser
Admin route: no new frontend or unimplemented public administrator transport is
enabled. Older Gateway browser order forms still use their explicit current
schema; they do not silently translate a legacy `plan_id/period` into a native
offer or claim native purchase support.

## Acceptance

CI must run API tests for service/actor domain separation, socket/proxy spoofing,
certificate binding, expiry/rotation/revocation, current admin grants/path,
unknown input and deadlines, same-key replay/conflict, resource ownership and
audit redaction. Gateway tests cover independent credentials, exact original
key, unrounded decimal strings, no TLS-header assertions, closed paths,
untrusted TLS failure and no retry/secret leakage. Database/Redis/real TLS and
process tests are CI responsibilities; local checks are syntax/diff only.
The real HTTPS tests verify client/server CA transport and hostname rejection;
they are not evidence that a deployment proxy strips spoofed identity headers,
checks service SAN/EKU/revocation, or re-encrypts the Core socket. Those proxy
deployment checks and evidence, shared-store multiprocess ingress enforcement,
SQL crash/concurrency recovery and CI execution are still acceptance work.
