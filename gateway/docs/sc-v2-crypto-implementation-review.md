# B6.2 isolated SDK crypto implementation review

Date: 2026-10-12. Reviewer scope: read-only review of the SDK experiment and its
local tests, plus the focused Windows test run below. Only this review document
was written. This is not a formal cryptographic audit or production SC-v2
approval.

## Decision

The context-retention blocker is resolved in the reviewed implementation. No
remaining implementation blocker was found for the narrowly scoped offline SDK
experiment. It may proceed to the parent's fixed-source Linux and actual browser
interop gates and reviewable commit. It must remain an explicit experimental
subpath without an HTTP, Redis or business consumer.

Network consumption remains blocked: reopening the same envelope allocates a
second recipient context with the same exported response key and nonce. The
one-shot rule applies to each closure, not to a request digest across closures,
realms, processes or retries. A shared authenticated ownership claim must occur
before response encryption; a losing owner cannot encrypt a separate error.

## Exact reviewed bytes

Paths below are relative to `D:/my-project/TXX/TXBoard-Gateway`. These hashes are
the Windows working-tree bytes read during this review, not a claim that a
particular commit or Linux snapshot has these byte hashes.

| File | SHA256 |
| --- | --- |
| packages/theme-sdk/src/experimental/sc-v2-crypto.ts | 325086f6a6d790acf6f9ac0ff91c816f6969b07d6a6adc9b0f59c23d2f3a9a06 |
| packages/theme-sdk/test/sc-v2-crypto.test.ts | 602f95f9829bf598d7bfe4f57b6aeeb80ba6bdfe5b4370469e91e3f4e3880a69 |
| packages/theme-sdk/src/experimental/sc-v2-codec.ts | 7c7dd5ec12864663fe5540013e4d9e409f4658cf5a3eff65826249617e42643d |
| packages/theme-sdk/test/vectors/sc-v2-crypto.json | 70b8c50f0c88366ecd15a3b997f0d255d0911e6299af9b1af850936e0bed5bbe |
| packages/theme-sdk/test/vectors/hpke-kat.json | 6a94d551fa0861bc309429021a7e2612d4159d77a08bbae55366bb2ea5662725 |
| packages/theme-sdk/package.json | 46cdbacc693e99a812b11cf551e7c55bfa72dc077cb7257c4e20203ea2e353dc |
| packages/theme-sdk/src/index.ts | 7d7ea2eeccf5b3c7fc346f90caac36e300dde1d4a503e8cf43925a26e1722dfe6 |

The dependency lock has no Git content diff. Its Windows CRLF working bytes hash
is `e93bef881fc3ddddd3f0179cd2ce87a9eed191a7a8b92703150b68b6c07aa60d`;
the committed LF blob/Linux bytes hash is
`a60bdbf6de2788282dfa87077316f55df950c7192e6a33960ead97dc953dc940`.
These different hashes must not be reported as a dependency version change.
The inspected resolved versions are `@hpke/core` 1.9.0 and `@hpke/common` 1.10.1.
Installed source hashes and the exporter/odd-y reproductions are recorded in
the earlier design review, [copied into this repository](sc-v2-crypto-design-review.md), original SHA256
`c8f9349eb04e02914419459396563220cb1384a80ef1ac4f3acf7ca87af1cb9e`.

## Implementation findings

1. **Closure state and context release.** Both response closures check `spent`
   and set it before any caller-property inspection, parsing, callback or await.
   They immediately move the private context holder to a local `owner` and set
   `sender`/`recipient` to `undefined`. A retained spent closure no longer keeps
   its HPKE context through that holder. The pending operation's local owner
   lasts until its Promise completes. This resolves the previous constant-holder
   retention issue. It does not guarantee garbage collection, native key erasure
   or automatic destruction of an unused closure at expiry.
2. **Snapshot and record boundaries.** Metadata, envelopes, response bindings and
   the trusted pair carrier are read from exact own enumerable data descriptors,
   with plain/null prototypes and no extra keys, symbols or accessors. Captured
   values are validated rather than rereading the caller record. This permits a
   Proxy's reflection traps, but the response state is claimed before those
   traps can synchronously reenter. The returned carriers and fresh metadata are
   frozen. Native CryptoKey and byte-array values are deliberate leaf types.
3. **Bytes and output ownership.** Intrinsic typed-array buffer/length/offset
   getters and a new view plus copied array handle nonzero-offset Buffer views
   without aliasing. Shared and detached buffers are refused. All caller byte
   inputs and metadata/binding/envelope values are captured before the first
   await in their operation. Internal plaintext copies, response raw-key bytes
   and nonce bytes are cleared on the implemented paths. Returned request and
   response plaintext arrays are separate caller-owned copies and are not
   zeroed by the internal finally blocks. Native/engine copies are outside this
   cleanup guarantee.
