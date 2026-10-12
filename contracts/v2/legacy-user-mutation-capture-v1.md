# Legacy User mutation capture v1

Status: isolated local/testing implementation slice. Legacy User remains authoritative.

## Writer and configuration

Only the existing authenticated Admin `UserEditorController::update` transaction is captured. The dynamic Admin path, permission checks, compare-and-swap snapshots, exact major-to-minor conversion, response, and existing hooks remain in place. Capture starts after the User lock, before in-transaction hooks. It reads the actual database row again after save. Post-commit hooks and every other writer remain outside this scope.

`legacy_capture_shadow.enabled` defaults to boolean false. Disabled performs no capture SQL. Enabled requires an actual boolean true, local/testing environment, strict `core_shadow.isolated_copy=true`, explicit run key, IANA billing timezone, and one of USD/CNY/EUR/GBP/HKD/SGD/CAD/AUD/NZD/TWD. Context is frozen per User stream; drift rejects the edit. No production writer switch exists.

## Fixed source and integer representation

The ordered allowlist is `id, plan_id, group_id, transfer_enable, u, d, speed_limit, device_limit, expired_at, last_reset_at, next_reset_at, reset_count, banned, balance, commission_balance`. These are raw persisted values, never request copies or values recomputed from Plan. Integer JSON values are canonical nonnegative decimal **strings**, without exponent, float, leading zero or sign. IDs are positive. Nullable native fields retain null. `banned` is a JSON boolean. Byte fields `transfer_enable/u/d` and the native BIGINT `expired_at` accept through 9223372036854775807; all other integer fields accept through the native signed INT upper bound 2147483647. The evidence does not sum counters or invent financial deltas. Invalid/negative preexisting values reject enabled edits before commit.

Email, password/hash, token, UUID, session data, callback credentials, request body, remarks, referral, commission rate and discount are excluded. Metadata-only and identical allowlisted snapshots create no stream/event and do not invalidate a map.

## Durable tables and atomicity

`tx_legacy_capture_shadow_stream` has a unique legacy User ID, frozen context/hash, last sequence, last event hash and last after hash. `tx_legacy_capture_shadow_event` stores stream ID, User ID, monotonic sequence, fixed writer/version, Admin actor ID, canonical before/after snapshots and hashes, previous event hash, context hash, capture time and content hash. `(stream_id, sequence)` is unique. The only FK is event to the new stream with RESTRICT; no legacy User FK blocks deletion. Event models reject update/delete; privileged direct SQL remains outside the immutability trust boundary and is detected by verification where observable. Additive migration replay validates schema and populated rollback refuses deletion.

The existing exclusive User lock protects stream creation and sequence allocation. Lock order is User then stream then legacy map. Append and map invalidation share the original business transaction: a present map becomes `source_changed/LEGACY_CAPTURE_PENDING` with incremented version; original mapping IDs, source evidence, Terms and period counters are retained. A missing map does not manufacture a Subscription. Capture failure rolls back User, event, stream and map together and emits only a fixed safe error code, without exception chaining or SQL bindings.

Every subsequent captured edit must start at the previous event's after hash. An intervening uncaptured writer producing a different endpoint causes `CAPTURE_SOURCE_GAP`, blocking the new edit. Returning to an identical endpoint, writes before the first event, and excluded fields cannot be proven by this stream.

## Read-only recovery and limits

`core:legacy-capture-shadow --isolated-copy --user=<id> --after=<sequence> --limit=<1..1000>` verifies an autonomous fresh read transaction; outer transactions are rejected. It takes a shared User fence and current reads of the stream, anchor and at most limit+1 events. SQL truncates untrusted persisted context and snapshot values before decoding; malformed/truncated evidence fails closed. It checks chain/payload integrity, frozen context, latest endpoint, map invalidation and bounded current mapping/Terms/slot/period facts. B3 evidence is inspected without reverse batch/grant locks: first select at most 1001 pending batch IDs/hashes/lengths, reject over 1000 batches, 262144 bytes per batch or 2097152 total bytes, then load only bounded payloads from the same fresh ordinary-read snapshot. Pending evidence or unavailable scope is a projection blocker. It never writes a checkpoint, applies events, repairs a map, reopens usage or appends money postings.

Output contains only IDs, hashes, fixed codes and pagination: `read_only`, `scope`, `page_ok`, `has_more`, `after_cursor`, `next_cursor`, `high_water`, `issues`, and `projection_issues`. `projection_applied=false`, `migration_gate_open=false`, and `global_coverage_complete=false` are unconditional. CLI success means this bounded capture page and its endpoint verified; it never means migration is permitted. Empty pages still verify the stream tail and current source. An absent stream is explicitly `CAPTURE_STREAM_MISSING`, not clean. Limits and unavailable evidence fail closed.

Uncovered writers include order creation/fulfillment/cancellation, payment and recharge settlement, commission, both Node traffic paths, resets, Plan bulk propagation, bulk ban, User creation/deletion, credentials, gift cards, CLI, plugin hooks and direct SQL. This is evidence for one writer, not a global writer fence or proof of historical completeness. Terms/slot/usage/fund projection conflicts stay unapplied until a separately specified consumer exists.

## Verification

Acceptance covers real Admin HTTP/CAS and permission regression, exact persisted decimals, disabled zero capture SQL, metadata/no-op suppression, capture failure atomic rollback, map invalidation, unknown-writer and tamper rejection, bounded SELECT-only recovery, additive schema checks, independent MariaDB concurrent sessions and process death before/after commit.

2026-10-12 isolated verification uses committed base `12985477ed0d2bd78e24f82a26525b9af3de1c94` plus only this slice's nine files, on an ext4 snapshot with the matching locked vendor and dedicated Redis databases 16/17:

| Gate | Passed | Skipped | Assertions |
| --- | ---: | ---: | ---: |
| Capture SQLite | 21 | 4 | 230 |
| Capture MariaDB | 25 | 0 | 271 |
| Complete API SQLite | 786 | 25 | 6157 |

The four capture SQLite skips are MariaDB-only independent connection/process cases. MariaDB proves a reader waits for the committed User endpoint, two writers using one original CAS produce one event, a stale outer snapshot cannot drive verification or stale stream allocation, and SIGKILL before commit preserves neither edit/event/invalidation while SIGKILL after commit preserves all three and permits SELECT-only recovery. Query logs and table comparisons prove recovery performs no writes; pending/settled B3 evidence and oversized evidence remain unapplied blockers. Native schema checker reports zero issues; all eight PHP files pass lint. This evidence does not validate a production rollout, a projection consumer, or global writer coverage.
