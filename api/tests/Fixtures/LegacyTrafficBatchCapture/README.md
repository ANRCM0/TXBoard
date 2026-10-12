# Legacy traffic batch capture fixture

Synthetic Users, Servers and immutable capture endpoints only. The new
[contract](../../../../contracts/v2/legacy-traffic-batch-capture-v1.md) keeps
legacy counters authoritative and all migration gates closed.

From `api/`, with the locked Composer dependencies installed:

```sh
php -d zend.exception_ignore_args=0 vendor/bin/phpunit \
  tests/Feature/Subscription/LegacyTrafficBatchCaptureTest.php \
  --fail-on-warning --fail-on-risky
```

The default fixture uses its own in-memory SQLite connection and migrations.
Four tests explicitly skip because they require real independent database
connections, Linux process control and child-process SIGKILL. To run them,
create an empty, disposable database named **exactly**
`txboard_concurrency_test`, grant its fixture account access only to this
database, and set `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` locally:

```sh
CORE_CAPTURE_TEST_MYSQL=1 DB_DATABASE=txboard_concurrency_test \
  php -d zend.exception_ignore_args=0 vendor/bin/phpunit \
  tests/Feature/Subscription/LegacyTrafficBatchCaptureTest.php \
  --fail-on-warning --fail-on-risky
```

The opt-in fixture removes and recreates all tables in that exact disposable
database before and after each test. Do not share it with parallel database
tests. Credentials are environment inputs, never committed production values.
Redis calls are mocked; no panel, production Node, SMTP or payment service is
contacted. Real database concurrency and process-crash behavior are tested;
power-loss durability and lost post-commit Redis hints are not claimed.

The tests cover replay/conflict, unchanged/absent Users, native fractional rate
rounding, mapping invalidation without projection, whole-batch rollback after
later capture failure or node overflow, source gaps, strict configuration,
fixed queue diagnostics, original retry policy, synthetic deadlock retry,
independent same/different Server sessions, stale MVCC and commit-boundary death.
