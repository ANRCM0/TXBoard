# Core Subscription v1: installation and catalog preparation

> C2-A implementation boundary. This is not a complete native Subscription
> runtime, a production activation contract or an authority migration.

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

## Remaining C2

This batch does not bind C1 orders to published offers, fulfill native
Subscription/Terms, settle native UsagePeriod, issue Provider credentials or
serve native Node/REST/WS/subscription-link entitlements. Those must consume the
same persisted authority and aggregate invariants in subsequent C2 integration.
New frontend, money ledger/refunds, full Internal API and deployment release
remain outside this preparation batch.
