# Native payment provider v1

Status: current unified payment plugin contract, merged in PR #261.
Exact-head validation and production limits are recorded in the
[current baseline](../../docs/architecture/v2/current-progress.md).

## Ownership and registration

Core owns attempts, immutable receipts, money, allocation, refunds and durable
fulfillment. A payment plugin implements NativePaymentPluginInterface and
returns NativePaymentProviderInterface for protocol-only work. The only current
package declaration is `package.schema=1`, `type=payment` and the closed
`payment` object containing `contract=native-payment-provider/1`, `provider`
and `snapshot_schema`. Adapter identity must match it exactly. Missing or old
contracts are rejected, not adapted. The historical PaymentInterface has been
removed, and direct PaymentService pay/notify always refuse. PHP plugins remain
trusted in-process code, not a sandbox.

PluginManager validates installed payment packages. Native Registry discovers
only those installed packages, resolves stable provider IDs and rejects
ambiguous third-party providers. Bundled provider ownership wins collisions.
No user-selected class, filesystem path, URL or merchant configuration enters
registration. Enabling a plugin or declaring capability grants no Core actor
permission. Registry never boots a disabled plugin for recovery.
Management lists and form lookup use this same validated registry, not
`available_payment_methods` hooks. The payment plugin interface includes
`form()` for management schema; it does not expose financial execution.
AlipayF2f is no longer bundled; only EPay currently implements this contract.
The [development guide](../../docs/development/payment-plugin-development.md)
and [fail-closed starter](../plugin-package/examples/payment-starter/) define
the new-plugin workflow.

The plugin's nativePaymentPackageVersion() returns a code-side literal matching
its release, not a value read from config.json at runtime. It must exactly match
both installed metadata and the package manifest. A package upgrade must update
this code-side version. A resident Octane worker with an old loaded class then
fails closed with PAYMENT_PROVIDER_LOADED_VERSION_UNAVAILABLE until restart;
reading a new manifest does not certify that new PHP code is executing.

Provider identity contains provider_id, contract_version and snapshot_schema.
Capabilities explicitly declare checkout, verify_notification, query,
external_refund, query_refund, currencies and currency_minor_exponent. Missing
capabilities fail closed. This candidate supports local redirect checkout;
network-created checkout and external refund remain unsupported, not simulated.
Both `external_refund` and `query_refund` must be exactly `false`: the current
interface exposes neither operation and Core refunds only to its wallet. Registry
resolution rejects a provider declaring either flag `true` with
`PAYMENT_PROVIDER_CAPABILITY_UNSUPPORTED`, including frozen recovery resolution.
Such a provider is omitted from available methods. This validation does not
revoke supported notification/query recovery merely because a plugin is disabled;
the original package/version/configuration binding still applies.
Provider query is bounded network I/O outside every SQL transaction.

## Frozen dispatch and normalized facts

Every dispatch freezes plugin_code, exact installed/package version,
contract_version, provider_snapshot_schema, canonical merchant/account identity,
original attempt binding and encrypted protocol configuration. Core validates
the provider snapshot against the owning attempt before committing it.
Core's envelope schema is native-payment-dispatch/1. Package version and
contract/schema must match for every later operation; current merchant config
never substitutes for frozen credentials. The bundled Epay NativeAdapter owns
EPay MD5 submit, callback, order-query, exact decimal conversion and ACK.

verifyNotification() and query() produce exactly attempt_id, provider,
provider_account_ref, transaction_ref, amount_minor, currency and occurred_at.
Core checks every binding, canonical positive integer amount, supported currency
and nonempty byte-exact transaction identity again. occurred_at may be null
only when the protocol does not authenticate provider occurrence time; Core
retains first verified observation under the owning user lock.

notificationReference() is bounded protocol parsing only. Core finds a retained
dispatch through installed pinned providers before choosing signature verifier.
Wrong callback method/UUID cannot fall back into historical payment handlers.
Checkout is exactly a type=1 HTTPS redirect with a bounded 8192-byte URL.
ACK is a nonempty printable ASCII protocol string of at most 128 bytes and is
returned only after durable settlement.

## Disable, recovery and destructive lifecycle

New dispatches and checkout generation require a currently enabled payment
registration and installed plugin without an active mutation fence. Disable
revokes new checkout, including reissuing an original checkout URL. An existing
dispatch still permits only reference parsing, notification verification, query
and reconciliation with its exact frozen package/contract/config. Recovery does
not execute plugin boot, routes, schedule, pay or arbitrary lifecycle hooks.

Core serializes managed package lifecycle through a permanent
tx_plugin_package_fence code row, even while no installed plugin row exists.
The short SQL lock order is package fence then installed plugin; no package
code row is deleted after uninstall/delete. A mutation commits its owner token
before hooks, migration or filesystem effects. Installed rows carry that same
token, so the first native dispatch either commits first or is rejected.
Install, delete and upload all participate; an absent registration cannot let
reinstall race the deletion of its package. Delete retains any installed row
until the live directory and assets are removed, then removes registration
before releasing the package fence. Installation hooks/assets also run outside
a SQL transaction, with disabled registration protected by the committed token.

Upgrade, upload replacement and uninstall fail with
PAYMENT_PLUGIN_DRAIN_REQUIRED while any historical native dispatch pins that
plugin. Those effects do not run under financial SQL locks. New checkout and concurrent
enable fail while the fence exists. A crash may leave the mutation fence set;
this is visible fail-closed state requiring operator investigation, not a lease
that silently expires during a destructive mutation.

Initial native bootstrap may retain only inactive (null-token) package fence
inventory. An active or abandoned token still refuses bootstrap; registered
plugins and financial records are not adopted through this inventory exception.

This is deliberately conservative: even successful historical transactions pin
the verifier permanently. A plugin used once cannot be upgraded/uninstalled
through managed lifecycle. The current product does not require compatibility
with old plugins or a multi-version code store. Independent new plugin
development uses the same current contract, while a previously dispatched
package remains retained solely for financial evidence. External refunds are
not part of the product scope; refunds go to the Core wallet. No timeout,
disabled flag or user request erases the pin.
Out-of-band replacement of plugin files remains an operator/deployment risk and
is not prevented by the managed lifecycle fence.
