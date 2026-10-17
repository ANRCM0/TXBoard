# Native Core Billing v1

Status: current native Billing foundation, merged in PR #260; settlement,
wallet-only refunds, upgrade credits and payment plugins are merged in PR #261.
[Settlement](native-payment-settlement-v1.md),
[payment plugins](native-payment-provider-v1.md),
[wallet/refunds](native-wallet-refunds-v1.md) and
[upgrade credits](native-upgrade-credit-v1.md) extend the original foundation
without weakening its money invariants. See the [current implementation and
acceptance limits](../../docs/architecture/v2/current-progress.md).

## Authority and scope

Core Billing owns payment attempts, verified receipt identity and the money
ledger. Subscription retains frozen purchase evidence and fulfillment. Payment
adapters verify external protocol facts; they do not mutate balances or grant
subscriptions. Gateway does not own financial state.

This is the current native model, not a projection of User.balance or retained
shadow tables. C3-A adds no public fund-credit endpoint, fake channel, external
payment worker or paid-admission bypass. Internal ledger primitives are not
proof that a payment happened.

## Shared values and transaction ownership

IDs and monetary amounts crossing a process boundary use canonical decimal
strings. Amounts are integer minor units, positive when a transfer occurs,
bounded by 9223372036854775807; no floats, exponent notation or implicit rounding.
Currencies are three uppercase letters and every journal/account/attempt/receipt
must agree. Quoting and external currency precision are frozen by the later
payment integration; the ledger performs no conversion.

Every public mutation starts a fresh SQL transaction, obtains current native
installation admission, then locks the owning tx_user row. Existing order paths
retain account/slot/subscription before order/purchase locks. Money account locks
are taken in sorted business-identity order after business aggregate locks.
An order-owned wallet reservation is part of that aggregate and may be located
with a current lock after its owning user/order locks, before money accounts.
The complete conditional money-account union precedes attempt/receipt,
allocation and journal evidence locks; pure channel payments do not create
wallet accounts merely to inspect a nonexistent reservation. Financial mutation
and replay evidence use current locking reads, including journal postings, not
an older REPEATABLE READ snapshot established during installation admission.
`inspectJournalLocked` requires those aggregate and money locks; the separate
read-only `inspectJournal` remains a non-locking evidence inspector and creates
or repairs nothing.
Services never perform provider network I/O in a SQL transaction.

Domain methods explicitly ending in Locked participate in the caller's
transaction and require its account-first aggregate locks; standalone entry
methods reject outer transactions. No process-global mutable lock proof exists.
Foreign keys, stable business keys and current SQL locks are mandatory.

## Money ledger

Tables: tx_money_account, tx_money_journal, tx_money_posting.

Accounts have immutable installation, owner, purpose, currency and normal side.
User purposes are wallet_available and wallet_reserved (credit normal, never
negative). System purposes are provider_clearing (debit normal),
sales_revenue and refund_liability (credit normal). System owner_id is 0;
user accounts belong only to the transaction's owning user.

Journal business identity is installation + user + business_type + business_ref
+ idempotency_key. Request hashes include the immutable currency, timestamp,
ordered postings and reversal reference. Replay must return the same committed
journal after validating the original postings; conflicting input is rejected,
including an identical key after any time interval. No financial TTL releases
an old business key.

A journal has 2 through 64 positive postings, at least one debit and credit,
and exact equal debit/credit totals in one currency. Posting account identities
are closed and bound to the journal's installation/currency. Account projections
and posted journal/postings commit atomically. A journal has no independently
committed draft state. Zero-price orders do not create a fictitious receipt or
zero-amount money transfer.

Available/reserved balances cannot become negative; every projected balance and
total must remain in the supported integer range. Posted evidence is append-only
through domain APIs. Corrections append a reversing journal referencing the
original and swapping each posting's side; an original journal is reversed at
most once. Replay, rollback and reversal must not repeat account mutations.

The internal ledger accepts only fixed business types: wallet_credit,
wallet_reserve, wallet_release, wallet_capture, provider_capture, refund,
adjustment and reversal. C3-B/C services must validate actual receipt, order,
refund and actor evidence before calling these primitives. No HTTP/CLI entry may
accept arbitrary postings as authorization to create money.

## Payment attempt and receipt foundation

Tables: tx_payment_attempt, tx_payment_receipt.

C3-A implements purchase attempts only. Both records require the original order
and frozen purchase. Recharge intents must extend this same receipt authority in
C3-B, not introduce a second transaction-identity store.

Attempts bind installation, user, original native order and purchase evidence,
provider/payment identity, provider account reference, immutable amount/currency,
request key and request hash. A nonzero attempt cannot be created for a free
order. Same user + request key with changed input conflicts permanently.

States: prepared -> pending | unknown | failed | succeeded.
pending may become unknown/failed/succeeded; unknown may become
pending/failed/succeeded after verification/query. A local timeout is unknown,
never proof of nonpayment. Success requires a verified receipt. Succeeded cannot
regress; failed/pending/unknown attempts may still receive genuine late success.
An attempt records actual successful receipt rather than discarding a late
payment because the order changed status.

Receipts are immutable and unique by installation + payment/provider-account
scope + external transaction reference across all purchase/recharge consumers.
The identity hash binds provider, provider account and external transaction;
duplicate payment configuration IDs cannot create a second identity. Identifiers
are byte-exact, including their case.
They bind the original attempt, amount and currency; repeating the identical
verified fact returns the same receipt, conflicting identity/amount/ownership
is rejected. A receipt cannot be reassigned to another order or wallet credit.
This foundation neither credits wallets nor marks orders paid.

Verified receipt input is internal trusted adapter output, not an HTTP claim
from a user. The complete adapter validation of signature, receiver account,
currency, amount and query/refund capabilities belongs to C3-B. C3-A introduces
no route that skips this boundary.

## Recovery and completion

All new tables use restrictive ownership foreign keys and survive ordinary
retries. Populated financial tables refuse destructive rollback. Current native
readiness validates the schema and rejects initialization/activation over money
or payment evidence; retained historical shadow evidence is never adopted.

Foundation CI verifies balanced postings, ownership/currency mismatch, insufficient funds,
permanent idempotency, reversal, atomic rollback, real MySQL races and immutable
receipt conflicts. The foundation alone retained C2 zero-price admission.
The integration candidate changes checkout/admission through the linked domain
contracts; foundation checks are not evidence that those changes passed.
Schema and internal foundation completion do not mean production release.
