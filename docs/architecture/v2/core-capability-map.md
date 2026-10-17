# Core capability and transaction map

> K0 inventory, K1 entitlement, K2 adapters, K3 fulfillment and K4 account/order read candidates, 2026-10-17.
>
> Starting source: local `main` at `85b0eac62f73f636efe736548e968535c561ced3`.
> This map records actual candidate scope. K1-K3 exact-commit development CI is
> recorded below, as is the accepted K4 account-read development CI. The new
> order-read subset still needs its own CI and domain review.
> None of these candidate branches is an assumed merged or production baseline.

Core is a modular control plane for one operator installation and its end users,
not a generic plugin shell or an implemented multi-operator tenancy platform.
The [detailed design](core-kernel-modular-design.md) and
[port draft](../../../contracts/v2/core-module-ports-v1.md) describe the wider target.
Current native behavior remains governed by its domain contracts and evidence.

## 1. Public capabilities and authoritative owners

| Capability | Current owner / entry | Transaction and invariant |
| --- | --- | --- |
| Identity and account | `Domains/Identity`: `AccountCreationService`, `AccountSqlPhase`, `AdminAccountModeration`, `AccountSessionGuard` | Current identity and role checks remain separate from entitlements and money; no user traffic fields become native entitlement authority. |
| Account read projection | Identity `AccountQueries` / `NativeAccountQueries` | Authenticated actor scalars from ingress; delegates entitlement facts to Subscription; no actor cache or independent authorization. |
| Order read projection | Billing `OrderQueries` / `NativeOrderQueries` | Existing owner-scoped list/status/detail with current Plan display; no write, financial snapshot or authority for a later mutation. |
| Catalog administration | `NativePlanAdministration::publish`, `PlanPublicationService::publish/setAvailability`, `NativePlanCatalog` | Immutable publication and current selling availability are distinct; purchased evidence is not repriced. |
| Purchase | `PublishedOrderService::create` | Account-first locks, permanent order key, frozen purchase and upgrade-source evidence; no provider network request in this transaction. |
| Order transitions | `PublishedOrderService::transitionOrder` and `NativeOrderLifecycle` | Controlled internal transitions, not an external arbitrary callback or `setStatus` capability. |
| Fulfillment | `SubscriptionFulfillmentService::fulfill/applyLocked` | Public fresh transaction versus explicit SQL-only participant; permanent `order.fulfill` evidence owns replay. |
| Subscription lifecycle | `SubscriptionLifecycleService::suspend/resume/revoke/expire/reset/tick` | Account/Slot/history, expected versions, Terms/Period boundaries and synchronization intent change atomically. |
| Entitlement projection | `SubscriptionEntitlementResolver` behind the K1 public/locked contracts | One non-secret projection; diagnostic public reads and strict transaction-internal reads retain different error semantics. |
| Payment checkout and receipt | `NativePaymentCheckout`, `NativePaymentAttempts`, `NativePaymentSettlement` | Frozen dispatch and verified facts; one winning allocation, real extra/late money retained as liability. |
| Free and wallet checkout | `NativeFreeCheckout`, `NativeWalletPayments`, `NativeWalletReservations` | Zero-price acceptance creates no fake allocation/receipt/journal; wallet and mixed allocations preserve reserve/capture/release evidence. |
| Ledger and refunds | `NativeMoneyLedger`, `NativeRefundService`, `NativeUpgradeCredits` | Native journals/postings and account projections; cash claims and refunds share the original verified budget. Posting primitives are not public extension capabilities. |
| Financial recovery and reconciliation | `NativeBillingRecovery`, `NativeBillingReconciliation` | SQL work drives bounded recovery; reconciliation is read-only and reports incomplete scans, not inferred success. |
| Credential lifecycle | `NativeCredentialProviderService`, `NativeCredentialSynchronization` | Persist intent, call Provider outside SQL, validate and persist result; unknown outcomes recover the original operation. |
| Links and delivery | `NativeSubscriptionLinks`, `NativeSubscriptionDelivery` | Ownership and finite delivery policy; bearer delivery is a separate authorized response, not entitlement DTO data. |
| Node authorization | `NativeProviderNodeAuthorization` | Provider metadata outside SQL followed by final locked identity/Terms/Period/version verification and finite authorization. |
| Usage | `UsageReceipts`, `UsageSettlement`, `NativeUsageReservations` | Original source/session/period and batch evidence, durable reservation, current fencing, atomic settlement and reservation release. |
| Installation | `InstallationAuthority`, `NativeRuntimeAdmission`, `NativeRuntimeReadiness` | Durable installation/epoch and admission precede domain access; a cached success or environment flag is not authority. |
| Extension runtime | `PluginManager`, `ModuleRegistry`, `ModuleLifecycle`, specialized Plugin/Theme adapters | Registry reads describe runtime facts; controlled lifecycle delegates to the existing owner, not another business kernel. |

