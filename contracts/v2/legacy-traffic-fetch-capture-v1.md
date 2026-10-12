# Unidentified legacy traffic increment capture v1

Status: isolated local/testing implementation. The existing User counter writes
remain authoritative. This extends [writer capture](legacy-user-mutation-capture-v1.md)
for `TrafficFetchJob`, the no-batch-ID branch of `UserService::trafficFetch`.
It is distinct from [identified batch settlement](legacy-traffic-batch-capture-v1.md).

Disabled capture retains the original one-statement `User::incrementEach` loop,
with no capture transaction, locking SELECT or evidence query. Enabled capture
uses one transaction per existing User: exclusive current User lock → exact
before snapshot → the same native multiplication and SQL assignment → append
and mapping invalidation → commit. No automatic transaction retry is added.
All original data normalization and collection iteration order remain intact.

The fixed policy is `traffic.fetch.apply`, version `traffic-fetch-capture/v1`,
actor domain `system_legacy_traffic_fetch`. Its actor ID is the canonical positive
32-bit Server reference from the queued work item. It does not independently
authenticate a Node or assert that this Server remains present. This path has no
Server lock or durable batch identity. Trusted traffic hooks and their original
queue payload boundary remain in place. Capture tickets cannot be exchanged
with the identified-batch or other writer policies. Old hashes/context remain.

The native arithmetic is intentionally different from identified-batch `floor`.
Fractional increments still use the original database assignment. MariaDB INT
rounding is recorded from the actual persisted row, never recomputed by capture.
SQLite may retain a fractional value in an INTEGER-affinity column; the exact
integer evidence profile then refuses and rolls that User's isolated transaction
back. Capture never rounds or repairs that value. Disabled behavior is unchanged.

There is no batch atomicity: if a later User fails, earlier successful User
transactions remain committed, as in the original loop. The failed current User's
counter and evidence roll back together. The original job has one try and a
20-second timeout. Repeating the same input is another increment and another
event; no synthetic idempotency key, original-result recovery or exactly-once
guarantee is introduced. Process death after commit can leave an unknown result;
blind replay can charge that User again. Pending unidentified traffic therefore
still must drain or quarantine before a future authority switch.

`StatUserJob`/`StatServerJob` remain separate queued consumers, and the original
Redis hint remains after the full loop. None joins the new per-User transaction.
Partial failure or death can leave missing statistics/notifications; this slice
does not repair or certify them. Missing Users retain the original hint behavior;
empty/invalid payloads and missing Users do not require capture configuration.
Unchanged captured fields create no event. Strict boolean/local/testing/isolated
context checks and source-gap rejection use the existing capture service.

Fixed capture exceptions retain `ShouldntReport`. Only their terminal callback
logs `LEGACY_TRAFFIC_FETCH_CAPTURE_FAILED` with empty context. Other failures
keep native queue semantics. Original serialized/failed-job traffic material is
not reclassified as shadow evidence. Capture SQL/DTOs exclude credentials,
raw reports, protocol, rate, callback and transport identifiers.

Bounded chain verification is SELECT-only. Event summaries use
`capture_boundary=transaction-only`, meaning this User transaction, not an entire
report. No Subscription/Terms/Usage/financial projection is applied. Global writer
coverage and the migration gate remain false; other writers, historical period
attribution, production cutover, lost hints/statistics and power-loss behavior
remain independent gates.

The [fixture](../../api/tests/Fixtures/LegacyTrafficFetchCapture/README.md)
exercises native SQL behavior and real local process death with synthetic data.
