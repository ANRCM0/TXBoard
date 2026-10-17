# Native published-offer commerce

Status: implementation candidate, 2026-10-17. This contract replaces the stale
Gateway catalog and purchase DTOs in development; it does not add a legacy
translation mode or claim runtime acceptance before CI.

## Ownership and scope

Core's `NativePlanCatalog` and `PublishedOrderService` remain the sole catalog
and purchase authorities. Existing HTTP controllers already delegate to these
services; this batch does not add redundant domain wrappers, pricing logic,
transaction ownership, tables, payment providers or new Internal operations.
Gateway validates transport shapes and exposes the existing Core results.
Checkout, cancellation and browser administrator operations are not added.

## Public catalog

`GET /gateway/v1/plans` returns an array of published Offer DTOs, not mutable
Plan price tables. Each entry contains exactly:

- `offer_id`, `plan_id`, `plan_revision_id`: positive canonical decimal strings;
- `name`, nullable `content`, `tags` (string array);
- `offer_code`: `monthly`, `quarterly`, `half_yearly`, `yearly`, `two_yearly`,
  `three_yearly`, `onetime` or `reset_traffic`;
- `purchase_kind`: `subscription`, `perpetual` or `traffic_reset`;
- `currency` (three uppercase ASCII letters), `currency_minor_exponent` (2),
  `price_minor`, `quota_bytes` (canonical nonnegative decimal strings);
- nullable decimal-string `group_id`, `speed_limit_mbps`, `device_limit`;
- boolean `sell`, `renew`, positive decimal-string `availability_version`;
- `publication_hash` (64 lowercase hexadecimal characters).

Core filters current publication visibility/currency/admission. Gateway does not
infer purchase eligibility, merge offers into one Plan, calculate prices, convert
money or quota into JavaScript numbers, or treat display evidence as reservation.
Unknown upstream fields are stripped; malformed required fields, duplicate Offer
identifiers and more than 256 entries fail closed. Catalog identifiers and decimal
values must fit the current signed 64-bit Core domain. Existing bounded public
GET caching may retain a stale display; Core rechecks the Offer on purchase.

## Purchase

`POST /gateway/v1/orders` remains disabled by default: 405 with zero upstream
calls unless `GATEWAY_ORDER_WRITE_ENABLED=true`. Bootstrap declares
`orders.create` only when that existing explicit gate is enabled.

When enabled, the request requires the actual user's Bearer and the original
`Idempotency-Key` (1-128 URL-safe ASCII characters). The strict body is exactly
`{"offer_id":"<positive canonical signed-64-bit decimal>"}`. Numeric IDs,
`plan_id`, `period`, coupons, prices, owner IDs and unknown keys are rejected
before upstream access. Gateway does not map old identities to Offers.

Gateway forwards the unchanged Bearer, Offer string and idempotency key to the
fixed `POST /txapi/orders`. Core owns admission, ownership, immutable publication
verification, capacity, pending-order rules, permanent replay/conflicts and the
purchase transaction. Success is 201 with only `{trade_no}`. Writes are never
automatically retried; unknown outcomes retain the original key and input.
Idempotency conflicts/in-progress errors preserve their specific safe error code
and HTTP 409; unrelated business failures are sanitized without upstream details.

The SDK's `plans.list()` returns `NativeOffer[]` and `orders.create()` accepts
`{offer_id: string}`. Existing order-read display DTOs are unchanged; successful
creation is not payment or fulfillment evidence.

## Acceptance

Targeted Gateway/SDK checks cover decimal precision above 2^53, malformed/legacy
DTO rejection, field stripping, default-disabled writes, actor/key forwarding,
replay/conflict and no automatic write retry. A CI-only actual Core/Gateway HTTP
scenario uses a dedicated database, persisted native admission and published
Offers, rather than legacy User entitlement or mutable Plan price fixtures.
Historical evidence JSON remains byte-for-byte historical. Production PKI,
real merchant payment, formal SDK publication and production deployment remain
separate acceptance scopes.
