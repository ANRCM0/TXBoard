# Legacy User mutation capture v1

> Retired: 2026-10-16 native development cleanup removes this capture runtime, flags and verifier entry points. The text below records historical behavior only. Database migrations and existing evidence remain; current account writes do not emit this shadow evidence. See [Core cleanup](../../docs/development/core-cleanup.md).

The independent default-off [captured money endpoint consumer](legacy-money-shadow-v1.md)
can project wallet/commission differences into separate evidence tables under
the same isolated User fence. It does not repair a mapping or apply Subscription,
Terms, usage or authoritative financial state. Existing verification flags and
payload hashes below remain unchanged.

Explicit [schedule-repair CLI capture](legacy-schedule-repair-capture-v1.md)
extends fix-null/force only with `--capture-schedule`; their unmarked historical
paths remain unchanged. It is separate from counter reset and after-commit
Observer capture, and does not prove all legacy writer coverage.

The additional default-off
[standalone reset extension](legacy-standalone-reset-capture-v1.md) captures
independent manual/user-access/other native reset transactions. Existing due
cron selection remains unchanged, and nested Order/gift resets retain their
outer capture ownership. Historical excluded-source statements below apply
when this additional switch is false.

The separate default-off
[Observer schedule extension](legacy-observer-schedule-capture-v1.md) can capture
the native after-commit follow-up under a second transaction. Original capture
alone retains historical source-gap behavior; it never retroactively extends an
earlier business ticket or certifies notification delivery.

The isolated reward extension is documented in
[commission and gift reward capture](legacy-reward-capture-v1.md). Its fixed
three policies cover actual per-Order distribution and primary/inviter gift
transaction endpoints; native duplicate work, ignored code-save results and
after-commit Observer source gaps remain explicit. Historical coverage statements
below retain their original slice scope.

Status: isolated local/testing implementation slice. Legacy User remains authoritative.

## Writer and configuration

The original seven policies below are extended by
[identified traffic batch settlement](legacy-traffic-batch-capture-v1.md).
That eighth policy is `traffic.batch.settle` /
`traffic-batch-capture/v1` /
`system_node_traffic_settlement`, referencing the locked Server as a work item.
Existing seven-policy evidence, event hashes and stream context remain unchanged.
At that eighth-policy boundary, unidentified Node traffic remains uncovered.

The ninth policy, [unidentified traffic increment](legacy-traffic-fetch-capture-v1.md),
adds `traffic.fetch.apply` / `traffic-fetch-capture/v1` /
`system_legacy_traffic_fetch` with a queued Server reference. It preserves
per-User partial commits and repeated-input increments; it adds no batch dedupe,
statistics atomicity or safe original-intent recovery. Earlier Node-coverage
limitations below refer to the historical seven-policy slices.

Seven fixed existing transactions are captured: authenticated Admin `UserEditorController::update`, verified-provider `WalletRechargeService::settleVerified`, the locked core transaction in `OrderService::open`, the native new-create closure of `OrderService::createFromRequest`, the wallet-refund branch inside `OrderService::cancel`, authenticated User `CommissionController::transfer`, and the selected standalone cron due-reset in `TrafficResetService::performReset`. The Admin dynamic path, permission checks, compare-and-swap snapshots, exact major-to-minor conversion, response, and existing hooks remain in place. Admin capture starts after the User lock, before in-transaction hooks. Post-commit hooks remain outside scope. Capture always reads the actual database row again after save.

Recharge retains the existing provider signature/amount verification boundary and Recharge → User lock order. Capture starts only after its existing successful User lock and balance headroom check; it appends after both User credit and Recharge PAID saves, before the original transaction returns. Principal alone is credited; fee arithmetic, native replay checks, callback persistence and raw HTTP ACK remain authoritative. Recharge creation/checkout produce no wallet credit or settlement event. Replayed paid callbacks return their native result without another event. Capture failure rolls the entire settlement back; `PaymentNotificationProcessor` catches only the fixed capture exception separately, logs its code as a plain string without Throwable/context/trace/arguments, and returns the existing 500/fail response. Other errors follow their original handling.

