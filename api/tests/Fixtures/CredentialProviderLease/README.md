# Core-only lease candidate fixture

The isolated Linux gates passed on 2026-10-12. This fixture is not production admission, authenticated
Node delivery, a recipient service or a secret channel. The Current boundary
is described in the [contract](../../../../contracts/v2/credential-provider/local-fixture-lease-candidate-v1.md).

Tests create a persisted Eloquent User, known Subscription/Terms/period, an
explicit trusted Plugin binding and real synthetic Provider issue receipt.
Candidate methods perform no IPC; only test setup invokes the existing Provider
process. Its private secret is checked against Core candidate SQL/results.
User/Subject/quota/legacy history remains unchanged and auth snapshots stay empty.
The fixture seeds an isolated usage source/tariff in setup, not during candidate
operations. Source identity is not an authenticated online Node.

On Linux, run from a complete fixed committed Core snapshot plus only these
reviewed new slice files and copied locked vendor with regenerated application
autoload paths. Do not use live uncommitted Subscription/Internal overlays.
Use public `.env.example` with a disposable test key and dedicated Redis20/21.

```bash
cd api
bash tests/Fixtures/CredentialProviderLease/run-linux.sh --sqlite
bash tests/Fixtures/CredentialProviderLease/run-linux.sh --full
```

Real independent-session and SIGKILL cases require Linux PHP pcntl/posix and the
precreated empty `txboard_provider_test` database with dedicated scoped user
`txboard_provider_fixture`. Pass its test-only password through `DB_PASSWORD`.

```bash
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=13306
export DB_DATABASE=txboard_provider_test DB_USERNAME=txboard_provider_fixture
bash tests/Fixtures/CredentialProviderLease/run-linux.sh --mariadb
```

The launcher refuses other database/user names. Tests migrate/drop only their
listed disposable tables; forked sessions are joined and barriers/private state
are removed. Do not point the fixture at production/shared data or another
agent's database/Redis namespace. No Node/Gateway command is invoked.

Tests cover same-key one-winner, committed response-loss/SIGKILL recovery,
transaction-trigger rollback, rehashed malformed/foreign-owner receipt refusal,
bounded expiry, observed clock regression and irreversible committed terminal
state. The two-way barriers use actual PluginManager disable, Provider revoke,
Terms reset and Usage source/generation operations in separate sessions; User
ban uses the actual Eloquent User in a locking SQL transaction. Its real Observer
calculates/saves `next_reset_at` and dispatches NodeUserSyncJob; the fixture asserts
that dispatch while faking only this delivery job. No Observer event is suppressed.
The original issue uses a future not-before of server time plus two seconds,
as its native issuance contract requires. Only the trusted test-container clock
advances to that value for candidate preparation; this does not prove Node time
or expiry enforcement. None fabricates a Node ACK.

The fixed complete committed base was
`1cc6bf9bd6a069758a0a8259bb472e3fb516ad88`, with exactly nine new slice files
and copied locked vendor. The complete archive SHA256 was
`e6722bcd168d15bfc2f31c4b20ea79eeda06388c97a5773cb9fdf06f6acae382`.
Composer lock SHA256 was
`f47b212c2fc530a7820e370eab7c6d624d13962e7d26e4f2ddf57a11848257c0`;
installed.json SHA256 was
`8e668e456c9772ae428a038ab003fba5752ab2591980fc8396d9122851145b80`.
PHP 8.4.16 and MariaDB 11.4.12 were used; this is not a MySQL 8.4 result.

| Gate | Total | Passed | Skipped | Assertions | Log SHA256 |
| --- | ---: | ---: | ---: | ---: | --- |
| SQLite specialized | 25 | 11 | 14 | 274 | `3e925938418283ca711f2b02a1e3a4474a245799a3a9647c197da1c76abab618` |
| MariaDB specialized | 25 | 25 | 0 | 402 | `f6c6b5d6cefde834bba0cddb581585f41983432a443039178a9fec6d73b95a26` |
| Full API, SQLite | 1000 | 936 | 64 | 8894 | `d6b488554438864bf73ea287773fea7b4a17769968a8d2c445fb3040ff894d93` |

The specialized SQLite skips are the fourteen MariaDB-only independent-session
cases; these all ran in the dedicated MariaDB gate. Full API skipped gates are
reported as skipped, without substituting the specialized result for them.
Local raw logs are retained at
`/opt/txboard-baseline/logs/provider-lease-r5-{sqlite,mariadb,full}-attempt01.log`.
Runtime, migration and test source hashes remain pinned to the successful r5
snapshot; this README and the Current contract received only verification text
after the gate. No other agent's live source was included.

Earlier failures remain retained: r1's User fixture lacked the Observer's reset
column; r2 sampled its issue not-before too early and caught the migration's
correct history refusal as the wrong exception type; r3 exposed the new
migration's overly long automatic foreign-key identifier on MariaDB; r4's test
misclassified the existing generation-recovery UsageError as unknown. The
fixture/migration fixes preserve native Provider time rules, recovery refusal,
history restrictions and business runtime. Unknown child exceptions still fail.
The original automatic approval review failure executed nothing; the user
authorized a normal reapplication and subsequent exact gates were approved.
All failed logs and their source manifests remain preserved, with no alternate
entry-point bypass or production/Node execution.
