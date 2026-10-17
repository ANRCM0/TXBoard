# Core Usage Queries v1

Status: implementation candidate, 2026-10-17. This is a K4 ingress ownership
change against merged `f59f167`, not a new usage settlement or release contract.

## Shared owner and consumers

`UsageV2\Contracts\UsageQueries::listing(int $userId, UsageQuery $query): UsagePage`
owns the existing NativeUsageController algorithm. The scoped NativeUsageQueries
implementation is shared by HTTP `GET /txapi/me/usage-periods` and the fixed authenticated
`usage.mine.list` Internal API read handler. The real Sanctum actor supplies the
user ID; no request field, service identity or DTO proves ownership.

The pure input is an optional positive subscription ID, nonnegative exclusive
period cursor (default 0) and limit 1..100 (default 100). HTTP and Internal input
validation keep their existing wire types and bounds. An omitted subscription
selects the actual owner's current Slot. An explicit ID may select an owned
historical subscription. Missing and other-owner IDs both produce 404.

## Transaction and integrity

The implementation retains the existing `DB::transaction(..., 3)` and the same
connection, installation `assertNativeRead`, account/Slot/subscription locks,
`lockHistory` and `verifyHistory` order. It does not add an outer transaction,
change ambient transaction behavior, split commit ownership or optimize history
validation to only the requested page. NativeSubscriptionStore remains the
aggregate integrity and transaction-local persistence owner.

This query is not write-free: lockAccount may create the initial empty Slot under
the existing user lock. No current subscription yields an empty page and commits
that existing initialization. A missing explicit target throws UsageTargetMissing
inside the transaction, so an inserted Slot and other transaction changes roll
back before either adapter maps the failure to 404. Returning null inside the
transaction and aborting after commit would change this behavior and is forbidden.

The existing 1000-entry account/aggregate history bounds and integrity failures
remain. Installation failure, corrupt terms/periods or reservation evidence never
become an empty successful page. The service neither changes Terms, Periods,
reservations, settlements or entitlement nor caches actors/results or calls a
Provider/network. The existing wall-clock read is only used for initial Slot
timestamps; this extraction does not change the clock or business policy.

## Output and ingress behavior

UsagePage contains only array items and nullable decimal-string nextAfterId. Each
item retains subscription_id, status, id, terms_id, period_no, quota_bytes,
upload_bytes, download_bytes, billable_bytes, row_version, starts_at, ends_at,
next_reset_at and consumed_bytes. Domain identifiers/counters remain decimal
strings, existing timestamps remain nullable integers. consumed_bytes uses the
existing NativeUsageReservations::consumed result, including reservations.

All history is verified before filtering period IDs greater than afterId, sorting
ascending and selecting the bounded page. nextAfterId is the last returned period
ID only when additional verified periods remain; otherwise null. Both adapters
retain meta schema `native-usage-periods/v1` and next_after_id.

HTTP retains closed input, validation, success envelope, 404 and successful
private/no-store responses.
Internal keeps the same service domain, actual actor, closed input, read mode with
no durable request receipt, field/meta allowlists, decimal normalization, response
bounds, safe failure mapping and audit. No command admission or operation is added.
The fixed handler depends on the public query contract, not HTTP/Auth/SQL.

## Acceptance

Targeted API/MySQL CI must cover actual alternating Bearer owners, omitted Slot
selection, explicit owned history, cross-owner/missing targets, empty pages,
cursor/limit, reserved consumption, corrupt history rejection, installation
failure, rollback of initial Slot on missing target, shared-port replacement and
Internal output/error controls. Static guards protect pure contracts, concrete
owner selection and the fixed handler. Local work remains static only; authored
tests do not count as executed acceptance. Production qualification is separate.
