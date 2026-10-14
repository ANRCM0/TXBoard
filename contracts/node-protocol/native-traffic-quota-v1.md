# Native traffic and quota closure v1 (Candidate)

This slice reuses existing native per-user metering, immutable Node pending/terminal batches, NativeNodeReport, UserService traffic hooks, TrafficBatchJob atomic settlement, User traffic resets and current desired-user synchronization. It does not activate isolated Usage-v2 or change global migration authority.

## Meter ownership

A new sing-box runtime has a new cumulative meter owner. The Service must observe the retired owner's final counters and explicitly establish the new owner's baseline; guessing a restart only when counters decrease loses bytes. Xray currently retains cumulative counters across reconstruction, so clearing its baseline would double count. User hot updates, rejected configuration and failed starts cannot drop pending bytes or replace an active meter owner. Actual VLESS/VMess reconstruction and preserved Xray counters must be tested.

## Durable native ingress and replay

Before a nonempty native traffic report is acknowledged, Core must durably record its authenticated node ID, original batch ID, canonical raw body hash, first acceptance time/day, selected billing rate and normalized post-hook settlement input. Unique (node ID, batch ID) identifies one immutable acceptance. Exact replay returns the original acceptance, even after rate/day changes; a different raw traffic body under the same identity is rejected with TRAFFIC_BATCH_CONFLICT and no mutation. Input validation and current Node/Machine authentication still precede acceptance. Secrets and credentials are never stored in receipt rows.

A durable pending acceptance is the source of recovery; Redis dispatch is a wakeup and cannot be the only copy. HTTP202 describes durable acceptance, not completed billing. Actual settlement uses the existing TrafficBatchJob SQL transaction and unique ledger, then marks acceptance settled with an independently observable ledger. Crash before/after ledger commit, missing Redis job and failed job can safely retry the original immutable input without double charge. An explicit bounded recovery command and production schedule must restore pending acceptances. Errors remain visible; no destructive auto-clear, fabricated settled state or unbounded retry loop.

The [observed-period contract](native-traffic-period-v1.md) adds signed sampling attribution to the real producer. Legacy native batches without sampling-period evidence retain explicitly documented arrival-period semantics. They cannot prove attribution to the period in which bytes were produced. The signed producer must pass its separate real reset/delay/restart gates; no timestamp guessing or clearing old spool is permitted.

## Quota and reset synchronization

SQL settlement and quota notification retain the committed users as recoverable candidates. Processing candidates must be bounded and retryable on notification failure. Workerman resolves current desired users before applying quota-triggered synchronization, so a delayed remove cannot override a later reset/regrant. Both integer and string group IDs are selected. Ordinary transport faults do not become authorization revocation. Existing active streams cease when the applied current user set removes their identity; unaffected users remain available within the selected kernel's declared reconstruction policy.

Native reset continues to lock and reread User, clear current u/d, increment reset_count and record reset history in one transaction. Late reports require explicit period evidence for historical attribution. Receipt recovery, rate/day freezing, metering generation and current-period quota closure alone do not complete cross-period billing.

## Acceptance and scope

Use dedicated synthetic SQL/Redis, true PHP HTTP/Workerman/queue worker, ordinary native Node and real VLESS/VMess byte streams. Observe raw and billed direction totals, unique ledger, durable acceptance, lost ACK replay, queue loss/recovery, overflow rollback, rate change, quota stream close and current-user reset recovery. Exact source/lock hashes and failed reproductions are retained. All-protocol coverage, cross-period attribution, production PKI, finite offline leases, Cluster/HA and global producer drain remain separate unfinished requirements until separately evidenced.

The recovery and quota commands process at most their explicit validated limits. A quota candidate is retained on Redis failure or zero subscribers; offline nodes enforce the current desired snapshot on reconnect. Publication and the quota marker are not an applied-state acknowledgement. `traffic:health` reports pending/failed durable receipts, pending quota notifications and failed traffic jobs independently of queue size; it never asserts global drain. Redis retry_after exceeds the traffic job timeout.
