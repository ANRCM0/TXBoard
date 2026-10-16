# Core Commerce v1 — purchased terms

> Status: Current implementation slice, additive and legacy-compatible. This contract does not switch Subscription/User authority or introduce a new payment ledger.

## Purpose

New user-created and manually assigned orders carry `purchase_terms_version=tx-core.purchase-terms/v1` and store an immutable purchase-terms snapshot in `tx_order_purchase_terms` in the same database transaction as the legacy order. The snapshot records the price actually charged and the plan entitlement that the order is intended to grant. Order fulfillment consumes the snapshot when it exists; older orders without a snapshot retain the legacy fulfillment path.

This prevents a Plan edit between order creation and payment fulfillment from changing the already purchased quota, group, speed or device limit. Calendar duration/overflow and the order-open reset event are frozen as well. The reset policy is recorded for later subscription authority; ongoing scheduled resets deliberately retain the current native Plan/system policy in this slice.

## Snapshot fields

The immutable record is bound one-to-one to `order_id` and includes:

- user and plan identity;
- canonical period and order type;
- final `price_minor`, configured currency and minor-unit exponent;
- quota bytes, group, speed and device limits;
- reset policy and timezone;
- calendar duration and purchase kind;
- normalized source snapshot and SHA-256 `content_hash`.

Both insertion and fulfillment validate the closed versioned snapshot, recursive canonical hash, scalar projections and binding to order/user/plan/period/type/final amount. Missing or corrupt evidence on a marked order refuses fulfillment; only unmarked orders without evidence use legacy behavior. Existing marked order identity and purchased fields cannot be edited through Eloquent. The record cannot be updated or deleted. Its foreign key prevents deleting the order while the purchase evidence exists. A failed snapshot insert rolls back order creation.

## Authority boundary

The existing `OrderService` remains authoritative for order creation, payment verification, idempotency, balance handling and legacy User fulfillment. This slice does not claim that `Subscription`, `SubscriptionTerms`, `UsagePeriod`, money accounts or payment attempts have become production authorities. It provides the immutable input required for the next Subscription fulfillment slice.

Discounts, coupons, surplus credit and invitation calculations remain in the existing order flow; `price_minor` records the final order amount after those calculations. New snapshot creation does not expose secrets or provider credentials.

## Compatibility and rollout

Existing orders without a snapshot continue through the current legacy fulfillment path. New orders require the snapshot. A future authority migration must update writers and readers together under the existing writer fence; simply deleting the legacy fallback is prohibited. New routes are not required for this slice; existing order APIs may expose only the non-secret snapshot summary when the relation is explicitly loaded.

The site currency follows the existing SiteConfigService default (`CNY`) when unset; the recorded currency does not relabel historical orders. This slice supports the existing ten two-decimal currencies; catalog prices that cannot be represented exactly in minor units are rejected transactionally rather than rounded into ambiguous purchase evidence. `price_minor` is the remaining external amount after existing discounts/wallet/surplus calculations, not a new gross consideration ledger.

Manual administrator assignments preserve their existing ability to choose a supported period and negotiated/free amount even when the catalog has no price for that period. Their snapshot declares `quote_source=manual_assignment` and `list_price_minor=null`; catalog purchases declare `quote_source=catalog` and retain the exact list price. No catalog price is fabricated for a manual assignment.
