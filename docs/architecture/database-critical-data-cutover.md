# Critical-data pre-cutover evidence

`api/scripts/database-critical-data-snapshot.php` is **read-only** and requires MySQL. It captures table row counts and aggregate numeric sums for user wallet/traffic, orders, wallet recharges, commissions, traffic batch ledger, and user/server traffic statistics. No emails, tokens, trade numbers, per-user records or credentials are exported.

Run in a controlled, quiescent environment before and after a **separately reviewed** schema cutover:

```bash
cd api
php scripts/database-critical-data-snapshot.php --output=artifacts/critical-before.json
# Perform approved cutover in a separately managed maintenance window.
php scripts/database-critical-data-snapshot.php --output=artifacts/critical-after.json
cd ..
node scripts/compare-critical-data-snapshots.mjs api/artifacts/critical-before.json api/artifacts/critical-after.json
```

**Limitations:** These are aggregate checks, not row-level parity proofs; compensating row changes can preserve sums. The snapshot script currently addresses `v2_*` tables and must be adapted/reviewed for the actual renamed schema before a real cutover. The CI check verifies that the snapshot tool runs on freshly migrated MySQL, and unit-tests comparison behavior; it does **not** simulate a rename or prove data preservation. Existing P3 billing invariant audit and provider reconciliation are independent requirements. Do not run pre/post checks while writes are occurring.
