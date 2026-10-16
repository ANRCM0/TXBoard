# Core Subscription v1: installation, catalog and native domain integration

> C2-A preparation and C2-B candidate domain integration. This is not a complete
> native Subscription runtime, a production activation contract or an authority
> migration. C2-B acceptance is tracked separately from the accepted C2-A batch.

## Persistent Installation Authority

The existing singleton `tx_authority_writer_fence` is extended, not replaced.
Installation metadata uses scope/version `core-installation/v1`, a caller-chosen
installation UUID, bootstrap business key, request hash, schema/domain versions,
authority epoch and content hash. `authority` is the persisted `legacy` or
`native` choice; environment variables only describe the process expectation.

`core:installation init --installation-id=UUID --mode=legacy|native --key=KEY`
creates the record transactionally. Repeating the same request returns the same
identity; a changed key, identity or mode is a conflict. Existing isolated fence
and receipt/upgrade evidence is never repurposed or deleted. A legacy record
may describe an existing legacy deployment; this does not migrate its data.
On MySQL-compatible databases a database-scoped connection advisory lock
serializes the first insertion before its transaction: compatible empty-key
gap locks alone do not serialize bootstrap. It is execution coordination, not
another persistent authority record, and is released on every return/error.

A native initialization must prove that every table is empty except the exact
configuration/migration allowlist (`migrations`, `tx_settings`,
`tx_subscribe_templates`) and bootstrap administrators with no business
entitlements, usage or funds. Non-admin accounts, pending jobs, financial,
subscription, usage, shadow and unknown nonempty tables refuse initialization.
Checking only the number of users is insufficient.

Native starts in `initializing`. This batch provides no activation operation.
The complete C2 lifecycle, common entitlement reader, Node/usage consumers,
Provider integration and writer coverage must be implemented and accepted
together before a later contract can permit readiness. Flipping configuration,
relabeling shadow rows or invoking a fixture Provider cannot do so.

## Process Admission

`CORE_INSTALLATION_MODE` defaults to `legacy`; `CORE_INSTALLATION_EPOCH` defaults
to `1`. Uninitialized legacy deployments remain compatible. Native requires the
persisted installation identity, version and epoch; missing, damaged, mismatched
or initializing state refuses runtime admission. Changing the expectation back
to legacy does not bypass a persisted native record.

HTTP business requests, queued jobs and business Artisan commands check the
same authority without a process-global mutable cache. Named legacy SQL writers
also check it inside their transaction, retaining the installation lock through
the write. Octane requests and long-lived workers recheck on every work item.
The legacy isolated writer fence remains an independent compatibility boundary;
turning its flag off does not disable installation admission.
Authority and historical-evidence reads use the primary database connection,
including inspection outside transactions. A stale read replica cannot hide a
persisted native record or reopen legacy HTTP/queue/CLI runtime admission.
Inspection inside a transaction uses a shared locking current read, so choosing
the native usage path does not establish a repeatable-read snapshot before its
later aggregate locks. This also applies to the old-schema compatibility path.

`/api/health` and `/txapi/health` remain database-independent liveness probes.
The public read-only `/txapi/readiness` returns only readiness, with a TXAPI
request ID; refusal is HTTP 503 with a bounded non-secret error code. It does not
expose installation UUIDs, bootstrap keys, schema diagnostics or credentials.
Forward schema migration and explicitly enumerated diagnostic/preparation
commands remain available for operator recovery; destructive migration commands
are not implicitly allowed on an initializing native installation.
Image filesystem preparation, native-schema diagnostics and the Octane HTTP
process may start without database readiness so liveness remains reachable.
Starting HTTP does not admit business requests; its middleware checks each
request. Queue work and scheduled business commands retain execution admission.
The container entrypoint uses the silent `core:installation native-preparing`
predicate to skip the legacy updater for a validated native preparation record.
It does not run the legacy plugin/settings updater under native authority.

These guards constrain supported application entry points, not trusted PHP
plug-ins or an operator's arbitrary SQL. They are not a plug-in sandbox.

## Immutable Plan Publication

`PlanPublicationService` owns a real transaction, validates the installation
preparation admission, and serializes a plan's publication by locking its Plan.
It uses the existing `tx_plan_revision`, `tx_plan_offer` and
`tx_plan_offer_availability`; no shadow checkpoint or catalog shadow pointer is
promoted to production authority.

Native catalog preparation is allowed only under a validated native
`initializing` record with the matching process expectation/epoch. It is not
business readiness. There is no new public administrator publication route in
this batch; existing API catalog/pricing behavior remains legacy.

Published revisions have `schema_version=plan-publication/v1` and a
plan-scoped publication business key plus request hash. Quota must have an
explicit finite meaning; unknown legacy null quota is not unlimited. Prices use
exact integer minor units, supported currency and a frozen calendar/reset
policy. The service may share pure value conversions with C1 but must not call
the isolated catalog capture transaction or alter historical purchase evidence.

The same key and frozen source replay the existing revision and never reset
mutable availability. The same key with different source refuses. A successor
uses a monotonically increasing revision number across both legacy evidence
and native publications; earlier immutable records are not updated.

Availability is mutable and separate from the frozen purchase terms. Native
offers use `published`; legacy `shadow` offers never become saleable by
inference. Changes to show/sell/renew/capacity use an expected row version under
Plan -> Revision -> Offer -> Availability locks. Stale versions, malformed
fields and attempts to manage shadow offers refuse.

Schema replay recognizes only the three enumerated C2 enum expansions when
their complete version marker columns exist. It neither narrows these columns
back to v1 nor accepts arbitrary enum supersets; old migrations are unchanged.

