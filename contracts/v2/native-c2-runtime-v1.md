# Native C2 runtime v1

Status: candidate implementation; exact-head remote acceptance is required.

This contract completes the first bounded native Subscription runtime for a
fresh installation. It does not migrate an existing legacy installation.
Core owns accounts, published purchases, Subscription/Terms/UsagePeriod,
authorization and settlement. Provider owns proxy credentials and their
rendering. Node enforces the same finite signed policy at I/O and metering.

The first runtime supports sing-box VLESS and VMess direct TCP. Unknown
transport, multiplexing, unsupported kernel/protocol and dynamic tariff
configuration refuse native activation or application. This is not a complete
protocol/platform matrix or the C3 money ledger/C4 production PKI delivery.

## Explicit activation and work-item admission

Initialization remains `core-installation/v1`, `native/initializing`, epoch 1.
It must pass the existing fresh-database check. Preparation creates published
offers, an operator-pinned Provider registration and explicitly provisioned
Nodes; it does not admit account business operations.

`core:installation preflight` validates the actual schema, immutable catalog
and Provider registration, signing identity equal to the installation UUID,
Node credentials/recipients and the supported connection/usage policy. It
returns an expected record version, a configuration digest and worker epoch 2.
It never accepts a caller assertion such as `tests_passed=true`.

Preflight and activation each call the actual Core-only mTLS
`POST /capabilities` outside any SQL transaction, then reacquire and recheck the
installation, registration and configuration. The closed response is
`credential-provider-capabilities/1`, with the matching request/provider/epoch,
`store_version=2`, operations `issue,query,revoke,rotate`, enabled
`node_delivery` and `subscription_render`, and the supported credential profiles.
The digest binds these stable capability facts, registration and configuration;
the request nonce is excluded. Disabled handlers or an old Provider refuse
activation. A stored same-input activation replay needs no new network call.

`core:installation activate --key=KEY --expected-version=1
--configuration-digest=HEX` is an operator action for this validated fresh
native installation. One transaction checks the expected state, records
immutable activation evidence and advances the existing authority record to
`native/open`, row version 2 and authority epoch 2. Same-key/same-input replay
returns that result; a changed key/digest refuses. Old epoch-1 workers must stop.
Processes explicitly use `CORE_INSTALLATION_MODE=native` and
`CORE_INSTALLATION_EPOCH=2`. Environment changes alone cannot activate,
downgrade, change configuration authority or reopen a legacy writer.

The runtime inventory is `native-core-runtime/1`. HTTP method/path, commands
and queue jobs are explicitly enumerated by `NativeRuntimeAdmission`. Unknown
business entry points refuse. Native admission does not weaken
`assertRuntime()` or `assertLegacyWriter()` for existing legacy consumers.
Every work item rechecks persistent installation/configuration authority;
requests and long-lived workers cannot cache an old epoch as authorization.
Native container startup skips the legacy settings/plugin updater.

Account creation stores identity with empty legacy proxy/link credential
columns and zero legacy entitlement/money fields. It does not generate an
authentication UUID, apply a legacy trial plan or enqueue legacy Node sync.
Login/session/password/preferences operations have native transaction admission.
Native account status mutation locks the account and uses expected banned
state; it stages durable credential synchronization with the committed change.
Existing legacy credential-reset and raw subscription-link rendering paths
remain unavailable under this runtime.

## Published catalog and purchase

The native `GET /txapi/plans` projection uses schema `native-plan-offers/v1`.
It returns verified immutable offers and explicit mutable sale availability;
the purchasable identifier is `offer_id`. Later mutable Plan edits and legacy
User counters cannot change this projection. Purchase/fulfillment retain the
C2 account/slot locks, frozen v2 purchase evidence, capacity arbitration and
atomic order completion. This runtime exposes only zero-price offers. A known
nonzero `offer_id` is rejected before an order or capacity reservation commits,
and checkout independently rejects a nonzero native order before selecting a
payment provider or starting a charge. Balance, discounts and coupons cannot
turn a paid offer into an admitted purchase. Payment webhooks remain outside
the runtime inventory. The real free-order API path is included in C2
acceptance; C3 must complete payment callbacks and the money/attempt/refund
ledger before paid native purchases can open.

Native administrator identity operations are:

