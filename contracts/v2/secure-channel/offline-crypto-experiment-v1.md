# SC-v2 offline crypto experiment v1

Status: B6.2 isolated SDK experiment. Gateway commit
`2a5d2ee2dd01a7568a0b121236b158fa5339fd8e` adds the explicit
`@txboard/theme-sdk/experimental/sc-v2-crypto` subpath. It has no Gateway/Core
HTTP or business consumer. The [SC-v2 target gates](README.md) remain open.

The [implementation contract](https://github.com/ANRCM0/TXBoard-Gateway/blob/2a5d2ee2dd01a7568a0b121236b158fa5339fd8e/docs/sc-v2-crypto-experimental.md)
records the exact candidate framing and limits. Locked HPKE core 1.9.0/common
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

## Verification

The fixed Linux base `e8875e7` plus the exact experiment passed 467 Gateway tests,
68 SDK tests including 28 crypto tests, and both workspace typecheck/build.
Actual Chromium 155 passed deterministic request/response and odd-y fixtures,
three rejection checks and four live Node/browser exchanges in both directions,
including empty, Chinese and maximum-size bytes. Existing metadata vectors
passed unchanged. A static loopback browser harness is not an encrypted HTTP
business operation.

The [verification report](https://github.com/ANRCM0/TXBoard-Gateway/blob/2a5d2ee2dd01a7568a0b121236b158fa5339fd8e/docs/sc-v2-crypto-verification.json)
distinguishes fixed-gate bytes, later documentation edits and LF/CRLF lockfile
bytes. The [design review](https://github.com/ANRCM0/TXBoard-Gateway/blob/2a5d2ee2dd01a7568a0b121236b158fa5339fd8e/docs/sc-v2-crypto-design-review.md)
and [implementation review](https://github.com/ANRCM0/TXBoard-Gateway/blob/2a5d2ee2dd01a7568a0b121236b158fa5339fd8e/docs/sc-v2-crypto-implementation-review.md)
are engineering reviews of this offline scope, not a formal cryptographic audit.
The deterministic reference uses the pinned library and public fixture scalars;
it is not an independent cryptographic implementation.
