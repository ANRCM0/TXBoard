# Captured legacy money endpoint projection v1

> Retired on 2026-10-16: this isolated shadow/prototype runtime, commands and dedicated tests have been removed. The text below is historical evidence, not an available interface or an implementation requirement. Existing database migrations and records are retained; this cleanup does not run data deletion.

Status: verified local/testing implementation slice, default off. This is additive
shadow evidence; legacy User balances remain authoritative.

The consumer requires `LEGACY_MONEY_SHADOW_ENABLED=true`, the existing capture
context and isolated-copy gate. It has no HTTP entry. The CLI requires
`--isolated-copy` and handles one User and at most 100 events per transaction.
It uses the actual User exclusive lock before checkpoint/stream locks; source,
context, event policy, canonical snapshot, hash chain and current endpoint are
validated by the existing capture service. Uncaptured endpoint changes, missing
events, malformed evidence and context drift refuse the entire page.

Three separate `tx_legacy_money_shadow_*` tables retain a checkpoint, immutable
event steps and postings. The first captured event's before balances are an
observed anchor, not an opening cash transaction or proof of earlier history.
Each event retains both money endpoints even when neither changes. Nonzero
wallet and commission deltas each produce two equal positive minor-unit lines:
the User purpose and a distinct **unclassified capture clearing** purpose.
An increase credits the User purpose; a decrease debits it. No zero posting,
payment recognition, inferred bank receipt, fee, refund classification or
live-ledger write is created. Native endpoint integers are used without float
conversion; repeated native business work remains separate captured events.

On first projection, the checkpoint freezes a stream high-water and its hash.
Every bounded page atomically inserts steps/postings and advances its cursor
and balances. Restart resumes the durable cursor; a lost acknowledgement does
not cause duplicate postings. New captured events require explicit `--extend`
after the previous high-water is complete. Extension validates the original
anchor and cursor, then freezes the new high-water in the same transaction.
Source validation still checks the latest captured endpoint, including events
beyond the frozen projection high-water. A missing source blocks recovery.

`--verify --after=<sequence> --limit=<1..100>` is an autonomous SELECT-only
bounded reconciliation. It derives expected steps and postings from validated
capture events, checks exact physical rows and checkpoint totals, and reports
pagination. Each page takes its own User shared fence; this does not prove a
cross-page global snapshot or all legacy writer coverage. Corruption is reported,
never repaired. All entry points reject an outer transaction and emit only fixed
error codes, IDs, hashes, cursors and money totals, never source snapshots,
credentials or SQL exceptions. Existing capture verification still reports
`projection_applied=false`: it refers to the full Subscription/Terms/usage and
authoritative financial migration, which this evidence consumer cannot open.

```sh
core:legacy-money-shadow --isolated-copy --user=1 --limit=100
core:legacy-money-shadow --isolated-copy --user=1 --extend --limit=100
core:legacy-money-shadow --isolated-copy --user=1 --verify --after=0 --limit=100
```

Schema expansion is additive and validates replay shape. Rollback refuses any
checkpoint, step or posting evidence. No User, Commerce opening, subscription,
Terms, usage, Order, existing event or mapping state is repaired or mutated.
Global coverage and migration gates stay false. SQLite13/182 assertions and
MariaDB15/203 assertions passed, including independent lock waits and SIGKILL
before/after commit. Full API1125 passed/101 engine-specific skips/11506
assertions. The same runtime/test hashes are recorded in the
[verification report](../../docs/architecture/v2/legacy-money-shadow-verification.json);
the dedicated Oracle MySQL8.4 CI result is tracked separately there. These are
synthetic fixtures, not production migration acceptance.
