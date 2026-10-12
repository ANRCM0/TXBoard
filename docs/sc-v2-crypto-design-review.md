# B6.2 response crypto experiment: implementation review

Date: 2026-10-12. Scope: the local SDK experimental subpath proposal only.
This is an engineering design and pinned implementation review, not a formal
cryptographic audit or production SC-v2 approval. No runtime source was edited.

Reviewed initial draft SHA256:
`73727a4707e2ecf0f18377fc722bba3c864b45fbd3f6d6b8c0538e05d853e4af`.
Reviewed amended draft SHA256:
`16cef6c8c3c0a1d4c996f37daab1f6c6a15c2c16a9f804df6c1d74e7ef460d7f`.
The conditions below incorporate the root task's accepted amendments. Keep the
remaining wording corrections below when producing the committed design.

## Decision and permitted scope

The amended direction can proceed as an offline interoperability experiment,
subject to the implementation and fixture conditions in this review. Permit
only an explicit SDK experimental subpath, fresh fixture keys, Node and actual
Chromium execution, byte equality tests and adversarial local tests. Keep the
existing B6.1 metadata vectors unchanged. Do not import this experiment from the
normal SDK entry point or any existing Gateway/Core route.

No HTTP listener, Redis admission implementation, discovery consumer, actor
authorization, business invocation, production key, transport fallback or
production release is authorized by this review. The repeated-recipient-context
hazard remains deliberately unsolved by this isolated slice; it blocks every
network or business consumer, even when an experimental label is present.

## Cryptographic direction and binding

