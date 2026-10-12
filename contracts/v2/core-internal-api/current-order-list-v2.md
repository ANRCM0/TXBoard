# Local Internal order list v2

Status: B5.3 implemented isolated local/testing slice. This adds
`user.order.list` with `contract_version:"2"` to the existing signed/mTLS
operation endpoint. The service is `user-gateway`; the current Sanctum actor
must be a non-admin user. It reuses `AccountController::orders`, including its
owner query, status filter, ordering and pagination. No new business authority
or table is introduced.

Input is exactly `{page,per_page}` plus optional `status`. They are JSON integers:
page 1–9999, per_page 1–100, status 0–4. Strings, floats, null, unknown or duplicate
keys are refused. No caller-selected user ID, path, sort or SQL is accepted.
The complete body remains bound by the existing service proof and deadline.

Success keeps the Internal v2 envelope with `meta.current_only=true` and
`meta.slice="internal-readonly/2"`. Data is
`{items:[lightweight_order],pagination:{page,per_page,total,last_page}}`.
The lightweight order uses the existing v1 Internal order DTO: decimal-string
IDs and minor amount, canonical period, integer type/status, nullable plan
reference and paid_at, integer created_at. It excludes payment, callback,
wallet/discount details and private user data. Deleted plans remain null as in
the current native list; the detail operation still requires an existing plan.

With the existing explicit local/testing Gateway Internal opt-in, `/orders` and
`/orders/page` use this operation and preserve their current array/page public
DTOs. Default mode remains Native. The adapter checks exact fields, safe numeric
conversion, requested page/size/status, unique IDs/trade numbers and pagination
consistency. TLS, actor, shape, overflow or timeout failure cannot select Native
fallback. The status endpoint retains its current Native implementation.

Pages reuse native live reads. There is no cross-page snapshot or stable total
under concurrent writes; a count/list inconsistency is refused by the adapter.
The existing byte/deadline limits can also refuse an otherwise valid large page.
Production PKI, full operation coverage, Admin Gateway and SC-v2 remain outside
this slice.

Fixed Core `e1ed462` plus this Internal slice passed the complete API gate: 767
passed, 21 engine-specific skipped, 5973 assertions. The new two API tests cover
actual owner filtering, deterministic ties, status/pages, exact >2^53 minor
amounts, missing plans, strict raw JSON input, actor and default-off gates.
Fixed Gateway `2a5d2ee` plus this slice passed 492 Gateway tests, 68 SDK tests and
both workspace typecheck/build. Actual Core/MariaDB/TLS/Gateway passed 27 checks,
including Native/Internal array/page equality and no fallback on trust failure.
See the [verification record](../../../docs/architecture/v2/internal-order-list-verification.json).
