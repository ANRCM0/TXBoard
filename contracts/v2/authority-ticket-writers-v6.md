# Authority support-ticket writers v6 (Candidate)

> Retired on 2026-10-16: the isolated authority migration prototype, CLI, configuration and dedicated tests/workflows have been removed. This document preserves historical protocol evidence only. Current installation admission and domain writes are described in [Core cleanup](../../docs/development/core-cleanup.md). Historical database rows and migrations are retained and cannot authorize native initialization.

Default-disabled local/testing isolated-copy SQL subset. Legacy Core remains authoritative. Extend the same singleton only from v5 open5 via explicit extend-ticket(expected5,key) to v6 open6, then prepare7. Append an immutable ticket upgrade receipt binding the unchanged identity receipt, complete preceding evidence chain, prior state and exact v6 inventory. Old v1-v5 hash recipes and prepared states remain unchanged; replay validates the full chain before returning the original receipt. All global coverage/drain/target authority/migration flags remain false.

The exact inventory is the unchanged 53 AuthorityIdentityInventory writers plus six source constants in order:

- `user.ticket.create`
- `user.ticket.reply`
- `user.ticket.close`
- `admin.ticket.reply`
- `admin.ticket.close`
- `ticket.close.cron`

Each reviewed root admits before domain locks. Unknown outer transactions refuse; inherited paths need the actual connection/PDO/PID/outer transaction proof, compatible declaration and fixed reviewed writer. Generic service calls cannot use arbitrary valid parent proofs or caller operation strings. Existing v5 withdrawal ticket creation retains its exact withdrawal-request proof; do not add a new user-ticket admission under the already-locked withdrawal User transaction. Only the reviewed v6 user-create and v5/v6 withdrawal paths can enter enabled shared creation SQL. Generic reply accepts only the exact reviewed user/admin reply proof, verifies ownership/current Ticket under that existing admission, and never first admits after a Ticket lock.

Original native transaction/effect boundaries remain: Ticket plus initial message commit atomically; user create hook after commit; user reply hook inside its original outer transaction; admin reply SQL commits before admin hook and cache-gated mail dispatch; close is idempotent; cron commits separately per ticket. Enabled manual creation rollback cannot consume an ancestor transaction (including duplicate/open-ticket refusal). Disabled paths retain original SQL/error/hook/cache ordering and require zero authority/receipt IO. No compensation, SQL replay, or exactly-once mail guarantee.

Enabled admin reply and cron lock/re-read the current Ticket after admission and recheck native closed/age/reply/ownership predicates. Ordinary create retains the native opening-ticket lookup and its existing lock pattern: there is no new unique-open constraint or stable User serialization claim. Withdrawal already locks User. Shared authority locks are not per-user creation mutexes; native concurrent duplicate/deadlock behavior is not silently advertised as fixed.

Postcommit policy accepts exact v4/epoch4, v5/epoch5 and v6/epoch6 tuples without changing old intent hashes. Existing User writers under v6 retain synchronous producer and committing schedule behavior. Old v4/v5 pending intents can hand off under v6; enqueue does not attest Node delivery. Existing v1-v4 closed admin session deletion compatibility remains unchanged; v5/v6 use the normal nested session.revoke phase.

Receipts contain no subjects, messages, email, passwords, tokens or callback payloads. Trusted PHP hooks, queue SQL/delivery, notification cache and SMTP remain separate producers/effects requiring explicit drain evidence. Prepared metadata alone never certifies process/queue/plugin drain.

Acceptance requires actual native user/admin HTTP and cron command; correct fixed root and authority-before-business-lock; missing/cross-account/closed/wait-reply denial; atomic ticket/message rollback and callback placement; retained SQL after admin hook/dispatch failure; per-ticket cron partial progress; exact v5 withdrawal compatibility and unknown/unrelated/expired proof refusal; v1-v5 immutable/replayed/corrupted receipt chain, prepare7 and bounded SELECT-only recovery; real Bearer/default-off existing API compatibility; independent guarded MySQL processes showing prepare waits through commit/rollback/SIGKILL and old workers refuse afterward; final immutable source/dependency snapshots, full API and Oracle MySQL8.4 CI.
