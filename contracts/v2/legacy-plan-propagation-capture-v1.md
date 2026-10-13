# Legacy Plan propagation capture v1

Status: verified isolated implementation slice. The current Admin
`POST /txapi/admin/{admin_path}/plans` remains the business authority and retains
its Admin Bearer, RBAC and dynamic path checks. Gateway receives no Admin route.

`LEGACY_PLAN_PROPAGATION_CAPTURE_ENABLED` defaults to strict boolean false.
Disabled, or without `force_update`, the original bulk SQL update is unchanged.
Enabled force propagation additionally requires local/testing, the existing
isolated-copy marker, capture context and strict capture enablement. This gate
also applies when no subscribers exist. Invalid settings fail before mutation.

The existing transaction first locks the Plan, then selects at most1001 current
matching User IDs in ascending order with exclusive locks. More than1000 refuses
the whole operation. The accepted set is frozen for this isolated transaction;
new subscribers arriving after selection are excluded. This deliberately bounded
mode cannot stand in for arbitrary production bulk propagation or a global fence.
The original bulk update applies only to the locked set, using the existing GiB
conversion and supplied-or-current group/speed/device values. No User save,
Observer, hook, schedule recalculation or notification is introduced.

Fixed `admin.plan.propagate` / `plan-propagation-capture/v1` evidence uses the
authenticated Admin ID in the `admin_user` actor domain. Plan membership is in
each original User endpoint, rather than a new business authorization claim.
Exact original before/after values, chain append and existing mapping
invalidation share the Plan/User transaction. Unchanged endpoints create no
event. Changed endpoints with an unrecorded source gap refuse and roll back the
entire selected set, earlier appends and the Plan update. SQL/storage failures
return fixed non-secret capture errors without underlying exception details.

The original Plan `saveOrFail` call remains unchanged. A thrown save exception
rolls back the transaction; an Eloquent saving listener returning false is
ignored by the current caller and may leave committed User propagation with an
unchanged Plan. Capture records actual User endpoints, not invented Plan success.
An unknown response is not an idempotency guarantee. Existing hash validation
and SELECT-only per-User verification remain authoritative for this evidence.

No Subscription/Terms/period/money projection, credential issuance, User lifecycle
or generic plugin writer coverage is added. Global coverage and migration gate
remain false. All prior legacy paths remain available.

SQLite12 passed /2 engine-specific skips /128 assertions; real MariaDB14 passed
/149 assertions, including Plan-lock current membership and SIGKILL before/after
the set commit. Full API1137 passed /104 explicit engine/toolchain skips /11637
assertions. Earlier fixture failures and final source/log hashes are retained in
the [verification report](../../docs/architecture/v2/legacy-plan-propagation-verification.json).
