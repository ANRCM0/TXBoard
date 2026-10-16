# Explicit bounded TCP collector v1

> 已退役（2026-10-16）：本文保留隔离原型的历史设计和当时验收记录。对应开关、Registry/CLI、运行器和专属 CI 已删除；正文 Current/isolated 状态不描述现行运行时。当前用量边界见 [当前 native 契约](README.md)。

Status: verified isolated producer/consumer prototype. Default Node Service does
not call this constructor. Existing v1 billing/reporting remain authoritative.
Core grants must come from the separately authenticated Core usage-v2 Session;
local Provider signature/window diagnostics are never accepted as grants.

The explicit sing-box tracker freezes a bounded immutable Session, runtime User
map and grant identity. TCP direct Read/Write (including extended writer and
pinned sing Copy delegation) record both I/O-start and completion UTC/monotonic
stamps. A private net.Conn view without Count/Upstream interfaces ensures Replaceable
and zero-copy counter unwrap cannot expose the raw
connection on this explicit path. Unknown users, UDP, mapping changes, invalid
counts, rollback, capacity/drop and late-after-Close fail-stop collection, while
v1 I/O, counters and rate limiting continue. Grants are bounded to 128 entries and at most one hour of remaining local
window; UTC and a fixed initial monotonic deadline both apply. Partial I/O with
an error records its
actual positive byte count and bounded range, never drops those bytes.

A Flush samples the clock under the collector lock and uses the *floor* current
UTC second as its half-open end. It never rounds adjacent ends independently.
An in-flight operation starting before that boundary, or a completed operation
crossing it, defers sealing without discarding or moving its counters. A later
boundary can include the complete operation. Fully post-boundary operations are
retained in the next interval. The initial start is the UTC second containing
constructor completion; the initial fractional prefix precedes collector setup
and no tracker I/O was registered there. The prototype makes no claim about
traffic outside this collector, elsewhere in the process or before setup.

Each actual operation has one private Begin ticket, frozen User/grant mapping
and completion stamp. A zero-byte completed I/O is still recorded. Exact endpoint
and clock/grant checks use nanoseconds; statement StartedAt/EndedAt use integers.
Only a nonempty byte interval entirely within each frozen grant can produce a
v2 payload. Unknown/cross-lease observations stop collection. Idle intervals are
advanced without inventing zero-byte batches. The Session cannot be updated.
Collector locks never span filesystem work; a failed Flush retains the exact
claim and random batch identity, while post-boundary I/O remains retained.

The consumer first stores an immutable bounded evidence journal using private
0600 files, file fsync, non-overwriting publication and directory fsync. It then
exports the original Session/Submission into the existing independent v2 spool.
That spool can be consumed by the existing Reporter. Recovery re-exports only
committed sealed journal records with original IDs and evidence; it cannot resume
an old live collector or reconstruct its unsealed tail. A durable process/source
marker requires a strictly newer Core source epoch before a fresh live collector;
even a previous source that produced no sealed batch cannot be resumed. Receipt identity/Core/
node/restore generation must still agree. Journal/local receipts are not Core
ACKs, and no record is deleted because export or settlement succeeded.

Crashes before journal intent leave unknown volatile tail. Crashes after journal
commit or spool commit preserve the same batch for replay. Existing local-event
buckets have explicit unattributable/tail/restore-unknown flags and are not inputs
to this constructor; their callback completion-only counts cannot be promoted.
No production admission, credential installation, trustworthy external time,
lease session termination, revocation convergence or restore authority is added.

Producer entry: explicit `NewConnTrackerWithBoundedUsage`, routed actual TCP
wrapper Begin/Complete. Consumer entry: tracker `FlushBoundedUsage` into the
journal exporter and existing Spool; existing Reporter handles original receipt
verification. Unsupported kernel/UDP and unknown evidence cannot become an empty
successful billing interval. Fixture traffic uses memory TCP; Reporter fixtures
use only in-process fake transport, never a production panel.


The evidence hash and canonical validation detect inconsistency; they do not
cryptographically attest complete historical I/O. The trusted actual producer's
private in-flight tickets, frozen binding and serialization establish that local
claim. Same-OS file replacement with rehashed invented evidence is outside this
prototype's trust boundary. No hostile plugin sandbox is claimed.


The ordinary v1 cumulative collector still counts this traffic. Calling the
explicit Flush/Reporter path alongside v1 is isolated evidence testing only and
must not activate a second authoritative billing writer. No automatic Reporter
is started. Production v2 handoff must first prove the existing v1 pending report
and cumulative collector empty using the separate handoff gate, and select one
settlement authority. Core's isolated usage-v2 feature remains default-off and
is not a production billing authority in this prototype.


Fixed Linux Go 1.26.3 snapshot: five complete affected packages passed 185 main
and 152 subtests under race, with zero skips or failures; the same packages
passed vet. Actual memory TCP produces a nonzero asymmetric 11-byte upload /
7-byte download batch, preserved through journal/spool and existing Reporter
query-before-replay using synthetic receipt transport. The prototype has no
actual Core HTTP interoperation yet. See [precise verification](../../../node/docs/bounded-usage-verification.json)
and [current producer/consumer boundary](../../../node/docs/bounded-usage-current.md).
