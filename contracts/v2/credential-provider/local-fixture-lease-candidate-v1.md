# Local Provider lease candidate v1

Status: Current local/testing implementation scope, isolated Linux gates passed;
default off. This prepares nonsecret Core metadata only. It grants no admission,
does not activate a Subject, insert an auth snapshot or publish a Node handshake,
and performs no IPC, signing, secret delivery or ACK. It does not complete the
[Provider target](README.md) or prove Node convergence.

Only the existing fixed synthetic Provider and its trusted reserved Plugin
binding participate. A separate strict flag, the existing fixture/bridge/shadow
flags, local/testing environment and isolated Usage identity policy are required.
Caller scope is the fixed trusted local operator, not public authentication.
No HTTP, Node or Gateway consumer is added.

`prepare(scope, issueOperationRef, businessKey, input)` accepts exactly canonical
decimal strings `expected_subject_version`, `expected_terms_id`, `source_id`,
`usage_period_id`, `tariff_revision_id`. No caller clock, expiry, policy, audience,
URL, private material or arbitrary metadata is accepted. It uses a successful
Core-stored original issue with exact allow-listed input/result schemas,
canonical hashes, matching row/provider/registration epoch/actor/Subject/Terms,
generation1, nonsecret refs, null error and exact credential expiry. Unknown or
damaged/rehashed malformed receipts refuse. Pending Subjects are accepted only
as candidates; the unchanged Core placeholder and private Provider reference
are separately retained, never falsely asserted bound.

The transaction rejects outer transactions and locks User first, fixed plugin,
binding, registration exclusively, lifecycle/revocation, original operation,
Usage source, Subject, Subscription, current Terms/periods and tariff. Ownership
hints are rechecked under locks. It explicitly verifies plugin enabled and
current issue/query/revoke grants, epoch/digest/profile, issuance fence and
revocation facts. It never calls public issue/query/source/grant, runs IPC or
locks Server after source. Current locking reads use the existing Terms boundary.

The period must be known, nonclosed and finite, belong to the same Subscription,
have exact Terms quota and settled billable below that quota. Tariff must match
source Node, have a canonical bounded rational, known rounding revision and
valid content hash. Current source identity is not Node online/recipient identity.
Legacy User bans, inactive/ended Subscription, stale Subject auth version,
revocation, unknown boundaries or receipt Terms no longer current refuse.
No quota reservation or safety against unreported/pending traffic is provided.

Core samples time after locks. Original candidate duration is at most 60 seconds
and clipped to credential, Subscription, Terms, finite period and tariff ends.
Future not-before or less than one second remaining refuses. Return-time clock
and monotonic elapsed checks can close an expired candidate before commit.

`tx_credential_provider_shadow_lease_candidate` stores immutable operation and
nonsecret evidence with restrictive history references and a small local
validation projection. IDs, bytes and versions are decimal strings in JSON;
times are safe UTC integers. It stores no secret, opaque Provider payload or
secret-derived hash. Original request/evidence digests detect corruption, not
authenticate Node grants. Nonempty migration rollback is refused.

`inspect(scope, candidateRef)` and identical-key `prepare` replay return original
immutable evidence with `historical_only=true`, never current eligibility. Live
User deletion, source rotation and Provider disable do not hide retained history.
Different input under the same scope/key conflicts; expiry replay never rebuilds
or extends an interval. Historical reads validate stored schema/hashes/scope.

Only `checkCurrent(scope, candidateRef)` checks current metadata under the lock
order above. Its result is `prepared/expired/refused`, always
`admission_granted=false`, `auth_snapshot_id=null`,
`delivery_status=not_implemented`, `node_convergence=not_implemented`.
Failed current gates and observed expiry/clock regression become irreversible
local terminal state committed rather than thrown inside the transaction.
Later reads cannot reopen it. Original immutable evidence remains untouched.
Clock high-water detects only observed regression; unobserved clock/DB rollback
and restores require externally verified recovery, not a claimed crypto fence.

This never changes B4.3 uninstall/delete refusals. Actual credential binding,
Provider-aware admission policy, authenticated delivery audience/grants,
private secret channel, verified Node time/session termination/anti-rollback,
per-source delivery/ACK evidence, HA/restore and historical Usage settlement
remain independent prerequisites. No production or legacy credential migration
is authorized by this slice.

The [fixture verification record](../../../api/tests/Fixtures/CredentialProviderLease/README.md)
records the fixed committed base, source scope, dependencies, engine-specific
checks, independent-session barriers, crash/replay evidence and earlier failed
attempts. These results verify the local candidate boundary, not production
admission, Node lease expiry, delivery convergence or a cryptographic fence.
