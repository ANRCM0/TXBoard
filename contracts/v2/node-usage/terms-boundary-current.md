# Node Usage v2: current Terms and period boundaries

Status: current native development contract, updated 2026-10-16. The
[native C2 runtime](../native-c2-runtime-v1.md) supplies signed grants, durable
receipts, original-period reservations and settlement. The earlier
[isolated Terms mutation service](../subscription-terms-shadow-v1.md) is retired.
This contract changes retained Core evidence requirements, not the public Node
payload, digest or receipt schema.

## Domain ownership and half-open intervals

Published-order fulfillment owns purchases and renewal Terms transitions.
`SubscriptionLifecycleService` owns resets and lifecycle changes.
`SubscriptionTermsTimeline` is their shared read-only projection, also consumed
by UsageV2 after subscription locks. Original Terms rows remain immutable;
outgoing transitions supply the predecessor cutoff.

Normal usage must fit one projected Terms segment and one known period of the
same subscription. Intervals are half-open: `[start, boundary)` belongs to the
predecessor and `[boundary, end)` to the successor. A period's `terms_id` records
its creation Terms. Renewal can change effective Terms without opening another
period or resetting consumption. Reset closes the old period and opens a new
zero-counter period. Late and pending historical usage stays with its original
Terms, period and tariff.

Native grants and receipts bind the original complete coverage window. A later
proven authorization cutoff can produce a persisted quarantined receipt with
original-period quota reservations; it does not produce a settled ACK. Invalid
scope, proof or billing remains a rejection. Missing or contradictory historical
evidence never permits reassignment to the current period.

## Required retained evidence

Current producers freeze all five fields for each retained usage entry:

| Field | Meaning |
| --- | --- |
| `boundary_revision` | Exactly `terms-period-half-open-v1` |
| `period_creation_terms_id` | Original period's creation Terms |
| `terms_effective_from` | Projected Terms interval start |
| `terms_effective_until` | Projected end, explicitly present even when null |
| `terms_ending_transition_id` | Outgoing transition, explicitly present even when null |

Settlement requires these fields, verifies the original Terms hash and checks
both the frozen interval and current historical projection. There is no B3.1
fallback that infers missing boundary fields from the entry's Terms or today's
state. Missing proof quarantines the batch as `USAGE_FROZEN_TERMS_BOUNDARY`
before counters, ledger or Node statistics are written. No automatic backfill,
receipt replacement or data deletion is performed.

Quarantine retains the same batch identity, receipt and native reservation.
Repeated submit/query returns that durable receipt; it does not create a second
reservation or settle the batch. Successful settlement atomically charges the
original period and releases its reservation. Explicit audited recovery retains
the evidence requirements.

## Mutation and transaction safety

Native mutation takes the account, slot, subscription and history locks before
inspecting accepted usage. Reset and renewal refuse a boundary that would
reassign an accepted interval, regardless of pending, processing, retry,
quarantined or settled state. Inspection does not reverse-lock batches or grants
against a settlement worker. Receipt and settlement paths recheck ownership in
their own admission-protected transaction.

Existing native tests cover purchase/renewal/reset, original-period settlement,
authorization cutoff and quota reservations. The cleanup regression removes all
five boundary fields from an accepted native batch while retaining a valid
evidence hash; settlement must quarantine with no charge, preserve the
reservation, and keep submit/query replayable.

The [B3.2 verification JSON](../../../docs/architecture/v2/usage-terms-boundary-verification.json)
records historical isolated tests. It is not evidence that the retired shadow
writer or incomplete-evidence compatibility remains callable.
