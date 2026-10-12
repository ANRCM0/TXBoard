# Isolated Observer schedule capture fixture

`LegacyObserverCaptureTest` uses fresh named in-memory SQLite, or explicitly
`CORE_CAPTURE_TEST_MYSQL=1` and only the disposable `txboard_concurrency_test`
database. Tests check the database name before table cleanup; run MariaDB
exclusively. Users, gifts and plans are synthetic, never site data or secrets.

The additional Observer switch starts false during fixture creation. Tests
enable it only in local/testing alongside original isolated capture. They cover
gift/Order transaction endpoints followed by actual after-commit recalculation,
no-op/native default SQL, stale current User reads, fixed policy/context guards,
source gaps, original withoutEvents listener bypass and failures after the earlier business commit.
Independent MariaDB processes test lock arbitration and SIGKILL around the
follow-up transaction. Neither prior business cancellation nor Node delivery,
complete writer capture, power loss or production migration is certified.

See [contract](../../../../contracts/v2/legacy-observer-schedule-capture-v1.md).