The fixed P-256/HKDF-SHA256/AES-256-GCM base-mode suite, fresh sender context,
single HPKE request seal/open and separate exported response material follow the
interfaces described in [RFC 9180 sections 5.2 and 5.3](https://www.rfc-editor.org/rfc/rfc9180.html#section-5.2).
The response exporter direction is specifically illustrated in
[section 9.8](https://www.rfc-editor.org/rfc/rfc9180.html#section-9.8).
Neither HPKE role is reversed: sender never calls HPKE open, recipient never
calls HPKE seal.

Keep the fixed experiment info string, existing request/response tuple labels,
request digest over full AAD/enc/ciphertext, distinct exporter key/nonce contexts,
32-byte response key and 12-byte response nonce. Response AES-GCM uses exactly
128 tag bits and the committed response AAD. Import the response key as
nonextractable, with only `encrypt` on the recipient side and only `decrypt` on
the sender side. WebCrypto defines AES-GCM output as ciphertext followed by the
tag; tag size must be explicit in this experiment.
[WebCrypto AES-GCM operations](https://www.w3.org/TR/WebCryptoAPI/#aes-gcm-operations)

Base-mode HPKE is not actor authorization, a signature, discovery authentication
or a general authenticated bidirectional transport. In particular, long-term
recipient key compromise permits recovery of past HPKE requests and the response
export material; do not claim forward secrecy against recipient compromise.
[RFC 9180 sections 9.7.3 and 9.7.4](https://www.rfc-editor.org/rfc/rfc9180.html#section-9.7.3)

## Reproduced blocker: private-only nonextractable P-256 key

The initial `openExperimentalRequest(privateKey, ...)` API is unsuitable for all
valid WebCrypto keys in the resolved dependency implementation. Its DHKEM decap
derives the recipient public key when given a private key alone. When private
JWK export is unavailable, the EC fallback recovers x and selects an even y.
A valid odd-y public key can therefore be replaced by its opposite point in the
KEM context, resulting in an HPKE authentication failure.

Actual Windows Node reproduction with core 1.9.0/common 1.10.1:

```text
fixed valid odd-y P256 nonextractable private only: rejected OpenError
same fixed valid key supplied as explicit pair: accepted
```

Use the amended trusted `CryptoKeyPair` API and pass the explicit pair to
`createRecipientContext({recipientKey: keyPair, enc, info})`. Permit a
nonextractable private key. Require an ECDH/P-256 private key with `deriveBits`
usage and the corresponding explicit ECDH/P-256 public key that the library can
serialize. Never make a private key extractable to work around this behavior.
Wrong-key, wrong-curve, wrong-type, wrong-usage and mismatched-pair fixtures must
fail with the fixed public error. Include both ordinary random keys and this
deterministic odd-y case in actual Node/Chromium verification. A successful
fixture does not constitute a trust or key-discovery decision.

Reproduction, using only a public test scalar and standard WebCrypto/HPKE APIs:

```js
import { CipherSuite, DhkemP256HkdfSha256, HkdfSha256, Aes256Gcm } from '@hpke/core'
const b64 = hex => Buffer.from(hex, 'hex').toString('base64url')
const x = '6b17d1f2e12c4247f8bce6e563a440f277037d812deb33a0f4a13945d898c296'
const y = '4fe342e2fe1a7f9b8ee7eb4a7c0f9e162bce33576b315ececbb6406837bf51f5'
const jwk = { kty: 'EC', crv: 'P-256', x: b64(x), y: b64(y),
  d: b64('0'.repeat(63) + '1') } // Public test fixture d=1; never a real key.
const algorithm = { name: 'ECDH', namedCurve: 'P-256' }
const privateKey = await crypto.subtle.importKey('jwk', jwk, algorithm, false, ['deriveBits'])
const publicKey = await crypto.subtle.importKey('jwk',
  { kty: jwk.kty, crv: jwk.crv, x: jwk.x, y: jwk.y }, algorithm, true, [])
const suite = new CipherSuite({ kem: new DhkemP256HkdfSha256(),
  kdf: new HkdfSha256(), aead: new Aes256Gcm() })
const sender = await suite.createSenderContext({ recipientPublicKey: publicKey })
const ciphertext = await sender.seal(new Uint8Array([1]))
// Private-only recipient opens fail for this valid fixed key:
try {
  const wrongContext = await suite.createRecipientContext({ recipientKey: privateKey, enc: sender.enc })
  await wrongContext.open(ciphertext)
  console.log('private-only accepted unexpectedly')
} catch (error) { console.log('private-only rejected:', error.name) }
// This succeeds without exporting privateKey:
const correctContext = await suite.createRecipientContext({
  recipientKey: { privateKey, publicKey }, enc: sender.enc })
await correctContext.open(ciphertext)
console.log('explicit pair accepted')
```

## Exporter limit: distinguish experiment policy from library behavior

The actual resolved `@hpke/common` 1.10.1 exports `INPUT_LENGTH_LIMIT = 8192`.
core 1.9.0's exporter checks that constant. Actual runtime observations:

| Exporter context length | Observed result |
| --- | --- |
| 128 bytes | accepted |
| 129 bytes | accepted |
| 8192 bytes | accepted |
| 8193 bytes | rejected |

Keep the experiment's exporter labels below 128 bytes as a deliberate framing
limit. Do not call 128 the library limit. Current documentation can correct that
attribution; historical reports should retain their original provenance rather
than pretending those runs used a different dependency.

Observed installed-source SHA256 values, paths relative to TXBoard-Gateway:

| File | SHA256 |
| --- | --- |
| package-lock.json | e93bef881fc3ddddd3f0179cd2ce87a9eed191a7a8b92703150b68b6c07aa60d |
| node_modules/@hpke/core/package.json | 1059f008709528c9d4dd81da86a0f52be3d55c4847e4d3e68a9aadcf2f35b2c9 |
| node_modules/@hpke/core/esm/src/exporterContext.js | 0d3f865d9d88e0ae8a4fc8e965d85156309a1c938a80354d4397d0bd765e3cf0 |
| node_modules/@hpke/core/esm/src/cipherSuiteNative.js | 709d78b20737e20e20950e4f3b3b764c221097456c129d5ddf115d8e6abeefc1 |
| node_modules/@hpke/common/package.json | d3089264c766aa0fe56047705ec137010e9622ed6ad292969fd8eea7e5579941 |
| node_modules/@hpke/common/esm/src/consts.js | 5428171e017c7e6f47291bce8b64d712aabc040484e7ec08de1c4da4636aee00 |
| node_modules/@hpke/common/esm/src/kems/dhkem.js | 3328af8d6cab77f8962ac51545a7d22810c2c1a5ac1786d9c3d99e31295bae34 |
| node_modules/@hpke/common/esm/src/kems/dhkemPrimitives/ec.js | 22a0857fbdd70ded725de6b54bcf6b55510c1d6299a6833e3aa7f2ce9cbadd6d |

These are the installed bytes inspected and executed in Windows. A later Linux
or browser run must report its own locked dependency/source provenance.

## Ownership, snapshots and implementation conditions

- The response closure must check and claim its spent state before inspecting
  any caller input, invoking a callback, parsing a field or awaiting. Avoid
  parameter destructuring such as `sealResponse(pt, {kind, status})`: argument
  getters run before the function body and can synchronously reenter a closure.
  Reentrant, concurrent, malformed, expired and bad-tag calls all consume or
  lose the same closure; no reset or retry is allowed.
- Metadata, envelopes, binding records and the pair carrier require exact own
  data descriptors, plain/null prototypes, no accessors, symbols or extra keys.
  Validate captured descriptor values, never validate the caller object and then
  reread it. Native CryptoKey and permitted byte-array values are explicit leaf
  types, not plain record inputs. Freeze both the returned carrier and its fresh
  metadata object.
- Copy byte views synchronously with `new Uint8Array(input)` before the first
  await, including Buffer subviews with nonzero byte offsets. Refuse shared or
  detached buffers. Never use Buffer.slice as a copy. Capture canonical B64,
  metadata, binding and digest inputs once; do not retain caller-owned buffers.
- Use a captured local clock, not public caller-supplied `now`/clock hooks.
  Recheck the time window at closure admission and after asynchronous response
  crypto before returning. Expiry consumes the closure. Also specify the result
  when initial request create/open finishes after expiry; refuse returning an
  accepted plaintext/context after that boundary. This is rejection on return,
  not cancellation of an in-progress WebCrypto operation. Wall-clock rollback
  and durable/distributed lifetimes remain separate transport gates.
- Empty plaintext is explicitly valid; empty or malformed envelopes, enc or ct
  are invalid. Bound plaintext to 0..3056 bytes and ct to 16..3072 bytes. Enforce
  canonical complete strings, exact digests and closed status/kind schemas.
- Clear only internal temporary key/nonce/plaintext copies. Do not zero the
  returned caller-owned decrypted bytes in a finally block. Drop references to
  spent HPKE/AES contexts even if the caller retains a spent closure. No GC or
  engine zeroization guarantee is claimed, consistent with WebCrypto's
  [security considerations](https://www.w3.org/TR/WebCryptoAPI/#security-considerations).

## Remaining network blockers and required isolated negative tests

Per-closure ownership is intentionally weaker than per-request ownership. A
second open of the same valid request, even in one JS realm with the same key,
reconstructs the same exporter material. Distinct AAD, status or error kind does
not make AES-GCM key/nonce reuse safe. The isolated test may demonstrate a second
successful request open, then consume the second closure with invalid response
input. It must not encrypt different response plaintexts under both owners.
The harness assigns exactly one response encryption owner per fixture request.

Every network consumer remains blocked until authenticated request-digest
ownership is atomically claimed before response encryption, across retries,
workers, crashes and failover. A replay loser cannot encrypt its own error with
that response material. Claim lifetime must cover the full accepted request and
skew window; expiry cleanup cannot make an old ciphertext eligible again.
Do not add an ad hoc random response nonce, context persistence or automatic
retry as a workaround in this experiment.

The amended design's earlier verification bullet still says a valid different
domain/operation is rejected. Correct that wording: changing metadata on an
existing enc/ct must fail authentication; a newly encrypted well-formed different
domain/operation is not rejected by this policy-free primitive. Its metadata is
AAD, not an authorization decision. Similarly, replace the ambiguous sentence
that all empty inputs fail with the explicit empty-plaintext rule above.

Required implementation evidence includes fixed odd-y/nonextractable pair
interop, both Node/Chromium directions, empty/max/over-bound bytes, modified
metadata/enc/ct/digest/kind/status, wrong key/pair/curve/usage, cross-request
response swaps, all spent-state paths, getter reentrancy without invocation,
Buffer subview mutation, shared/detached input rejection, input mutation around
await, and fake-local-clock expiry before and after asynchronous completion.
All failures use the fixed error without plaintext, ciphertext, keys or native
crypto internals. No actual browser crypto execution is claimed by this review;
that is required implementation evidence, separate from the Node probes above.

With these conditions the root task may implement and test the isolated
experiment. Formal cryptographic review, key/discovery trust, shared replay,
Core authorization, business idempotency, encrypted error policy and production
SC-v2 acceptance remain open.
