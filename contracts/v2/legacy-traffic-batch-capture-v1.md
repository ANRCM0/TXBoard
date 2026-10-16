# Identified legacy traffic batch capture v1

> Retired: 2026-10-16 native development cleanup removes this capture runtime, flags and verifier entry points. The text below records historical behavior only. Database migrations and existing evidence remain; current account writes do not emit this shadow evidence. See [Core cleanup](../../docs/development/core-cleanup.md).

Status: isolated local/testing implementation. Legacy User counters and the
native `tx_traffic_batch` settlement remain authoritative. This extends the
[fixed writer capture](legacy-user-mutation-capture-v1.md); it does not activate
Usage v2, attribute old traffic to a Subscription or apply shadow projections.

`TrafficBatchJob` keeps its existing normalization, sorted payload hash,
Server → sorted User locks, unique batch identity, rate/floor arithmetic,
overflow checks, user/node statistics and three transaction attempts. After the
native duplicate check, each existing User starts a capture ticket under its
already-held exclusive lock. Append follows that User's native billed counter
and statistics changes. Batch claim, all User changes, capture streams/events,
mapping invalidation, node counters and statistics commit or roll back together.
A later User failure or final node overflow cannot leave an earlier User event.
In the explicit capture path, the existing bounded user-statistics and node-statistics
lookups use shared current reads under their native User/Server fences. An older
outer MVCC snapshot must not mistake already-committed unique statistics rows for
missing rows. Disabled capture retains its original ordinary reads. This is not a
global deadlock-freedom claim or a change to native rounding/uniqueness.

The added fixed policy is `traffic.batch.settle`, version
`traffic-batch-capture/v1`, actor domain
`system_node_traffic_settlement`. Its actor ID is the actual locked Server ID:
a native work-item reference, not independent Node authentication. Event summaries
add `capture_boundary=transaction-only`. Existing event hashes and stream context
remain unchanged. No batch ID, protocol, rate, raw payload, credentials or
transport request reference is stored in capture evidence. This evidence proves
persisted User endpoints, not a historical authorization or billed Usage v2 entry.

Capture is strictly default-off. Disabled settlement retains native bulk reads
and performs no capture/map queries. Enabling requires the existing exact boolean,
local/testing environment, isolated-copy marker and frozen currency/timezone/run
context. Native duplicate/conflicting batches return before capture configuration
or schema reads, including after an original committed result is lost. Empty or
invalid input and missing Users retain native behavior. A valid rate can floor
small increments to zero; unchanged captured User fields create no event or map
invalidation, while native raw node statistics and `t` updates remain intact.

`LegacyCaptureShadowException` keeps fixed codes and `ShouldntReport`; the queue
exhaustion callback logs only `LEGACY_TRAFFIC_BATCH_CAPTURE_RETRIES_EXHAUSTED`
with empty context. Native non-capture exceptions retain queue behavior. Native
serialized/failed-job payloads still contain the original traffic batch input;
this slice does not redefine that storage boundary. Tries, timeout and backoff
remain 3, 120 seconds and `[5,20,60]`. Deadlock retry resets the local accepted-User
list so only the successful transaction's Users reach the existing Redis hint.

Redis notification remains best-effort after SQL commit. Death after commit but
before Redis cannot be treated as notification completion; original batch replay
does not charge or capture twice and does not repair a lost hint. There is no new
outbox, period claim or durable Node ACK. Death before commit rolls back the batch
and its evidence, permitting native original-batch retry.

Bounded verification remains SELECT-only and never applies Subscription/Terms,
period, quota or funds changes. `global_coverage_complete`, `projection_applied`
and `migration_gate_open` stay false. Unidentified `TrafficFetchJob`, registration,
commission distribution, manual/other reset and hooks/Observer writers still
require their own coverage; detected endpoint gaps refuse later capture. No
production migration or historical completeness is inferred from synthetic tests.
