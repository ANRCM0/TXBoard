# Native Eloquent model coverage — wave 2

This branch builds on PR #197 and opts the remaining 20 Eloquent models into the same guarded table resolver. All 36 files in `api/app/Models` are now covered on this branch. The flag `TX_NATIVE_TABLES` stays disabled by default. `v2_server_log` and `v2_server_stat` are source-only/unverified tables; this change does not create them.

Do not enable native mode before all raw SQL, plugins, scripts and workers are migrated and the actual database rename is coordinated. No DDL or production changes are included.
