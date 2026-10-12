# Local runtime usage observations

Status: default off. This observer collects local evidence through the production
Service lifecycle. It does not submit Usage v2 batches, enforce finite grants or
change the current v1 accounting authority.

Explicitly enable it on the individual Service configuration:

```yaml
usage_observer:
  enabled: true
  directory: /var/lib/txnode/observations
  max_bytes: 67108864
```

The directory is an optional persistent root; the default is `kernel.config_dir`.
Service derives a separate directory from the provider, panel URL, numeric node
and machine IDs and local instance ID. Tokens, UUIDs, source IPs, credentials and
inferred subscription IDs are absent from records. Multi-instance configurations
must opt in on each instance; this setting is not inherited from global defaults.

Service opens the observer when `Run` starts. The ordinary track tick passes the
same raw cumulative map to the observer after the existing v1 Tracker has consumed
it. Observer lifecycle hooks cover Start, successful/failed Reload, full/incremental
user updates, Stop for no users, typed operations and Run shutdown. These hooks
never call `GetUserTraffic` again and never flush, reset or restore the v1 Tracker.

Each immutable record contains a sequence, previous-record digest, random local
process ID, local observation generation, event, wall-clock Unix nanoseconds,
process-relative monotonic elapsed nanoseconds and original signed cumulative
upload/download counters as decimal strings. Generation advances at process and
lifecycle boundaries. It is not a Core source epoch or a proof that kernel meters
reset. The monotonic clock cannot span process restarts.

Track records identify a `current_track_sample`. Lifecycle records preserve the
`last_track_snapshot` with its original sample times and generation; a lifecycle
timestamp does not turn cached counters into a new sample. When no sample exists,
records explicitly contain `none`. Unknown unsampled tails remain unknown.

All records have `attribution: unattributable` and
`AUTHORIZATION_BOUNDARY_UNKNOWN`. Clock rollback, monotonic rollback, negative
counters, decreasing counters and disappearing counters add stable reason codes.
Neither a reset nor missing data is converted into a billing delta. Clean process
shutdown still cannot prove the last sample includes traffic arriving afterward.
An interrupted process adds `UNCLEAN_PROCESS_BOUNDARY` on restart.

Linux exclusive locking prevents two writers. Files use mode 0600, file fsync,
publication that refuses an existing filename and directory fsync. Sequence and
digest chains are checked on restart; malformed, reordered, modified records or
gaps within the retained chain stop observation. Incomplete temporary files remain and are marked as
interrupted evidence. SHA256 detects accidental modification; it is not an
authenticated audit log or protection against an operator rewriting the directory.
Without an external high-water mark, deletion of the entire directory or its final
suffix and restoration of an older copy cannot be detected. A new process always
has a new random identity and an unknown boundary; local generation must never
be used as a restore-safe billing identity.

Storage is bounded by `max_bytes` (64 MiB by default), 4095 retained evidence files,
4 MiB per record and 10000 counters per sample. There is no automatic deletion or
rotation. The file count can be reached before the byte limit. At a 10-second
sampling interval, an otherwise idle stream reaches it in about 11 hours; this is
a bounded observation exercise, not unattended production retention. Operators
must keep the directory on persistent storage and archive it only after stopping
the writer. Failed or uncertain writes stop this observer instance and retain
existing evidence; it does not retry or invent continuity. Restart revalidates the
whole retained history and records a new process boundary. Linux is required;
unsupported platforms leave observation unavailable while v1 continues.

Enabled Service metrics expose a safe state (`recording`, `capacity_reached`,
`evidence_invalid`, `storage_unavailable`, `closed`), committed record count, local
generation and accounted file bytes. Errors log only a safe reason. Observation I/O
does not discard v1 counters or gate kernel changes. Opt-in writes are synchronous
and can delay that Service loop on slow storage; the default-off path has no
observer disk I/O.

The cumulative v1 meter cannot identify a Core manual Terms cutoff received after
the event. No proportional splitting or current-grant remapping is permitted.
sing-box full Start also drains the old tracker in a background instance, so the
old instance's final tail cannot be proven by the new poll. This evidence stays
unattributable. Exact runtime v2 requires advance boundaries with a fence/ACK or a
Core policy that honors outstanding finite coverage, signed Provider/runtime grant
binding, bounded authorization checks, clock-skew rules, atomic meter intervals,
recovery/backpressure policy and backend revocation semantics. The current Service
observer neither proves the v1 pending/counter handoff nor activates that path.

Verification is tracked in [runtime-usage-observer-verification.json](runtime-usage-observer-verification.json).
The new Service test runs the production Service loop and track ticker over a
kernel-interface lifecycle harness with the actual sing-box ConnTracker and
local `net.Pipe` I/O; it does not deploy a proxy listener. Separate tests feed actual
sing-box connection counters through Service hooks into the original v1 reporting
controller, including observer capacity failure. Storage tests cover restart,
publication faults, exclusive ownership, history corruption, rollback and resets.