Order fulfillment capture is explicitly **transaction-only**. Native `open` locks the current PROCESSING Order, reads Plan and calls `order.open.before`, then locks User. Capture starts immediately after that User lock, before surplus credit, discounting and period/reset changes; it appends after User and COMPLETED Order saves, before the original closure returns. Carbon calendar addition, principal/predebit/surplus/fee handling, nested reset behavior and reset logs remain unchanged. A failure rolls that fulfillment transaction back to PROCESSING for its existing retry path. The native paid-state transaction and raw payment ACK happen before asynchronous fulfillment and remain unchanged. `OrderHandleJob` retains tries/timeout/backoff and rethrows capture exceptions; only their attempt/exhaustion log branches use fixed strings with no context, Throwable, trace, arguments or trade identifier. Native queue payloads and failed-job storage retain their existing system-job boundary.

The actual after-commit `UserObserver` may write `next_reset_at` separately when plan/expiry changes. `openEvent` can perform another reset after the fulfillment commit, and `order.open.after` runs afterward. These timings and operations are preserved. Pre-User-lock hook changes and post-commit writers are outside this transaction-only evidence. If any later raw endpoint differs, verification reports `CAPTURE_SOURCE_GAP` and a subsequent captured edit is rejected; a completed-order retry does not recapture or repeat fulfillment. In particular, successful new purchases can have valid fulfillment transaction evidence while their final after-commit endpoint is blocked. No follow-up event or inferred Terms is manufactured to conceal that gap.

Order creation capture is also **transaction-only**. The existing authenticated User POST `/txapi/orders` calls `BillingController::createOrder`, retaining its input checks, User authorization and original response. The actual current User lock and `OrderIdempotency::run` precede capture. Only the native new-create closure starts capture, after purchase/pending preflight and before `order.create.before`; append follows both `order.create.after` and `order.after_create`, before returning to native idempotency success persistence in the same transaction. Native User → idempotency → Coupon → User re-lock order and three transaction attempts remain unchanged. Actual persisted User values are re-read because native wallet deduction uses a separate locked model. Native quote rounding, coupon/VIP/upgrade arithmetic, partial/full wallet reservation, Order amounts, hooks and financial authority remain unchanged. A User allowlist no-op does not create an event merely because an Order exists.

Successful keyed creation replays return their original owned Order before capture or new-create preflight, including after cancellation/completion or later capture context/schema changes; they perform zero capture SQL. Native conflict, fresh/stale in-progress, expired-key reuse, missing-original and unkeyed-client semantics remain authoritative. Capture failure rolls back wallet deduction, Coupon reservation, Order insertion, native idempotency claim, event/stream/map together. HTTP capture errors use the existing fixed-safe TXAPI conflict renderer and `ShouldntReport` boundary without a Throwable/context/logging branch. Commit-unknown clients retry the original native key; no second receipt or permanent shadow idempotency authority is introduced.

Cancellation refund capture is **transaction-only**. The original transaction current-locks a PENDING Order, calls `order.cancel.before`, and saves CANCELLED before reaching the existing `UserService::addBalance` position. Only a nonzero refund enters capture. Enabled capture first validates its strict isolated context and a positive canonical native INT refund through 2147483647, then takes an exclusive current User snapshot at this existing first-User-lock position. The original `addBalance` re-lock, amount calculation, Order → User → Coupon sequence, one transaction attempt and Coupon release remain unchanged. Append follows Coupon release and precedes the original transaction return. The dedicated exclusive source-read flag does not change the four earlier writers' shared current reads. Disabled capture validates nothing, adds no User lock and performs zero capture SQL; zero/null refunds create no User event or map invalidation solely for Order/Coupon changes.

