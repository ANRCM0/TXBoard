# Critical row-level fingerprint evidence

Before a controlled table rename (with writes frozen and a verified backup):

```sh
cd api
php scripts/database-critical-row-fingerprints.php --output=artifacts/rows-before.json --prefix=v2
```

After cutover, capture the corresponding native tables:

```sh
php scripts/database-critical-row-fingerprints.php --output=artifacts/rows-after.json --prefix=tx
node ../scripts/compare-critical-row-fingerprints.mjs artifacts/rows-before.json artifacts/rows-after.json
```

This is read-only and produces no raw row data. It hashes all columns in sorted-column order and rows ordered by `id`, rejecting missing `id` columns. Unlike aggregate totals, it detects individual row changes in seven critical tables. It does **not** prove equivalence for the other tables, guard against concurrent writes, or authorize a live migration. The snapshots must be captured with application writes quiesced and a consistent database state.
