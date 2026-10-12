# SC-v2 metadata codec prototype v1

Status: experimental B6.1 bytes only, identifier `txboard.sc-v2-codec/1`. This is
not a finalized SC-v2 protocol, encryption implementation or release approval.
The [Gateway experiment](https://github.com/ANRCM0/TXBoard-Gateway/blob/main/docs/sc-v2-codec-experimental.md)
contains the exact field order, byte limits, reference encoder and current
restrictions. It is an optional SDK subpath with no registered HTTP route, Core
operation or plaintext fallback. Current v1 Gateway/SDK behavior remains intact.

The experiment uses length-prefixed ordered tuples, explicit request/response
labels and canonical binary integer/UUID/key/digest values. Secret plaintext is
outside these public metadata bytes. A request digest binds the complete request
AAD, encapsulation and ciphertext; response metadata additionally binds its type
and status. Separate candidate exporter labels include that digest and fit the
locked HPKE library's 128-byte context limit. The codec performs no cryptographic
key/nonce derivation or AEAD. It rejects ambiguous value encodings, but raw JSON
duplicate rejection must precede it in any future transport.

The tuple format and parameters remain candidates for independent review.
[RFC 9180](https://www.rfc-editor.org/rfc/rfc9180.html#section-5.3) defines the HPKE
export interface; it does not define this application's bidirectional channel.
One-shot response ownership, nonce uniqueness, discovery trust, key revocation,
shared replay, encrypted error behavior and business-idempotent retries remain
mandatory before a transport can use these bytes. Node/Chromium metadata vectors
alone do not satisfy the [SC-v2 acceptance gates](README.md).