- `POST /txapi/admin/{admin_path}/native/accounts`, exact email/password.
- `PATCH /txapi/admin/{admin_path}/native/accounts/{id}/status`, exact
  `banned` and `expected_banned` booleans; protected administrators/staff refuse.

They retain AdminPath, administrator authorization, throttling and redacted
audit middleware. They do not accept roles, balance or legacy entitlements.

## Credential generations and durable synchronization

The existing `credential-provider/1` control envelope adds `rotate`. Input is
the original issue input plus old `credential_ref`,
`expected_credential_generation`, and `rotation_mode="immediate"`. Terms and
the expected Subject version identify the new binding. Subscription, Subject,
Provider and credential profile cannot be changed.

The result is the original closed issue result for the new generation plus
`previous_credential_ref`, `previous_generation`, `previous_valid_until` and
`rotation_mode="immediate"`. Generation increases by exactly one; the old
generation is permanently retired at issuance. Same operation/key replay
returns the historical non-secret result. No overlap mode is advertised.
An expired credential may rotate while the current Subscription is authorized.

Core persists intention and fences new grants before the network request.
Unknown results query the same operation before replay; they do not generate
another key/generation. Finalization rechecks current entitlement and ownership.
An unusable late success receives durable compensating Subject revocation.
`current_credential_operation_ref` references the complete current ownership
tuple; historical receipts remain immutable and independently verified.

Provider store version 2 retains generation evidence and retired history,
atomically upgrades intact old evidence and prevents an old writer opening the
new store. Metadata/material/render endpoints accept only the current active
generation. Historical operation query is not current authorization.

Fulfillment, renewal, reset, suspension, revocation and account status changes
stage synchronization in a SQL outbox in the same business transaction.
`core:native-work` recovers pending work and bounded credential expiry scans.
Provider failure or Redis failure cannot erase the work. Synchronization only
rotates stale/expiring bindings; repeated work does not create extra generations.
Resuming after permanent revocation creates a new Subject, never revives a
tombstone. Rotation does not reset Subscription consumption.

## One signed authorization for access and billing

The new policy is `native-provider-authorization/v2`; v1 remains compatible
for its existing bounded, non-ready C2-C2 consumer.
The v2 payload adds exactly `usage_v2` to the original payload plus Provider.
It is the complete existing UsageV2 Session:

```text
protocol_version: 2
core_instance_id, node_id, source_epoch, receipt_generation
retention_seconds, max_late_report_seconds, receipt_retention_seconds
issued_at
grants: ordered objects:
  user_id, access_subject_id, auth_snapshot_id, tariff_revision_id
  terms_id, usage_period_id, issued_at, lease_expires_at, revocation_version
```

Decimal IDs/generations remain canonical strings. User IDs correspond to the
signed user list; each grant has the same Subject and effective Terms as the
same-position Provider binding and the exact finite issuance/expiry window.
The Session Core and Node must match the enclosing signed payload. Empty
snapshots carry empty users/subjects/grants and close access.

Core creates immutable source/tariff/auth-snapshot evidence in the final
account-first transaction and binds it to the exact signed payload digest.
Provider still owns only material; it cannot alter the billing owner.
Node cannot take billing mappings from an independent current handshake.
Wrong policy, target, revision, profile or grant mapping refuses before access.

The Core assigns a stable source per Node; restart does not relabel historical
spool data. Tariff is frozen with each grant. A reset closes the original period
and creates a new period; a renewal preserves consumption unless its frozen
purchase explicitly resets it. An unbounded period remains unbounded while
each access/collection grant remains finite.

## Durable collection and settlement

Native Node mode requires `authorization.policy_version` v2 and explicit
`usage_v2.enabled=true`, `isolated_copy=false`, private absolute state/spool
directories and HTTPS Core/Provider trust. Legacy billing is disabled.
No native production claim is made for the old isolated collector prototype.

Native collection persists I/O intent before execution, and exact completed
bytes after completion. Refresh/rotation/configuration/period handoff stops
old I/O, drains/seals it against the original Session and persists its spool
before installing the new collector. Completed evidence is not relabeled to a
new revision/period. Crash or unresolved I/O is retained as persistent
quarantine, never silently converted to zero or a successful receipt.
Durable handoff checkpoints reclaim covered journal records with bounded
storage rather than exhausting the prototype's 128-record lifetime.
Completed operations may be durably aggregated only within their original
collector, user, direction and grant, preserving the earliest start, latest
completion, exact accumulated bytes and request bounds, and operation count.
Pending operations retain individual intents. A waiting TCP Read cannot make
completed writes consume an unbounded journal. Batch intervals still cover
the full original observation window; aggregation never moves bytes across
authorization or Period boundaries.