Actual User and Admin cancellation routes retain their authorization and native responses; the two-hour system job retains its original false-result handling and scheduler recovery. Every caller uses the same locked Order work-item reference, without guessing an authenticated principal from queue-worker/global Auth state. A non-PENDING duplicate returns the original false/HTTP `ORDER_CONFLICT` 409 before capture guards or SQL, rather than a new success receipt. Only `LegacyCaptureShadowException` gets a separate fixed `LEGACY_ORDER_CANCELLATION_CAPTURE_FAILED` string log with empty context and the original false result; no Throwable, trace, arguments, request/trade reference or SQL binding is attached. Other native failures retain their existing behavior. Capture failure rolls back Order cancellation, refund, Coupon release and capture together. Commit-unknown recovery rechecks the native Order: a committed cancellation is not refunded or captured twice. `order.cancel.after` remains outside the transaction; failure there may return false after commit, and a changed endpoint there or in the pre-User-fence hook leaves a source gap. No final endpoint or hook-wide coverage is claimed.

Commission transfer capture is **transaction-only**. The existing User POST `/txapi/billing/commission-transfer` retains Sanctum User authentication, banned rejection and throttle. Its one-attempt transaction current-locks the actual owner User, calculates the original effective minimum using `admin_transfer_minimum()` inheritance and `round(minimum * 100)`, and returns native `MINIMUM_NOT_MET`/`INSUFFICIENT_COMMISSION` 422 before capture. Begin follows those checks, immediately before native commission subtraction and wallet addition. Append follows `saveOrFail`, before returning OK. The shared current source read remains under the already-held User exclusive lock; no Order, Coupon, Recharge, queue, hook, or new credential domain is introduced. The original arithmetic and `{data:true}` response remain authoritative. Disabled capture and native rejected requests perform zero capture SQL, including with invalid capture context or unavailable evidence schema.

Commission transfer has no native idempotency key, successful-transfer receipt or task retry. An identical request, including an ignored `Idempotency-Key` header, is another transfer when current commission permits; insufficient funds retain the native rejection. A lost result after commit does not prove a safe retry for the original client intent. Process-death recovery can inspect the current User evidence chain but cannot correlate or replay a client request. Capture failure rolls both wallet movements, event/stream and map back together and uses the existing fixed TXAPI conflict renderer and `ShouldntReport` exception boundary with no new catch/log branch. Native non-capture errors retain their original handling. Canonical evidence uses exact raw persisted INT strings, without replacing minimum/amount math or manufacturing a money journal. Beyond-native-INT values reject capture where reached; a native SQL overflow before append remains a native failure. Unknown source gaps and all shadow projections remain unapplied.

Cron due-reset capture is **transaction-only** and selected from the original method entry: SQL transaction level zero, `triggerSource === TrafficResetLog::SOURCE_CRON`, and `recheckDue === true`. That local selection is frozen before the original one-attempt transaction. The actual `reset:traffic` due path and `batchCheckReset` helper use this transaction; a cron label describes trusted PHP work, not independent scheduler authentication. Original User exclusive lock, ordinary Plan relation read and locked due recheck precede begin. No-longer-due rejection returns native false before capture context/schema reads. Begin precedes counter/date calculation and mutation; append follows the original reset log, cache invalidation and `traffic.reset.after`, before returning true. Native calendar/timezone/reset rules, timestamps, reset_count, log metadata and command candidate collection/partial completion/exit behavior remain authoritative.

Only `LegacyCaptureShadowException` receives a fixed `LEGACY_CRON_TRAFFIC_RESET_CAPTURE_FAILED` message with empty context and the original false result. SQL failure rolls the selected User/reset-log/event/stream/map back together. Native errors retain their existing branch; cache invalidation, trusted external hook effects, Observer and Node notifications are not SQL rollback guarantees. Reset normally changes no plan/expiry, so its Observer only queues native Node synchronization when relevant counters changed. A hook changing plan/expiry can still cause a later Observer source gap. This slice adds no durable notification or Observer obligation, Terms transition, usage period/reset, quota adjustment or money journal.

Manual, auto, API, user-access, order, gift, forced cron without due recheck, fix-null/force schedule recomputation and calls entered inside another SQL transaction are outside the new policy. In particular, Order fulfillment's inner reset stays owned by its outer fulfillment ticket; adding an inner event would conflict with that ticket's tail. Re-entrant cron inside the selected reset hook also creates no inner ticket. Other independently captured same-User hook writers may collide with the outer source; collisions fail closed with rollback, never by rebasing or deleting events. Foreign-User hook effects remain outside proof.

