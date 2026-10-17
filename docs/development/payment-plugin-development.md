# Current payment plugin development

Payment plugins use one explicit contract:
[Plugin Package v1](../../contracts/plugin-package/README.md) plus
[native-payment-provider/1](../../contracts/v2/native-payment-provider-v1.md).
There is no old-plugin compatibility mode, `PaymentInterface`, legacy
`pay/notify` adapter, or `available_payment_methods` registration hook.
The bundled EPay implementation is the executable reference. The former
AlipayF2f plugin is no longer bundled because it has no current-contract
implementation; selecting its old provider name cannot initiate a payment.

## Start a new plugin

Copy the three files from
[`payment-starter`](../../contracts/plugin-package/examples/payment-starter/)
into `api/plugins/ExamplePay/`, then replace identity, protocol and tests.
The starter intentionally declares checkout, verification and query as false;
it is not advertised as a usable payment channel until all three operations
are implemented and explicitly enabled. It never returns a simulated paid fact.
No Core provider switch statement or Core-specific plugin registry entry is
needed for a new provider.

The manifest declares the exact current package and payment contract:

```json
{
  "package": {"schema": 1},
  "type": "payment",
  "payment": {
    "contract": "native-payment-provider/1",
    "provider": "ExamplePay",
    "snapshot_schema": "examplepay-dispatch/1"
  }
}
```

The full manifest also requires name, code, version, description and author.
`Plugin.php` extends `AbstractPlugin` and implements
`NativePaymentPluginInterface`. Its three entry points are:

- `nativePaymentPackageVersion()`: a code-side release literal, matching both
  manifest and installed version; never read this value from the manifest.
- `form()`: management configuration schema only; saved merchant credentials
  belong to the Core Payment registration, not plugin instance mutable state.
- `nativePaymentProvider()`: construct the protocol adapter without booting
  routes, hooks, schedules or service providers.

The adapter identity must exactly match manifest `payment` fields.
Management provider lists, forms and checkout use the same validated registry.
An invalid or ambiguous optional provider is not advertised. Bundled plugin
ownership wins a same-provider-ID collision; it grants no actor permission.

## Protocol-only adapter

Implement all methods of `NativePaymentProviderInterface`:

1. `identity/capabilities`: bounded exact values; version and snapshot schema
   are explicit, currency codes use canonical ISO-style three-letter uppercase
   identifiers and the current minor exponent is `"2"`.
2. `accountRef/freeze`: validate merchant configuration and freeze the exact
   attempt identity, canonical account reference, credentials and protocol
   settings. No checkout network side effect occurs here.
3. `checkout`: generate a bounded HTTPS redirect only from frozen input.
   Network-created checkout is not supported by this contract.
4. `notificationReference`: bounded parsing only, returning the original
   `tx` plus 34 lowercase hex provider reference, or null. It cannot query
   user-controlled URLs, verify a different merchant, or create financial facts.
5. `verifyNotification/query`: use frozen merchant identity/configuration;
   authenticate the result and match reference, status, amount and currency.
   Query uses bounded network I/O outside SQL transactions. Return only
   `attempt_id/provider/provider_account_ref/transaction_ref/amount_minor/`
   `currency/occurred_at`, or null for an inconclusive query.
6. `acknowledgement`: a printable ASCII provider ACK of at most 128 bytes.
   Only Core returns it, after durable settlement.

Do not use floats for money, accept unsigned amount fields, follow arbitrary
URLs, log frozen credentials, or replace an old dispatch's configuration with
current payment-registration settings. Throw on invalid evidence; an unknown
provider response is not a failed financial transaction and cannot free holds.

Plugins never write orders, receipts, wallet balances, journals, subscriptions
or fulfillment work. Core validates every returned fact and commits all
authoritative state. `external_refund` and `query_refund` remain false; the
product refunds to the Core wallet and does not require channel refunds.
PHP plugins are trusted in-process application code, not a sandbox.

## Lifecycle and maintenance

Before the first dispatch, a package may be replaced by a complete higher
SemVer release using the existing managed upload/update flow. Dependencies,
archive/admin assets and lifecycle ownership are validated. Do not manually
overwrite live files.

After any native dispatch exists, managed replacement, upgrade, uninstall and
delete remain blocked with `PAYMENT_PLUGIN_DRAIN_REQUIRED`. Even a settled
attempt can receive a late or disputed notification; disabling the plugin or
waiting a timeout does not erase that dependency. This is a financial evidence
guard, not support for old plugins. Current merchant configuration may be
changed for new dispatches, while original frozen credentials remain intact.

Disable stops new checkout and URL reissue, but original callback verification
and query remain available without booting the plugin. Mutation fences and
code-side versions reject concurrent changes and stale Octane classes.
After an allowed package update, restart workers before new checkout. A new
plugin with a new code/provider identity can be developed independently; it
must not impersonate or take over old dispatches.

No multi-version code repository or automatic historical dependency handoff
is supplied. Removing a pin, renaming a provider to bypass a pin, or deleting
dispatch/ledger rows is never an upgrade procedure.

## Acceptance checklist

Run runtime/database/network tests in GitHub Actions under the repository's
development policy. Include at least:

- Manifest/schema/interface/adapter identity agreement and rejection of missing,
  unknown or old contracts before checkout or package effects.
- Exact signed amount/currency/account/reference/status checks; forged,
  incomplete and replayed callback/query inputs cannot write partial money.
- Duplicate settlement and cross-order/provider/owner conflicts; only one
  purchase allocation and durable fulfillment result.
- Disable, merchant rotation, query timeout, unknown result, late payment and
  encrypted frozen evidence recovery without current configuration substitution.
- Managed mutation fencing, pinned destructive lifecycle, archive traversal,
  dependency failures and old Octane code rejection.
- Management lists/forms only advertise current validated providers; a hook
  cannot introduce a channel or re-enable direct `pay/notify` execution.
