# Captured money evidence fixtures

`tests/Feature/Subscription/LegacyMoneyShadowTest.php` uses fresh synthetic
Users and either in-memory SQLite or the dedicated disposable
`txboard_concurrency_test`. It applies all additive migrations before each test.
The MySQL-family mode explicitly drops only tables in that exact database;
never point it at a populated or production database.

```sh
php artisan test --filter=LegacyMoneyShadowTest
CORE_CAPTURE_TEST_MYSQL=1 DB_HOST=127.0.0.1 DB_PORT=13306 \
DB_DATABASE=txboard_concurrency_test DB_USERNAME=txboard_test \
php artisan test --filter=LegacyMoneyShadowTest
```

Provide dedicated fixture credentials through the local test environment. A
local Redis service is required by the native Admin path. No external panel,
payment provider or SMTP is involved. Root `ci-mysql.yml` runs the same fixture
in disposable Oracle MySQL8.4 and Redis containers with generated synthetic data.

Coverage includes actual native Admin money edits, endpoint-only/nonmoney
events, opposing wallet/commission deltas, integer boundaries, frozen paging
and explicit extension, exact read-only reconciliation, strict gates, context
and source gaps, corrupted capture/checkpoint/posting data, whole-page rollback,
safe process restart and schema replay/nonempty rollback refusal. Two Linux-only
tests use independent `pcntl` sessions: a competing consumer waits for the real
User lock, and SIGKILL before/after SQL commit recovers zero/one durable page.
These tests are skipped explicitly outside MySQL-family process fixtures.

The expected amounts are observed legacy endpoints, not evidence of external
payment receipt or all prior transactions. No production writer is switched.
See the [contract](../../../../contracts/v2/legacy-money-shadow-v1.md).
