# Core Account Read Ports v1

> K4 candidate subset, 2026-10-17. Based on K3 candidate `c8f782b`, not a
> merged baseline at the time of authoring. Now merged via PR #266, with exact
> source development CI passed; no production qualification is implied.

## Scope and ownership

Identity owns `Contracts\AccountQueries` and the immutable scalar
`Contracts\AccountIdentity`. `NativeAccountQueries` implements the port and is
scoped in the composition root. It assembles the current native account and
subscription read projections; Subscription's `EntitlementQueries` remains the
only entitlement authority. This is not another identity store or authorization
engine. No database migration, route, operation or wire field is added.

| Method | Input | Result |
| --- | --- | --- |
| `account(AccountIdentity $identity): array` | Current authenticated actor's positive integer ID, email, nullable integer Telegram ID | Existing `id`, `email`, `telegram_id`, `subscription`, `credential_status` projection |
| `subscription(int $userId): array` | Current authenticated actor's positive integer ID | Existing entitlement projection plus `credential_status` and `delivery_formats` |

`AccountIdentity` contains no Request, User model, token, permission, closure or
caller-selected timestamp. Its fields are a projection of the actor resolved by
the entry point, not proof that the caller is authorized. No email validation or
new Telegram ID policy is imposed on existing authenticated account data.

Both methods call the existing public entitlement port using server time. They
must not be wrapped in another SQL transaction: the entitlement owner preserves
its fresh read transaction and installation admission. Denied entitlement views
remain valid read results; installation/dependency exceptions still propagate.
No new account, slot, money, work or credential truth is written by this query.
Sanctum's existing session last-used writes and Internal API audit are separate
entry responsibilities; these reads do not create internal idempotency receipts.

## Entry-point delegation

| Entry | Delegation | Entry-specific behavior retained |
| --- | --- | --- |
| Native HTTP `AccountController::me` | `AccountQueries::account` | Current Sanctum actor; existing HTTP envelope and scalar types |
| HTTP `AccountController::subscription` | `AccountQueries::subscription` | Current Sanctum actor; adds HTTP-only `delivery_endpoint` |
| `identity.account.get` | Fixed `AccountReadOperations::account` handler | User service domain; current user actor; existing output allowlist |
| `identity.admin.get` | Same fixed account handler | Admin service, path, actual administrator, current token ability and `identity.read` grant; output remains only `id` and `email` |
| `subscription.mine.get` | Fixed `AccountReadOperations::subscription` handler | User service domain; current user actor; existing entitlement allowlist |

Internal API registry targets are compiled server-side constants. A handler is
allowed only for a read with an authenticated actor; request input cannot select
a class, method, user ID or authorization decision. Other existing operations
retain their controller targets for later bounded work, not a newly introduced
fallback. No generic plugin handler registration is added.

The three reads no longer instantiate an HTTP controller or manufacture an HTTP
request to obtain business data. Their handler result rejoins the same executor
pipeline: field allowlisting, nested subscription filtering, recursive secret
redaction, decimal-string conversion, response size limit, normalized error and
no-store response. There is no early success return bypassing these controls.

`identity.admin.get` still evaluates the same account/entitlement query before
filtering its output. Narrowing its fields does not silently remove that
dependency or change its failure behavior.

Service authentication remains with `NativeServiceIdentity`: actual TLS socket
peer, certificate fingerprint, secret, epoch, expiry, revocation, domain,
installation admission and operation allowlist. Actor authentication remains
with `OperationExecutor::actor`, resolving Sanctum afresh on every call, then
applying current account/session policy and administrator permission checks.
Neither an `AccountIdentity` nor an in-process PHP interface replaces these.

## Acceptance and remaining work

Tests must prove shared query replacement, unchanged HTTP projection, internal
reads without resolving AccountController, alternating real Bearer actors,
closed input, current admin grant/ability and service credentials, nested field
filtering/redaction, response bounds and sanitized dependency failures. Existing
Internal API authentication, audit, checkout/replay and gateway regressions stay
required. Static guards protect pure contracts and direct concrete dependencies;
they are not a PHP sandbox or a proof of dynamic authorization.

This subset does not migrate all controller dispatch, order reads/writes,
session lifecycle, CLI or Worker operations. K3 already routes the current order
job and financial recovery through the fulfillment port. No Internal API
fulfillment operation, broader Gateway admin support, historical compatibility
layer or multi-operator tenancy is added. K4/K5 completion and production release
qualification remain separate from this candidate.

See [entitlement reads](core-entitlement-queries-v1.md),
[Internal API](native-internal-api-v1.md) and
[fulfillment](core-order-fulfillment-ports-v1.md).