Cron reset has no operation key or success receipt. Concurrent stale due inputs arbitrate current eligibility under User X: a successful reset normally moves next_reset_at forward or to null, and the waiter returns false. Restart after a lost committed result observes current due state; a later due date can reset again. This is not permanent exactly-once period settlement. SIGKILL after commit can precede command output/Observer/Node delivery. The command can retain its original success exit and zero error_count even when the service returns false; CLI capture verification cannot certify command-wide success.

Writer-specific APIs reject cross-use of Admin/recharge/fulfillment/creation/cancellation/commission-transfer/cron-reset tickets. The code-owned policy pairs are:

| source_kind | source_version | actor_domain / actor_id |
| --- | --- | --- |
| admin.user.update | legacy-user-capture/v1 | admin_user / authenticated Admin User ID |
| wallet.recharge.settle | wallet-recharge-capture/v1 | verified_payment_method / verified Payment method ID |
| order.fulfill | order-fulfillment-capture/v1 | system_order_fulfillment / actual locked Order ID as system work-item reference |
| order.create | order-create-capture/v1 | authenticated_user / actual locked owner User ID from the existing Billing principal |
| order.cancel.refund | order-cancel-refund-capture/v1 | system_order_cancellation / actual locked PENDING Order ID as system work-item reference |
| commission.transfer | commission-transfer-capture/v1 | authenticated_user / actual locked owner User ID from the existing User principal |
| traffic.reset.cron | traffic-cron-reset-capture/v1 | system_traffic_reset / actual locked User ID as scheduled work-item reference |

Unknown kind/version pairs are rejected. `actor_domain` is derived from the pair, never from caller-supplied text. Payment callbacks never become Admin actors. Fulfillment is system work whether triggered by provider payment, free checkout or manual paid; its Order reference is neither Admin nor Payment authentication. Cancellation's Order ID is likewise only a native system work-item reference, not proof of User/Admin/payment authentication or external funding. Creation and commission transfer reference the actual selected User principal, including the original behavior when a User with an Admin role invokes these User endpoints; they do not promote that request to Admin or payment authentication. Cron's User ID describes only the selected scheduled work item, not a principal or period receipt. Creation, transfer and cron actors must equal the captured owner in append and event verification. The service remains trusted internal PHP code; tickets and capture evidence do not independently authenticate callers. All seven policy pairs fit the existing schema. Previous event hashes and frozen stream context stay unchanged. No raw Idempotency-Key, coupon code, bearer, callback/merchant UUID/signature/transaction reference or reset metadata is stored or hashed into shadow evidence; consequently the stream proves the captured User transaction endpoint and writer policy, not which external transaction funded it.

`legacy_capture_shadow.enabled` defaults to boolean false. Disabled performs no capture SQL. Enabled requires an actual boolean true, local/testing environment, strict `core_shadow.isolated_copy=true`, explicit run key, IANA billing timezone, and one of USD/CNY/EUR/GBP/HKD/SGD/CAD/AUD/NZD/TWD. Context is frozen per User stream; drift rejects the edit. No production writer switch exists.

## Fixed source and integer representation

The ordered allowlist is `id, plan_id, group_id, transfer_enable, u, d, speed_limit, device_limit, expired_at, last_reset_at, next_reset_at, reset_count, banned, balance, commission_balance`. These are raw persisted values, never request copies or values recomputed from Plan. Integer JSON values are canonical nonnegative decimal **strings**, without exponent, float, leading zero or sign. IDs are positive. Nullable native fields retain null. `banned` is a JSON boolean. Byte fields `transfer_enable/u/d` and the native BIGINT `expired_at` accept through 9223372036854775807; all other integer fields accept through the native signed INT upper bound 2147483647. The evidence does not sum counters or invent financial deltas. Invalid/negative preexisting values reject enabled edits before commit.

Email, password/hash, token, UUID, session data, callback credentials, request body, remarks, referral, commission rate and discount are excluded. Metadata-only and identical allowlisted snapshots create no stream/event and do not invalidate a map.

## Durable tables and atomicity

