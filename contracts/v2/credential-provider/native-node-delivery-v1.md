# Native Provider to Node delivery v1

C2-C2 adds controlled material consumption to C2-C1. Native installation
activation, subscription rendering, credential rotation and native traffic
settlement are separate gates. The existing initialization refusal stays.

## Authority and transport

Core remains the entitlement authority and never receives plaintext UUIDs.
It obtains non-secret SHA-256 commitments from its registered Provider,
rechecks current account/Subscription/Subject authority, and signs a complete
Node snapshot. The UUID has cryptographically random UUIDv4 entropy; its
commitment is not an authentication credential.

Node verifies the pinned Core signature and its Node/Machine target before
requesting material directly from the Provider. Both new Provider endpoints
require TLS 1.3 and verified client certificates, have a 1 MiB body limit,
and accept at most 128 subjects. They never follow caller-selected URLs.
Core and Node client fingerprint allowlists are disjoint. Control clients
cannot use the material endpoint; Node clients cannot use control or metadata.
The original `/operations` contract and its 64 KiB limit are unchanged.

Only VLESS and VMess are supported, one configured Provider per Node snapshot.
Node pins the Provider endpoint, identity/epoch, CA and client identity through
operator configuration. It neither accepts these values from a response nor
uses a legacy User UUID fallback. Empty complete snapshots close users.

## Non-secret metadata

`POST /credential-metadata` is restricted to the existing Core control identity.
The exact request object is:

```text
contract_version: "credential-delivery/1"
request_id: ASCII [A-Za-z0-9._~:-], 1..96 bytes
provider_id, provider_instance_epoch: as native-issuance-v1
subjects: ordered array of exact objects:
  installation_id, access_subject_id, subscription_id, terms_id
  provider_subject_ref, credential_ref, credential_generation
  credential_profile_id
```

IDs and generations are positive canonical decimal strings. Reference and
profile grammars match native-issuance-v1. The exact successful response is:

```text
contract_version, request_id, provider_id, provider_instance_epoch
credentials: same order as request, exact objects:
  access_subject_id, credential_ref, credential_generation
  credential_sha256: 64 lowercase hex characters
  credential_expires_at: integer Unix seconds
```

All requested bindings must match intact, unrevoked private records and their
issue evidence. Partial success is forbidden. This operation is read-only.
Unknown, expired, revoked or corrupt state returns a bounded non-secret error.

## Core signed snapshot

The existing Ed25519 envelope `{payload,signature}` and per-Node revision
ledger are reused, with a distinct policy `native-provider-authorization/v1`.
The legacy policy must reject the new shape, and vice versa.

The signed payload contains exactly the existing 14 fields from
`native-authorization-lease-v1`: protocol_version, audience, policy_version,
core_instance_id, node_id, machine_id, revision, key_id, key_epoch, issued_at,
not_before, expires_at, complete, users; plus one `provider` object:

```text
installation_id: lowercase UUID, equal to core_instance_id
provider_id, provider_instance_epoch
recipient_sha256: SHA-256 of the authorized Node client leaf certificate DER
credential_profile_id: "vless-uuid/v1" | "vmess-uuid/v1"
subjects: ordered array of exact objects:
  user_id: positive integer <= 2147483647
  access_subject_id, subscription_id, terms_id
  provider_subject_ref, credential_ref, credential_generation
  credential_expires_at: integer Unix seconds
```

`users` uses the existing Node User DTO, sorted strictly by user ID, but `uuid`
is `sha256:` followed by the credential commitment. No UUID material is signed
by or returned from Core. Each user has exactly one subject at the same array
position. Group/profile filtering uses current native entitlement and the
locked target Node. Repeated UUID commitments or Subject references refuse.

The finite snapshot retains the existing audience, target, trust and revision
rules. Expiry is at most 120 seconds and capped by every included credential,
Subscription expiry and next reset boundary. Issuance never runs a Provider
network request inside a SQL transaction. Membership, ban, version, quota,
receipt binding and target identity are rechecked after metadata retrieval.
Unknown or damaged evidence cannot become an empty authorized response.

## Material delivery

`POST /credentials` requires a separately allowed Node client identity and
the exact request `{contract_version,request_id,authorization}`, with version
`credential-delivery/1` and the signed Core envelope above. The Provider pins
one operator-provisioned Core Ed25519 trust tuple
`{protocol_version,core_instance_id,key_id,key_epoch,algorithm,public_key}`.
No dynamic key URL, trust-on-first-use or mutation of trust via a request.

The Provider verifies the full closed snapshot, signature, identity/epoch,
current finite window, and `recipient_sha256` against the TLS peer. Each
Subject must still be live with exact issuance bindings; its material hash
must match the Core commitment. Revoked/expired/missing/corrupt records refuse
the entire request. The Provider checks expiry again before releasing data.
The Provider permits at most 30 seconds of issuance/not-before clock skew;
expiry never has a grace period. Node enforces its own pinned skew bound.

The successful response has exactly:

```text
contract_version: "credential-delivery/1"
request_id
authorization_digest: lowercase SHA-256 of the exact signed payload bytes
credentials: ordered array of exact objects:
  access_subject_id, credential_ref, credential_generation
  uuid: canonical lowercase UUIDv4
```

Failures use non-200 status and exactly `{error_code: UPPERCASE_TOKEN}`; no
partial credentials, raw error, certificate details or stored JSON. Secret
responses have `Cache-Control: no-store` and are never persisted or logged by
the delivery client. Provider backups remain secret.

Node validates all returned identities and hashes, combines only UUIDs with
the signed non-secret user policy, and re-verifies the Core envelope at the
existing application boundary. Persistent authorization state contains only
the non-secret payload digest and revision, never UUIDs. Cached metadata or
304 cannot renew authority; failed delivery does not extend an existing
lease. Expiry and restart use the existing fail-closed watchdog and online
refresh rules. WebSocket updates remain hints for a complete signed refresh.

The Linux Node read-only capability `node_provider_credential_delivery_v1`
and the native Core handshake describe this material consumer only. They do
not announce production readiness, subscription rendering or traffic billing.
Old traffic report writes are refused under native authority until the native
usage integration is complete; this batch must not charge legacy User fields.
