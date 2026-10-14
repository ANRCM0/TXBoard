# Core identity SQL admission

This batch implements the [v5 contract](../../../contracts/v2/authority-identity-writers-v5.md) on a dedicated synthetic database. Legacy Core remains authoritative. Admission is disabled by default and accepts enabled workers only in `local`/`testing` with an explicit isolated copy. Frontend development remains deferred to TX-F; installation remains in TXBoard-Deploy.

The fixed inventory adds 27 native identity, session, settings, statistics and log owners to the preceding 26 owners. Admission precedes business locks. A nested phase requires the actual outer transaction record, connection, weak PDO reference and PID; a numeric transaction level cannot authorize it. Unknown outer transactions and malformed enable flags refuse before the native callback.

| Existing scope | Open epoch | Prepared epoch | Identity extension |
| --- | --- | --- | --- |
| v1 admin user editor | 1 | 2 | Refused |
| v2 native inventory | 2 | 3 | Refused |
| v3 finance inventory | 3 | 4 | Refused |
| v4 native writers | 4 | 5 | Explicit open4 → v5 open5 |
| v5 identity writers | 5 | 6 | Exact receipt replay only |

The extension changes the same singleton and appends an immutable receipt binding the v4 parent receipt, preceding state and fixed v5 inventory. Existing receipt/hash recipes remain unchanged. Recovery performs bounded reads and does not reopen a prepared state or repair corrupted evidence. A populated identity receipt cannot be dropped by migration rollback.

Native operations keep their separate commit boundaries. Registration consumes an invite, inserts the User, runs its after hook, removes the email code, updates last-login and issues a session in their original order. Password changes commit before later session revocation. Settings commit per key; Server statistics commit separately from Server traffic. If prepare intervenes, prior completed phases remain visible. No compensation, token reissue or email retry is introduced.

Sanctum retains its parent authentication, provider and expiration checks, retired Agent policy, `TokenAuthenticated` order and RequestGuard refresh. The application subclass wraps only the protected last-used write, locks the current User/token, rechecks validity and restores the original connection modification state. Acceptance uses actual Bearer requests. Session issuance rechecks current ban/admin status under lock and maps refusal through the existing 403 authorization response.

The original `removeAllSessions` calls within already admitted old admin update/delete/bulk-ban transactions retain their original SQL under a closed list of valid v1-v4 proofs and matching declarations. This preserves existing covered roots without adding an old standalone session writer. An unrelated old proof or unknown outer transaction cannot authorize the deletion.

Creation capture finishes before the committing Observer computes its schedule. A no-op schedule does not manufacture an event. New postcommit intents use the exact v5 scope/version/epoch and inventory; pending v4 intents retain their original hashes and can still hand off under v5. Queue enqueue evidence does not prove Node delivery.

Audit append occurs after the endpoint response; mail-log append occurs after the native mail transport. Their refused writes preserve the earlier response/send result and record a fixed safe unknown outcome. The mail test uses Laravel's actual array transport and does not prove SMTP delivery. Cache invalidation failure after settings SQL preserves the committed setting and reports an unknown cache outcome. Receipts contain no passwords, tokens, email addresses, setting values or mail bodies.

The [verification report](authority-identity-writers-verification.json) records immutable source/dependency hashes, native owner tests, independent database lock waits, rollback/SIGKILL outcomes and full API regression. [MySQL 8.4 CI](../../../.github/workflows/ci-authority-identity-writers.yml) runs the dedicated owner, evidence and concurrency suites. Default-off verification removes all authority tables and exercises the native API/CLI/Bearer paths without authority IO.

This inventory is a reviewed subset. Generic ticket writers, remaining network/control/content/payment/prune writers, trusted plugin direct SQL and alternate connections still require coverage or independently observed stop/drain evidence. Process restarts, queue ready/reserved/delayed/failed states, SMTP, cache and filesystem outcomes remain separate cutover requirements. `global_writer_coverage_complete`, `global_drain_complete`, `target_authority_active` and `migration_gate_open` remain false.