## C2-A Handoff

The C2-A preparation batch did not bind C1 orders to published offers, fulfill native
Subscription/Terms, settle native UsagePeriod, issue Provider credentials or
serve native Node/REST/WS/subscription-link entitlements. Those must consume the
same persisted authority and aggregate invariants in subsequent C2 integration.
New frontend, money ledger/refunds, full Internal API and deployment release
remain outside this preparation batch.

## C2-B candidate domain integration

C2-B implements native purchase/fulfillment and lifecycle services behind the
existing readiness gate. It adds no activation operation.
`InstallationAuthority::assertNativeMutation()`
and `assertNativeRead()` require a validated native **open** record; this version
cannot create or validate such a record. Tests may substitute that admission
boundary to exercise domain transactions, but production HTTP, queue and CLI
remain closed while the installation is `initializing`.

Native purchase evidence uses `tx-core.purchase-terms/v2`, extending the closed
C1 snapshot with `catalog = {revision_id, offer_id, publication_hash}`. It is
constructed from verified immutable published records, not the mutable Plan.
The existing order and purchase-terms tables remain the purchase authority.
Legacy v1 evidence and fulfillment are unchanged. Native purchases do not use
legacy wallet, promotion, commission or surplus-credit fields; unsupported
financial adjustments refuse until the C3 ledger integration.

Native aggregates use `authority_version=core-subscription/v1` and the persisted
installation UUID. Terms use `schema_version=core-subscription-terms/v1`,
`source_order_id`, `source_snapshot` and a real catalog revision reference.
Historical shadow rows retain null markers and are never promoted. The single
canonical `tx_subscription_operation` stores immutable request/result evidence
and a user-scoped business key for fulfillment and lifecycle operations; its
unique order reference prevents a paid order from granting twice.

Writers lock installation -> User -> persistent Slot -> sorted Subscription
rows -> Order (where applicable) -> Terms/Transitions -> Periods. Catalog
purchase creation locks Plan -> Revision -> Offer -> Availability after the
account/slot, and never reacquires an account after catalog locks. Domain
services own fresh transactions; caller-owned transactions refuse. Order
completion and operation evidence commit atomically with entitlement changes.

New purchases occupy the one persistent slot. Renewals append frozen Terms and
a Transition, extend expiry with the purchased calendar and preserve the same
usage period/counters unless an explicit frozen reset event applies. Period
`terms_id` identifies its opening terms, not necessarily the current tip;
authorization must record both the effective Terms and the original period.
Plan changes preserve consumption unless the frozen purchase explicitly resets
it. Resets close, never erase, the old period and open a new zero-counter period.
Lifecycle operations require expected aggregate versions and immutable business
keys. Suspended subscriptions reserve capacity; revoked subscriptions cannot
resume. Terms are append-only, and accepted usage cannot be reassigned across
a newly introduced boundary.

The common entitlement resolver reads account ban status, native aggregate
ownership, the terms timeline and the open usage period consistently. Legacy
User entitlement/counter fields never grant native access. Its result is
non-secret and is not a Provider credential or a Node authorization snapshot.
Production Node REST/WS/delta, subscription distribution, Provider issuance and
native usage admission/settlement remain required before activation.

The usage receipt/registry/settlement adapters recheck installation admission
within each transaction and require the native installation marker on every
Subscription they use. Historical isolated usage behavior retains its separate
gate. These checks prepare domain ownership; a test that substitutes native
admission does not prove production Node credential delivery, usage transport or
end-to-end settlement readiness.

### Native application adapters

The existing authenticated `POST /txapi/orders` selects native purchase from
persisted installation authority, requires `offer_id` and `Idempotency-Key`,
and rejects legacy Plan/period, coupon and financial adjustment inputs.
It delegates to `PublishedOrderService`; it never changes an order to paid.
Legacy installations retain their current request contract.

Administrator subscription operations use
`POST /txapi/admin/{admin_path}/subscriptions/{id}/operations/{operation}`
with the existing administrator authentication and request audit middleware.
Only suspend, resume, revoke, expire and reset are accepted. A mandatory
`expected_version` and `Idempotency-Key` serialize/replay the operation; account
ownership is read from the aggregate, never selected by the caller. These
operations do not issue Provider credentials or directly edit legacy User
entitlements. Initializing installations still reject the route.

`core:subscriptions-tick --limit=100` advances native expiry and scheduled reset
boundaries with bounded work and stable business keys. Scheduled reset uses
the stored boundary, not the worker's current wall clock; missed periods are
processed individually. It never invokes legacy traffic reset. Production
admission remains mandatory for the command and each domain transaction.

The native scheduler also runs `check:order --limit=100`, scoped to v2 purchase
orders. It recovers committed processing orders through the existing
idempotent fulfillment job and cancels unpaid reservations after two hours.
An installation-scoped cache cursor rotates bounded scans past failed orders;
losing the cursor only repeats safe dispatches. The job reads current order
status from the primary database and rechecks native admission before writing.

### Remaining activation prerequisites

The next integration batch must define and verify one explicit ready-state
transition, real Provider-backed AccessSubject issuance and rotation, shared
entitlement consumption in Node REST/WS/full/delta snapshots and subscription
delivery, and accepted usage from those grants through durable UsagePeriod
settlement. It must reject replayed legacy work and demonstrate the required
writer/queue drain for the installation. No schema relabel, process flag,
hand-edited and rehashed `native/open` row, or test admission substitute meets
these prerequisites in this version.