`tx_legacy_capture_shadow_stream` has a unique legacy User ID, frozen context/hash, last sequence, last event hash and last after hash. `tx_legacy_capture_shadow_event` stores stream ID, User ID, monotonic sequence, fixed writer/version, policy-scoped actor ID, canonical before/after snapshots and hashes, previous event hash, context hash, capture time and content hash. `(stream_id, sequence)` is unique. The only FK is event to the new stream with RESTRICT; no legacy User FK blocks deletion. Event models reject update/delete; privileged direct SQL remains outside the immutability trust boundary and is detected by verification where observable. Additive migration replay validates schema and populated rollback refuses deletion.

The existing exclusive User lock protects stream creation and sequence allocation. Capture lock order is User then stream then legacy map; recharge and fulfillment retain their preceding native Recharge/Order locks, creation retains its native User/idempotency/Coupon locks, and cancellation retains Order → User → Coupon before stream/map. Commission transfer and selected cron reset start at their existing User lock and add no foreign-domain locks. Shared current source reads remain under that lock. Cron's ordinary Plan relation does not prove globally current Plan settings or immutable reset Terms. Append and map invalidation share the original business transaction: a present map becomes `source_changed/LEGACY_CAPTURE_PENDING` with incremented version; original mapping IDs, source evidence, Terms and period counters are retained. A missing map does not manufacture a Subscription. Capture failure rolls back User, event, stream and map together (and Recharge when settling, Order/reset logs when fulfilling, Order/Coupon/idempotency claim when creating, Order/Coupon when refunding cancellation, or the selected reset log) and emits only a fixed safe error code, without exception chaining or SQL bindings. Cache invalidation, external hooks and post-commit side effects are not represented as transactional SQL rollback guarantees.

Every subsequent captured edit must start at the previous event's after hash. An intervening uncaptured writer producing a different endpoint causes `CAPTURE_SOURCE_GAP`, blocking the new edit. Returning to an identical endpoint, writes before the first event, and excluded fields cannot be proven by this stream.

## Read-only recovery and limits

`core:legacy-capture-shadow --isolated-copy --user=<id> --after=<sequence> --limit=<1..1000>` verifies an autonomous fresh read transaction; outer transactions are rejected. It takes a shared User fence and current reads of the stream, anchor and at most limit+1 events. SQL truncates untrusted persisted context and snapshot values before decoding; malformed/truncated evidence fails closed. It checks chain/payload integrity, frozen context, latest endpoint, map invalidation and bounded current mapping/Terms/slot/period facts. B3 evidence is inspected without reverse batch/grant locks: first select at most 1001 pending batch IDs/hashes/lengths, reject over 1000 batches, 262144 bytes per batch or 2097152 total bytes, then load only bounded payloads from the same fresh ordinary-read snapshot. Pending evidence or unavailable scope is a projection blocker. It never writes a checkpoint, applies events, repairs a map, reopens usage or appends money postings.

Output contains only IDs, hashes, fixed policy names/codes and pagination: `read_only`, `scope`, `page_ok`, `has_more`, `after_cursor`, `next_cursor`, `high_water`, `issues`, and `projection_issues`. Each bounded event summary adds `source_kind`, `source_version`, derived `actor_domain` and decimal `actor_id`; no snapshots or payment credentials are returned. The original `scope=admin-user-update-evidence` tag is retained for existing CLI consumers; exact writer coverage is given by the seven fixed policy pairs and each event's added metadata. Fulfillment, creation, cancellation refund, commission transfer and selected cron reset summaries add `capture_boundary=transaction-only`, not a final post-commit endpoint guarantee; original Admin/recharge DTO fields and all payload hashes remain unchanged. `projection_applied=false`, `migration_gate_open=false`, and `global_coverage_complete=false` are unconditional. CLI success means this bounded capture page and its current endpoint verified; it never means migration is permitted. Empty pages still verify the stream tail and current source. An absent stream is explicitly `CAPTURE_STREAM_MISSING`, not clean. Limits and unavailable evidence fail closed.

