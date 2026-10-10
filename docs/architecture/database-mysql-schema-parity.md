# Native schema: source ↔ MySQL 8.4 parity

The MySQL CI job runs the complete Laravel migration chain on a disposable MySQL 8.4 instance. It then exports the existing read-only `information_schema` artifact and compares that metadata against the source inventory.

- `node scripts/database-schema-inventory.mjs --root . --output api/artifacts/source-schema.json --check`
- `node scripts/database-mysql-parity.mjs --source api/artifacts/source-schema.json --mysql api/artifacts/p0-schema.json --output api/artifacts/mysql-schema-parity.json --check`

The checker fails for a missing expected V2 table, an already occupied proposed `tx_*` name, or an invalid mapping. Historical source references with no creation migration are **warnings**, retained in the artifact. Extra V2 tables found in MySQL are listed for review rather than silently dropped.

This checks an **empty, freshly migrated CI database**. It does not inspect any deployment database or financial rows, does not rename any table, and is not permission to deploy a cutover. Full business-data parity and restore testing remain required.

## Five intentionally consolidated protocol tables

Migration `2025_01_05_131425_create_v2_server_table.php` copies records from `v2_server_trojan`, `v2_server_vmess`, `v2_server_vless`, `v2_server_shadowsocks` and `v2_server_hysteria` into unified `v2_server`, then drops those five source tables in its **up** method. Its **down** method recreates them for rollback. Therefore source regex scanning sees their historical create statements even though the current schema must not contain them. The parity checker explicitly classifies missing historical protocol tables as `consolidated-into-v2_server`, never as live rename candidates. A missing non-consolidated created table still fails the gate. If any protocol table remains in an actual deployment, review its rows and the original migration before any cutover.