Subscription services remain in
[`api/app/Domains/Subscription/Services`](../../../api/app/Domains/Subscription/Services);
the [domain ownership notes](../../../api/app/Domains/Subscription/README.md)
describe their existing cooperation. The K1 seam does not move domain algorithms,
tables or ordinary public/locked transaction ownership.

## 2. K1 entitlement seam: all production consumers

The minimal framework-neutral Kernel addition is `Clock::now(): int`.
`Core/Infrastructure/SystemClock` provides the host implementation.
`CoreCapabilitiesServiceProvider` explicitly binds the clock and both entitlement
contracts to the existing resolver; no new module registry or generic container is
introduced.

The public contract is
`App\Domains\Subscription\Contracts\EntitlementQueries::forAccount(EntitlementQuery): EntitlementView`.
The immutable query/view belong to Subscription, not Kernel. The view converts to
the current wire array; it does not grant access or introduce a new HTTP endpoint.
The public query obtains business time from the injected clock, not client input.

The separate internal contract is
`App\Domains\Subscription\Internal\LockedEntitlementQueries::resolveLocked(int, int): array`.
Its existing array and caller-supplied transaction boundary time are intentional:
it is not a public DTO or a route available to plugins or clients.

| Production call site | Contract | Existing responsibilities that stay with the caller |
| --- | --- | --- |
| `NativeAccountQueries::account` | Public `EntitlementQueries` | HTTP and Internal API derive the current actor first; preserve account identity fields and entitlement result. |
| `NativeAccountQueries::subscription` | Public `EntitlementQueries` | Shared native subscription projection; HTTP alone appends its delivery endpoint. |
| `NativeSubscriptionLinks::issue` | Internal `LockedEntitlementQueries` | Recheck actor/subject ownership and entitlement inside the link-issuance transaction. |
| `NativeSubscriptionDelivery` locked projection | Internal `LockedEntitlementQueries` | Preserve metadata-before-final-check workflow, account locks and finite delivery authorization. |
| `NativeProviderNodeAuthorization::projectLocked` | Internal `LockedEntitlementQueries` | Preserve complete signing transaction locks and final verification; never sign a cached public view. |
| `NativeRuntimeWorker` usage-event handling | Internal `LockedEntitlementQueries` | Preserve installation/account/subscription locks and the original durable event identity. |

These remain six call sites in five direct production consumers after K4 moves
the two account projections out of the HTTP controller. Direct concrete resolver
dependencies remain only in its own implementation and the composition provider.
Existing domain tests may still call the implementation to verify its native
algorithms; a test reference does not authorize another production caller.

### Public and locked reads are not interchangeable

- The public `resolve` implementation rejects an existing outer transaction,
  starts a fresh one, checks persistent installation admission first and returns
  the existing safe denied view for known storage/evidence failures.
- The locked projection requires the original caller's transaction and account-first
  lock plan. Damaged evidence raises an error to abort the signing or mutation
  transaction; it must not be sanitized into a normal diagnostic result.
- Neither reads User plan/traffic fields as a native fallback, creates accounts,
  resets a period, changes credentials or repairs damaged history.
- A typed query is not proof of authorization. Ingress checks, current account
  status, resource ownership and installation admission remain necessary.
- The existing direct resolver methods remain internal algorithm/test surfaces;
  production consumers use the appropriate public or locked contract.

### Internal API delegates account reads without HTTP controllers

`OperationRegistry` maps `identity.account.get`, `identity.admin.get` and
`subscription.mine.get` to compiled `AccountReadOperations` handlers. They use
Identity `AccountQueries`, the same application query consumed by native HTTP.
The executor still verifies the separate service, actual actor, current grants,
domain, closed input and output field allow-list. The next candidate also maps
`commerce.orders.list/get` to fixed OrderReadOperations using Billing OrderQueries.
Other operations keep their existing controller targets; these subsets do not
claim all ingress is migrated.

Do not remove these checks because a handler now injects a typed capability.
Neither mTLS nor a stored workflow response replaces current actor authorization.
Octane and Worker must not retain actor/context state across requests or jobs.

