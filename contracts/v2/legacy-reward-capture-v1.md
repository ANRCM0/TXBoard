# Legacy commission and gift reward capture v1

Status: default-off, isolated local/testing evidence. Legacy User balances and
entitlements remain authoritative. This extends the
[fixed writer capture](legacy-user-mutation-capture-v1.md), without creating a
financial ledger or applying Subscription/Terms/Usage projections.

| Native writer | Fixed policy/version | Actor domain/reference |
| --- | --- | --- |
| CheckCommission payout | commission.distribute / commission-distribution/v1 | system_commission_distribution / Order work-item ID |
| GiftCardService primary rewards | gift.reward.apply / gift-reward-capture/v1 | system_gift_redemption / locked GiftCardCode ID |
| GiftCardService inviter rewards | gift.invite.reward / gift-invite-capture/v1 | system_gift_redemption / the same locked GiftCardCode ID |

References identify native work, not authenticated principals, payment proof or
new globally unique operations. They are canonical positive native INT IDs.
Tickets are writer-specific and retain exact current persisted endpoints,
existing context/hash-chain checks, immutable events and map invalidation.
Unchanged captured fields create no event. No secret gift code, trade number,
request, callback, credential, raw reward configuration or source amount is
copied into capture SQL or verification DTOs.

Commission retains the original collected Order candidates, one transaction per
Order, original level order/percentage multiplication, wallet-versus-commission
setting, CommissionLog records, actual_commission_balance and status update.
Enabled capture uses exclusive current inviter reads; disabled capture keeps the
original ordinary read and adds zero capture SQL. Begin follows native nonzero
amount and inviter/share checks. Append follows increment/save/log and in-memory
Order total update, before the outer Order save/commit. Missing inviters, missing
shares and zero amounts skip capture guards. A direct payHandle call that would
mutate without a caller SQL transaction is refused only when capture is enabled.

No Order lock/status recheck, candidate deduplication, new transaction retry or
idempotency receipt is added. Concurrent already-collected/stale Order work can
pay again under native behavior; both actual commits get evidence. This slice
does not certify exactly-once commission or repair that independent legacy gap.
Native percentage floats and actual database INT assignment are preserved.
MariaDB persisted rounding is recorded; SQLite fractional evidence is refused
and the isolated transaction rolls back, rather than recomputing the payout.
Later recipient/capture/Order-save failure rolls back the entire current Order's
SQL effects. Earlier completed Orders remain committed.

Gift redemption retains code → template → recipient User locks, eligibility,
usage limits, native random/festival calculation, reward primitives, optional
inviter lock, code consumption and GiftCardUsage in the original transaction.
Primary capture spans giveRewards, including its nested reset or assignPlan;
there is no inner capture ticket. Inviter capture spans the original balance and
transfer rewards. Primary append precedes inviter handling, so a native
self-invitation creates contiguous primary then inviter endpoints. The original
code max_usage controls repeated redemption; a reusable code can reward twice,
and a consumed single-use code rejects before capture even if its context/schema
is unavailable. Native missing-inviter and zero-amount behavior is preserved.

Default-off production paths add no capture SQL or transaction. Strict boolean,
isolated-copy/environment/context checks and source-gap rejection remain.
Any later thrown code/usage/recipient/capture failure rolls back the original
transaction and all its evidence; no new catch/log branch is introduced. Native errors retain
their existing handling; capture exceptions use fixed ShouldntReport errors.
In-process trusted hooks, cache invalidation and observer/Node notifications are
not rollback or delivery guarantees. Death after commit can precede response or
notification, and there is no new durable recovery receipt. Commission work and
reusable gift-code unknown results must not be blindly replayed at cutover.

Expiry/plan changes can cause UserObserver to recompute next_reset_at after the
gift transaction commits. This uncaptured independent write leaves a source gap:
verification rejects the current endpoint and later captured writers fail closed.
The gift event is the transaction endpoint, not the final observer endpoint;
this slice does not suppress, rebase or mislabel that native follow-up write.

The original markAsUsed boolean result is ignored by redeem. A trusted model
saving listener returning false can leave the code unconsumed while User rewards,
usage and capture commit; the same code can then reward again. This native soft
failure is preserved and tested, not certified as successful code consumption.

Bounded verification is SELECT-only and labels events transaction-only. Complete
legacy writer coverage, financial/payment facts, production cutover, restoration,
power-loss durability and notification recovery remain independent gates. The
[synthetic fixture](../../api/tests/Fixtures/LegacyRewardCapture/README.md)
records Linux/MariaDB process and transaction evidence.
