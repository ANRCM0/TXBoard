# Verified native MySQL table cutover (v2_* → tx_*)

The `txboard:database-cutover` command requires MySQL, a reviewed full-table plan, explicit execution, maintenance mode and proof that a restorable backup was checked. **It does not run during ordinary `migrate` or start-up.** The supplied static planner emits a deliberately **non-executable** plan; never change its approval fields without an independent schema and plugin review.

## CI evidence

The MySQL 8.4 workflow first performs the full legacy migration replay, then the normal billing, traffic and API tests. The final `database-native-full-roundtrip.php` smoke runs only in `APP_ENV=testing` against `txboard_ci`. It seeds a setting, user balances, a paid order, a pending wallet recharge, a payment method, a server, and a traffic idempotency record; it builds an approved plan inside this disposable database, fingerprints every row of every live V2 table, runs a single multi-table rename, verifies Eloquent native resolution and all fingerprints, then runs the native traffic settlement job twice to validate idempotency and native writes; it reverses all tables with matching post-write fingerprints. The probe does not establish production readiness.

## Maintained production procedure

1. Inventory all live `v2_*` and `tx_*` tables, triggers, views, routines, foreign keys, plugins, external SQL and workers. Compare against the reviewed mappings and block unknown tables or occupied targets. Do not fabricate source-only model tables.
2. Stop all application, queue, scheduler, Agent and plugin writers. Enter Laravel maintenance mode. Confirm there are no old workers or mixed-prefix consumers.
3. Create and **restore-test** an offline backup; capture `php scripts/database-critical-data-snapshot.php --prefix=v2 --output=...` and `php scripts/database-critical-row-fingerprints.php --prefix=v2 --output=...`, then secure the artifacts.
4. Approve a JSON plan conforming to `native-table-cutover-plan` with `executable: true`, `requiresManualApproval: false`, empty blockers and complete `proposedRenames` (`from` and `to`). Keep it outside web-accessible directories. The command requires exact coverage of every present `v2_*` table.
5. Run `php artisan txboard:database-cutover --plan=/secure/reviewed-plan.json` for read-only preflight. Set `TXBOARD_CUTOVER_APPROVED=1` and `TXBOARD_BACKUP_VERIFIED=1` only after approvals, then run with `--execute` while maintenance mode is active.
6. Deploy all native-aware code together with `TX_NATIVE_TABLES=true` and clear the configuration cache / restart Octane and queue workers. **Keep writers frozen** until all critical row fingerprints and aggregates collected with `--prefix=tx` match, and smoke-test login, orders, webhooks, traffic, subscriptions, plugins and node operations.
7. For rollback, re-enter the frozen state, use the *same* approved plan and `--direction=down --execute`, restore `TX_NATIVE_TABLES=false`, clear cache and restart all workers, then re-run the V2 integrity checks. If a write happened after cutover, do not blindly reverse; use the tested backup/recovery decision and reconcile the writes.

For a **new installation**, run historical migrations and the installer under `TX_NATIVE_TABLES=false` first, then apply the same approved one-time cutover before opening the instance for traffic. For an **existing installation**, stage and test against a populated clone before the maintenance window. Neither path authorizes direct production DDL from CI or this document.

A clean CI run is necessary but not sufficient: real plugin code, production backup restoration, open connections, external reporting, runtime flags and operational authorization remain independent sign-off gates.

## Maintained migration and source guards

The compatibility layer remains intentional until production has completed the
backed-up schema cutover. In particular, `v2_*` declarations inside models
using `ResolvesNativeEloquentTable` are NOT unadapted production queries.
Pre-2026-09-21 schema migrations are historical Laravel replay records, and
must not be deleted just to make a text search appear clean.

Modern migrations (since 2026-09-21) use
`NativeTableName::runtime('v2_example')` for schema and query builder operations,
even if they were first shipped while the default schema was still legacy.
The static `database-native-migration-gate.mjs` test prevents newly introduced
literal table references in these migrations. Source inventory now recognizes
prefix-aware migration creators for MySQL parity.

The `database-runtime-reference-gate.mjs --check` CI step blocks unresolved
literal runtime references, but reports dynamic calls separately for code review.
Neither source gate proves external plugin or worker compatibility and neither
automatically approves a real rename. Only the documented maintenance-window
procedure can do that.

The retired `migrateFromV2b` raw-SQL converter and its migration-history
seed helper are intentionally no longer shipped as live Artisan commands.
They were not part of the maintained update path and bypassed transactional
schema safety checks. Existing migrations-table rows and historical database
records remain untouched. Operators needing to import older upstream
databases must use an independently reviewed backup-and-import procedure.
