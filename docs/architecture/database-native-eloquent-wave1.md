# Opt-in Eloquent native table resolution — wave 1

Sixteen core Eloquent models now use `ResolvesNativeEloquentTable`, which maps `v2_*` to `tx_*` through the validated `NativeTableName` resolver when `TX_NATIVE_TABLES=true`. Default remains **false**, preserving current production behavior.

**Do not enable the flag yet.** Raw `DB::table` calls, hand-written SQL, other Eloquent models, plugins, scripts and background workers still use legacy names. This wave intentionally does not alter migration files or execute DDL. Enabling early will break reads and writes.

The eventual coordinated cutover must update every runtime reference, verify both MySQL fresh install and populated upgrade, pause writes/workers, back up, rename tables and switch the application together. Rollback requires the reverse schema change and restoring the old application mode.
