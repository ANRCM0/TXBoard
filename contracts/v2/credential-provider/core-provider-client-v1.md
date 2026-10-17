# Core Provider Client v1

> K2 candidate implementation, 2026-10-17. Depends on the K1 foundation candidate;
> not yet merged or accepted by CI. This is a trusted in-process adapter contract.

## Owner and supported capability

CredentialProvider owns `Contracts\ProviderClient`. `ProviderTransport` directly
implements it using the current HTTPS/mTLS protocol. `CoreCapabilitiesServiceProvider`
binds the interface to this implementation per request/work-item scope. There is
no forwarding wrapper, new package runtime or generic remote invocation API.

```php
interface ProviderClient
{
    public function configuration(string $providerId): array;
    public function configDigest(string $providerId): string;
    public function call(array $registration, array $request): array;
    public function metadata(array $registration, array $request): array;
    public function capabilities(array $registration): array;
}
```

The signatures and closed array schemas preserve the existing
[issuance](native-issuance-v1.md), [Node metadata/delivery](native-node-delivery-v1.md) and capability
protocols. The interface carries no credentials in its results. It does not
authorize users or grant access to arbitrary URLs, file paths or operations.
Only configured provider identities, supported profiles and codec-validated
requests are accepted by the adapter. The existing error type is `ProviderError`.

## Local configuration versus network

`configuration` and `configDigest` read operator configuration and validate local
TLS material. Their result is non-secret provider identity, epoch, profiles and
digest. They perform no remote request and may be used by existing locked
readiness/configuration checks. They are not a remote health check, and successful
configuration validation does not prove peer availability or authorization.

`call`, `metadata` and `capabilities` perform bounded remote I/O. The native
implementation rejects any active transaction on the owning/default Laravel SQL
connection with `ProviderError('PROVIDER_NETWORK_INSIDE_TRANSACTION', false)`
before reading configuration, decoding input or issuing network I/O. This is a
local programming failure, not evidence of an uncertain remote operation. These
methods must run between the owning workflow's durable SQL phases.

Existing TLS, endpoint/configuration digest, registration epoch, deadline, size,
profile and response checks remain in `ProviderTransport` and its codecs. Network
failures retain their existing retry/unknown semantics. An uncertain mutation
must query its original operation before any identical resend; callers must not
invent a fresh logical issuance key. Finalization still verifies current account,
subscription and installation state in the original owner transaction.

## Consumers and replacement

The five consumers are `NativeCredentialProviderService`,
`NativeSubscriptionDelivery`, `NativeProviderNodeAuthorization`,
`NativeRuntimeReadiness` and `NativeProviderTrust`. All depend on `ProviderClient`.
Only transport implementation and composition root name the concrete class in
production. Node signed snapshots, provider trust rotation and admission rules
retain their current behavior. Proxy secrets remain at the independent Provider
and its authorized direct-delivery channel.

A new adapter must satisfy the same schemas and failure semantics, including
the network/transaction boundary and operation recovery. Registration in the
composition root is a controlled code/configuration deployment, not an arbitrary
plugin claiming permission to replace the Provider. Test doubles replace the
interface; real mTLS interop tests continue to exercise the native implementation.

## Validation and scope

Candidate regression tests cover scoped composition and replacement, transaction
rejection at each network entry, existing configuration-drift rejection, unknown
operation recovery, Node authorization, delivery, readiness and trust workflows.
The Core source guard checks pure contracts and concrete implementation ownership;
it is not a PHP sandbox. Matching API, MySQL and real Provider CI evidence is
required before acceptance. No schema, route or wire format is added by this seam.