An authenticated Core may refuse a newly submitted historical batch with the
specific `USAGE_NATIVE_AUTHORIZATION_CUTOFF` code after verifying its original
ownership/proof/lease and establishing a later effective cutoff. Native Node
retains that exact batch and Session in local quarantine without a receipt or
settlement claim. It does not change the batch ID, drop the bytes or charge a
new period. A refusal affecting the active Session closes its I/O and requires
a newer signed grant before reopening. Authentication, scope, digest, corrupt
proof and missing previously confirmed receipts remain terminal failures.

The existing authenticated `/txapi/node/v2/usage/submit` and `batch/get`
perform current-read native ownership checks and retain the same batch ID,
digest and historical bindings. Revocation or Provider unavailability does
not discard provable pre-cutoff traffic. Core verifies frozen grant/tariff
hashes and period/Terms coverage before acknowledging durable receipt.

`core:native-work` scans the SQL inbox, settles with integer tariff arithmetic,
updates the original Period atomically and retries SQL notification outbox
work. Quota exhaustion prevents new grants and causes a full refresh/finite
expiry close. A Provider receipt is not a Node application ACK; a usage receipt
is not a credential delivery receipt. Redis is only a delivery/wakeup aid.

## Client rendering without Core material access

`POST /txapi/me/subscription-deliveries` requires a current user session and
exact `access_subject_id`, `recipient_public_key` and optional fixed
`format_id="native-connection-bundle/v1"`. The client generates an ephemeral
Ed25519 key outside Core. Core verifies current ownership, group/node scope,
current generation and metadata; after the external metadata call it locks
and rechecks authority, then signs at most 30 seconds of delivery policy.

The separate policy/audience are `native-provider-subscription/v1` and
`tx-subscription-client-v1`. The closed payload binds Core/key identity,
grant ID/time, Provider identity/epoch, recipient public key, format, user,
complete metadata Subject, commitment/credential expiry and ordered public
connection DTOs. Connection DTOs include Node/Machine, name, host, port,
VLESS/VMess TCP and optional validated SNI/ALPN. No private TLS key, arbitrary
callback, transport extension or credential UUID enters the Core grant.

Provider runs a separate public TLS listener using `--subscription-listen`,
`--subscription-tls-cert` and `--subscription-tls-key`. This does not relax
the control/Node listener's TLS 1.3 mTLS or disjoint fingerprint roles.
Public `POST /subscription-render` requires exactly
`{contract_version:"credential-render/1",request_id,authorization,proof}`.
The recipient signs these exact UTF-8/ASCII bytes with its private key:

```text
native-provider-subscription-request/v1\n
<request_id>\n
<sha256 of exact signed payload bytes, lowercase hex>\n
```

There is one newline after each line, with no blank lines. Provider verifies
Core signature, recipient proof, current generation and finite deadline,
then sends the JSON connection bundle directly to that recipient. Core's
grant alone cannot retrieve material. Responses are bounded and `no-store`;
request bodies, proofs and material are not logged.
Core remains the trusted authorization signer: this proof prevents redemption
of an existing grant without its recipient key, and does not isolate material
from a malicious holder of Core's signing key who can authorize a new recipient.

The reference consumer `scripts/native-subscription-client.mjs` pins both
origins and Core trust, generates the private key locally, verifies the
signed commitment and writes the bundle to a new private file. No frontend
is required. Long-lived auto-refresh subscription URLs and their independent
link lifecycle remain C4 work; the short delivery grant is not such a URL.

## Acceptance and remaining release gates

Remote acceptance must use actual production migrations, persistent
installation activation and normal authenticated HTTP/queue/worker entry
points, a separate Provider and real Node traffic. No substituted installation
admission or fabricated active Subject counts as this C2 acceptance.
Verify initial purchase, rendered client material, refresh, renewal/reset,
rotation, revoke/quota, restart and original-period replay/settlement.

Production certificate management/overlap rotation, hardware rollback
protection, legacy migration, complete protocol/UDP/HA matrices, C3 finance,
C4 formal Internal API and C5 installation/release remain separate gates.
Candidate push, CI success, approval, merge and image publication are distinct.
