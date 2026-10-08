# Phase 2 traffic and node reliability — operations

TXBoard schedules `php artisan traffic:health` every five minutes
(`--minutes=15 --threshold=1000` by default).
The command checks the durable `v2_traffic_batch` ledger for recent
settlement throughput and probes the Redis `traffic_fetch` queue backlog.
Run `php artisan traffic:health --json` for machine-readable counters.

Warnings logged as `TXBoard traffic reliability alarm` include a
`traffic_queue_unavailable` or `traffic_queue_backlog_high` code.
An empty ledger can be normal if no traffic is generated; throughput
alone is not interpreted as a failure.

## Fault-injection checklist

1. Send a valid identified report. Verify one ledger row and matching
   user, user-stat, server and server-stat counter increments.
2. Retry the identical `traffic_batch_id` and byte counters: verify all
   SQL values stay unchanged and duplicate event is recorded.
3. Send the same ID with different counters: verify collision log and no
   second settlement.
4. Send batches in reverse ID order, including repeats: final charged
   totals must match the sum of distinct valid increment batches.
5. Cause an SQL counter overflow: the ledger and every counter must roll
   back together; a successful repair and job retry can then settle.
6. Interrupt Redis queue before submission: the node must retry the same
   ID and unchanged bytes after connectivity restores.
7. Disable Redis Pub/Sub while keeping SQL active: the WebSocket server
   should reconcile full snapshots on its periodic sweep.
8. Reconnect a machine while an old socket is closing: the old close
   event must not erase the new node's presence or device state.
9. Kill TX-Node during an unacknowledged HTTP request: until a durable
   pending-batch spool is deployed, this is **not** crash-loss-proof.
10. Check `traffic:health --json` on a backup queue test environment
    under artificial backlog and restore normal queue service.

The first six cases have automated unit/feature coverage for the
database boundary; live distributed-process chaos/fault drills must
be performed against Redis/MySQL/TX-Node staging before production.
