# Isolated standalone reset capture fixture

`LegacyStandaloneResetCaptureTest` uses a fresh named in-memory SQLite database.
Explicit `CORE_CAPTURE_TEST_MYSQL=1` only allows the disposable
`txboard_concurrency_test` database and checks its name before cleanup. Synthetic
fixture data and local process/socket barriers never access real Nodes/sites.
Run the MariaDB fixture exclusively.

Tests cover native manual User/Admin permissions, fixed trusted reset labels,
current eligibility, old counters/reset log/context, default false SQL behavior,
storage rollback, nested gift/hook ownership, original due cron policy and
separate order post-commit reset. Real independent MariaDB sessions test manual
repeat semantics and SIGKILL before/after User/reset log/evidence commit.
v2 Terms/Usage projection, full writer coverage, notifications, power-loss
durability, restore and production cutover remain separate gates.

See [contract](../../../../contracts/v2/legacy-standalone-reset-capture-v1.md).
