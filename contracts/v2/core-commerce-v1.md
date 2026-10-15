# Core Commerce v1 — purchased terms

> Status: Current implementation slice, additive and legacy-compatible. This contract does not switch Subscription/User authority or introduce a new payment ledger.

## Purpose

Every newly created order stores an immutable purchase-terms snapshot in `tx_order_purchase_terms` in the same database transaction as the legacy order. The snapshot records the price actually charged and the plan entitlement that the order is intended to grant. Order fulfillment consumes the snapshot when it exists; older orders without a snapshot retain the legacy fulfillment path.

This prevents a Plan edit between order creation and payment fulfillment from changing the already purchased quota, group, speed, device limit or reset policy.

## Snapshot fields

The immutable record is bound one-to-one to `order_id` and includes:

- user and plan identity;
- canonical period and order type;
- final `price_minor`, configured currency and minor-unit exponent;
- quota bytes, group, speed and device limits;
- reset policy and timezone;
- calendar duration and purchase kind;
- normalized source snapshot and SHA-256 `content_hash`.

The record cannot be updated or deleted. Its foreign key prevents deleting the order while the purchase evidence exists. A failed snapshot insert rolls back order creation.

## Authority boundary

The existing `OrderService` remains authoritative for order creation, payment verification, idempotency, balance handling and legacy User fulfillment. This slice does not claim that `Subscription`, `SubscriptionTerms`, `UsagePeriod`, money accounts or payment attempts have become production authorities. It provides the immutable input required for the next Subscription fulfillment slice.

Discounts, coupons, surplus credit and invitation calculations remain in the existing order flow; `price_minor` records the final order amount after those calculations. New snapshot creation does not expose secrets or provider credentials.

## Compatibility and rollout

Existing orders without a snapshot continue through the current legacy fulfillment path. New orders require the snapshot. A future authority migration must update writers and readers together under the existing writer fence; simply deleting the legacy fallback is prohibited. New routes are not required for this slice; existing order APIs may expose only the non-secret snapshot summary when the relation is explicitly loaded.
