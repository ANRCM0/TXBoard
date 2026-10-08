# Phase 4 — MySQL integration check

The standalone `api-mysql-ci` workflow runs on API changes. Unlike the
faster SQLite-based `api-ci`, it provisions an ephemeral MySQL 8.4 service,
applies the full production schema, verifies ledger and billing tables, and
runs the traffic settlement and order recovery regression suites against MySQL.

This test uses *only local CI credentials* and an empty throwaway database.
It is not a production migration, multi-process chaos test, or backup restore
drill. Keep the main SQLite suite for fast broad coverage and use MySQL to
catch storage-driver and DDL differences before deployment.
