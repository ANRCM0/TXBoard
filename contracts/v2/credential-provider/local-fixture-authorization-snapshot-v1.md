# Local Provider authorization snapshot v1

Status: implementation scope, verification pending. Local/testing, strict default
off, fixed trusted synthetic Provider only. This commits an initial Core Subject
binding and an offline-verifiable signed statement. It authorizes no Node or
production use and does not implement secret delivery, credential parsing,
recipient authentication, lease enforcement or Node convergence.

The [candidate](local-fixture-lease-candidate-v1.md) and successful issue receipt
are prerequisites. Provider material creation is distinct from Core acceptance:
only `commit(scope, candidateRef, businessKey)` accepts its validated nonsecret
references into Core. Caller scope is the existing trusted local operator.
There is no HTTP, Gateway, Node or Internal API consumer.

The same SQL transaction verifies a pending, unrevoked synthetic Subject with
the exact original `core_pending_fixture` placeholder and issue/candidate auth
version. It writes the strict issue `ps_...` reference, status active, auth version
plus one and row version plus one, together with immutable binding and signature
evidence. Provider/subscription/Subject IDs cannot change. Legacy-shadow subjects
and User secrets cannot participate. No Usage/Terms/quota/legacy field changes.
Active here means Core committed the isolated binding, not Node admission.

Only one initial commit per Subject/issue/candidate is supported. Same key and
exact request replays the original bytes; a different key cannot consume it a
second time. Rotation, renewal, multiple generations and multiple audiences are
unsupported. The old candidate/issue is not rewritten. Its old auth version
naturally becomes stale after binding; its historical replay remains intact.

The common current boundary takes User, fixed Plugin, PluginBinding, exclusive
registration, lifecycle/revocation, original issue, source, Subject, Subscription,
current Terms/ordered periods, tariff, candidate and authorization record locks
in that order. It rejects outer transactions. Registration remains exclusive
through signing and commit. No Server lock follows source and no Provider IPC,
public issue/query/source/grant or nested public candidate call runs inside it.
Existing TermsTimeline and UsageTermsBoundary remain authoritative.

All candidate gates still apply: ban, plugin/current grants and script/profile,
exact registration/epoch/scope, no Provider revoke fact, owner/auth version,
effective Terms, finite known nonclosed period, exact quota, settled billable
below quota, canonical tariff/hash and current source/Core/receipt identity.
There is no quota reservation or protection against unreported pending usage.

The signature is a fixed local compact JWS using locked PHP-JWT 6.11.1 and
OpenSSL RS256 with exactly 3072-bit RSA. Header keys are exactly alg/typ/kid;
typ is `txboard-fixture-authorization+jws`. Payload version is
`credential-provider/local-authorization/1`, issuer
`txboard:local-synthetic-authority`, audience `txboard:offline-fixture-verifier`.
This separate key/trust is not APP_KEY, an Internal service proof or Provider
secret. No header URL/jwk, alternate algorithm or key type is accepted.

The exact payload binds original candidate/issue hashes and evidence, the initial
Core binding transition, actor scope, Provider generation, source/installation/
Core/receipt identity, policy/quota/current settled counter and finite UTC window.
Subject and Provider revocation fact domains are named explicitly; no Node-domain
revocation sequence or applied version is invented. All ID/version/byte values
are canonical decimal strings; UTC values are safe integers. It always carries
`admission_granted=false`, delivery and convergence `not_implemented`.

The nonsecret statement is stored in the independent
`tx_credential_provider_shadow_authorization` table with short unique/FK names
and restrictive history refs. Its public `asf_...` ref is not a Node Usage
`auth_snapshot_id`. It never writes `tx_auth_snapshot` or calls capability,
preventing automatic export to Node. Nonempty rollback is refused.

Trust is loaded only from a fixed server configuration path. It permits at most
two exact key entries, one current signer and an optional verify-only predecessor,
with fixed kid/epoch/SPKI digest, validity and revocation. Files must be regular,
not symlinks and bounded; private material stays in a disposable 0600 local file,
never SQL/DTO/log/exception arguments. Configuration, public/private mismatch,
missing schema, wrong grants or unknown history fail closed.

The full JWS has a 16KiB cap and canonical base64url segments. Bounded strict
JSON rejects duplicate and extra fields. Algorithms/keys are fixed independently
of untrusted headers. `issued_at/lease_expires_at` are this profile's explicit
UTC fields; JWT iat/nbf/exp cannot substitute for them. No JWT global clock/leeway
state is changed under Octane. Signature validity is separate from key trust,
the window and live Core eligibility. It is not an API/OAuth token.

After all locks, Core samples wall/monotonic time. Expiry is no later than the
original candidate, credential, Subscription, Terms, period, tariff and key ends,
still at most 60 seconds from original preparation. Signing/encoding/SQL work
is followed by a final elapsed/wall and identical trust check. A first-commit
time/config/signing/SQL failure rolls back both binding and new snapshot; no
partial evidence or signature is returned. SQL and external trust replacement
are not atomic; each current verification reloads trust. This is not a claim of
instant distributed revocation or bounded database cancellation.

`inspect` and same-key replay are pure history. They return original signed
bytes even after User deletion, disable/revoke, source rotation, retired keys
or expiry, without extending time or re-signing. They report current signature
trust as not evaluated. `verifySignature` checks fixed shape/domain/signature;
`verifyWindow` separately checks key trust and UTC eligibility. Neither authorizes
admission or asserts live business eligibility. An independent offline PHP
verifier uses only trusted public keys and no Core/Provider private material.

Only `checkCurrent` evaluates live metadata and the accepted version/ref
transition. Its local valid/expired/refused result always denies Node admission.
Observed expiry/regression or failed live/key gates become irreversible terminal
state committed by returning, not throwing. Unknown DB faults cannot guarantee
terminal persistence, but produce no successful authorization result.

Commit-before-response loss or SIGKILL recovers the original ref, bytes, binding
version and expiry under the original key, never a second activation. DB restore,
lost counters/key/source epochs and unobserved clock rollback require separately
verified recovery. No signature/hash is advertised as a restore or crypto fence.
History is retained; B4.3 uninstall/delete remains blocked while Node convergence
is unknown, even if private cleanup is complete.

The trusted PHP process and this same-OS fixture are not an adversarial sandbox.
Node service identity, Provider private delivery/parse, per-source revocation
mapping, session termination/clock enforcement, ACK/offline convergence, pending
traffic policy, production isolation/HA/restore and legacy migration remain open
gates. See [verification fixture](../../../api/tests/Fixtures/CredentialProviderAuthorization/README.md).
