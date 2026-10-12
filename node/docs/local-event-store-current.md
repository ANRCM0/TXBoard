# Local event store foundation

Status: explicit consumer only. This slice provides a durable store and bounded
coordinator for the actual accounting-event meter. It does not attach a consumer
to Service, change sing-box lifecycle/configuration, submit v2 usage or recover
v1 pending reports. The production runtime remains on its current v1 path.

An explicit consumer opens `usagebucket.OpenStore`, constructs `NewCoordinator`
and adds a meter obtained from `NewConnTrackerWithEventBuckets`. Store initialization
is synchronous in this foundation. Automatic asynchronous startup, old-tracker
retirement, lifecycle fences and production drain belong to the next slice.

The coordinator has one worker, a one-slot wake channel and at most eight meter
references. Each meter has at most one private claim for its oldest sealed bucket.
The bucket remains in the meter's bounded retained queue until confirmation;
claiming does not move or delete it. Only the package-private persistence receipt
can release that exact claim. A foreign owner, missing commit, changed digest or
stale claim cannot acknowledge it. The disk identity is `(tracker_id,bucket_number)`
with contiguous per-tracker sequence ranges, independently of wall timestamps.

The worker serializes outside the meter lock. It writes the original sealed
events, totals, binding generations, clock eras, direct operation stamps,
unknown callback intervals, close fences and both physical and legacy directions.
There is no interpolation or current-user rebinding. The same sealed identity
and digest returns its original local receipt, including after a store restart;
different content for that identity stops persistence. Reading evidence returns
independent objects and validates the committed digest.

Every record states `authorizes: false`, `attribution: unattributable`,
`restore_unknown: true` and `tail_unknown: true`. Bucket records contain
`local_receipt.type: local_receipt`; this is a local disk confirmation, never a
Core receipt, an authorization, a billing ACK or evidence that the stream is
complete. Raw integers and sequence/time values use decimal strings in the fixed
disk schema. Unknown JSON fields, duplicate/noncanonical encodings, invalid
direction totals and nonconsecutive identities fail validation.

The store uses a private journal directory and a separate sibling `<dir>.state`
directory, with Linux exclusive flock, no-follow file opens, 0700 new directories
and 0600 files. Immutable journal filenames are local sequence numbers, not
timestamps. Records form a SHA-256 chain. New directory entries are synchronized
in their parents. A local confirmation requires all these steps:

1. Write an exclusive pending record and `File.Sync` its content and metadata.
2. Publish a hard link to the immutable final name, refusing overwrite, then
   synchronize the journal directory.
3. Write and sync an independent head temporary file; atomically replace the
   state head and synchronize its directory.
4. Remove the pending record and synchronize that removal in the journal.
5. Publish the private local receipt and acknowledge the exact meter claim.

`File.Sync` uses fsync, which covers the data durability needed from fdatasync as
well as metadata; directory fsync is a separate requirement. Any gate failure
returns no confirmation and leaves retained meter evidence and remaining pending
files intact. The writer stops, preserving its original identity. When a commit
completed but the in-memory ACK was lost, the original disk record is retained
and can answer the same identity without a second record. No disk history is
garbage-collected or rewritten to resolve uncertainty.

Opening scans at most 4096 files and decodes at most one 512 KiB record at a time.
Pending files, a missing or ahead/behind head, missing records, unknown files,
symlinks, duplicate identities and digest/sequence corruption stop reopening.
Uncertain publication is not automatically promoted to a successful receipt.
Journal-only rollback is detected when the independent state head remains newer.
Rolling back or deleting both directories together cannot be detected without an
external anchor. Every restart therefore records `RESTORE_NOT_EXTERNALLY_VERIFIED`
and `UNSYNCED_TAIL_UNKNOWN`, with a fresh process identity; unclean exits also
record `UNCLEAN_PROCESS_BOUNDARY`. Monotonic clocks are not compared across processes.

Open or sealed buckets still in RAM are not durable until this confirmation.
SIGKILL can lose that uncommitted tail; its bytes and boundaries remain unknown.
Neither a clean stop record nor ActiveCount zero proves that no late callbacks
exist. Closing the coordinator stops further observation and dispatch, preserves
uncommitted retained buckets and writes a tail-unknown control record where the
disk remains usable. It does not claim to drain the runtime or all retained data.

Limits are 64 MiB by default, configurable from 1 MiB to 1 GiB, 4096 files across
journal/state including the lock, 512 KiB per record and 65536 potential retained
events across registered meters. At most one sealed payload is serialized at a
time. A 64 KiB and 16-file control reserve, plus conservative pending/head space
reservation, preserves room for a stop record. All pending evidence consumes the
same capacity budget. Capacity, registry overflow or storage failure stops all
registered observation meters while their v1 counters continue. No unbounded
queue or replacement writer is created.

Filesystem work holds only the store write lock, never the meter or coordinator
state lock. A stalled sync cannot block count hooks, local cuts or state reads.
`Coordinator.Close(ctx)` has a deadline: if a syscall remains stalled it leaves
that one worker and exclusive lock alive until the worker finishes or the process
exits. External callers cannot close the worker's live store or start a second
consumer. Default production construction creates no store worker or disk I/O.

[Verification report](local-event-store-verification.json) records real Linux
race/vet and fixed source hashes. Explicit-consumer tests use net.Pipe TCP and
loopback UDP through the actual tracker and pinned sing CopyPacket, validate
durable-before-release and original directions, and preserve late callback
binding. Child-process tests send actual SIGKILL at five persistence/ACK gates;
they prove process-crash behavior, not power-loss or faulty-device durability.
Fault injection covers each file/directory/head gate and blocked-writer isolation.