4. **Time boundaries.** Create/open and both response closures validate the local
   time window before admission and again after asynchronous crypto/digest work
   before returning success. The closure is already consumed when an expired or
   invalid attempt fails. This is refusal of a late result, not cancellation of
   the underlying WebCrypto operation. Runtime clock rollback and distributed
   cleanup remain transport concerns, not claims of this primitive.
5. **Recipient pair and role direction.** An explicit trusted ECDH/P-256 pair is
   captured; a nonextractable private key with `deriveBits` is accepted. The pair
   is passed intact to HPKE, avoiding the reproduced private-only odd-y fallback.
   No private key export is required. Type/curve/usage checks and wrong/random
   pair authentication fixtures are present; they do not constitute a general
   mathematical key-pair consistency validator or key-discovery trust system.
   The caller must supply its trusted corresponding pair. HPKE sender only seals
   the request and recipient only opens it; the response uses separately exported
   key/nonce material with distinct labels. AES-GCM keys are nonextractable and
   have only their required encrypt/decrypt usage, with an explicit 128-bit tag.
6. **Shared suite.** The inspected pinned implementation keeps fixed suite/KDF
   configuration and lazily loaded WebCrypto capability on the module-level
   suite; sender/recipient contexts and operation secrets are allocated per call.
   No cross-operation secret state was found in this path. The 12-context test
   exercises simultaneous create/open/seal/decrypt and verifies isolated results
   and distinct encapsulations. The exported `suiteIds` array is now frozen.
   This is code and regression evidence, not a concurrency proof for future
   dependency versions.
7. **Experiment isolation and failure shape.** Search of the current SDK source
   and Gateway app finds only the explicit package subpath and experiment module,
   with no normal SDK entry-point or Gateway consumer import. Errors remain the
   fixed `SC_EXPERIMENT_INVALID`, without native exception detail or payload/key
   data. Domain, operation, origin and key identifiers are authenticated metadata;
   a newly encrypted well-formed different domain is not an authorization denial.

These directions use the existing HPKE and WebCrypto primitives rather than a
new cryptographic construction. The response-export use is illustrated by
[RFC 9180 section 9.8](https://www.rfc-editor.org/rfc/rfc9180.html#section-9.8);
HPKE context roles and export are specified in
[sections 5.2 and 5.3](https://www.rfc-editor.org/rfc/rfc9180.html#section-5.2).
The response uses [WebCrypto AES-GCM](https://www.w3.org/TR/WebCryptoAPI/#aes-gcm-operations).
These references support the primitive direction, not approval of this
application protocol. HPKE does not supply shared replay ownership or forward
secrecy against compromise of the recipient's long-term key.
[RFC 9180 sections 9.7.3 and 9.7.4](https://www.rfc-editor.org/rfc/rfc9180.html#section-9.7.3)

## Verification observed by this reviewer

Executed from the Gateway repository:

```text
npm.cmd run test --workspace @txboard/theme-sdk -- sc-v2-crypto.test.ts
Vitest 4.1.11: 1 test file passed; 28 tests passed; no failures.
```

The observed suite includes the locked-library request/response reference;
empty, Unicode/NUL and maximum-size plaintext; request and response AAD/enc/ct
tampering; wrong keys, curve, type, usage and random mismatched pair; the fixed
odd-y nonextractable pair; cross-request response swaps; concurrent same-closure
winners; accessor refusal and Proxy reentrancy; exact/null-prototype records;
Buffer offset and caller-mutation snapshots; shared/detached/over-bound inputs;
12 independent contexts; expiry before use and after each asynchronous
create/open/seal/decrypt completion. The repeated-envelope test intentionally
demonstrates a second owner without encrypting two different responses under the
duplicate response material.

The parent incorporated the pair-carrier fixture improvement during this review:
the replacement key is now generated first, and the carrier is mutated
synchronously immediately after calling open. The focused 28-test gate was
rerun successfully after that change. The implementation captures the pair before
its first await.

The deterministic SC vector is explicitly a locked-library reference, not an
independent formal protocol validation. This review does not claim a new full SDK,
Linux, browser, cross-role browser/Node or published HPKE KAT run. The parent must
record those gates with their actual fixed-source and dependency provenance.

## Remaining acceptance boundaries

No production release, deployment or network consumer is approved. Shared replay
ownership, authenticated key discovery/rotation, actor authorization, business
idempotency, error/timeout ownership, distributed recovery and a formal protocol
review remain open. An experimental subpath label does not remove those gates.
Only the isolated byte/crypto interoperability experiment and its fresh public
fixtures fall within this review's allowed scope.
