# Critical aggregate snapshot across a table-prefix cutover

Before renaming tables, run from `api/`:

```sh
php scripts/database-critical-data-snapshot.php --output=artifacts/before-critical.json --prefix=v2
```

After an authorized and completed native schema migration, run:

```sh
php scripts/database-critical-data-snapshot.php --output=artifacts/after-critical.json --prefix=tx
node ../scripts/compare-critical-data-snapshots.mjs artifacts/before-critical.json artifacts/after-critical.json
```

The snapshot refuses unknown prefixes, checks required tables/columns, and does not write to the database. It captures aggregate counts and totals, **not** row-level identity, financial ledger correctness, concurrent writes or transactional consistency. Stop application writes and back up the database before any real rename. A matching report is necessary but insufficient for cutover approval.
