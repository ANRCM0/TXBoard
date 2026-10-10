# Native MySQL table cutover — implementation gate

The current application has extensive runtime `v2_*` table references. Renaming tables in isolation will break queries. Historical migrations must continue to support fresh installs and rollbacks.

`scripts/database-native-cutover-plan.mjs` consumes the MySQL parity artifact and emits a **non-executable**, auditable candidate rename list. Historical consolidated protocol tables and source-only references are excluded unless present in the actual database. Unknown V2 tables and occupied targets are explicit blockers.

## Remaining work before execution

1. Replace all active Eloquent table names, raw SQL, plugin queries, queue jobs and runtime tests with native names in a coordinated release.
2. Add a terminal Laravel migration that renames the actual tables only after historical migrations have completed; verify MySQL 8.4 migration and rollback on a clone.
3. Test fresh installs, upgrades from populated databases, row-level checks and exact financial/traffic preservation. Aggregate snapshots alone are insufficient.
4. Validate production plugins, backups, maintenance-mode write freeze, database restoration and deployment ordering. No dual-version application should write during cutover.
5. Obtain explicit deployment authorization before running any DDL against a live database.

No migration DDL is introduced by this PR.
