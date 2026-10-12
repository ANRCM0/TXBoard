# Legacy User mutation capture v1

Status: isolated local/testing implementation slice. Legacy User remains authoritative.

## Writer and configuration

Three fixed existing transactions are captured: authenticated Admin `UserEditorController::update`, verified-provider `WalletRechargeService::settleVerified`, and the locked core transaction in `OrderService::open`. The Admin dynamic path, permission checks, compare-and-swap snapshots, exact major-to-minor conversion, response, and existing hooks remain in place. Admin capture starts after the User lock, before in-transaction hooks. Post-commit hooks remain outside scope. Capture always reads the actual database row again after save.

Recharge retains the existing provider signature/amount verification boundary and Recharge → User lock order. Capture starts only after its existing successful User lock and balance headroom check; it appends after both User credit and Recharge PAID saves, before the original transaction returns. Principal alone is credited; fee arithmetic, native replay checks, callback persistence and raw HTTP ACK remain authoritative. Creation/checkout produce no wallet credit or event. Replayed paid callbacks return their native result without another event. Capture failure rolls the entire settlement back; `PaymentNotificationProcessor` catches only the fixed capture exception separately, logs its code as a plain string without Throwable/context/trace/arguments, and returns the existing 500/fail response. Other errors follow their original handling.

Order fulfillment capture is explicitly **transaction-only**. Native `open` locks the current PROCESSING Order, reads Plan and calls `order.open.before`, then locks User. Capture starts immediately after that User lock, before surplus credit, discounting and period/reset changes; it appends after User and COMPLETED Order saves, before the original closure returns. Carbon calendar addition, principal/predebit/surplus/fee handling, nested reset behavior and reset logs remain unchanged. A failure rolls that fulfillment transaction back to PROCESSING for its existing retry path. The native paid-state transaction and raw payment ACK happen before asynchronous fulfillment and remain unchanged. `OrderHandleJob` retains tries/timeout/backoff and rethrows capture exceptions; only their attempt/exhaustion log branches use fixed strings with no context, Throwable, trace, arguments or trade identifier. Native queue payloads and failed-job storage retain their existing system-job boundary.

The actual after-commit `UserObserver` may write `next_reset_at` separately when plan/expiry changes. `openEvent` can perform another reset after the fulfillment commit, and `order.open.after` runs afterward. These timings and operations are preserved. Pre-User-lock hook changes and post-commit writers are outside this transaction-only evidence. If any later raw endpoint differs, verification reports `CAPTURE_SOURCE_GAP` and a subsequent captured edit is rejected; a completed-order retry does not recapture or repeat fulfillment. In particular, successful new purchases can have valid fulfillment transaction evidence while their final after-commit endpoint is blocked. No follow-up event or inferred Terms is manufactured to conceal that gap.

Writer-specific APIs reject cross-use of Admin/recharge tickets. The code-owned policy pairs are:

| source_kind | source_version | actor_domain / actor_id |
| --- | --- | --- |
| admin.user.update | legacy-user-capture/v1 | admin_user / authenticated Admin User ID |
| wallet.recharge.settle | wallet-recharge-capture/v1 | verified_payment_method / verified Payment method ID |
| order.fulfill | order-fulfillment-capture/v1 | system_order_fulfillment / actual locked Order ID as system work-item reference |

Unknown kind/version pairs are rejected. `actor_domain` is derived from the pair, never from caller-supplied text. Payment callbacks never become Admin actors. Fulfillment is system work whether triggered by provider payment, free checkout or manual paid; its Order reference is neither Admin nor Payment authentication. The service remains trusted internal PHP code; tickets and capture evidence do not independently authenticate provider calls. All three policy pairs fit the existing schema. Old Admin/recharge event hashes and frozen stream context stay unchanged. No callback/merchant UUID/signature/transaction reference is stored or hashed into shadow evidence; consequently the stream proves the captured User transaction endpoint and writer policy, not which external transaction funded it.

`legacy_capture_shadow.enabled` defaults to boolean false. Disabled performs no capture SQL. Enabled requires an actual boolean true, local/testing environment, strict `core_shadow.isolated_copy=true`, explicit run key, IANA billing timezone, and one of USD/CNY/EUR/GBP/HKD/SGD/CAD/AUD/NZD/TWD. Context is frozen per User stream; drift rejects the edit. No production writer switch exists.

## Fixed source and integer representation

The ordered allowlist is `id, plan_id, group_id, transfer_enable, u, d, speed_limit, device_limit, expired_at, last_reset_at, next_reset_at, reset_count, banned, balance, commission_balance`. These are raw persisted values, never request copies or values recomputed from Plan. Integer JSON values are canonical nonnegative decimal **strings**, without exponent, float, leading zero or sign. IDs are positive. Nullable native fields retain null. `banned` is a JSON boolean. Byte fields `transfer_enable/u/d` and the native BIGINT `expired_at` accept through 9223372036854775807; all other integer fields accept through the native signed INT upper bound 2147483647. The evidence does not sum counters or invent financial deltas. Invalid/negative preexisting values reject enabled edits before commit.

