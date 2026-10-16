# Native C2 runtime configuration

Implementation and operator scope are defined here; exact-commit CI, review and
merge evidence are recorded in [PR #251](https://github.com/ANRCM0/TXBoard/pull/251).
The normative behavior is [Native C2 runtime v1](../../../contracts/v2/native-c2-runtime-v1.md).
This is operator configuration for a fresh native installation, not a legacy
migration or a host installer.

## Supported first scope

Activation accepts explicitly provisioned sing-box VLESS/VMess direct TCP
Nodes, static tariff, no multiplexing and TLS mode 0. The client rendering DTO
can describe ordinary TLS, but native activation does not yet accept that
configuration. UDP, Reality, Xray, other transports and protocols remain
outside this native runtime. Existing legacy runtime support is unchanged.
Only zero-price offers are exposed or purchasable. Nonzero native orders cannot
start payment checkout; payment callbacks and the money ledger remain C3 work.
Provision a zero-price offer for this runtime.

## Preparation and activation

Use a fresh production schema. Provision only bootstrap administrators with
empty legacy entitlement, usage and money fields before initialization.

```bash
php artisan migrate --force
php artisan core:installation init --installation-id=UUID --mode=native --key=BOOTSTRAP_KEY
```

Preparation processes use `CORE_INSTALLATION_MODE=native` and
`CORE_INSTALLATION_EPOCH=1`. Publish immutable Plan offers using the existing
publication service; configure Node/group records and Node or Machine bearer
credentials. This batch does not add an installer or a public catalog management
API. Use the operator-owned configuration files for the following pins:

| Configuration | Required value |
| --- | --- |
| `credential_provider.providers` | Fixed HTTPS `/operations`, Provider ID/epoch/profiles, CA, Core client certificate and private key paths |
| `native_provider_node.provider_id` | The configured Provider ID |
| `native_provider_node.recipients` | Each Node ID with exact Machine ID or null, and Node client certificate DER SHA-256 |
| `native_subscription_delivery.providers` | Each Provider's fixed HTTPS `/subscription-render` URL |
| `native_node_authorization` | Enabled, `native-provider-authorization/v2`, installation UUID, Ed25519 key identity/epoch/private seed, finite lease |
| `core_usage_v2` | Same installation UUID, receipt generation 1, isolated mode false |

Keep private keys and Provider storage private. Core and Node mTLS leaf
fingerprint roles must be disjoint. Run the Provider with the existing control
listener options plus:

```text
--delivery-trust /private/core-public-trust.json
--node-client-fingerprint NODE_CERT_DER_SHA256
--subscription-listen ADDRESS
--subscription-tls-cert /private/public-server.pem
--subscription-tls-key /private/public-server.key
```

Register the configured Provider with
`core:provider-register PROVIDER_ID --key=REGISTRATION_KEY`.
Run preflight, inspect its bounded diagnostics, then use its exact returned
digest and expected version:

```bash
php artisan core:installation preflight
php artisan core:installation activate --key=ACTIVATION_KEY --expected-version=1 --configuration-digest=HEX
```

Activation probes the actual Provider capabilities and rechecks authority
after the external call. It persists the open runtime at epoch 2. Restart
application, queue and scheduler processes with `CORE_INSTALLATION_EPOCH=2`;
old epoch-1 work refuses. Keep the normal Laravel scheduler and real Redis
order queue running. Scheduled `core:native-work` recovers SQL-backed
credential synchronization and usage settlement even when a Redis notification
was lost. Readiness is not a substitute for the supported-scope CI evidence.

## Node configuration

Use the existing Provider Node authorization configuration and select:

```yaml
authorization:
  enabled: true
  policy_version: native-provider-authorization/v2
  # Existing Core public-key, Node target, private state and Provider mTLS pins.
usage_v2:
  enabled: true
  isolated_copy: false
  directory: /private/native-usage
  ca_file: /private/core-ca.pem
```

The runtime derives separate `node-ID/spool` and `node-ID/native` directories.
Preserve both, together with the existing authorization high-water state, in
backups. Restart requires a fresh online signed grant; replay retains the old
batch ID, original Session, period and digest. Do not remove unknown or
quarantined evidence to force reopening.

## Direct client delivery

Authenticate through the normal user API and use the returned bearer from a
private file. Select an owned current AccessSubject ID and independently
provisioned public Core trust. The reference consumer generates the ephemeral
recipient private key locally:

```bash
node scripts/native-subscription-client.mjs \
  --core-origin https://core.example.test \
  --provider-origin https://credentials.example.test \
  --provider-id primary-provider \
  --subject-id 123 \
  --bearer-file /private/account-bearer \
  --ca-file /private/ca.pem \
  --trust-file /private/core-public-trust.json \
  --output /private/new-subscription.json
```

The output path must not already exist. The resulting private JSON bundle is
`native-connection-bundle/v1`, and contains the actual Provider UUID and public
connection metadata. It is consumed directly by the client, never by Core.
This short grant is not an automatic long-lived subscription URL.

## Recovery and rollback

Use the durable operation recovery API and `core:native-work` for uncertain
Provider outcomes; never create a replacement operation merely because its
response was lost. Provider store version 2 upgrades intact prior generation
evidence atomically, and old writers refuse that store afterward.

Populated generation-pointer or activation/usage evidence migrations refuse
destructive rollback. Keep a consistent private backup and use a forward fix;
do not revert an activated authority record, receipt generation or high-water
state with configuration changes. C5 supplies the final deployment, update and
restore procedure.
