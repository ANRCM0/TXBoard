# Isolated Service usage-v2 mode v1

Status: verified isolated Service implementation slice. This explicitly connects the real Service
lifecycle to the [bounded TCP collector](bounded-tcp-collector-v1.md) and existing
native Core HTTPS reporter. Ordinary configuration remains v1. Production handoff
is closed until authoritative writer/Provider/restore gates are independently met.

`usage_v2.enabled` requires `isolated_copy=true`, one TXBoard HTTP/sing-box Service,
an explicit durable directory, and loopback verified HTTPS. Machine, multi-node,
standalone, xray, WebSocket bootstrap and simultaneous local observers are refused.
No mode flag is inherited across root instances. One permanent process owner can
enter this mode; it is not replaced after failure or timed-out shutdown.

Before listeners start, native authenticated handshake supplies a finite Session.
The existing exact v1 pending path must be absent; the legacy reporting controller
must have no RAM pending/active batch; the Service Tracker must be empty; the actual
kernel must not be running and must have no cumulative bytes/connections. The
existing CheckHandoff is called with these observed facts, not a caller assertion.
Present/invalid pending files and unknown collectors refuse startup without
changing or draining them. There is no live downgrade/upgrade switch.

The real sing-box Start installs the explicit bounded tracker before starting its
listeners, and permits only one start for that frozen Session. The tracker returns
direct-I/O views, rejects UDP/unknown binding and prevents new I/O when its collector
is unknown/expired/closed. Partial positive n/error remains recorded. A separate
short check cannot certify already-running I/O beyond its signed grant; such tail
is unknown and stops mode traffic rather than being billed as zero.

A single bounded worker Flushes immutable complete intervals, commits journal/spool
and invokes the existing Reporter. File operations stay outside user/connection
locks. Normal heartbeat/online reporting still uses native v1; Traffic is omitted.
Cumulative statistics remain available for metrics without accumulating a second
billing queue. Unknown state, storage/receipt/recovery failure, lease expiry or
local config/user mutation stops accepting and closes active connections, returns
a fixed error and never falls back to v1 billing. A main-loop lease timer operates
independently of a blocked worker. The finite Node/Core policy remains isolated.

A restart with the old source epoch is refused by the durable marker. No handshake
or restart invents a new source or reapplies current grants to old batches. A
separately assigned higher Core source epoch is required; old sealed records may
replay with original identity. No new source-opening HTTP operation is added.

Shutdown first closes mode traffic, marks unknown unsealed tail and allows the one
worker to retain/export already sealed evidence. It joins within two seconds.
Stalled fsync retains original descriptors/flock and process owner until completion;
no replacement worker starts. Closing a mode does not delete payloads/receipts or
pretend the unfinished tail was successfully settled. Production admission,
credential delivery, distributed revocation, trusted time and restore are not
provided by this single-Service isolated integration.


Fixed Linux Go1.26.3 snapshot: nine complete affected packages passed239 main and
152 subtests under race; the sole actual-Core-only test was explicitly skipped
there and then passed in both capture and settled invocations against actual
PHP8.4.16/MariaDB11.4.12 over verified TLS1.3. Vet passed on the same snapshot.
Actual Service.Run with native config/users/handshake and production enforcing
ConnTracker produced11 upload/7 download, one SQL batch/ledger entry and billed
16/10 bytes, total26. Original legacy User u/d remained0/0. The integration kernel
lifecycle is injected to route memory TCP without a live proxy listener; a separate
actual sing-box Start test proves the frozen producer is installed before listener
start and refuses a second Start. There is no production VLESS/VMESS session claim.
See [source and evidence report](../../../node/docs/service-usage-v2-verification.json).