Uncovered writers include cancellation's pre-User-fence and post-transaction hooks, fulfillment's pre-lock hooks and after-commit Observer/event/hooks, creation's after-commit callbacks, subscription-payment processing, commission settlement/distribution, both Node traffic paths, resets outside the selected standalone cron due boundary, fix-null/force schedule writes, Plan bulk propagation, bulk ban, User creation/deletion, credentials, gift cards, other CLI writes, out-of-transaction plugin hooks and direct SQL. Creation and cancellation capture their selected transaction's actual wallet predebit/refund endpoint; commission transfer captures only its selected owner wallet movement, not inviter commission payouts. Later Observer/hook writes still cause `CAPTURE_SOURCE_GAP`. Trusted hooks can write outside the selected User and the SQL transaction, so this slice cannot prove all plugin activity. Commerce's immutable per-Order source hash and source-high-water growth remain independent reconciliation blockers; a User event neither fills missing Order evidence nor repairs financial openings. This is evidence for seven fixed transactions, not a global writer fence or proof of historical completeness. Terms/slot/usage/fund projection conflicts stay unapplied until a separately specified consumer exists.

## B1.11 commission transfer verification

Fixed specialized Core `ceea19b` plus four files: SQLite14 passed/4 engine
skips/300 assertions, MariaDB18/0/348. Existing five writers and native paths:
SQLite107/23/1837 and MariaDB100/0/1727. Fresh final complete Core based on
committed `d2c3026` plus the same runtime/test hashes:920 passed/50 engine-only
skipped/8537 assertions. See [source and raw-log verification](../../docs/architecture/v2/legacy-commission-transfer-capture-verification.json).

Real independent sessions preserve one winner with insufficient combined funds
and two legitimate transfers with sufficient funds. Stale outer MVCC reads use
the actual locked current recharge endpoint. SIGKILL before commit rolls all
SQL back; after commit preserves wallet/capture/map, and another identical POST
can perform a new transfer. Actual bearer failure with exception arguments
enabled returns fixed CONFLICT with no credential-bearing log. Native MariaDB
overflow remains its original SQL error and rollback. Minimum inheritance,
zero override, float rounding, role behavior and older event hashes remain.
Verification is read-only and does not open the global migration gate.

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

B1.9 isolated verification uses committed base `422e85c44d064bfb763922316acd17d0174137d4` plus only `OrderService`, `LegacyUserMutationCapture`, this contract, the updated fulfillment test and the new creation test. Its ext4 archive uses matching locked vendor and dedicated Redis databases 16/17. The separate native expired-key builder fix is already part of that committed base; creation capture does not modify native idempotency.

| Gate | Passed | Skipped | Assertions |
| --- | ---: | ---: | ---: |
| Creation capture SQLite | 13 | 4 | 440 |
| Creation capture MariaDB | 17 | 0 | 488 |
| Existing capture/native order recovery SQLite | 68 | 13 | 1035 |
| Existing Admin/recharge/fulfillment capture MariaDB | 62 | 0 | 854 |
| Complete API SQLite on 422e85c | 854 | 40 | 7476 |
| Final complete API SQLite on committed 342c12c | 859 | 40 | 7531 |

The final complete API gate comes from a newly archived committed base `342c12cde81aa05dab1c08aab1746b68a4b31fe2`, including the committed Internal order-write adapter, plus the same five slice files. It does not copy unrelated working-tree files or relabel the earlier snapshot. Runtime/test hashes match the 422e85c specialized gates; only acceptance documentation is added after the gates.

The four creation SQLite skips require independent MariaDB sessions and process control. Real User HTTP creation preserves partial/full wallet reservation, exact persisted endpoints, Coupon/VIP amounts and upgrade surplus. The actual native Admin User role behavior remains the existing User principal at this endpoint. Free/no-wallet/metadata-only creation creates no User event or mapping invalidation; Commerce Order evidence and source-growth blockers remain independent. Admin/recharge → creation → paid stable-schedule renewal produces a contiguous User chain while preserving earlier hashes and projection records. Real cancellation refunds and actual after-commit Observer writes continue to produce source gaps.

