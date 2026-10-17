# Native wallet, recharge and refunds v1

Status: current C3 development contract, updated 2026-10-17. The wallet-only
refund and upgrade-credit changes are candidates awaiting exact-head CI;
successful checks on the earlier e9e65f2 head do not validate this batch.

## Money authority and payment choices

`tx_money_account` and its append-only journals/postings are the sole wallet
authority. `tx_user.balance` is not read, adopted or updated. Currency and minor
amounts are exact canonical decimal strings. No route accepts arbitrary credits,
postings or a customer's claim that a payment happened.

Wallet balance reads return `{schema:"native-wallet/v1",balances:[...]}`.
Each balance contains `currency`, `available_minor` and `reserved_minor`; both
amount fields are decimal strings. A read does not create a missing account.

Checkout supports the entire frozen purchase from the wallet, the entire
purchase from the verified channel or a frozen mixed allocation. A mixed
reservation permanently fixes its wallet share; the channel attempt binds the
remaining exact amount. Settlement prelocks the union of stable money accounts
before posting either component. The frozen purchase price is not rewritten.
Wallet checkout reserves available funds, captures reserved funds into sales,
commits one canonical `tx_order_payment` and one durable `order.fulfill` work
item, and changes the order to processing in one account-first SQL transaction.
It creates no zero-value channel attempt or fictitious external receipt.

Reservation identity is permanent per original order, binding owner, currency,
frozen purchase hash, full amount and request key. Canceling a reserved unpaid
order releases reserved funds in the same transaction. Released reservations
cannot be captured. A committed wallet payment cannot be canceled as an unpaid
order or allocated again. Replays validate the original journals and evidence.

## Recharge

`tx_native_recharge` is an intent, not a separate receipt authority. It binds the
original installation, owner, payment registration, CNY amount, permanent request
key and random trade reference. The current range is 100 through 500000 CNY minor
units. Handling fees and payment registrations without an installed, enabled
native payment plugin supporting CNY are rejected, never rounded or silently
ignored. Core selects the registered plugin by declared capabilities, not a
channel-specific class. The bundled EPay connector owns its protocol.

Recharge uses `tx_payment_attempt` / `tx_payment_receipt` and the same immutable
provider-account/external-transaction identity as purchase. Attempts explicitly
bind a purchase or recharge; a transaction cannot be consumed by both. Checkout
uses the frozen plugin dispatch, receiver, exact amount, currency and
callback/query validation. Only a verified unique receipt can debit provider clearing and
credit the owning wallet. A second genuine payment of the same recharge is
retained as refund liability, never credited twice. Timeout remains unknown.

## Refund destination and limits

The product destination is exclusively `wallet`, not the external channel.
The bundled EPay external-refund capability is false. Original-channel refund
execution is out of scope: external requests fail with
`REFUND_EXTERNAL_UNSUPPORTED`; no successful external refund is invented.

An authorized administrator supplies the original receipt or wallet-payment
allocation, a positive exact amount, permanent request key and bounded reason
code. The source must be backed by immutable settled ledger evidence. A recharge
wallet credit is not refundable to the same wallet. An additional/late receipt
booked to refund liability is eligible; an original winning receipt is eligible
up to its settled amount. Wallet-only payments reference the canonical allocation
and capture journal, not a fabricated receipt.

Refunds persist source receipt/allocation and source journal, actor, reason,
request hash, refund journal, destination and entitlement decision. They transfer
sales revenue or refund liability into the user's available wallet atomically.
The owning user lock serializes cumulative refunds and competing keys; the
committed cumulative total can never exceed the original source amount. Keys
remain bound forever, including across different sources and actors. Replays
validate both source and refund journal evidence and do not repeat credits.

Every partial or full refund records `entitlement_effect: "none"` and a null
`subscription_id`. It does not cancel the order or any pending, running or
completed fulfillment/free/credential work; it does not revoke a subscription,
close a UsagePeriod, invalidate credentials or reconstruct old entitlements.
This also applies when several partial refunds cumulatively reach the full
source amount, before or after fulfillment of new purchases, renewals,
upgrades or traffic resets. Independent lifecycle authorization is required
when an administrator also intends to revoke access. Refund approval alone
does not authorize that separate operation.

Original allocation cash is the maximum refundable base. Existing refunds and
pending/processing/completed upgrade cash claims share that allocation budget;
refunds plus reserved/consumed claims cannot exceed its verified amount.
An upgrade's carried noncash credit is not a new payment and cannot be credited
to the wallet. Source financial validation and the owning user lock serialize
refunds with quote creation, acceptance, cancellation and competing refund keys.
Malformed or over-budget requests roll back every money effect. Historical
entitlement reversal and original-channel refunds are deliberately not required
product capabilities, rather than unimplemented refund maturity blockers.

## Read-only reconciliation

Reconciliation is administrator-only and uses one repeatable read view. It checks
selected-user journals, attempts, receipts, refunds and durable work, plus that
user's wallet projections and the installation's **global system accounts across
all users**. System accounts are not incorrectly compared with only the selected
user's postings. Both account scopes have independent 2000-posting scan budgets
and an explicit account page limit. Each account reports whether every posting
was traversed, whether its projection was actually compared, and an unknown
comparison result on truncated scans. Checked counts never include untraversed
rows/accounts. Nonzero cursors and scan-budget exhaustion are incomplete, never
proof that the installation is clean. It performs no repair, balance rewrite,
provider call, payment claim or hidden background mutation.

## Upgrade-credit candidate

[Native upgrade credit v1](native-upgrade-credit-v1.md) defines immutable
source-bound quotes, source allocation cash claims and separately tracked
noncash carry. Finite source/component values use UTC coverage seconds and are
rounded down independently; perpetual sources use billed and durably reserved
quota. Credit is capped at the target's list price and does not mint excess
wallet balance.

Every native upgrade persists its quote and subscription/period fingerprint,
even when credit is zero. Quote lifetime is at most two hours and cannot cross
the source expiry/current period boundary. Permanent order keys return the
original order and quote, never reprice a retry. Acceptance rechecks current
financial evidence and source state. Upgrade payment/free acceptance and SQL
subscription replacement commit atomically; net-zero upgrades use free checkout
without a fake receipt or zero-value posting. The candidate's regression and
MySQL evidence must be collected on the new head, not inherited from e9e65f2.

## Acceptance

Required CI acceptance covers real recharge receipts, shared receipt identity, cross-owner and
amount rejection, wallet reservation/capture/release, insufficient funds,
permanent replay/conflict, cancellation, canonical allocation/durable work,
refund cumulative limits, wallet-only and channel sources, atomic rollback,
unchanged entitlements/work for full refunds, retained usage, upgrade-source
cash budget interactions and bounded read-only
reconciliation. Local validation is limited to static checks; financial database
and concurrency acceptance run centrally in CI.
Dedicated real-MySQL cases exercise concurrent same-key wallet captures,
different-order overspending, same-key refunds, cumulative competing refund
keys, mixed-source full refunds and refund-versus-fulfillment serialization.
The current refund policy must leave fulfillment work unchanged regardless of
which transaction commits first.