## 3. Current protocol adapters

| Boundary | Existing adapter and composition | Preserved restrictions |
| --- | --- | --- |
| Payment | `NativePaymentProviderInterface`, `NativePaymentProviderRegistry`, the installed payment plugin | No concrete EPay dependency in Core Billing. Frozen package/configuration identity and permanent dispatch pin remain. Disable blocks new checkout, not narrowly authorized historical verification/query. |
| Payment callback | `PaymentNotificationProcessor` -> `NativePaymentCheckout::notify` | Independent provider identity and original dispatch; no User/Admin bearer. ACK follows durable settlement, not successful protocol parsing alone. |
| Credential Provider | `ProviderTransport`, codecs, `NativeCredentialProviderService` | Fixed peer, mTLS, bounded requests, explicit operation identity; proxy secrets stay in the independent Provider and direct delivery channel. |
| Internal API | `OperationRegistry`, `OperationExecutor`, service credentials and HTTP middleware | Compiled operations, real actor and current permission; no caller-selected class/method/SQL/URL. |
| Module inventory/lifecycle | `ModuleServiceProvider` and specialized Plugin/Theme adapters | Existing scoped inventory and controlled delegation; no universal invocation mechanism or second module state table. |

Payment-provider and Credential-Provider identities are unrelated security
domains. PHP plugins are trusted in-process code, not sandboxed programs.
Interfaces and source checks do not technically isolate malicious PHP from the
host. The K1 seam introduces no new extension permission or old-package adapter.

## 4. Transaction collaboration that must not change

1. `NativeBillingTransaction::run` and public Subscription operations retain their
   fresh-transaction requirements. Wrapping them in another transaction is not a
   supported way to compose domains.
2. Upgrade acceptance already uses `SubscriptionFulfillmentService::applyLocked`
   to commit financial/free acceptance and SQL entitlement replacement atomically.
   It uses the complete admission/account/history/order/money lock scope, with
   network synchronization only after commit.
3. Current financial evidence reads and replay remain current locking reads under
   the complete sorted money-account union. A generic repository must not turn
   them into stale MySQL repeatable-read snapshots.
4. Paid acceptance proves the real allocation and its wallet/channel components.
   Free and zero-net upgrade acceptance instead proves the durable free-checkout
   record. No abstract proof type may demand a fabricated allocation or receipt.
5. Wallet refunds retain `destination=wallet` and `entitlement_effect=none`.
   Partial/full refunds do not change the order, subscription, period or any
   fulfillment/free/credential work. Independent revocation is a separate
   authorized operation.
6. Verified money received after cancellation, account ban or channel disable is
   still a financial fact, not an ordinary rejected user request. Existing
   settlement rules retain it as liability when it cannot win payment.
7. Billing work, free work, credential synchronization and Usage outboxes retain
   their own durable identity, foreign keys and completion evidence. Queue success
   is not transaction success; an unknown network result does not authorize a
   new side-effect key.

The [payment settlement](../../../contracts/v2/native-payment-settlement-v1.md),
[wallet/refund](../../../contracts/v2/native-wallet-refunds-v1.md) and
[upgrade](../../../contracts/v2/native-upgrade-credit-v1.md) contracts retain
their product scope. This seam does not add original-channel refunds, broader
currencies, multi-tenancy or additional native Node protocols.

## 5. Enforced static scope and CI ownership

The existing [`check-core-boundaries.py`](../../../scripts/check-core-boundaries.py)
retains its retired-code and current-domain checks. This candidate extends it
for the actual K1 and K2 surfaces:

- Require the concrete Kernel/contract/composition files and their namespaces.
- Reject framework, host I/O and business implementation dependencies in Kernel.
- Reject host/framework, persistence, callable and implementation dependencies in
  Subscription and CredentialProvider public contracts; arrays and ordinary PHP
  value types remain valid.
- Reject concrete entitlement resolver dependencies outside its exact owner and
  composition provider, including production config/routes and bundled plugins.
- Reject concrete ProviderTransport dependencies outside its implementation and
  composition provider; five current business consumers use ProviderClient.
- Leave other existing application `app()` calls and internal locked arrays alone.
  This is not a generic ban on service containers or a demand to rewrite Core.

The scanner ignores ordinary PHP comments but checks quoted dependency names.
It is deliberately a small source guard, not a PHP parser, security sandbox,
proof of authorization, dynamic dependency analysis or transaction validator.
Review and behavior tests remain required.

