# Native runtime reference audit

Run `node scripts/database-runtime-reference-gate.mjs --source api/artifacts/source-schema.json --output api/artifacts/native-runtime-reference-gate.json` after source inventory.

The audit excludes historical migrations from the runtime blockers and groups remaining Eloquent `$table` declarations, literal DB table calls, raw SQL and dynamic query call sites. CI intentionally **does not fail** while legacy runtime code remains deployed. `cutoverReady` is only a static-scan signal and cannot authorize DDL; review plugins, external integrations, live database backups, dual-version writes, rollback and row-level parity independently.
