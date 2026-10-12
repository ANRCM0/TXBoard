# Local Internal User order creation v1

Status: B5.7 implemented and verified for isolated local/testing only. Native Core
`BillingController::createOrder` and `OrderService::createFromRequest` remain
the business authority. This operation creates synthetic fixture orders only.

Fixed operation `user.order.create`, contract version `1`, requires the current
non-admin Sanctum User and `user-gateway` service proof. In addition to the
existing local Internal gate, Core requires strict boolean
`internal_api_slice.order_write_enabled=true` and
`core_shadow.isolated_copy=true`; both default off. Gateway selects this path
only when both its existing order-write flag and local Internal User transport
are enabled. No other write operation is added.

Input is a closed object: `plan_id` is a canonical positive decimal string
within the existing signed-INT Plan ID range (1..2147483647); `period` is one of
monthly, quarterly, half_yearly, yearly, two_yearly, three_yearly, onetime,
reset_traffic; `idempotency_key` is 1..128 ASCII characters in
`[A-Za-z0-9._~-]`; optional `coupon_code` is a nonempty string of at most 64
Unicode characters. The body and idempotency key are bound by the actual TLS
boundary service proof. Arbitrary forwarded idempotency or actor headers have
no authority. Request ID/proof nonce and business idempotency are distinct.

The adapter constructs a fresh native request with exactly the native purchase
fields and body-bound Idempotency-Key, then delegates BillingController. Core
preserves live Plan/preflight, coupon/price/wallet rules, current User locking,
atomic SQL idempotency and native replay. It does not perform checkout,
fulfillment or shadow ledger/Terms projection. Success is HTTP201 with exact
`data={trade_no}`, the request ID and
`meta={current_only:true,slice:"internal-order-write/1"}`. Errors retain native
codes/statuses and safe envelopes, including idempotency conflict/in-progress.

Gateway keeps its existing public order-create result and error classification.
The Internal client accepts success only as HTTP201 and this exact write meta;
read successes cannot substitute for write receipts. It performs no automatic
retry and no Native fallback. An expired deadline, aborted socket, lost ACK or
audit failure after business commit can return an error despite a committed
order. Recovery uses a new transport request/proof nonce with the same original
business Idempotency-Key and purchase parameters; Core alone decides replay.
The existing native idempotency retention window still bounds this guarantee.
Replay also depends on the current actor, surviving Plan and original owned
Order: native Billing looks up Plan before the keyed replay, so deleting it
preserves a 404 even when the original order already committed.

Existing 16KiB request, 64KiB response, 5s deadline, current actor, proof replay,
rate limits and no-secret audit apply. A transport deadline does not cancel
blocking SQL. Audit is technical evidence and does not become a financial
ledger. No production PKI, Admin write, Secure Channel consumer or public new
route is enabled. The existing public Gateway parser normalizes JSON before
this adapter; its handling of duplicate public fields remains unchanged. Strict
duplicate rejection here describes the actual Core Internal request bytes.

Fixed Core `422e85c` and Gateway `ee1cec6` plus the fourteen reviewed files
passed 845 API tests (36 engine-specific skips, 7085 assertions), 529 Gateway
tests, 68 SDK tests, typecheck/build and 40 actual TLS1.3/MariaDB checks. Real
same-key concurrency reserves wallet once; expired-key replacement immediately
replays its new successful claim. A deliberate audit failure returns 503 after
SQL commit; independent fixture inspection confirms one order and succeeded
claim before the original key recovers that same trade. Source and retained
log hashes are in the [verification record](../../../docs/architecture/v2/internal-order-write-verification.json).