[`test_check_core_boundaries.py`](../../../scripts/tests/test_check_core_boundaries.py)
contains synthetic temporary-tree fixtures and never starts PHP or a database.
The existing [`ci-api.yml`](../../../.github/workflows/ci-api.yml) runs the guard
and its fixtures before application setup; both push/PR filters include them.
No new automatic workflow or duplicate full acceptance matrix is introduced.

Current behavior anchors include `NativeSubscriptionEntitlementTest`,
`NativeProviderNodeAuthorizationTest`, `TxapiNativeInternalApiTest` and the
native Subscription/Usage tests. API owns the normal PHP suite; MySQL's existing
subscription-domain job owns the relevant database behavior. Added tests must
be selected by their actual owning jobs before claiming their CI coverage.

Local work is limited to syntax, difference and source checks. Authored tests
and CI wiring are not passing evidence. Exact-head database, provider/node and
long-running-process acceptance remains centralized in CI.

## 6. Deferred work

K1 supplies a real typed read seam and explicit locked collaboration, not a
completed abstract Core framework. Financial transaction participant types, direct domain dispatch for all ingress, other
capabilities and a complete dependency graph remain K3-K5 work only when an
actual use case justifies them. Ordinary business work continues in its owning
module under the [feature development guide](../../development/core-feature-development.md).

## 7. K2 current adapter candidate

K2 builds on the K1 candidate commit `e76abc9`, not an assumed merged baseline.
`CredentialProvider/Contracts/ProviderClient` exposes the current five methods;
`ProviderTransport` directly implements it with a scoped composition binding.
NativeCredentialProviderService, NativeSubscriptionDelivery,
NativeProviderNodeAuthorization, NativeRuntimeReadiness and NativeProviderTrust
now depend on this contract. There is no new adapter wrapper or second codec.

Native `call`, `metadata` and `capabilities` reject an active owning SQL transaction
before configuration or network work. Local configuration/digest reads remain
available inside existing locked workflows. Frozen configuration, original
operation recovery, Node snapshot checks and secret ownership remain unchanged.
The precise surface is [Core Provider Client v1](../../../contracts/v2/credential-provider/core-provider-client-v1.md).

Payment retains its existing plugin interface and Registry. The Registry now
rejects `external_refund=true` or `query_refund=true`, including frozen recovery
resolution, because the current interface cannot execute those operations.
Well-formed disabled-channel frozen query/notification recovery is unchanged.
Wallet refunds retain `entitlement_effect=none`.

Existing module inventory/lifecycle adapters remain the authoritative extension
seam; K2 does not add another registry or open generic module invocation.
Candidate regression tests and source guards cover the changed boundaries.
K2 commit `832a8e8` passed its triggered API, MySQL and real Provider development
checks in PR #264. It remains unmerged and does not prove production PKI,
arbitrary Provider protocols or original-channel refunds. K4/K5 and release
qualification remain separate work.

## 8. K3 fulfillment and financial proof candidate

This subset builds on K2 candidate `832a8e8`; it is not a merged baseline.
The [Order Fulfillment Ports contract](../../../contracts/v2/core-order-fulfillment-ports-v1.md)
records the exact three interfaces and their failure/locking rules.

| Owner capability | Real consumers migrated | Commit authority |
| --- | --- | --- |
| Subscription public OrderFulfillment | BillingRecovery, FreeCheckout recovery, OrderLifecycle open | Own fresh SQL transaction, then credential synchronization |
| Subscription internal LockedOrderFulfillment | UpgradeCredits acceptance (four existing acceptance entry paths converge here) | Caller commits financial acceptance and SQL rights atomically |
| Billing internal WinningPaymentProofs | Subscription fulfillment, BillingRecovery, UpgradeCredits source verification | Caller transaction; money union and authoritative allocation/receipt/journal/wallet checks |

Both fulfillment interfaces resolve to the existing scoped owner. The proof
interface resolves to the existing scoped settlement owner. No domain algorithm,
work table, payment format or public route changes. Internal model and collection
inputs retain existing contracts; they do not prove locks or authorization.

The proof participant can initialize money accounts and is not a side-effect-free
query. A free/zero-net acceptance does not create a zero-value payment proof;
liability receipts cannot grant rights. SQL-only participants never synchronize
credentials or perform network I/O. Fresh fulfillment drives synchronization only
after commit and retains the original durable operation/work for replay.

