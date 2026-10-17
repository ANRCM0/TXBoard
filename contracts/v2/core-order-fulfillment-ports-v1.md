# Core Order Fulfillment Ports v1

> K3 candidate subset, 2026-10-17. Based on K2 candidate `832a8e8`, not a merged
> baseline when authored. Now merged via PR #265 with exact source development
> CI passed. No new route or schema; no production qualification is implied.

## Owners and composition

Subscription owns `Contracts\OrderFulfillment` and
`Internal\LockedOrderFulfillment`. The existing `SubscriptionFulfillmentService`
implements both; scoped aliases resolve to the same authoritative service.
Billing owns `Internal\WinningPaymentProofs`, implemented by the existing
`NativePaymentSettlement`, also scoped through the composition root. Existing
domain algorithms and durable receipts remain authoritative.

| Interface | Existing signature | Current production consumers |
| --- | --- | --- |
| OrderFulfillment | `fulfill(int $orderId, ?int $now = null): array` | NativeBillingRecovery, NativeFreeCheckout recovery, NativeOrderLifecycle open |
| LockedOrderFulfillment | `applyLocked(array $installation, object $user, object $slot, Collection $subscriptions, array $histories, Order $order, ?OrderPurchaseTerms $purchase, int $now): array` | NativeUpgradeCredits acceptance; owner fresh path calls its own method |
| WinningPaymentProofs | `verifyWinningPaymentLocked(array $installation, int $userId, Order $order, OrderPurchaseTerms $terms): array` | Subscription fulfillment, NativeBillingRecovery, NativeUpgradeCredits source checks |

The public interface uses scalar input and the existing fulfillment result array.
Internal interfaces intentionally retain the existing locked Models, Collection
and history arrays. These are not public DTOs, lock certificates, plugin methods
or operations exposed by Internal API. Possessing an object does not prove its
locks, ownership, installation admission or financial acceptance.

## Fresh fulfillment

`fulfill` rejects an outer SQL transaction with
`NATIVE_OUTER_TRANSACTION_UNSUPPORTED`. It validates order identity and trusted
server boundary time; optional `now` preserves current internal deterministic
workflows and is not an HTTP caller-controlled time override.

The write-PDO locator runs before the fresh transaction. It is untrusted until
rechecked under installation admission, account/slot/subscription and complete
history locks, then order and frozen purchase locks. Purchased evidence, native
grant, financial adjustments, accepted usage and current winning payment remain
validated by the existing owners. Completed receipts replay their original
operation after evidence verification; a new receipt cannot fabricate payment.

The fresh method commits SQL before calling credential synchronization. A
postcommit synchronization failure cannot undo the committed order or rights;
the original durable synchronization work and replay remain the recovery path.
Callers must not wrap this method to compose another domain's transaction.

## SQL fulfillment participant

`applyLocked` requires an active transaction, matching account identity and the
owner's admission/account/history/order/purchase locks. Missing transaction or
account context throws `NATIVE_TRANSACTION_CONTEXT_REQUIRED`. Existing evidence
failures still throw and abort the transaction; they are not denied result DTOs.

The caller must retain the complete financial account and upgrade-source lock
union in the existing order. The method verifies authoritative evidence rather
than trusting a caller's `paid=true`, grant payload or fabricated proof. It
performs SQL changes and stages durable credential synchronization only. It does
not commit, dispatch Provider I/O or drive postcommit synchronization.

The four existing acceptance paths (channel/mixed settlement, wallet payment,
free checkout and native order lifecycle) continue to converge through
`NativeUpgradeCredits::fulfillAcceptedLocked` for upgrades. Financial acceptance
and SQL rights are committed together. This seam does not introduce an async
gap, another transaction coordinator or a second fulfillment algorithm.

## Winning payment evidence participant

`verifyWinningPaymentLocked` requires an active owning transaction and existing
installation/account/history/order/purchase locks. Missing transaction or account
binding retains `PAYMENT_SETTLEMENT_BINDING_INVALID`. It verifies frozen purchase
and current financial evidence using locking reads and the complete sorted money
union. Source unions needed for upgrade/refund cooperation remain the caller's
responsibility before this participant is invoked.

This is SQL-only, but **not guaranteed read-only**: the existing money-account
locking helper can initialize absent accounts. The interface grants no general
journal posting, settlement acceptance or refund operation. It performs no
network I/O and neither commits nor owns a nested public transaction.

Channel payment must prove an `order_payment` allocation and its verified receipt,
settlement and journal. Pure-wallet proof delegates to the existing wallet owner;
mixed payment verifies captured wallet and channel components. A refund-liability
receipt is not order-payment proof. Free or zero-net acceptance uses its existing
free-checkout evidence, does not call this positive-payment proof for the zero
price and never manufactures a zero-value receipt or journal.

Results and `BillingError` failures remain unchanged. The wider rules remain in
[payment settlement](native-payment-settlement-v1.md),
[wallet/refunds](native-wallet-refunds-v1.md),
[upgrade credit](native-upgrade-credit-v1.md) and
[subscriptions](core-subscription-v1.md).

## Acceptance and remaining scope

Candidate tests cover scoped aliases/replacement, fresh/locked transaction
rejection, real fulfillment/replay, proof failure rollback and atomic upgrade
failure. Existing channel, wallet, mixed, free, refund, concurrency and recovery
regressions remain required in their existing API/MySQL jobs. Source guards limit
concrete fulfillment references to owner/composition and prevent Subscription
services from naming the concrete settlement owner. Internal model types remain
allowed; source checks cannot prove runtime lock ownership or sandbox PHP.

This subset does not interface every Billing or Subscription mutation, merge
their work tables or change refunds to revoke rights. Broader ingress dispatch,
other justified use-case contracts and integrated acceptance remain K4/K5 or
subsequent K3 work. Production release qualification remains separate.
