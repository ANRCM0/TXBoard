# Database modernization: TXBoard schema transition

## Status

The HTTP, admin, and node protocols no longer accept the historical V1/V2 routes. The **SQL schema has not yet been renamed**: `v2_*` is still the canonical storage layout for live Eloquent models, payment and commission records, subscription identities, traffic ledger, audit history and machine/Agent operations.

Renaming a table is not a cosmetic source-code change. It changes raw `DB::table(...)` calls, model `$table` properties, indexes, foreign keys, stored queries, plugins, old migration replay, queue workers and any externally connected reporting jobs. A code-only rename causes application outages; a SQL-only rename breaks live reads and writes. A safe final cutover requires coordinated code, schema and runtime rollout.

## Completed: remove obsolete persisted configuration

The one-way migration `2026_10_10_000001_purge_retired_node_and_captcha_settings.php` removes only these retired rows from `v2_settings`:

| Key | Reason | Canonical replacement |
| --- | --- | --- |
| `server_ws_enable` | Old mutable WS switch has no runtime effect | `TXBOARD_NATIVE_NODE_WS_ENABLED` environment flag |
| `server_ws_url` | Old URL no longer configures native proxy | `/txapi/node/v1/ws` in deployment proxy |
| `recaptcha_enable` | Obsolete alias for CAPTCHA enablement | `captcha_enable` |

The migration is rerunnable, invalidates the shared `admin_settings` cache and never recreates retired values on rollback. The Settings model and service reject old keys on read/write, including mixed batches and cached stale values. **Do not remove `captcha_enable`, `server_token`, `frontend_theme`, or any financial ledger rows.**

## Remaining schema cutover (not executed)

Before renaming active `v2_*` tables, complete these gates:

1. Generate a complete source inventory of `Schema::*`, `DB::table`, model `$table`, SQL literals, indexes and any plugin references. Produce an explicit one-to-one `old_name -> tx_* / native_name` mapping and distinguish truly obsolete tables from active tables.
2. Validate backups and restoration on a disposable MySQL clone. Capture row counts, primary key maxima, index definitions, constraints, payment totals, commission balances, wallet balances, traffic-ledger deduplication keys and order/payment reconciliation.
3. Build and test a coordinated migration and application update on the clone. Preserve historical migration replay from an empty database, idempotence, concurrency/queue safety and failure recovery. Prefer maintenance-window cutover over shadow writes unless dual-write consistency is explicitly proven.
4. Stop all writers and queue workers during the atomic rename window; verify plugins, MCP, native Node, subscriptions, settlement jobs and background workers after switching the app. Never permit mixed old/new workers to write incompatible tables.
5. Verify the same accounting and traffic invariants after cutover, then separately verify rollback from the tested backup. Do not delete source tables until a defined observation period and an approved retention decision.

The original `2023_03_19_000000_create_v2_tables.php` creates more than 20 V2 tables; later migrations create additional `v2_server*`, settings, and traffic tables. This is an application-wide migration, not a safe one-file `Schema::rename` operation.

## Acceptance

The settings purge is tested in SQLite-backed API CI and MySQL regression CI. A full native table-name cutover **has not been performed** and must not be described as completed. Do not apply unreviewed schema-renaming SQL to production or any database containing financial records.