Email, password/hash, token, UUID, session data, callback credentials, request body, remarks, referral, commission rate and discount are excluded. Metadata-only and identical allowlisted snapshots create no stream/event and do not invalidate a map.

## Durable tables and atomicity

`tx_legacy_capture_shadow_stream` has a unique legacy User ID, frozen context/hash, last sequence, last event hash and last after hash. `tx_legacy_capture_shadow_event` stores stream ID, User ID, monotonic sequence, fixed writer/version, policy-scoped actor ID, canonical before/after snapshots and hashes, previous event hash, context hash, capture time and content hash. `(stream_id, sequence)` is unique. The only FK is event to the new stream with RESTRICT; no legacy User FK blocks deletion. Event models reject update/delete; privileged direct SQL remains outside the immutability trust boundary and is detected by verification where observable. Additive migration replay validates schema and populated rollback refuses deletion.

The existing exclusive User lock protects stream creation and sequence allocation. Capture lock order is User then stream then legacy map; recharge and fulfillment retain their preceding native Recharge/Order locks. Append and map invalidation share the original business transaction: a present map becomes `source_changed/LEGACY_CAPTURE_PENDING` with incremented version; original mapping IDs, source evidence, Terms and period counters are retained. A missing map does not manufacture a Subscription. Capture failure rolls back User, event, stream and map together (and Recharge when settling, or Order/reset logs when fulfilling) and emits only a fixed safe error code, without exception chaining or SQL bindings. Cache invalidation, external hooks and post-commit side effects are not represented as transactional SQL rollback guarantees.

Every subsequent captured edit must start at the previous event's after hash. An intervening uncaptured writer producing a different endpoint causes `CAPTURE_SOURCE_GAP`, blocking the new edit. Returning to an identical endpoint, writes before the first event, and excluded fields cannot be proven by this stream.

## Read-only recovery and limits

`core:legacy-capture-shadow --isolated-copy --user=<id> --after=<sequence> --limit=<1..1000>` verifies an autonomous fresh read transaction; outer transactions are rejected. It takes a shared User fence and current reads of the stream, anchor and at most limit+1 events. SQL truncates untrusted persisted context and snapshot values before decoding; malformed/truncated evidence fails closed. It checks chain/payload integrity, frozen context, latest endpoint, map invalidation and bounded current mapping/Terms/slot/period facts. B3 evidence is inspected without reverse batch/grant locks: first select at most 1001 pending batch IDs/hashes/lengths, reject over 1000 batches, 262144 bytes per batch or 2097152 total bytes, then load only bounded payloads from the same fresh ordinary-read snapshot. Pending evidence or unavailable scope is a projection blocker. It never writes a checkpoint, applies events, repairs a map, reopens usage or appends money postings.

Output contains only IDs, hashes, fixed policy names/codes and pagination: `read_only`, `scope`, `page_ok`, `has_more`, `after_cursor`, `next_cursor`, `high_water`, `issues`, and `projection_issues`. Each bounded event summary adds `source_kind`, `source_version`, derived `actor_domain` and decimal `actor_id`; no snapshots or payment credentials are returned. The original `scope=admin-user-update-evidence` tag is retained for existing CLI consumers; exact writer coverage is given by the three fixed policy pairs and each event's added metadata. Only fulfillment summaries add `capture_boundary=transaction-only`, not a final post-commit endpoint guarantee; original Admin/recharge DTO fields and all payload hashes remain unchanged. `projection_applied=false`, `migration_gate_open=false`, and `global_coverage_complete=false` are unconditional. CLI success means this bounded capture page and its current endpoint verified; it never means migration is permitted. Empty pages still verify the stream tail and current source. An absent stream is explicitly `CAPTURE_STREAM_MISSING`, not clean. Limits and unavailable evidence fail closed.

Uncovered writers include order creation/cancellation, fulfillment's pre-lock hooks and after-commit Observer/event/hooks, subscription-payment processing, commission, both Node traffic paths, standalone resets, Plan bulk propagation, bulk ban, User creation/deletion, credentials, gift cards, CLI, out-of-transaction plugin hooks and direct SQL. A creation wallet predebit between existing evidence and fulfillment remains an uncaptured source gap and blocks fulfillment append rather than being reclassified as captured. This is evidence for three fixed transactions, not a global writer fence or proof of historical completeness. Terms/slot/usage/fund projection conflicts stay unapplied until a separately specified consumer exists.

## Verification

Acceptance covers real Admin HTTP/CAS and permission regression, exact persisted decimals, disabled zero capture SQL, metadata/no-op suppression, capture failure atomic rollback, map invalidation, unknown-writer and tamper rejection, bounded SELECT-only recovery, additive schema checks, independent MariaDB concurrent sessions and process death before/after commit.

