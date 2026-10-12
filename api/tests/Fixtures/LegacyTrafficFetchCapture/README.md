# Unidentified traffic capture fixture

From `api/`, run with the locked Composer dependencies:

```sh
php -d zend.exception_ignore_args=0 vendor/bin/phpunit \
  tests/Feature/Subscription/LegacyTrafficFetchCaptureTest.php \
  --fail-on-warning --fail-on-risky
```

Default tests use a private in-memory SQLite database. To exercise the two real
Linux process/concurrency tests and native INT assignment, use the same strictly
disposable database setup as the [identified-batch fixture](../LegacyTrafficBatchCapture/README.md):

```sh
CORE_CAPTURE_TEST_MYSQL=1 DB_DATABASE=txboard_concurrency_test \
  php -d zend.exception_ignore_args=0 vendor/bin/phpunit \
  tests/Feature/Subscription/LegacyTrafficFetchCaptureTest.php \
  --fail-on-warning --fail-on-risky
```

Set local `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` before that opt-in.
The fixture recreates tables only in the exactly named disposable database;
do not share it with another running database test. Redis is mocked and no
production Node, panel, payment or mail service is contacted.

Tests preserve the actual no-ID semantics: identical inputs increment twice,
later failure retains earlier User commits, and replay after lost commit can
increment again. They compare native MariaDB rounding against disabled capture,
reject noninteger SQLite evidence, and verify fixed diagnostics, separate stat
jobs, mixed identified/unidentified chains, source gaps and per-User crash atomicity.
The [contract](../../../../contracts/v2/legacy-traffic-fetch-capture-v1.md)
states these limits explicitly.
