# Native payment settlement v1

Status: current native settlement contract, merged in PR #261. Purchase settlement and the bundled EPay payment plugin
share the native money/receipt authority with recharge, wallet allocation and
refund-to-wallet services. CNY paid admission has a real HTTP producer, verified
callback/query consumer and durable fulfillment recovery. Implementation and merge
do not replace exact-head CI or authorize a production release.

## Ownership and transaction

Core owns financial decisions. A channel adapter verifies provider protocol
facts outside SQL transactions; it never grants subscriptions or writes money.
The settlement transaction locks installation admission, user, subscription
slot/history, original order and frozen purchase, then stable money accounts,
then attempt/receipt and financial settlement evidence. Replays use permanent
keys. Provider requests never hold SQL locks.

The reservation locator is an order-owned aggregate: after locking its user and
order, Core reads it with a current locking read before selecting the money
union. That union always covers provider clearing, sales and refund liability;
wallet accounts participate only when the order has a reservation. Core locks
the complete conditional union in sorted account identity order before reading
journals, receipts, allocations or settlement decisions and validating attempt
financial evidence. Reservation journal validation follows that union as well.
Current locking reads of narrowly scoped, user-owned attempt/dispatch locators
may precede the union to recover the original immutable identity or request key.
Those locators do not prove an amount, payment status or winning decision and
cannot replace the financial evidence validation after the money locks.

MySQL repeatable-read admission checks may establish a snapshot before the user
lock is acquired. Ordinary snapshot reads therefore cannot select an existing
settlement decision or the first observation timestamp after waiting for another
transaction. Financial mutation and replay use explicit current locking reads
and validate the original evidence; read-only reconciliation retains its separate
snapshot reader. A duplicate success cannot become a new refund liability merely
because the original winning settlement was absent from an earlier snapshot.

An accepted provider transaction atomically writes a verified receipt, balanced
money journal, immutable settlement decision and, if this purchase wins payment,
one order-payment allocation plus a durable `order.fulfill` work item. No
queue/network success is required for this commit. Any failure rolls back the
whole transaction, including account projections.

The order lock selects at most one winning receipt. A pending, payable order
with its original unbanned account and no previous payment is a winner; it
becomes PROCESSING and its receipt credits sales_revenue. Additional successful
attempts and canceled/already-processing orders retain the actual receipt and
credit refund_liability, without another fulfillment. The immutable decision
cannot change on replay, even after later order lifecycle changes.

Journal business type is provider_capture, business_ref is the attempt ID and
idempotency_key is the immutable provider transaction identity digest. The debit
account is provider_clearing; the matching credit is sales_revenue or
refund_liability. Original amount/currency and receipt/journal ownership are
revalidated on replay. The frozen purchase and order-payment allocation retain
full price; the channel attempt, receipt and provider_capture journal retain
only the actual channel portion. A permanent order-owned wallet reservation
freezes the difference before the first channel attempt. Its winning capture
and the channel settlement commit together. The complete money-account union
is locked in sorted identity order before multiple journals.

An allocation cannot change after any channel attempt, including a failed
attempt which may still receive genuine late success. A released reservation
never becomes a new winner. Canceled/banned late receipts release only still
reserved wallet money and retain actual channel money as refund liability;
an extra receipt never recredits wallet money already captured by the winner.
Purchase and recharge use intent_type in the same attempt/receipt tables.
Purchase binds the original order and frozen terms; recharge binds the native
recharge intent, with restrictive ownership foreign keys. Permanent provider
transaction identity is shared across these consumers. Wallet-only purchases
create no fake channel receipt and use the same allocation/work authority.

## Receipt time and exact identity

The adapter binds attempt, canonical provider account, external transaction,
positive exact minor amount, currency and successful provider status. EPay
callbacks do not authenticate a payment timestamp: the settlement service
records first verified observation time under the owning user lock and reuses
it forever. It never fabricates a channel payment time. Other future adapters
must explicitly distinguish provider occurrence from Core observation.

The provider order reference is derived from installation identity and the
permanent attempt ID, not the original order number. Different attempts cannot
collide at the channel. Provider account identity includes canonical gateway
origin/path and merchant ID, so duplicate payment registrations share receipt
identity while independent EPay installations do not share merchant namespaces.

## Frozen dispatch and payment-plugin boundary

The typed channel surface is defined in `native-payment-provider-v1.md`.
Core Billing resolves installed plugins through PluginManager; it contains no
EPay protocol adapter or native wrapper over the historical PaymentInterface.
One immutable dispatch record per attempt stores the exact plugin code,
installed/package version, contract version, provider snapshot schema and
validated protocol configuration encrypted with Core's application key.
It includes original callback registration, currency, amount, provider order
reference and account identity. No credential enters the public attempt DTO,
logs or diagnostics. Replays, query recovery and late notifications use this
snapshot, even if the current payment configuration changes or is disabled.
Disable prevents new checkout and reissuing a checkout URL, but not notification
verification or original query recovery. These limited operations do not boot
disabled plugin routes/hooks. Managed destructive lifecycle uses the persisted
plugin mutation fence and refuses any plugin pinned by a historical dispatch.
The permanent-pin limitation deliberately remains: version handoff and retained
old-package storage are not yet implemented.
Financial foreign keys prevent deleting referenced payment registrations.

