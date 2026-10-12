# Provider initial-binding and offline signature fixture

Local/testing engineering slice only. Default disabled. A real successful
synthetic Provider issue and B4.4 candidate precede one atomic pending Subject
binding plus signed evidence. No Node, Gateway, Internal or HTTP consumer exists;
`admission_granted` is always false. This is not production crypto acceptance.

`run-linux.sh --sqlite` runs the new feature tests and all B4.4 candidate tests.
`--mariadb` uses only an initially empty `txboard_provider_test` as
`txboard_provider_fixture`; the test class creates and removes a fixed list of
owned tables. The reviewed external launcher first refuses a nonempty database.
It does not use root, new grants, shared databases or generic cleanup. Redis
20/21 and array cache are reserved for this fixture. `--full` uses SQLite and the
complete committed Core snapshot plus the precise slice and locked copied vendor.

The fixture creates a real User and observes its real NodeUserSyncJob dispatch,
faking only that job's delivery. The original Provider requires a future
not-before: issuance uses `time()+2`, followed by a trusted test clock at least
that value. This controlled clock is not caller input and proves no Node lease
time behavior. SQL, actual RSA3072 signatures, public-only offline verification,
rollback triggers, separate MariaDB processes and barriers are real runtime
checks. They do not emulate Node acceptance. No runtime result is claimed until
its preserved log and source manifest have been reviewed.

The suite checks one binding and original same-key bytes, different-key refusal,
source/Terms/period/provider/actor/version gates, strict JWS/JSON/algorithm/key
boundaries, expiry/regression and durable terminal refusal, SQL/signing/late-time/
trust rollback, restricted history and migration repair. MariaDB tests add
independent-session winners, both orders of six mutation barriers and SIGKILL
after commit. Unknown child exceptions fail tests rather than count as refusals.

`verify.php PUBLIC_TRUST.json SNAPSHOT.jws` loads only Composer classes. It never
boots Laravel, connects to SQL/Redis or loads a private key. Its output reports
signature/key-revocation evidence with window and live eligibility not evaluated.
This is deliberately distinct from `verifyWindow` and Core `checkCurrent`.

Fresh independent RSA3072 key files live under a disposable 0700 fixture root;
private PEM is 0600, public trust is fixed and exact, and cleanup removes only
that generated root. Signing material, Provider fixture secrets and legacy
credentials never appear in SQL, returned receipts, logs or exception arguments.

History survives retirement, expiry and current-business refusal; it is never
re-signed or extended. SQL/trust replacement is not atomic, and a restored DB,
unobserved clock regression, HA, external revocation mapping, private delivery,
Node identity/admission/enforcement/ACK and legacy migration remain open gates.
See the [Current contract](../../../../contracts/v2/credential-provider/local-fixture-authorization-snapshot-v1.md).