2026-10-12 isolated verification uses committed base `12985477ed0d2bd78e24f82a26525b9af3de1c94` plus only this slice's nine files, on an ext4 snapshot with the matching locked vendor and dedicated Redis databases 16/17:

| Gate | Passed | Skipped | Assertions |
| --- | ---: | ---: | ---: |
| Capture SQLite | 21 | 4 | 230 |
| Capture MariaDB | 25 | 0 | 271 |
| Complete API SQLite | 786 | 25 | 6157 |

The four capture SQLite skips are MariaDB-only independent connection/process cases. MariaDB proves a reader waits for the committed User endpoint, two writers using one original CAS produce one event, a stale outer snapshot cannot drive verification or stale stream allocation, and SIGKILL before commit preserves neither edit/event/invalidation while SIGKILL after commit preserves all three and permits SELECT-only recovery. Query logs and table comparisons prove recovery performs no writes; pending/settled B3 evidence and oversized evidence remain unapplied blockers. Native schema checker reports zero issues; all eight PHP files pass lint. This evidence does not validate a production rollout, a projection consumer, or global writer coverage.

B1.7 isolated verification uses committed base `a51d572a01d2bffddbb1051238fd03835eb8abbb` plus only the four changed files and new recharge test, on an ext4 snapshot with the matching locked vendor and dedicated Redis databases 16/17:

| Gate | Passed | Skipped | Assertions |
| --- | ---: | ---: | ---: |
| Recharge capture SQLite | 13 | 4 | 177 |
| Recharge capture MariaDB | 17 | 0 | 222 |
| Original Admin capture SQLite | 21 | 4 | 230 |
| Original Admin capture MariaDB | 25 | 0 | 271 |
| Complete API SQLite | 799 | 29 | 6334 |

The four recharge SQLite skips are MariaDB-only independent connection/process cases. Actual signed native callbacks preserve principal-only credit, native verification, replay and raw ACK behavior. Independent MariaDB sessions prove duplicate callbacks serialize without duplicate credit/events, distinct recharges serialize on the User endpoint, and Admin/recharge writers maintain one contiguous stream. SIGKILL after event/map writes before commit rolls settlement and capture back; SIGKILL after the database commit before ACK preserves PAID, credit and capture, and the original callback retry adds nothing. Mixed chains preserve original Admin hashes and context. Disabled settlement performs zero capture SQL; bounded recovery remains SELECT-only and unapplied. The failure-log fixture explicitly enables `zend.exception_ignore_args=0` and verifies the outer HTTP failure emits one fixed string with empty context and no Throwable, trace or credential data. All four changed/new PHP files pass lint; native schema checking reports zero issues. These fixtures do not establish production readiness, global writer coverage, external transaction attribution or an applied projection.

B1.8 isolated verification uses committed base `79a38540c323b6ddd57f84e2962026bdb216534d` plus only `OrderService`, `OrderHandleJob`, `LegacyUserMutationCapture`, this contract and the new fulfillment test, on an ext4 archive with the matching locked vendor and dedicated Redis databases 16/17:

| Gate | Passed | Skipped | Assertions |
| --- | ---: | ---: | ---: |
| Fulfillment capture SQLite | 14 | 5 | 306 |
| Fulfillment capture MariaDB | 19 | 0 | 355 |
| Original capture and native order recovery SQLite | 45 | 8 | 453 |
| Original Admin/recharge capture MariaDB | 42 | 0 | 493 |
| Complete API SQLite | 815 | 34 | 6686 |

The five fulfillment SQLite skips require independent MariaDB connections and process control. Real signed HTTP callbacks preserve ACK before the system job; free/manual paid entry points still produce system fulfillment references. New, renewal, upgrade, one-time and reset cases retain actual v1 calendar, counters, surplus, discounting and reset-log behavior. Actual after-commit Observer and `openEvent` resets demonstrably leave source gaps where values differ; completed retries do not recapture them. Actual order-creation wallet predebit after prior evidence blocks fulfillment append while retaining the already committed predebit. Bounded mixed-writer pages perform SELECT only and retain old hashes/DTO fields, without applying Terms, usage or funds.

Independent MariaDB sessions prove same-Order replay completes once, distinct Order locks serialize on current User, stale outer snapshots do not allocate stale source/sequence, and recharge/fulfillment share a contiguous stream. SIGKILL after event/map writes before commit leaves no fulfillment SQL; SIGKILL after the actual commit preserves COMPLETED/User/event/map, and native retry adds nothing. These kill fixtures use a renewal with unchanged after-commit schedule; they do not claim recovery of a lost post-commit callback. A real Redis worker retains three attempts and native backoff/exhaustion semantics with `zend.exception_ignore_args=0`; capture failures emit only fixed messages with empty context, while native failed-job payload retains its existing Order trade reference. No fixture credentials enter shadow rows, DTOs or capture logs. Four PHP files pass lint; native schema checking reports zero issues. Production capture, full post-commit completion, global coverage and shadow projection remain unproved and disabled.
