# Subscription domain

Current subscription business services live in `Services/`. HTTP controllers,
console commands, Node authorization and UsageV2 consume domain capabilities.
Public entitlement consumers use `Contracts\EntitlementQueries`; existing
account-first transaction participants use `Internal\LockedEntitlementQueries`.
Both are scoped bindings to the same authoritative resolver. Other service
entry points retain their current contracts.
There are no forwarding classes in `App\Services\Subscription`.

## Ownership

- `PlanPublicationService` owns immutable revisions and published offers.
  `NativePlanCatalog` exposes verified availability.
- `PublishedOrderService` owns published-offer checkout orchestration and the
  account/subscription lock order required by checkout and order transitions.
  Billing retains order idempotency and immutable purchase snapshots/grants;
  those helpers do not independently grant subscription access.
- `SubscriptionFulfillmentService` and `SubscriptionLifecycleService` own
  subscription state transitions, purchased Terms and UsagePeriod boundaries.
  `NativeSubscriptionStore`, `NativeSubscriptionCalendar` and
  `SubscriptionTermsTimeline` supply aggregate validation and shared primitives.
- `SubscriptionEntitlementResolver` is the shared non-secret entitlement
  projection for current consumers. Consumers must not derive access from old
  User plan or traffic counters.
- `NativeCredentialProviderService` coordinates durable non-secret Provider
  operations. Transport and wire validation stay in `CredentialProvider`;
  actual credentials stay in the independent Provider process.
  `NativeCredentialSynchronization` stages durable synchronization work and
  drives it after the owning SQL transaction.
- `NativeSubscriptionDelivery` combines verified entitlements and Provider
  bindings into finite signed delivery policy. Network owns Node authorization
  signing; this service does not store proxy credentials.
- `PlanAccountPropagation` applies the existing administrator plan projection to
  a locked account set in the plan save transaction. Chunked SQL changes only
  group, traffic allowance, speed and device fields; purchased Terms are unchanged.

## Transactions

Public mutation/projection methods preserve their existing transaction contracts.
Checkout, fulfillment, lifecycle mutations, entitlement resolution and Provider
operations require a fresh transaction where enforced by the service. Callers
must not wrap them in a new outer transaction.

Methods explicitly named `*Locked` participate in the caller's transaction and
retain its account-first lock order. Provider network operations occur outside
SQL transactions, with persisted operation evidence and subsequent validation.
UsageV2 remains responsible for accepted usage reservations and settlement;
subscription transitions consume that authority instead of maintaining a second
usage balance.

Legacy Terms shadow writers are retired. Changes to purchased Terms go through
published-order fulfillment; resets and lifecycle changes go through
`SubscriptionLifecycleService`. `SubscriptionTermsTimeline` is a shared read-only
projection, not an alternative mutation entry. Usage settlement requires the
complete current frozen boundary evidence and never infers missing legacy fields.

Moving these services does not change database tables, wire contracts or lock
semantics. Historical shadow reservation helpers are not current domain entry
points.

The public entitlement query uses a server-owned `Clock` and returns an immutable
closed projection. It requires a fresh transaction and does not authorize callers.
HTTP adapters select the authenticated account. The internal locked query keeps
scalar arrays and propagates damaged-evidence errors so signed snapshot checks
retain their existing equality and rollback semantics. See
[the precise contract](../../../../contracts/v2/core-entitlement-queries-v1.md).
