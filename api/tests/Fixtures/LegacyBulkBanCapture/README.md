# Isolated Admin bulk ban fixture

Run `php artisan test --filter=LegacyBulkBanCaptureTest` for disposable SQLite.
Enable `CORE_CAPTURE_TEST_MYSQL=1` against the exact dedicated
`txboard_concurrency_test` database for independent sessions and SIGKILL checks.
The fixture resets only that permitted database and uses synthetic Users/tokens.

The actual native Admin route checks owner/path/roles, selected/filtered limits,
closed input and current staff protection. Tests verify exact high-precision
endpoints, original count/session behavior, no-op capture, default-off SQL,
pretransaction filter drift, secret-free evidence, source-gap and capture/session
SQL rollback, unchanged Subscription state, SELECT-only verification and policy
ticket separation. Child-process barriers occur on local sockets and database
commit events; no Node, public panel or production credentials are involved.

See the [contract](../../../../contracts/v2/legacy-bulk-ban-capture-v1.md) for
partial filter semantics and unknown live-Node/request revocation coverage.
