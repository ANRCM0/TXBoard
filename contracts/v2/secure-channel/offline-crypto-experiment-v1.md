# SC-v2 offline crypto experiment v1

Status: isolated SDK experiment at the explicit
`@txboard/theme-sdk/experimental/sc-v2-crypto` subpath. It has no Gateway/Core
HTTP or business consumer. The [SC-v2 target gates](README.md) remain open.

The [implementation](../../../gateway/packages/theme-sdk/src/experimental/sc-v2-crypto.ts)
defines the candidate framing and limits. Locked HPKE core 1.9.0/common
1.10.1 uses base P-256/HKDF-SHA256/AES-256-GCM for the request. Separate exporter
labels derive a 32-byte response key and 12-byte nonce for WebCrypto AES-GCM.
Request metadata, ciphertext digest, response kind and status are bound to the
candidate bytes. This does not freeze the production protocol.

The API accepts opaque plaintext bytes, 0–3056 bytes. It snapshots caller records
and byte views before awaits, refuses accessors/shared or detached buffers, and
checks the local lifetime at admission and completion. Response closures claim
their one use before caller-property inspection or async work, then release the
closure's context holder. Invalid input, expiry and authentication failure also
consume that use. Errors have a fixed nonsecret shape.

The recipient receives an explicit trusted ECDH key pair, including a permitted
nonextractable private key. This avoids a reproduced pinned-library private-only
odd-y fallback problem; it does not establish discovery trust or actor identity.
Unused closures and engine/library secrets have no guaranteed destruction or
zeroization. Local wall-clock rollback remains a transport concern.

## Shared ownership boundary

One use is **per closure**, not per authenticated request digest. Opening an
envelope again can derive the same response key and nonce, even in the same JS
realm. A second owner must not encrypt a different response or error. The fixture
demonstrates this boundary without performing the unsafe second encryption.

Before any network or business adoption, one authenticated digest must acquire a
shared atomic owner before response encryption, across workers, retries, errors
and crashes. Lost ownership cannot restore or reconstruct response material.
Key discovery/rotation/revocation, actor policy, encrypted error ownership,
business-idempotent retries and independent protocol review also remain gates.
The experiment implements no Redis ownership or replay policy.

## Verification boundary

The [historical verification report](../../../gateway/docs/sc-v2-crypto-verification.json)
records fixed-source Node/Chromium vectors and their original environment.
It is not current-head acceptance, a network business operation or a formal
cryptographic audit. The deterministic reference uses the pinned library and
public fixture scalars, not an independent cryptographic implementation.
