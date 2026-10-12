# Legacy Observer schedule capture v1

Status: isolated local/testing follow-up writer. Existing UserObserver remains
the reset schedule authority. This opt-in extends
[legacy writer capture](legacy-user-mutation-capture-v1.md) without moving an
Observer write into the earlier business transaction or applying v2 projections.

`LEGACY_OBSERVER_CAPTURE_ENABLED` defaults false, independently of existing
capture. False retains original refresh, calculate, withoutEvents save and Node
dispatch, with zero capture queries or new transaction. True also requires the
strict existing capture/local/testing/isolated context. Non-boolean values reject
when schedule recomputation is reached. Changes that do not trigger native
recomputation do not consult this new guard. Existing deployment defaults remain.

The fixed policy is `user.schedule.observer`, version `observer-schedule-capture/v1`,
actor domain `system_user_schedule_observer`, actor ID the actual User ID. This is
trusted Core work, not the initiating request's authorization. The native after-
commit Observer selects current User under an exclusive lock in a new one-attempt
transaction. It records exact before, performs the original calendar/timezone/Plan
calculation and withoutEvents save, then appends and invalidates the map before
commit. No-op schedules create no event. Original passed User refresh and queued
Node synchronization remain; no new delivery/recovery receipt is introduced.

Native created and plan/expiry updated events can recompute. This ticket only
captures schedule follow-up, not the original User insert or arbitrary untracked
plan/expiry change. A previously captured business transaction followed by a
changed schedule produces a contiguous second event; failures never rebase a
source gap. An uncaptured earlier change still rejects. Manually invoking the
Observer inside an open transaction is unsupported when enabled, avoiding nested
tickets owned by another writer. Public listener afterCommit behavior is retained.

The earlier business commit is already durable when this callback executes.
Capture or schedule-save failure rolls back only the new schedule transaction;
it does not cancel the preceding gift/order/Admin business result. The exception
can escape the native after-commit callback and prevent later Node dispatch, so
the caller's result may be unknown even though business effects committed. Fixed
ShouldntReport capture errors contain no secret context or original Throwable.
There is no blind retry or automatic recovery, and unchanged schedules do not
erase a gap. Process death between business commit and callback remains pending
follow-up; death after this second commit can precede Node delivery.

The original save false result is preserved: only actual persisted differences
create events. Legacy hooks and other writers remain outside complete coverage.
The Plan relation uses the original ordinary read; there is no catalog fence or
cluster writer exclusion. Bounded chain verification remains SELECT-only and
transaction-only; complete migration, power-loss/restore, notification recovery
and authoritative v2 activation still require their own gates.

See [synthetic fixture](../../api/tests/Fixtures/LegacyObserverCapture/README.md).