One hundred successful same-key replays return the original Order with zero capture SQL, even with invalid new capture context and dropped capture tables; cancelled/completed original results, native conflict/preflight, stale and expired claim recovery retain their behavior. A real Sanctum Bearer HTTP fixture enables `zend.exception_ignore_args=0`, executes both after-create hooks, injects a secret-bearing capture storage failure and proves fixed-safe conflict output, empty logs and rollback of wallet/Coupon/Order/idempotency/event/stream/map, followed by one successful original-key retry. Shadow rows, SQL bindings and read DTOs exclude credentials, coupon/key values and trade references. Bounded mixed-writer recovery remains SELECT-only and unapplied.

Independent MariaDB sessions prove same-key competition reserves wallet/Coupon and commits Order/claim/event once, different intents retain native serialized preflight, and creation under a stale outer MVCC snapshot reads the newly committed recharge endpoint and current stream tail. SIGKILL after capture/map writes before commit leaves no native reservation, Order, claim or event; SIGKILL after actual commit before response preserves them, and original-key retry returns the same native Order without another debit or event. The kill fixture changes only wallet balance and does not claim recovery of lost Observer obligations. Four PHP files pass lint, native schema checking reports zero issues, and registered HTTP route boundaries remain unchanged. Capture stays default-off; global writer coverage, production cutover, historical completeness and any applied Subscription/Terms/usage/funds projection remain unproved.

B1.10 specialized verification uses committed base `a7e73b13e1a8083c9f01056b243568b39033df68` plus only `OrderService`, `LegacyUserMutationCapture`, this contract, the updated fulfillment/creation tests and the new cancellation test. A separate fresh final archive uses committed base `e29db55489ed0ed2bc6bd24d3d0ddea67b557ec2`, including the committed public Internal read adapter, plus the same six files. Both use matching locked vendor and dedicated Redis databases 16/17; MariaDB uses only the isolated `txboard_concurrency_test` database. The five PHP hashes are identical between the specialized and final snapshots. Acceptance documentation is appended after testing without changing or relabeling either snapshot.

| Gate | Passed | Skipped | Assertions |
| --- | ---: | ---: | ---: |
| Cancellation capture SQLite | 15 | 6 | 304 |
| Cancellation capture MariaDB | 21 | 0 | 386 |
| Existing capture/native cancellation, Coupon, idempotency and scheduler SQLite | 87 | 17 | 1543 |
| Original four capture writers MariaDB | 79 | 0 | 1341 |
| Final complete API SQLite on committed e29db554 | 887 | 46 | 8000 |

The six cancellation SQLite skips require independent MariaDB sessions and process control. Actual User/Admin routes and the original two-hour job retain authorization, ownership checks, false/409 duplicates and scheduler behavior. Partial/full wallet reservations refund only native `balance_amount`, preserve fee/discount/principal fields, and release Coupon once. Zero/null refunds bypass capture and add no User reads/events; disabled production retains the original single User read/refund algorithm and zero capture SQL. Strict context, malformed/negative native refund/source, INT headroom and context drift fail atomically. A real Sanctum Bearer request with `zend.exception_ignore_args=0` injects a storage failure after Coupon/event/map writes, proves original `ORDER_CONFLICT` output and one fixed empty-context log without secrets/Throwable/trace/arguments, and then recovers with one refund. Actual pre-fence and post-transaction hook changes demonstrably remain gaps, including native false after an already committed after-hook failure.

Independent MariaDB sessions prove stale duplicate cancellation refunds once, both payment/cancellation status winners retain their original results, a stale outer MVCC snapshot uses an exclusive current User endpoint and current recharge stream tail, and cancellation serializes with recharge and new creation without losing wallet/Coupon evidence. The dedicated snapshot uses `FOR UPDATE`; older writer source reads retain their shared current-read SQL. SIGKILL after refund/Coupon/event/map writes before commit preserves none of them; SIGKILL after the actual commit preserves CANCELLED/refund/Coupon/capture, and original cancellation retries return false/409 without another refund or event. Mixed history retains older hashes/context; bounded verification is SELECT-only with unchanged projection records and unconditional unapplied/global-coverage flags. Five PHP files pass lint, native schema checking reports zero issues, and actual route export retains the two registered cancellation routes. These fixtures do not establish global deadlock freedom, hook/Observer completion, production readiness, external funding attribution or any applied shadow authority.
