# Native credential issuance v1

This C2-C1 implementation batch provides a real, separately executed Provider
and durable Core issue/query/subject-revoke operations. It does not activate
native installations or deliver material to Node or subscription clients.
The existing fixture registrations and fixture operations remain isolated.
Rotation, render delivery, Node consumption and the ready-state transition
are subsequent integration work; they are not advertised as capabilities here.

## Runtime and trust

The Provider is a separate Go executable in `node/cmd/credential-provider`.
It owns a private, transactionally persisted credential store. It generates
real random UUID credentials for `vless-uuid/v1` and `vmess-uuid/v1`, but its
Core control endpoint returns only non-secret references and metadata.
No endpoint in this batch exports credential material. Its private database,
keys and credential values never enter Core's database, logs or API output.
The runtime is trusted code, not a plugin sandbox.

`POST /operations` requires TLS 1.3, a verified client certificate and an
explicit allow-listed SHA-256 fingerprint of that client certificate.
Server identity is verified through the configured CA and hostname. Provider
ID and instance epoch are fixed at process startup and persisted with its
store; restarting an existing store with different identity must fail.
Core selects endpoint and client TLS files from operator-owned configuration,
never from an HTTP request or an operation payload. Redirects are refused.
All requests and responses are bounded to 64 KiB; request deadlines are at
most 30 seconds ahead. The runtime offers only issue, query and revoke.

## Closed wire envelope

All objects have exactly the fields specified here. Unknown fields, duplicate
JSON keys, noncanonical decimal strings and mismatched identities refuse.
IDs, versions and generation numbers are decimal strings; time fields are
integer Unix seconds. All text inputs below are restricted to ASCII tokens.

Request fields:

```
contract_version: "credential-provider/1"
operation: "issue" | "query" | "revoke"
request_id: ASCII token, 1..96 bytes
operation_id: "cp_" followed by 32 lowercase hex characters
provider_id: [a-z][a-z0-9_-]{0,63}
provider_instance_epoch: positive decimal string <= 9223372036854775807
idempotency_key: ASCII [A-Za-z0-9._~:-], 1..128 bytes
input_hash: 64 lowercase hex characters
deadline_at: integer
input: operation-specific object
```

`input_hash` is SHA-256 of canonical JSON containing exactly
`{operation, provider_id, provider_instance_epoch, input}`. Canonical JSON
sorts object keys recursively, preserves array order and scalar types, and
contains no insignificant whitespace or escaped slashes. Restricting strings
to the stated ASCII token grammar avoids cross-language Unicode ambiguity.
`request_id` and deadline can change on retry; logical identity cannot.

Issue input:

```
installation_id: lowercase UUID
access_subject_id, subscription_id, terms_id: positive decimal strings
provider_subject_ref: "subject_" followed by 32 lowercase hex characters
expected_subject_version: positive decimal string
credential_profile_id: "vless-uuid/v1" | "vmess-uuid/v1"
not_before_at, credential_expires_at: integer
```

The requested lifetime is positive and at most 31 days. Core also caps it by
the subscription expiry. Core preallocates the random non-secret subject
reference, and the Provider binds it immutably to installation, Subject,
subscription and profile. The reference cannot authenticate or retrieve a
credential. Only one generation is issued in this batch. Reissuing with a
different logical operation refuses; querying/replaying the original returns
the same metadata. Revoked subject references remain tombstones permanently.

Subject-revoke input:

```
installation_id: lowercase UUID
access_subject_id: positive decimal string
provider_subject_ref: "subject_" followed by 32 lowercase hex characters
revocation_fact_version: positive decimal string
effective_at: integer
reason_code: "MANUAL" | "SUBSCRIPTION_CHANGED" | "ACCOUNT_BANNED"
```

Revocation is valid even if the issue outcome is unknown. A tombstone created
before issue prevents that issue from creating a usable credential. A lower
fact version cannot replace a higher one. The Provider result proves private
Provider revocation only; it does not prove Node convergence.

Query input has exactly `operation_id`, naming the original operation.
Its envelope uses that same operation ID and the original idempotency key.
Query is read-only and does not persist a new operation or extend a lifetime.

Response fields:

```
contract_version: "credential-provider/1"
request_id, operation_id, provider_id, provider_instance_epoch: echo request
status: "succeeded" | "unknown" | "rejected"
result: operation-specific object or null
error: null or {code: ASCII uppercase token, retryable: boolean}
```

Successful issue result has exactly:

```
access_subject_id, provider_subject_ref, credential_profile_id
credential_ref: "credential_" followed by 32 lowercase hex characters
credential_generation: "1"
issued_at, not_before_at, credential_expires_at: integer
```

Successful revoke result has exactly:

```
access_subject_id, provider_subject_ref
accepted_revocation_fact_version: decimal string
provider_revoked_at: integer
```

Successful query result has exactly `{operation, input_hash, result}` for the
original successful issue/revoke. A missing operation returns status unknown,
result null and error `{code:"OPERATION_UNKNOWN",retryable:true}`. Querying a
stored rejection returns that rejection. Mutations atomically persist their
input hash, non-secret result and private state. Replays return the original
receipt, even after later revocation; a receipt is never current authorization.
Conflicting operation IDs or idempotency keys return IDEMPOTENCY_CONFLICT.
Unreadable or damaged private storage returns status unknown with
`{code:"STORE_UNAVAILABLE",retryable:true}` for any operation. It never means
that an operation is missing and must not trigger a fresh logical issuance.
Stored receipts are validated and reconstructed as closed non-secret DTOs
before replay; raw persisted JSON is never forwarded.

## Core persistence and recovery

Core uses canonical `tx_credential_provider_registration` and
`tx_credential_provider_operation` tables, and versioned native columns on the
existing `tx_access_subject`. Shadow rows are not promoted.
Registrations bind an installation, Provider ID/epoch and an operator config
identity. Preparation registration is permitted under the existing native
initializing admission; issuing/revoking require native domain admission.

Core records the operation and pending Subject before any network call.
Network calls never run inside a SQL transaction. Retrying an uncertain result
first queries the original operation, then only resends the identical logical
request if the Provider reports it missing. Malformed responses, TLS failures,
timeouts or unexpected metadata preserve recoverable unknown state and use
bounded diagnostics. Core never records a raw response body or transport error.

Finalization rechecks installation, ownership and current subscription/account
state under the C2 aggregate lock order. A late issue receipt cannot reactivate
a locally revoked Subject, a banned account or superseded/expired entitlement.
An orphan issue must be retained for subject-scope revocation and reconciliation.
No credentials become Node authority merely by having an issue receipt.
Formal APIs must derive account ownership from authenticated identity, require
business keys and expected versions, and expose only bounded public state.
