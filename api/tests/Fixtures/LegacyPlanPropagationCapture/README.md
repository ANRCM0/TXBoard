# Isolated Plan propagation fixture

`LegacyPlanPropagationCaptureTest` uses disposable SQLite by default. Set
`CORE_CAPTURE_TEST_MYSQL=1` and `DB_DATABASE=txboard_concurrency_test` to run
independent MySQL/MariaDB lock and SIGKILL cases. Only this exact test database
is allowed by the destructive reset helper. No production snapshot is used.

```sh
php artisan test --filter=LegacyPlanPropagationCaptureTest
CORE_CAPTURE_TEST_MYSQL=1 php artisan test --filter=LegacyPlanPropagationCaptureTest
```

The real current Admin route verifies role/path/validation, the existing Plan
transaction and SQL User propagation. Tests inspect exact endpoints above JS
integer precision, mapping invalidation and unchanged owned shadow state, omitted
and null fields, no-op history, default-off query behavior, strict empty scope,
1001-User refusal, later source gaps and storage rollback. They also distinguish
original Plan saving-listener false from a thrown exception. Process tests use
local sockets, dedicated database sessions and disposable child processes only.
SELECT-only verification and fixed cross-policy ticket rejection are checked.

This fixture does not assert global writer coverage, production bulk-update
semantics, captured subscriber insertion/deletion, business replay protection or
v2 domain authority. The [current contract](../../../../contracts/v2/legacy-plan-propagation-capture-v1.md)
defines the bounded selected set and default-off scope.
