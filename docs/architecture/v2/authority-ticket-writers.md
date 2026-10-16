# Core support-ticket SQL admission

> Historical evidence only. The isolated v1-v6 authority prototype and its dedicated CI were retired on 2026-10-16. The behavior and results below describe the former implementation, not current runtime support. See [current Core boundaries](../../development/core-cleanup.md).

The [v6 contract](../../../contracts/v2/authority-ticket-writers-v6.md) extends the same isolated-copy authority singleton with six native support operations. The fixed inventory contains the preceding 53 owners plus user ticket create/reply/close, admin reply/close and per-ticket cron close. The legacy runtime remains authoritative; admission is disabled by default and global cutover flags remain false.

A separate immutable ticket receipt binds the verified v5 parent chain and unchanged historical hashes. Only v5 open5 can extend to v6 open6; prepare produces7. Earlier prepared versions cannot reopen or change meaning. Replay and recovery validate the complete receipt chain, including parent hashes, before returning the original result. Bounded recovery remains SELECT-only.

| Native root | Original SQL/effect boundary |
| --- | --- |
| User create | Ticket and initial message commit together; create hook follows commit |
| User reply | Admission precedes Ticket lock; message/state and user reply hook remain in the original outer transaction |
| User/admin close | Locked conditional update; repeat close remains idempotent |
| Admin reply | Current locked Ticket/state update commits before admin hook and notification cache/dispatch |
| Cron close | Each candidate has its own admitted commit; current age/status/replier predicates are rechecked |

The user workflow uses a fixed creation entrypoint. Generic creation accepts only the actual reviewed v6 user-create proof or v5/v6 withdrawal proof. Generic reply requires the actual reviewed user/admin reply proof and checks current ownership/status. Unknown, unrelated or expired outer transactions refuse before SQL. Proofs remain tied to the actual Connection/PDO/PID/outer transaction record and exact worker declaration.

Enabled nested creation uses one managed child transaction. Duplicate-open-ticket or initial-message failure rolls back that child without consuming the admitted ancestor. Disabled code retains its original error and rollback behavior. Ordinary creation retains the original opening-ticket lookup; no new uniqueness constraint or per-user serialization guarantee is introduced. Withdrawal continues to lock its User in its original root.

Enabled admin reply and cron use fresh locked records. A concurrent close may therefore refuse a later reply even if the earlier unlocked controller read saw an open ticket. User hooks remain inside their original transaction; admin/create hook or notification dispatch failure after commit preserves the already committed SQL. Cache gating still occurs before dispatch. Neither queue enqueue nor cache state certifies mail delivery, and SQL is not automatically replayed.

The v6 postcommit policy retains exact v4/v5 intent hashes and adds the exact v6 scope/version/epoch tuple. Existing User owners under v6 retain synchronous observer capture and committing schedule calculation. Old pending intents can hand off under v6 without rehashing; Node delivery remains separate evidence.

Acceptance uses actual native HTTP/CLI, real Bearer authentication, original default-disabled consumers, immutable source/dependency snapshots and independent MySQL processes. The former dedicated MySQL8.4 workflow verified the native owners, receipt integrity, lock waits, rollback and SIGKILL. Receipts contain no ticket subjects/messages, email addresses or credentials.

This is a closed native subset. Network/control writers, commerce/content administration, retention, trusted plugins and alternate connections remain separate. SMTP, notification cache, process restart and queue ready/reserved/delayed/failed outcomes still require independently verified drain evidence before any production authority switch.

The [verification report](authority-ticket-writers-verification.json) records the final frozen runtime, dedicated engine tests, independent processes and full API regression; remote acceptance is recorded after the exact stage push.
