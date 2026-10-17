# Native upgrade credit v1

Status: current native development contract; candidate acceptance is recorded on
the exact PR head. This is a Core price adjustment, not a payment plugin feature.

## Quote and policy

Core quotes a cross-plan replacement from the locked native subscription and its
immutable fulfilled purchases. Clients still submit only `offer_id`; arbitrary
discounts, legacy surplus fields and client-supplied credit amounts are rejected.
The target price is capped at its immutable offer price. Excess residual value
is not minted into the wallet. The target starts its own purchased duration.

For finite purchases, each source uses actual UTC calendar coverage and integer
seconds. New purchases and replacements start at fulfillment; a renewal purchases
the interval from the preceding expiry to the new expiry, not from the early
renewal's execution time. Eligible value is rounded down per source and component.
Only sources since the latest replacement belong to the current plan. Traffic
reset purchases do not supply time credit.

Perpetual purchases use their purchased quota minus all billed and durably
reserved bytes since that purchase. Exhaustion yields zero, never a negative
credit. A later reset does not restore the old purchase's refundable value.
Perpetual quotes recheck available bytes at payment acceptance.
When an upgrade retains the existing period instead of resetting it, that
period's retained counters also consume the new purchased quota.

Source cash is a verified winning allocation less validated wallet refunds and
other reserved/consumed upgrade claims. A previous upgrade's transferred credit
is a separate noncash component: it can transfer again but cannot be refunded
as cash. Carry is used before cash within each source. A zero-price/free source
cannot manufacture a monetary balance.

The immutable quote freezes source terms/purchase hashes, coverage, cash/carry,
refunds, rounding, target offer/currency, subscription/period fingerprint and
price arithmetic. Its validity is at most two hours and never crosses the
source expiry or current period boundary. The order and quote are created in
the same transaction, including zero-credit upgrades so source changes still
invalidate payment acceptance. The permanent order key returns that original order,
not a new quote. Expired orders can be canceled and replaced with a fresh key.

## Single consumption and financial safety

`tx_order_upgrade_credit` holds the immutable quote and
`tx_upgrade_credit_source` binds its cash portions to original order allocations.
The owning user serializes quotes, payment, cancellation and refunds. Pending or
processing target orders reserve cash; completed targets consume it permanently;
cancellation releases it without deleting the quote or changing old journals.
Source allocations, claims and refunds use current reads, including under MySQL
repeatable read. Refunds plus reserved/consumed cash can never exceed the original
allocation. Source edges point to older orders and history is bounded.

Before accepting money, Core rechecks quote integrity, the subscription/period
fingerprint, deadline and source financial evidence. Changed or expired sources
cannot claim the quote. Genuine late channel payments become refund liabilities;
any mixed wallet reservation is released and the stale pending order is canceled.
A malformed financial record fails closed rather than being reclassified as a
valid quote. Wallet/free acceptance rejects stale quotes without side effects.

For an upgrade, allocation, any wallet capture, SQL subscription replacement and
the subscription operation receipt commit in the same transaction. No provider
I/O runs under SQL locks. Durable billing/free work remains responsible for
postcommit credential synchronization and replay. There is no paid-but-unapplied
upgrade window in which expiry or a reset can consume the same source again.
Net-zero upgrades use free checkout, without fake receipts or zero postings.

## Refund policy

Refunds only transfer verified refundable cash to the owning wallet. Partial or
full refunds do not cancel fulfillment, revoke a subscription or reconstruct old
entitlements. Administrators use the separate lifecycle operation when they also
want to revoke access. Noncash upgrade credit is never refundable to the wallet.
Original-channel refunds and historical entitlement reversal are out of scope.
