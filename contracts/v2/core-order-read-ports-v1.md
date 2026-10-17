# Core Order Read Ports v1

> K4 candidate subset, 2026-10-17. Based on account-read candidate `a4cee43`
> (PR #266). Merged via PR #267, with exact source development CI passed;
> no production qualification is implied.

## Ownership and scope

Billing owns `Contracts\OrderQueries`, immutable `OrderListQuery` and `OrderPage`.
`NativeOrderQueries` extracts the existing AccountController queries and fixed
projections. It is scoped in CoreCapabilitiesServiceProvider. It does not own
purchase creation, payment acceptance, fulfillment or order state transitions.
No route, operation, schema, pricing or refund policy is added.

| Method | Input | Result |
| --- | --- | --- |
| `listing(int $userId, OrderListQuery $query): OrderPage` | Actual authenticated actor ID; page >= 1, perPage 1..100, optional status 0..4 | Existing summary rows and page/per_page/total/last_page metadata |
| `find(int $userId, string $tradeNo): ?array` | Actual actor ID and existing trade number | Existing summary, null when missing or owned by another account |
| `detail(int $userId, string $tradeNo): ?array` | Same ownership restriction | Existing HTTP detail fields; null also when its current Plan is missing |

The ID must be positive. A scalar ID is not authorization proof. Entry points
authenticate afresh and never take the owner from request input. The port does
not accept arbitrary filters, relation names, SQL, permission claims or time.
HTTP validation retains its current page range; Internal API retains its existing
page <= 100000 limit. The DTO does not impose that narrower protocol bound on
HTTP. Existing route/closed-input validation owns trade-number syntax.

## Projection and consistency

Lists retain status filtering, created_at DESC then id DESC order, per-page
default 20, page default 1, selected columns and current Plan id/name relation.
Summary fields remain id/trade_no/plan_id/period/type/status/amount_minor/plan/
paid_at/created_at. Dates remain UTC ISO-8601. Missing Plan produces null in
summary; detail still requires a Plan and adds its current traffic_limit_bytes,
payment_id, balance_amount_minor and discount_amount_minor. These current Plan
display fields are not frozen purchase terms or proof of payment/entitlement.
Detail-only fields do not expand list/status or Internal API output.

These are ordinary read projections using the current database connection and
isolation, not an atomic multi-query financial snapshot. The extractor introduces
no transaction, lock, network call, repair, business write or idempotency receipt.
Session-last-used and audit remain independent entry responsibilities. A read
does not authorize a subsequent checkout, cancel or fulfillment operation.

## Entry mapping and failure

HTTP AccountController orders/order/orderDetail normalize authenticated inputs,
delegate and map OrderPage metadata or null to the existing envelope/404.
Internal `commerce.orders.list` and `commerce.orders.get` use the fixed
OrderReadOperations handler, not an HTTP controller or manufactured Request.
The handler accepts only the executor's freshly authenticated User and validated
closed input. A missing order returns null, mapped by the executor to the
existing 404 response.

Internal read handlers return an immutable adapter-local ReadOperationResult
(data plus optional metadata). AccountReadOperations uses the same result type.
It has no permission or status override and is not a Kernel business result or
plugin extension registry. The executor still owns HTTP wrapping, output/meta
allowlists, recursive redaction, integer-to-decimal conversion, response limits,
safe errors and no-store. Existing service TLS/epoch/expiry/revocation/domain/
operation restrictions, real Sanctum actor, admin checks and audit stay in place.
The two order reads are user-only. Other dispatch and mutation semantics remain
unchanged. Query exceptions propagate to existing protocol error handling.

## Acceptance and limits

Verify actual port replacement by all three HTTP views and both internal reads;
real Bearer A/B isolation, missing and cross-owner 404, stable pagination/status,
summary/detail separation and missing Plan behavior. Prove internal reads never
resolve AccountController, preserve page metadata, sanitize failures and reject
service/actor/closed-input violations before querying. Existing account handlers,
checkout, receipt recovery and financial suites remain regression coverage.

Static guards restrict pure Billing contracts, direct concrete consumers and
fixed read handlers. They are source heuristics, not a PHP sandbox or proof of
dynamic authorization. This batch does not finish all K4 ingress or K5 handoff,
add CLI/Worker queries, broaden Gateway admin support or qualify a release.

See [account reads](core-account-read-ports-v1.md),
[Internal API](native-internal-api-v1.md) and
[order fulfillment](core-order-fulfillment-ports-v1.md).
