# Phase 9 — Complete retirement of V2 administrator routing

> Development-time API breaking change. This milestone removes the old **administrator** router only. It does not claim all API V1/V2 runtime protocols, payment notifications, third-party integration contracts or storage tables are removed.

## Removal inventory

The Laravel V2 route loader registers each file in `app/Http/Routes/V2/*.php` by convention. Removing `V2/AdminRoute.php` means no `/api/v2/{admin_path}/*` administrator prefix is registered. The old route's three children are also deleted:

| Removed module | Operations retired | Native authority |
|---|---|---|
| `SystemRoute` | System status, queue workload/masters/stats, failed jobs and audit log | `/txapi/admin/{admin_path}/queue/snapshot`, `queue/failures`, `audit-logs` and `/txapi/health` |
| `CommerceRoute` | Plan, order, coupon and payment method administrator CRUD | `plans`, `orders`, `coupons`, `payment-methods` under native administrator TXAPI |
| `UserRoute` | User lists/mutations/secrets/exports and ticket management | `users`, `tickets` and explicit native admin mutation endpoints |

The old V2 administrator controller files have been deleted, including the already unregistered gift-card and queue-management controllers, so they cannot be reactivated by accident.

The old system-specific Horizon diagnostics (master/supervisor and raw failed-job browsing) are intentionally not reproduced verbatim. The native queue API limits results, scrubs exceptions and excludes raw payloads. The native health endpoint and queue snapshot cover first-party operations. If a future UI needs richer diagnostics, define a new bounded, least-privilege TXAPI response rather than reviving V2.

## Financial and subscriber security

- Existing native OrderAdminController, OrderOperationsAdminController, CommerceReadController, PlanMutationController, PaymentManagementController and CouponAdminController retain the money-in-cents and settlement/replay rules.
- Existing native UserReadController and UserEditorController maintain user-editor amounts in major currency units where explicitly designed, and hide bearer/subscription tokens in list DTOs.
- Historic regression tests for user money editing, order email search, audit log redaction and coupon validity windows now target the respective native endpoints and envelopes.
- **No** V1/V2 subscription, PSP payment callback, Telegram callback, Agent or Node runtime Wire route is removed as part of this administrator-only phase. Payment callbacks and external protocols must be migrated separately with source/consumer acceptance.

## Gates and evidence

New PHPUnit `RetiredLegacyAdminRoutesTest` asserts the V2 admin route tree is empty and critical native replacements remain administrator-guarded. `scripts/p0-api-audit.mjs` fails if a V2 administrator route is registered by convention or reintroduced; its test asserts this failure. The existing strict React Admin client audit remains mandatory.

CI success covers code/test/build/MySQL regression, **not** representative deployment, subscription/notification contract probes, financial reconciliation against real historic records, backup/restore, and 1Panel + MySQL/Redis rollback evidence. Continue tracking those release blockers in Issue #168.