Source checks reject concrete fulfillment use outside implementation/composition
and concrete settlement use in Subscription services; they deliberately preserve
legitimate Billing settlement entry points and internal SQL model types. Tests
cover scope/replacement and real transaction/proof/rollback behavior in existing
API/MySQL acceptance. K3 commit `c8f782b` passed its triggered API (1179 passed,
33 skipped), MySQL billing/subscription and concurrent recovery, real C2,
installation, docs and verify checks in PR #265. This is exact-commit development
evidence, not a result for later K4 edits or a release/merge declaration.

Identity/account creation, catalog publication, purchase coordination, lifecycle,
wallet/refund and usage already retain their listed domain owners/transaction
contracts. This slice does not claim every mutation now has an interface; further
ports require an actual consumer and must retain their original atomic boundary.
K4 ingress delegation, K5 integrated checks and release qualification remain open.

## 9. K4 account read ingress candidate

This subset builds on K3 candidate `c8f782b`, still unmerged. The
[Account Read Ports contract](../../../contracts/v2/core-account-read-ports-v1.md)
records Identity's two query methods, scalar actor projection and the three
fixed Internal API handler targets. No new business or service operation exists.

| Real consumer | Shared capability | Entry owner responsibilities |
| --- | --- | --- |
| Native AccountController me | AccountQueries account | Actual Sanctum actor and current HTTP shape; no client-selected account |
| AccountController subscription | AccountQueries subscription | Actual Sanctum actor; HTTP-only delivery endpoint |
| identity.account.get | Same account query via AccountReadOperations | User service identity, actual actor, unchanged output projection |
| identity.admin.get | Same account query via AccountReadOperations | Admin service/path/role/token ability/current identity.read grant; only id/email output |
| subscription.mine.get | Same subscription query via AccountReadOperations | User service identity, actual actor, unchanged entitlement field allowlist |

Queries delegate to the existing EntitlementQueries owner and preserve denied
views, installation errors and outer-transaction refusal. They do not cache the
actor, write business state or create internal request receipts. Session
last-used writes and audit remain separate entry behavior. Administrator reads
retain the existing entitlement query dependency before output filtering.

Fixed handler results rejoin common projection, recursive secret redaction,
decimal-string normalization, size limits and error handling. The remaining
controller invocation block is extracted without changing its request/form/
route mapping; login, orders, checkout, lifecycle and other operations retain
their current owners and idempotency behavior.

Identity public contracts and concrete-owner dependencies are statically guarded;
only the new account handler has a direct HTTP/auth/SQL dependency fence. No
source heuristic proves authentication or dynamic calls safe. Behavior tests
cover shared query replacement, alternating actual actors and authorization
failure; account-read development CI is recorded below.

Account-read candidate `a4cee43` (PR #266) passed its triggered API/MySQL/C2/image/
docs development checks, and remains unmerged. This does not qualify a release.

## 10. K4 order-read candidate

The exact contract is [Core Order Read Ports v1](../../../contracts/v2/core-order-read-ports-v1.md).
Billing's scoped OrderQueries implements the existing AccountController queries;
no order, payment or subscription write authority moves.

| Consumer | Port method | Preserved behavior |
| --- | --- | --- |
| HTTP orders | listing | Actual actor, validation, status filter, deterministic order and page metadata |
| HTTP order | find | Actual actor, summary-only fields, owner/missing 404 |
| HTTP orderDetail | detail | Detail-only financial display/current Plan fields; missing Plan 404 |
| commerce.orders.list | listing via OrderReadOperations | Separate user service and real actor; closed input, existing page bound and projected metadata |
| commerce.orders.get | find via OrderReadOperations | User-only actual owner scope; no caller-supplied class or owner |

Summary keeps a null Plan when missing; detail still requires a current Plan.
Current Plan display is not frozen purchase evidence. Queries use the current
connection and isolation without adding transactions/locks or claiming an atomic
financial snapshot. They create no business writes or internal request receipts.

Account and order read handlers return adapter-local ReadOperationResult data/
metadata, or null for a missing order. Executor maps this through the existing
HTTP envelope and output/meta allowlists, redaction, decimal conversion, limits
and safe errors. It continues to authenticate service/actor before dispatch and
audits the result. This result DTO is not a Kernel or plugin invocation API.

Pure Billing contracts, concrete consumers and fixed handlers have source guards.
New tests cover real HTTP/Internal parity, paging, owner isolation, missing Plan,
port replacement, admission rejection and failures. Exact-commit CI for this
order-read candidate is pending. Other justified ingress slices, K5 integrated
handoff and production qualification remain separate work.
