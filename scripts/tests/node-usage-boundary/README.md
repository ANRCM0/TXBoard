# Actual bounded TCP usage and Core HTTPS fixture

This fixture tests the [bounded TCP/Core contract](../../../contracts/v2/node-usage/bounded-tcp-core-interop-v1.md).
It uses actual sing-box ConnTracker memory TCP, existing journal/spool/Reporter,
Node native handshake and HTTPS transport, actual Laravel middleware/controllers,
MariaDB receipt/outbox and the existing settlement worker. No production Service,
credential issuer or billing writer is enabled.

Prerequisites are Linux, locked PHP/Go dependencies including Core autoload-dev
fixtures, Go race support, MariaDB client and server, Redis, OpenSSL and Python3.
Run from a fixed source snapshot with copied locked Composer vendor and an empty
`.env`. The fixture is not part of the production image; it requires tests/dev
classes. Migration and Go race compilation finish before the Core grant is seeded, so
a cold CI build cannot consume its finite lease. No shared lease is extended.

The Node helper is copied inside a fresh private directory beneath the
supplied Node module and built with `go build -race`; Go internal-import visibility
is preserved without a go.mod replacement or changing module locks.

The exclusive disposable DB must be `txboard_bounded_usage_test`, accessible only
through the fixture user `txboard_bounded_usage` at `127.0.0.1`. Supply its password
and port through your local test environment. `APP_ENV=testing` and
`DB_CONNECTION=mysql` are required. This launcher never creates/resets a database
or grants DB privileges; an external fixture setup owns that decision. It refuses
any populated database before migration and does not clean existing history.

```sh
python3 scripts/tests/node-usage-boundary/run.py /fixed/TXBoard/api /fixed/TXBoard/node /private/new-evidence-directory
```

Set `REDIS_HOST/PORT/DB/CACHE_DB` for dedicated local test caches. The runner applies
array caches, a synthetic-only Node token, fixed isolated Core usage settings and
a test application key. It opens only loopback PHP/TLS listeners on ephemeral
ports, creates a one-day local certificate restricted to 127.0.0.1/localhost and
requires TLS1.3 with normal root/SAN verification. The TLS terminator only forwards
three closed Node operations; there is no Admin/Internal proof or client mTLS
certificate. It terminates only its two created local child processes.

Actual 11-upload/7-download direct/extended TCP bytes produce one frozen batch.
The client consumes a real successful Core submit response before injecting loss,
then the existing Reporter queries that original receipt. Core SQL verifies its
pending batch/digest/outbox before settlement. Duplicate submit preserves receipt,
changed canonical bytes return409, invalid Bearer/unknown Node refuse access.
After close/reopen, Core's frozen3/2 per-direction floor yields16/10, total26.
The Node queries settled, replays original payload and retains its receipt after
another Close; SQL still has one batch/one ledger entry and unchanged totals.

Fresh artifacts include original Session/submission, pending/settled receipts,
immutable journal/spool, SQL summaries, public TLS cert and hashes. Private TLS key
and executable remain only inside the local private evidence directory; do not
publish them. Public evidence should exclude key.pem, executable and lockfiles.
The output JSON contains no Node bearer or runtime User secret/UUID label.

See the [verified snapshot report](../../../node/docs/bounded-core-interop-verification.json).
This demonstrates real isolated receiving and exactly-once settlement. V1 still
counts the traffic; full Service handoff, one production settlement authority,
credential admission/revocation, restore and distributed Node recovery remain
separate gates. The launcher does not silently reuse or destroy a previous DB.
