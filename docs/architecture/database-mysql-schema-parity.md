# Native schema: source ↔ MySQL 8.4 parity

The MySQL CI job runs the complete Laravel migration chain on a disposable MySQL 8.4 instance. It then exports the existing read-only `information_schema` artifact and compares that metadata against the source inventory.

- `node scripts/database-schema-inventory.mjs --root . --output api/artifacts/source-schema.json --check`
- `node scripts/database-mysql-parity.mjs --source api/artifacts/source-schema.json --mysql api/artifacts/p0-schema.json --output api/artifacts/mysql-schema-parity.json --check`

The checker fails for a missing expected V2 table, an already occupied proposed `tx_*` name, or an invalid mapping. Historical source references with no creation migration are **warnings**, retained in the artifact. Extra V2 tables found in MySQL are listed for review rather than silently dropped.

This checks an **empty, freshly migrated CI database**. It does not inspect any deployment database or financial rows, does not rename any table, and is not permission to deploy a cutover. Full business-data parity and restore testing remain required.
