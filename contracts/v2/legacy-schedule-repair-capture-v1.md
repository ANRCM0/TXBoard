# Legacy schedule repair capture v1

Status: explicit isolated CLI path. `reset:traffic --fix-null --capture-schedule`
or `--force --capture-schedule` extends the original schedule-only repair.
Without the new flag all native paths remain unchanged and add zero capture SQL.
The flag without a repair mode rejects before mutation. No new production switch,
environment default, HTTP endpoint or arbitrary command is introduced.

The fixed policy is `user.schedule.repair`, version `schedule-repair-capture/v1`,
actor domain `system_user_schedule_repair`, actor ID actual locked User. Native
candidate collection/iteration remains, then each captured repair uses a new
one-attempt transaction and exclusive current User lock. Current plan_id, banned,
expiry and fix-null eligibility are rechecked. Ordinary Plan relation and native
calendar/timezone calculation remain. No calculated schedule means native skip.
Begin/save/append/map invalidate commit together, without modifying counters,
expiry, reset_count or creating a TrafficResetLog. No-op schedules create no event.

Only local/testing plus strict original capture/isolated context is accepted.
Unknown source gaps refuse, never rebase. Transaction nesting is unsupported;
native initial-schedule helper and other writers are separate coverage. CLI mode
does not independently prove operator authorization or full writer exclusion.

Each User is a separate commit; a later failure leaves earlier repairs committed.
Fixed capture failures increment native error_count with a fixed
LEGACY_SCHEDULE_REPAIR_CAPTURE_FAILED message and empty log context. Original
command success/partial-result behavior is retained; its exit code alone cannot
certify all objects repaired. Non-capture native errors retain existing handling.
The original save false result is not promoted to proof: capture records only
actual persisted changes. Complete v2 projection, notification recovery,
production cutover, cluster fencing and power-loss durability remain separate.

See [synthetic fixture](../../api/tests/Fixtures/LegacyScheduleRepairCapture/README.md).
