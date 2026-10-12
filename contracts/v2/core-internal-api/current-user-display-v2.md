# Local Internal user display reads v2

Status: B5.4 implementation verified, local/testing only. Two fixed operations
extend the existing version-2 User service allowlist, with exact empty input:

| Operation | Existing Core authority | Display output |
| --- | --- | --- |
| user.dashboard.get | AccountController::dashboardStats | unpaid_orders, open_tickets, invited_users |
| user.payment-methods.list | BillingController::methods | id, name, provider, icon, fee_fixed_minor, fee_percent |

Both require the current non-admin Sanctum User and `user-gateway` service proof.
Payment methods do not become anonymous. The methods adapter delegates the
existing enabled-method selection and ordering; it does not inspect provider
secrets, instantiate providers or calculate a charge. IDs and fixed minor fees
use decimal strings; fee_percent preserves the native numeric display rate.
Dashboard counts remain nonnegative safe integers.

The existing local Gateway Internal opt-in selects these operations for
`/dashboard/stats` and `/payments`, preserving the current public DTO. Default
upstream remains Native; missing actors, trust errors, overflow, unknown fields
and transport failure have no Native fallback. These reads do not authorize
checkout or change the default-off order writer.

No new route or business table is introduced. Existing proof, bounded response,
deadline, actor revalidation and audit semantics apply. Production PKI, Admin
Gateway, remaining operations and SC-v2 still require their own gates.

Verification: fixed committed Core `79a3854` and Gateway `6ea649b` plus only this
slice passed 803 Core API tests (29 skipped, 6422 assertions), 512 Gateway and
68 SDK tests, typecheck/build, and 29 actual Core/MariaDB/TLS1.3 integration
checks. Tests cover current User scope, enabled-method ordering/fees, private
configuration exclusion, exact operation dispatch and empty input, missing,
expired and Admin actors, valid wrong-service proof, and no Native fallback.
[Fixed source and runtime evidence](../../../docs/architecture/v2/internal-user-display-verification.json).
These DTO equivalence checks apply to supported non-admin User actors.
