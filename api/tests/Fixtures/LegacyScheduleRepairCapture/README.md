# Isolated schedule repair capture fixture

Use `LegacyScheduleRepairCaptureTest` with synthetic local/testing data. SQLite
uses a fresh named in-memory connection. Explicit `CORE_CAPTURE_TEST_MYSQL=1`
allows only disposable `txboard_concurrency_test`, validates its name before
cleanup and requires exclusive use. No production configuration or keys.

Tests cover real fix-null/force CLI, original no-marker behavior, unchanged
counters/logs, per-User partial commits, current eligibility, exact schedule,
mapping, no-op replay, original save false, source gap and safe diagnostics.
Independent MariaDB sessions and SIGKILL prove current-read/collision and SQL
commit boundaries. They do not certify projection, complete writer coverage,
notification/power-loss/restore or production cutover.

See [contract](../../../../contracts/v2/legacy-schedule-repair-capture-v1.md).
