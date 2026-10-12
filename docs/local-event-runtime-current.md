# Automatic local event consumer

Status: implemented and verified for local evidence only. This contract describes the runtime
slice built on the [local event store](local-event-store-current.md). It remains
default-off local evidence: `authorizes: false`, `unattributable`, restore and
tail unknown. No Node Usage v2 submission or billing authority is enabled.

`usage_event_store.enabled` explicitly opts a Service into observation. The
configuration is not inherited from multi-instance root defaults. Expanded node
and machine Services keep an explicit group's option, but only the first enabled
supported Service acquires the process lifetime owner. Other Services continue
v1 and report `LOCAL_PROCESS_OWNER_EXISTS`. This slice does not provide complete
multi-Service coverage. The owner is never released or replaced in that process,
including after open failure, normal close or shutdown timeout. Xray is unsupported.

One worker opens the private store asynchronously and then persists meter claims.
Service startup, count hooks and lifecycle notifications perform no filesystem
work. Registration holds at most eight current or retired meters; each runtime
meter holds 128 events per bucket and eight sealed buckets, with one open bucket.
There are no per-tracker workers, opener goroutines or retirement timer tasks.
Default construction does not open the store, create meters or start this worker.

A successful full sing-box Start creates a new meter and fixed connection
bindings. Reload and user updates keep the existing tracker and perform local
lifecycle cuts. Their generation is local observation state, not a Core Terms,
period, grant or authorization boundary. The v1 cumulative collector is polled
exactly as before; local event observation does not poll or consume its counters.
Each tracker's monotonic origin is instance-owned. Elapsed stamps are comparable
within that tracker and cannot align separate tracker or process timelines.

An old tracker stays registered across a full restart. Retirement grace begins
only after its box Close returns and cancel is invoked, never when ActiveCount
becomes zero. Its meter uses process-relative monotonic time for a fixed five
second grace. Begin, completion callbacks and the worker's bounded poll check
the deadline. At the first actual check after expiry, the meter seals available
events and stops accepting new observation events. That seal records the actual
check time; it does not assert an exact expiry or complete I/O interval. Late
callbacks retain their original binding and increment only unknown-tail/drop
state after observation stops. The original v1 count and limiter path continues.

A normally stopped meter can still persist retained sealed buckets. Its registry
reference is released only after every retained claim has a durable local receipt
and its open tail is sealed or explicitly unknown. This does not prove that no
old callbacks remain. Capacity, invalid evidence or storage failure fail-stop
observation and preserve bounded retained data; they do not stop v1 runtime.

Service shutdown first performs the existing kernel stop. Observation shutdown
can end the grace early, with tail unknown, and tries to persist retained evidence
within a two second join. It dispatches no fresh work after the deadline. A worker
already stalled in Open or Sync retains its file descriptors and exclusive lock
until the syscall returns or the process exits; no replacement worker is started.
All unconfirmed RAM remains an unknown tail after SIGKILL. Existing hash-chain,
pending/head recovery checks, byte/file limits and no-history-deletion policy apply.

The private event journal uses a non-secret stable Service identity hash beneath
the configured root (or Kernel.ConfigDir), independently of cumulative observation
and the v1 pending report. Tokens and user UUIDs are not written. Direct-operation
stamps and callback-completion-only intervals, physical directions and existing
legacy UDP counter directions are retained unchanged. Local evidence never fills
an unknown manual Core boundary, clock rollback or I/O interval by interpolation.

Fixed Node `4094fe0` plus sixteen Go runtime/test files: Linux four affected
packages passed173 main tests and68 subtests under race, zero skips/failures;
the same packages passed vet. The fixtures execute actual Service.Run and the
production ConnTracker using memory TCP and loopback UDP, with no live VLESS
listener or panel. Delayed Open/Sync, immutable receipt/fsync, retirement,
capacity and existing process-death recovery tests are included. See the
[source and log verification](local-event-runtime-verification.json).
