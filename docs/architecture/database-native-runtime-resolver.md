# Runtime table name resolver

`App\\Support\\Database\\NativeTableName::resolve('v2_order')` returns `v2_order` by default; passing `true` returns `tx_order`. This helper is intentionally **not wired to any runtime model or SQL query yet**. It provides a validated, deterministic naming primitive for the coordinated cutover.

A per-process feature toggle is not sufficient for live cutover: models, raw queries, plugins, jobs and migration ordering must all switch together. Never enable native naming before the database has been migrated and all active query paths audited.
