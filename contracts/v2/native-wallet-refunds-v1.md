# Native wallet, recharge and refunds v1

Status: current C3 integration contract. Real CI acceptance remains required.

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

The implemented destination is explicitly `wallet`, not the external channel.
The bundled EPay external-refund capability is false. Core external-refund
execution is not implemented: external refund requests fail with
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

Partial refunds leave subscription rights unchanged. A cumulative full refund
of an unfulfilled winning purchase cancels its durable fulfillment and order in
the same transaction. A full refund of a fulfilled **new purchase** revokes only
its still-current subscription terms, closes the original UsagePeriod through
native lifecycle primitives and stages credential invalidation. Accepted usage
is never moved or deleted. Any still-pending fulfillment work is cancelled in
the refund transaction; previously acknowledged completed work retains its
original completion history. An original new-purchase order superseded by later
renewal or plan change cannot be fully refunded automatically
(`REFUND_ENTITLEMENT_SUPERSEDED`). Full refunds of fulfilled renewal, plan-change
and traffic-reset purchases are explicitly rejected with
`REFUND_ENTITLEMENT_REVERSAL_UNSUPPORTED`: exact restoration of preceding terms,
expiry and retained usage is not implemented. This also applies when several
partial refunds cumulatively reach the original amount. Every rejected request
rolls back all money effects; it cannot revoke earlier or later purchased rights.
A new-purchase subscription already ended needs no new revocation. A
zero-duration boundary or accepted future usage also rejects atomically rather
than rewriting evidence. These unsupported full-refund reversals remain a Core
maturity blocker, not an implemented generic refund capability.

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

## Known upgrade-credit gap

The current native purchase model charges the full published price for plan
changes. It does not implement unused-value/upgrade credits: no old `surplus_*`
field, generic adjustment or unbacked wallet credit is treated as that feature.
An immutable source-bound credit quote, exact proration/rounding policy,
single-consumption claim and cancellation/refund interaction must be delivered
together before the C3 upgrade-credit goal can be marked complete.

## Acceptance

CI covers real recharge receipts, shared receipt identity, cross-owner and
amount rejection, wallet reservation/capture/release, insufficient funds,
permanent replay/conflict, cancellation, canonical allocation/durable work,
refund cumulative limits, wallet-only and channel sources, atomic rollback,
superseded entitlement rejection, retained usage and bounded read-only
reconciliation. Local validation is limited to static checks; financial database
and concurrency acceptance run centrally in CI.
Dedicated real-MySQL cases exercise concurrent same-key wallet captures,
different-order overspending, same-key refunds, cumulative competing refund
keys, mixed-source full refunds and refund-versus-fulfillment serialization.
