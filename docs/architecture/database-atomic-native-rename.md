# Atomic MySQL native table rename builder

`App\\Support\\Database\\AtomicNativeRename::sql($plan, $existingTables, 'up')` builds **one** MySQL `RENAME TABLE` statement, and `down` builds the reverse. It does not execute SQL. All identifiers are strictly validated; missing sources, occupied targets, duplicates, empty plans, blocked or unapproved plans fail closed.

The current generated cutover plan is deliberately `executable: false` and has runtime/backup blockers, so this builder **refuses it**. Do not flip these fields merely to bypass the guard. Before any execution: finish model/raw SQL/plugin/worker cutover, test fresh install and populated upgrade, freeze writes, take and restore-test a backup, capture pre/post row fingerprints, and obtain explicit production deployment authorization. Atomic multi-table rename reduces partial-schema risk but does not make application rollout atomic.
