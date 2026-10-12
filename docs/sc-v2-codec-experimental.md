# SC-v2 metadata codec prototype

Status: B6.1 experiment `txboard.sc-v2-codec/1`. This is an explicitly separate
SDK subpath, `@txboard/theme-sdk/experimental/sc-v2-codec`. It performs no encryption,
key discovery, HTTP, replay consumption or actor authorization. Existing v1 SDK
and Gateway behavior is unchanged. The target [SC-v2 contract](https://github.com/ANRCM0/TXBoard/blob/main/contracts/v2/secure-channel/README.md)
remains a draft requiring protocol review before implementation and rollout.

The existing locked implementation is `@hpke/core` 1.9.0 and P-256/HKDF-SHA256/
AES-256-GCM, with IDs 0x0010/0x0001/0x0002 in base mode 0. These are candidate
parameters for the experiment; no encrypted SC-v2 traffic is accepted. HPKE is
unidirectional; its [RFC 9180 exporter](https://www.rfc-editor.org/rfc/rfc9180.html#section-5.3)
is the interface for separate response material, rather than encrypting with a
recipient HPKE context. The prototype keeps exporter context below 128 bytes and
binds a 32-byte request digest inside direction-specific labels. This is an
experiment limit; locked `@hpke/common` 1.10.1 permits up to 8192 input bytes.
It does not implement or approve response key/nonce derivation. In particular,
these bytes alone do not establish safe single-response ownership or nonce reuse
protection across retries, workers, faults or key rotations.

Every tuple is a fixed ordered list of uint16 big-endian length followed by field
bytes, including the direction label. No JSON field order or delimiter is used
as authenticated bytes. The request AAD fields, in order, are:

| Field | Bytes |
| --- | --- |
| label | ASCII `txboard.sc-v2-codec/1/request-aad` |
| version, mode, KEM, KDF, AEAD | each uint16 big-endian |
| domain | ASCII `user` or `admin` |
| key ID | 32 bytes from 64 lowercase hex characters |
| operation | restricted lowercase dotted ASCII, maximum 128 bytes |
| operation version | canonical positive decimal ASCII, maximum 6 bytes |
| request ID | 16 bytes from lowercase UUID v4 |
| issued/expires | each uint64 big-endian Unix seconds, restricted to safe JS integers |
| replay nonce | 32 bytes from canonical unpadded base64url |
| origin | exact canonical HTTPS origin, no silent URL normalization |
| path | fixed `/gateway/v2/channel/user` or `/gateway/v2/channel/admin` |

The named paths are prospective transport binding values, not installed routes.
Server configuration must independently pin origin/domain/operation; accepting a
syntactically valid field does not make it an authorized target. The key ID is
opaque metadata, not a trust decision. Uppercase hex, padded base64, alternate
trailing bits, default port `:443`, trailing slash, URL credentials/query/hash,
Unicode protocol labels, missing/null/unknown object fields and unsafe numbers
are rejected. A raw JSON parser still has to reject duplicate fields before
passing an object to this codec. Plaintext JSON/Unicode serialization is outside
this slice; the codec never guesses a normalization for secret payloads.

Request digest input is a distinct tuple containing its label, full request AAD,
65-byte uncompressed P-256 encapsulation and bounded ciphertext. The point must
begin with 0x04; curve validation and ciphertext authentication belong to HPKE.
The digest is SHA-256 of those bytes. Response AAD has its own label, the same
metadata, the request digest, `result`/`error` and uint16 status. Result status is
200–299; error status is 300–599. Exporter context is a tuple of either
`txboard.sc-v2-codec/1/response/key` or `/response/nonce`, followed by the request
digest. Both fit the locked library's context limit. All tuple outputs are capped
at 4096 bytes; digest input ciphertext is additionally capped at 3072 bytes.

The candidate request window is at most 60 seconds, with 5 seconds future skew,
and strict expiry (`now < expires_at`). This helper only checks time: shared
Redis atomic replay consumption, TTL covering the full window/skew, server key
revocation and current Core actor permissions remain separate required gates.

Independent Python vectors are frozen in `test/vectors/sc-v2-codec.json`. Their
zero ciphertext is framing evidence, not encrypted protocol traffic; they contain
no private key. Review byte changes before manually running
`python scripts/generate-sc-v2-codec-vectors.py`. Node tests check those bytes and
negative encodings; the actual Chromium test also hashes the digest input with
browser WebCrypto. Run:

```sh
npm run test --workspace @txboard/theme-sdk
npm run typecheck --workspace @txboard/theme-sdk
node scripts/test-sc-v2-codec-browser.mjs
```

Before a transport can use this prototype, independent protocol review must
resolve key/nonce separation, one-shot response state, plaintext schema,
pre/post-decryption errors, discovery authenticity, revocation across caches,
shared replay races and business-idempotent retries. Browser/Node full HPKE
vectors and real Core/Gateway/Redis interoperability are still outstanding.

Verification on 2026-10-12: fixed committed Gateway `8310a4c` plus only this
experiment passed Linux Node 22.23.2 typecheck/build, 425 Gateway tests and 40 SDK
tests. Windows SDK typecheck and 40 tests also passed. Actual local Chromium 155
matched six positive byte/hash checks and rejected five invalid encodings; the
Chromium vector script is included in the existing browser CI job. The reference
vector SHA-256 is `0a8012eaaf80682bd8ac301a730bd00f9a6bb3094ccfba63a2b62bfe84db553c`.
No encryption or real business acceptance is inferred from these results.
