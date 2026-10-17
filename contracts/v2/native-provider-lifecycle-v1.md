# Native Provider lifecycle and subscription links v1

Status: current bounded lifecycle contract, merged in PR #261.
Production PKI, real certificate rotation and Node trust distribution remain
unverified; merge and CI are not release approval.

Core owns non-secret registration, policy, durable operations and subscription
links. The separately executed Provider owns all proxy credential material.
Existing issue/query/rotate/revoke and finite Node authorization remain the
only credential authority; this batch does not add another credential store.

## Controlled transport and signing trust rotation

Operator-owned TLS files remain pinned by their actual certificate/CA bytes.
Changing files or environment does not authorize a new configuration.
`core:provider-trust preflight` probes the candidate Provider over verified
TLS 1.3/mTLS outside SQL locks. Candidate configuration may change Provider
transport trust and Core signing trust only; installation, Provider identity,
store epoch, profiles, recipients and usage identity must remain unchanged.
Signing rotation requires a strictly larger key epoch and a different key ID.
The operator provisions the corresponding Provider client fingerprint and
Node/recipient trust before committing the transition. There is no silent
fallback, automatic CA download or promise of seamless overlap.

`core:provider-trust rotate --key=KEY --expected-digest=HEX
--configuration-digest=HEX` checks the original configuration by digest, probes
the new pinned transport and atomically appends a hash-linked transition,
updates the existing Provider registration pin, and retains activation evidence
unchanged. Every domain admission validates the chain from activation to the
current configuration. Stale workers/files fail closed. Same-key/same-input
replay returns the original result; a different payload conflicts permanently.
Provider store epoch changes are not certificate rotation and are rejected.

No command accepts an arbitrary endpoint, certificate, private key or node
configuration on the wire. Commands read the operator configuration. Private
files, credential material and raw transport errors never enter receipts.

## Persistent independently revocable links

An authenticated owner creates a link for one owned Subscription/AccessSubject
and an explicit expiry (at most 365 days). Core generates a separate random
reference and 256-bit bearer; only its SHA-256 verifier is persisted. The
bearer is delivered once, never placed in a URL, audit event or ordinary DTO.
Creating with the original key replays the original non-secret link record
but does not reveal the bearer again. The owner may revoke the link independently
of the Subscription or Provider credential. Revoked references never reactivate.

Redemption is a dedicated bounded endpoint, not a User/Node/service identity.
It takes the bearer in Authorization, the non-secret reference in the path and
a recipient public key in the closed body. It revalidates the original owner,
current Subscription entitlement, subject, link version and expiry both before
and after the Provider metadata request. Provider outages retain the same Core
link and subscription, and never create or rotate credentials as a fallback.

The result is the existing at-most-30-second recipient-PoP render authorization.
The recipient calls the Provider directly to receive secret configuration.
Core does not relay or log secret renders. Revocation prevents new grants;
an already signed grant remains usable only until its original finite expiry.
The link bearer alone cannot retrieve material from the Provider.

Supported rendering remains the actually implemented sing-box VLESS/VMess
direct TCP range. No new frontend, old-site migration, generic plugin sandbox,
HA, automatic Node pin distribution or complete protocol matrix is implied.
