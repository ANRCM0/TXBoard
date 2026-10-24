# Runtime scheduler ownership and diagnosis

The production image runs one `schedule:work --no-interaction` process as `www`
under Supervisor. It starts by default, restarts after unexpected exit, and
stops its process group on shutdown. Octane no longer runs an implicit scheduler.
Do not add a host cron or a second schedule worker for the same installation.
Existing `onOneServer` and `withoutOverlapping` task locks remain in place.

Native mode deliberately registers only these bounded owner commands:

- `core:subscriptions-tick --limit=100`
- `check:order --limit=100`
- `core:native-work --limit=100`
- `core:module-work --limit=100`

The legacy schedule below the native branch in `Console/Kernel.php` is not a
native task inventory. Legacy traffic, subscription and financial commands must
not be enabled as a workaround. Starting the scheduler neither changes runtime
admission nor activates an installation. An unprepared installation can reject
business commands until its normal installation flow completes.

## Operator checks

Run these inside the application container as root:

```sh
supervisorctl -c /etc/supervisor/conf.d/supervisord.conf status
supervisorctl -c /etc/supervisor/conf.d/supervisord.conf status scheduler:*
php /opt/txboard/healthcheck.php
```

The control socket is `/run/txboard-supervisor.sock`, mode `0600`, with no TCP
listener. The scheduler should show `RUNNING`. Container stdout includes each
scheduled command and its `DONE` or `FAIL` result; inspect at least one full
minute after startup. A process list or a leftover Redis lock alone does not
prove task completion. Health combines HTTP liveness with the enabled
scheduler's Supervisor state; it does not certify task success or queue drain.

The image defaults `ENABLE_SCHEDULER=true`. Root Compose exposes
`TXBOARD_ENABLE_SCHEDULER` with the same default. Set it to `false` only when
another explicitly managed scheduler owns this installation, or in fixtures
that intentionally omit a prepared database. A Compose environment override
must be applied when recreating the container. Do not disable the scheduler to
hide a failed native startup.

Embedded Redis starts first. PHP services wait up to 60 seconds for its local
socket to answer PING before Laravel boots; Supervisor retries failed starts.
Pre-supervisor database probes use temporary array caches. When the embedded
Redis program is explicitly disabled, the wait is skipped and the external
Redis configuration remains the operator's responsibility. Redis persistence
and no-eviction policies are unchanged.

## Acceptance

The Images workflow verifies the actual built image after fresh native install,
module upgrade and interrupted-upgrade recovery. It observes successful runs of
all four native tasks without manually calling `schedule:run`, checks a single
dedicated worker and a root-only control socket, kills the fresh worker to prove
automatic restart, and stops it to prove that health fails even while HTTP
remains available. The fixed-image proof artifacts record these observations.
The unprepared SQLite HTTP smoke fixture explicitly disables scheduling.

Candidate Actions results must be bound to the exact source SHA and tested
tree. They do not establish production deployment or production task completion.
