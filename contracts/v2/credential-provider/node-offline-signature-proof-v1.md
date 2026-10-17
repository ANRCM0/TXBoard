# Node offline Provider signature proof v1

Status: isolated signature diagnostic with historical verification evidence.
The former Core fixture and its admission profile are retired. The retained Go
**signature diagnostic** is not a Node admission or lease consumer. Current
admission uses [native Node delivery](native-node-delivery-v1.md); default Node
Service, kernel credentials, usage grants and network routes do not import this
diagnostic.

`node/internal/providerproof` accepts a caller-supplied, trusted public-key set
of one or two entries. It never loads keys from a JWS, URL or untrusted payload,
reads private keys, boots Core, or accesses a network/database. The caller owns
trust provisioning and refresh. The verifier copies/parses key material at
construction and retains immutable public state suitable for concurrent reads.

It recognizes only the existing Core local-fixture JWS domain, exact outer
header/payload field names, RS256 and 3072-bit RSA SPKI. Key ID/epoch/SPKI digest
and validity metadata must match configured trust. Compact segments must be
canonical base64url, with the existing 16KiB cap and 384-byte signature. Bounded
JSON scanning rejects duplicate keys at every depth, malformed Unicode,
decoded replacement characters conservatively, and
trailing data. Outer IDs/timestamps and fixed domain/denial flags are checked;
nested business evidence is opaque to this diagnostic.

The result contains signature/key-revocation evidence, signed-byte digests,
snapshot reference and time metadata. It **always** reports
`admission_granted=false`, `business_evidence_validation=not_evaluated`,
`live_core_eligibility=not_evaluated`, and `window_validation=not_evaluated`.
No decoded grant or credential is returned. A valid signature, including a
historical expired or revoked-key signature, does not grant admission. Unknown
keys, malformed profiles and signature failures return one fixed error with no
input or underlying parser/crypto detail.

The historical interoperability fixture invoked the former Core initial-binding
transaction and full PHP profile validator before exporting only public key
metadata and the original signed statement to Go. That retired fixture and
`CORE_PROVIDER_GO_PROOF_INTEROP=1` are not current execution instructions.
The retained pure Go tests exercise cryptographic tampering, algorithm/domain/key
confusion, encoding/JSON limits and concurrent verification without an API
runtime change or production key.

This was a narrow B4 prerequisite. Full nested evidence validation, intended
recipient/source matching, trusted clocks, lease enforcement, secret delivery,
revocation convergence and runtime integration remain separate gates. The
[archived Core snapshot contract](https://github.com/ANRCM0/TXBoard/blob/5b546dfc992ec56a90bfe54a103df6326eb47f08/contracts/v2/credential-provider/local-fixture-authorization-snapshot-v1.md)
describes the former business profile, not a current issuance or admission
protocol; the Go diagnostic is deliberately not an equivalent implementation
of that entire validator.

Go1.26.3 race tests passed 7 main tests plus30 subtests, with the opt-in Core
fixture explicitly skipped in that pure run. A separate actual Core binding
fixture passed1/6 assertions and invokes the Go interoperability test with the
original public-only statement. Vet passed. The full API run passed1125 with
102 explicit engine/cross-toolchain skips and11509 assertions. Source, dependency,
raw-log hashes and earlier launcher failures are retained in the
[verification report](../../../docs/architecture/v2/provider-offline-proof-verification.json).
