# Legacy Admin bulk ban capture v1

> Retired: 2026-10-16 native development cleanup removes this capture runtime, flags and verifier entry points. The text below records historical behavior only. Database migrations and existing evidence remain; current account writes do not emit this shadow evidence. See [Core cleanup](../../docs/development/core-cleanup.md).

Status: isolated implementation candidate. The existing Admin
`POST /txapi/admin/{admin_path}/users/ban` remains authoritative. Its role/path,
selected/filtered input, at most500 IDs, missing/protected User rejection and
ordered exclusive User locks are unchanged. Gateway has no Admin consumer.

`LEGACY_BULK_BAN_CAPTURE_ENABLED` defaults to strict false. Disabled retains the
same bulk SQL ban and per-User native Sanctum session deletion, with no capture
SQL. Enabled requires the existing local/testing isolated-copy capture context.
All accepted nonempty sets validate context before their first mutation. The
native empty filtered result still returns updated0 before entering a transaction.

After native current role/ID protection, the fixed `admin.user.bulk-ban` /
`bulk-ban-capture/v1` policy obtains before endpoints for the locked set and
records the actual Admin ID in the `admin_user` actor domain. Original bulk ban,
session deletion, event chain and existing mapping invalidation share one SQL
transaction. Every changed endpoint gets exactly one event; already-banned
Users get no fabricated state change, but their sessions are still deleted and
they still count in the native updated response. Replay is not a business
idempotency guarantee and can delete sessions created after a previous call.

SQL/capture failures or a changed endpoint with a missing source chain roll back
the entire ban, all deleted sessions and preceding evidence. Capture-mode failures
use fixed non-secret errors without SQL/input exception detail. No per-User model
save, Observer, hook, Node sync or additional role change is introduced. Existing
native session-deletion false results remain whatever the original caller accepts.
Session contents are never stored in capture snapshots or evidence.

Filtered IDs are chosen before the native transaction. Filter qualification is
not re-evaluated under the lock: changed fields can cease to match while the
selected User is still banned. Current roles are rechecked by the existing
protection. Unreported traffic, already-admitted requests and Node disconnect/
revocation convergence are not certified by a captured ban endpoint.

No Subscription/Terms/period/money projection, creation/deletion tombstones,
generic plugin interception or production writer switch is added. Per-User
read-only verification reports global coverage and migration gate false.