The supported protocol is the existing EPay MD5 submit/notification/order-query
dialect. Only an operator-configured HTTPS base without userinfo, query or
fragment is allowed. Redirect following is disabled for queries; TLS validation,
timeouts and response size limits are mandatory. Return/callback URLs are
server-owned and cannot be selected by the paying user.

Native EPay uses CNY with exponent 2. Decimal formatting/parsing uses strings,
never floating point. Native checkout rejects handling fees until a frozen fee
allocation contract is implemented. Notify validates the full signature,
merchant, status, provider order reference, transaction and exact amount.
Query uses the frozen merchant configuration and verifies the returned order
identity, merchant, status and amount before reporting success. Missing or
ambiguous facts never become a receipt. Unsupported native providers fail
explicitly; there is no optional signed-amount fallback.

Invalid notifications and amount/account/order mismatches write nothing.
Transport timeout or unverifiable query results remain unknown and recover by
the original attempt; a verified late success is accepted even after failure.
Callback ACK follows the existing EPay protocol only after durable settlement.
No generic user route accepts a claimed verified fact or arbitrary journal.

## Durable fulfillment work

Billing work owns purchase fulfillment before a Subscription exists; it does
not reuse the subscription/credential outbox's required Subscription foreign
key. A unique event key and original order/user/receipt bind the work.
Recovery invokes the existing real SubscriptionFulfillmentService outside the
work-status transaction. Its existing durable order operation makes a crash
after fulfillment replayable. Work completes only after committed fulfillment;
failures retain a bounded non-secret error code and retry count.

Recovery uses the current installation/account-first locks. Concurrent workers
may observe the same item but cannot grant the order twice. A crash or queue
outage cannot discard the paid receipt, journal, allocation or pending work.
The existing native worker performs bounded billing recovery alongside its
subscription and credential duties. It queries at most ten pending/unknown
frozen channel attempts per invocation, oldest query observation first, and
waits at least sixty seconds before automatically querying an attempt again.
The query observation commits before provider I/O and never claims payment.
An explicit user query may retry sooner. Canceled work is terminal, not a failed
fulfillment retry. Partial and full refunds credit the wallet only with entitlement_effect=none; they do not cancel fulfillment or pending work. See [Native Wallet / Refunds v1](native-wallet-refunds-v1.md).

## Scope and acceptance

The native catalog and published-order producer admit CNY paid offers. Other
paid currencies remain unavailable because this adapter freezes CNY exponent 2.
Native order creation uses a permanent native.order.create claim in the shared
order idempotency store. It is excluded from expiry/pruning; cancellation or
later fulfillment never releases the key for another order. Direct order APIs
accept the existing bounded key alphabet; the service ingress requires UUIDs.
Native free checkout also requires Idempotency-Key. A permanent
tx_native_free_checkout record binds installation, user, order, frozen purchase,
original key and normalized checkout input to the original successful response.
It atomically transitions a zero-fee, zero-price order to PROCESSING and is its
own durable SQL fulfillment work. Exact replay returns the original type=-1,
data=true result after response loss or later fulfillment; a changed key for
that order, changed input or reused key for another order conflicts explicitly.
The free record creates no payment attempt, provider receipt, money journal or
financial allocation. Recovery reuses SubscriptionFulfillmentService and its
permanent order.fulfill operation, including a crash after fulfillment commit
but before free-work completion. Free requests accept no channel/wallet input.

Native paid checkout requires Idempotency-Key,
an enabled supported method, and optionally canonical string wallet_amount_minor
for a partial wallet allocation. wallet=true instead selects full-wallet payment
without a channel method. Native checkout rejects fees, arbitrary allocation
fields and provider tokens. Replays retain original frozen dispatch.

GET /txapi/billing/payment-attempts/{id} and
POST /txapi/billing/payment-attempts/{id}/query require the owning User actor.
Payment callbacks retain their dedicated provider ingress and raw protocol ACK.
Native references are claimed before the historical adapter and cannot fall
through on a bad method or callback path; unknown native references fail closed.
A manual/admin PROCESSING status or the free-order paid() transition cannot
grant a production paid order without canonical allocation, receipt and ledger
evidence. Pure Subscription-domain fixtures may use a mock authority without
a production runtime version; real activated-runtime acceptance must retain
actual persisted admission and prove this bypass rejection.

CI covers real activation, signed EPay facts, frozen plugin/configuration replay,
wrong merchant/signature/amount/currency/order, simultaneous successful attempts,
late canceled-order money, atomic rollback, duplicate receipt/decision replay,
query unknown recovery and durable fulfillment after a process/queue failure.
It includes disabled-plugin recovery, exact package-version/schema failures,
destructive-lifecycle pins, the first-dispatch versus uninstall race, free
response-loss replay and simultaneous free checkout retries.
MySQL races run in CI; no local DB, provider process or complete build is needed.
