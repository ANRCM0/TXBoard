# TXBoard native SQL schema inventory (pre-cutover)

Run `node scripts/database-schema-inventory.mjs --root . --output artifacts/database/schema-inventory.json --check`.

This command **only scans source code** and produces an artifact. It never connects to MySQL, alters tables, or executes migrations. The inventory lists `v2_*` names, a proposed `tx_*` naming map, migration creators, model and SQL call sites, and dynamic table calls requiring human review. Proposed names are **not yet approved**, and the script does not authorize production renaming.

The inventory is automatically tested and uploaded in the P0 workflow. Missing creation migrations are **warnings**, not automatic failures: existing code references \`v2_server_log\`, \`v2_server_stat\`, and \`v2_stat_order\` without a matching create migration in the current tree. These require explicit verification against MySQL before cutover. The strict check fails on mapping collisions and other hard invariants, while preserving warnings in the artifact. It does **not** prove the deployed database schema matches the source, nor does it detect dynamic/plugin SQL perfectly.

## Gates before an actual rename

1. Compare the generated source inventory against `information_schema` on an isolated clone; include indexes, constraints, triggers, views and any external/plugin consumers.
2. Record backup restoration and pre/post parity for order totals, wallet balances, payment callbacks, commission totals, user identity, node traffic batch deduplication and row counts.
3. Implement coordinated migration and application reference updates. Validate full empty-schema replay, incremental upgrade and a tested rollback, with old workers stopped during cutover.
4. Do not rename or drop any live `v2_*` table until the full cutover is demonstrably safe.

The inventory is intentionally the **first stage** of the schema transition, not the transition itself.
