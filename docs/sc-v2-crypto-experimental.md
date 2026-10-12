# Offline SC-v2 response crypto experiment

Status: B6.2 experiment, available only as
`@txboard/theme-sdk/experimental/sc-v2-crypto`. There is no Gateway/Core import,
encrypted HTTP operation, discovery consumer, actor authorization or production
approval. The [SC-v2 target](https://github.com/ANRCM0/TXBoard/blob/main/contracts/v2/secure-channel/README.md)
still requires all transport, replay, key-trust and independent review gates.
Existing v1 SDK and routes retain their current behavior.

The primitive uses locked `@hpke/core` 1.9.0, resolved `@hpke/common` 1.10.1,
P-256/HKDF-SHA256/AES-256-GCM in base mode. Every create call allocates a fresh HPKE
sender. The SDK exposes no ephemeral key, exporter, context or nonce override.
HPKE info is ASCII `txboard.sc-v2-crypto-experiment/1/request`. Request AAD and
SHA-256 request digest reuse the unchanged
[B6.1 metadata framing](sc-v2-codec-experimental.md).

HPKE remains unidirectional. Request sender seals and request recipient opens;
neither role uses its HPKE context in the other direction. A response uses
`Export(responseExporterContext(digest,'key'),32)` and
`Export(responseExporterContext(digest,'nonce'),12)`, then browser WebCrypto
AES-GCM with a nonextractable key and explicit 128-bit tag. Export contexts have
separate labels, each below the experiment's 128-byte framing limit. This follows
the interface and example in [RFC9180 sections 5.2, 5.3 and 9.8](https://www.rfc-editor.org/rfc/rfc9180.html#section-9.8);
the RFC does not approve this application's full transport.

Request and response plaintext are opaque bytes, 0–3056 bytes. Ciphertext is
16–3072 bytes including the tag. The primitive does not parse JSON, reinterpret
numbers or normalize Unicode. Response framing is exactly
`{request_digest,kind,status,ct}`; response AAD binds the full original metadata,
request digest and result/error status. Byte errors and success use the same
encrypted primitive; invalid input, crypto failure or expiry yields only
`SC_EXPERIMENT_INVALID`, never plaintext payload or library internals.

The request API is `createExperimentalRequest(publicKey,metadata,bytes)`. The
recipient API is `openExperimentalRequest(keyPair,envelope)`, requiring an
explicit trusted ECDH/P-256 key pair, with nonextractable private key permitted.
The pinned library's private-only fallback may recover the opposite y-parity
for a valid nonextractable odd-y key. Passing both keys avoids that problem
without exporting a private key. The caller must supply a matching trusted pair;
this is not a discovery authenticity check or an authorization decision.

Metadata/envelope/binding records must have only the exact enumerable own data
properties and a plain or null prototype. Getters, symbols and extra fields are
refused. Values are copied before the first await. Typed-array inputs use intrinsic
buffer/offset/length access and independent byte copies, including Node Buffer
subviews. Shared or detached buffers are refused. Fixed suite labels are frozen.

Each returned response closure can be consumed once. It marks spent before any
input inspection or await, moves its context reference to the current invocation,
and releases the closure's reference immediately. Invalid input, failed tags and
expiry also spend it; there is no crypto retry. Calls check the local clock at
creation, response invocation and crypto completion. The allowed window is the
codec's ≤60 seconds, 5 seconds future skew and strict expiry. Wall-clock rollback
and shared lifetime ownership remain transport gates.

Internal byte copies of plaintext, exported raw key and response nonce are cleared
where possible. Returned decrypted bytes belong to the caller. JavaScript and
library-held keys are not guaranteed to be erased by the runtime; no secret
zeroization claim is made.

## Shared ownership is a blocking transport gate

The spent flag is **per closure only**. Opening one envelope twice, even in one
JS realm, allocates two recipient closures with the same response key/nonce.
Changing status, kind or AAD cannot make two differing encryptions safe. The
local harness uses one response owner per request; its duplicate-open test spends
the second owner with invalid input and never encrypts a differing response.

Every network/business consumer is blocked until an authenticated request digest
has one atomically claimed shared owner **before response encryption begins**,
including retries, multiple workers, errors and crashes. A replay loser cannot
encrypt an independent replay/error response with the winner's material. Material
must not be restored or reconstructed after a crash. This experiment provides no
Redis gate and is unsuitable for transport adoption by itself.

Well-formed domain/operation/origin values are authenticated metadata, not policy.
Tampering them on an existing ciphertext fails authentication; creating a new
well-formed different operation remains possible. Base HPKE is not actor identity,
a signature or forward secrecy against long-term recipient compromise.
[RFC9180 replay and secrecy limits](https://www.rfc-editor.org/rfc/rfc9180.html#section-9.7.3)

## Verification

Tests cover empty/Chinese/non-BMP/maximum bytes, request and response tampering,
wrong keys and curves, response swapping, concurrent closures, independent request
contexts, synchronous reentry, mutable inputs, expiry across awaited crypto, byte
subviews and fixed error behavior. A deterministic locked-library reference uses
already-public fixture scalars, not production keys or an independent crypto
implementation. Metadata Python vectors remain unchanged.

Actual Chromium executes the reference and odd-y fixture, then exchanges four
live requests/responses with Node in both role directions. A loopback server
serves only static JavaScript to the test browser; encrypted data is passed by
the local test runner, not an HTTP business handler.

```sh
npm run check
node scripts/test-sc-v2-codec-browser.mjs
node scripts/test-sc-v2-crypto-browser.mjs
```

Reference regeneration is manual and reviewable:
`npm run build --workspace @txboard/theme-sdk` then
`node scripts/generate-sc-v2-crypto-vector.mjs`. Its deterministic ephemeral key
override exists only in that fixture script. The SDK does not offer it.
Fixed committed Gateway `e8875e7` plus this slice passed Linux Node 22.23.2
typecheck/build, 467 Gateway tests and 68 SDK tests (28 focused crypto tests).
Actual Chromium 155 passed the deterministic request/response, odd-y key, three
rejections and four Node/browser role exchanges; existing metadata vectors also
passed unchanged. Exact source and limits are in the
[verification report](sc-v2-crypto-verification.json). The
[design review](sc-v2-crypto-design-review.md) and
[implementation review](sc-v2-crypto-implementation-review.md) record reproduced
library behavior, the context-retention fix and the permitted offline scope.
