# Standalone legacy reset capture v1

Status: isolated local/testing extension. A separate strict
`LEGACY_STANDALONE_RESET_CAPTURE_ENABLED=false` preserves original behavior by
default. Enabling also requires existing legacy capture and isolated context.
It captures only performReset entered at transaction level zero and outside the
existing due cron ticket; native eligibility and locked due/manual rejection
remain before capture. Nested Order/gift/hook resets retain their outer ticket.

Fixed policy `traffic.reset.standalone`, version `standalone-reset-capture/v1`,
actor domain `system_legacy_traffic_reset`, actor ID actual locked User. Trusted
source labels manual/auto/api/user_access/order/gift_card/cron are accepted;
unknown labels refuse the enabled isolated mutation. A source label or reset-log
metadata never asserts independent User/Admin/Node authorization. Existing HTTP
credentials and domain permissions remain unchanged. Native reset log retains
its source; capture only describes this transaction's actual User endpoint.

The original one-attempt transaction uses current User X and ordinary Plan
relation, original calendar/timezone/counters/reset log/cache/hook ordering.
Begin follows locked checks; append follows reset log/cache/traffic.reset.after.
No new retries, idempotency key, history inference or v2 projection is added.
Captured failure rolls back the User/reset log/event/map, returning native false
with only LEGACY_STANDALONE_TRAFFIC_RESET_CAPTURE_FAILED and empty log context.
Existing due cron policy and its diagnostic retain their original behavior.

Repeated manual/forced resets remain repeated native resets, even when counters
are zero; due rechecks arbitrate current next_reset_at. A committed result lost
before response or notification is not a permanent exactly-once reset receipt.
Later standalone order reset is a separate endpoint from earlier fulfillment,
not part of its committed ticket. Unknown source gaps reject and do not rebase.
Trusted same-User captured hook writers can conflict with the outer ticket;
foreign-User/hook/cache/external effects are not complete rollback guarantees.

Original callback errors can be returned after reset SQL already committed;
optional Observer schedule uses its own follow-up transaction and may fail.
No durable follow-up/Node delivery recovery is certified. Original manual/service
false handling and HTTP envelopes stay native. Force/fix-null/initial schedule
writers are not this counter-reset operation and remain separate coverage.

See [synthetic fixture](../../api/tests/Fixtures/LegacyStandaloneResetCapture/README.md).
