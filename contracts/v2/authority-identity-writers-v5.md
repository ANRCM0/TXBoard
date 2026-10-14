# Authority identity writers v5 (Candidate)

Strict local/testing isolated-copy SQL subset, default disabled. Core remains legacy authority. Same physical singleton id1: explicit v4 open4 -> extend-identity(expected4,key) -> v5 open5 -> prepare6. Immutable parent-native receipt binds unchanged v4 state/history to exact v5 inventory. Old v1-v4 prepared states cannot extend or reopen; old hash recipes are unchanged. Read-only bounded recovery cannot certify global writer/drain completion.

The exact SQL inventory is all26 AuthorityNativeInventory::WRITERS plus these27 code constants:

- `user.register.invite-consume`
- `user.register.create`
- `user.register.touch`
- `install.admin.create`
- `install.settings.seed`
- `user.login.touch`
- `user.password.recover`
- `user.password.change`
- `user.account.preferences`
- `user.account.credentials.rotate`
- `session.issue`
- `session.revoke`
- `session.last-used`
- `cli.password.reset`
- `cli.user.credentials.rotate`
- `invite.create`
- `invite.page-view`
- `withdrawal.request`
- `settings.write`
- `settings.subscribe-template.write`
- `settings.remove`
- `stats.user.apply`
- `stats.server.apply`
- `stats.server.traffic`
- `stats.daily.apply`
- `admin.audit.append`
- `mail.log.append`

Each reviewed native owner admits authority first before domain locks. Enabled-only bridges contain the original single SQL phase. Unknown outer transaction requires actual bound PDO/PID/Connection/outer transaction proof; transaction depth, request operation names and process/cache flags do not authorize. Native partial phases stay separate: invite consume -> user insert -> after hook -> cache code forget -> last-login -> token; password save -> original session revoke; server stat -> server traffic; settings per key; SMTP -> log. No compensation/reissue/email resend. Defaultfalse delegates native paths with zero new authority or receipt IO.

Existing `removeAllSessions` inside the admitted `admin.user.update` root (v1-v4), or `admin.user.delete` / `admin.user.bulk-ban` roots (v2-v4), retains its original token deletion under that same valid outer proof and exact old scope/version/epoch declaration. These callers already lock the User before deletion. This closed compatibility path introduces no standalone old session owner, extra authority lock after a business lock, or inventory/hash change. Other inherited roots and unknown outer transactions cannot use it; v5 uses the independent `session.revoke` phase.

Creation helper retains captured outer-transaction refusal. It first admits within its own original insert transaction, writes original creation capture, then actual committing handler computes schedule with original service. V4 intents retain exact hashes; v5 intents have fixed v5 scope/version/epoch5 and53owner enum. V4 pending NodeUserSync handoffs can still drain under declared v5; enqueue evidence is not Node delivery.

App Sanctum Guard overrides only actual protected last-used SQL. Parent behavior remains when disabled; provider/token policies, retired Agent denial, event order, hasModifiedRecords restoration, RequestGuard request refresh stay native. Bearer GET validation uses real token, not actingAs. Query-builder updates and vendor token writes have independent owners, never fake UserObserver events.

Passwords/hash/salt/code/token/email/UUID/setting values/mail bodies never enter authority receipts or audit evidence. Plugin PHP remains trusted in-process. External CAPTCHA/email/Redis/cache/Theme filesystem/hooks, process lifecycle and queue delivery require independent operation/process/queue drain evidence. Stopping plugin flags or pausing workers is insufficient; observed actual terminal instances, every configured DB connection, active transaction and ready/reserved/delayed/failed job must be accounted. Unknown outcomes block final cutover. All global coverage/drain/targetauthority/migrationgate flags remain false.

Acceptance: all native HTTP/CLI owners and actual bearer metadata writes; first-lock SQLwait/commit/rollback/SIGKILL; phase failures preserve original partial results; exact creation then schedule capture chain; immutable old prepared histories and oldv4pendinghandoff; closed enum and lifetime misuse; original default-off fullAPI and current consumers; dedicated synthetic txboard_identity_fence_test with locked dependencies and MySQL8.4 CI. Other native/module/rawSQL producers remain final completion blockers until verified owner or independently observed operator stop/drain.
