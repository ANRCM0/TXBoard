# Management frontend access

The local F2-B management candidate declares 75 reviewed operations at
`/gateway/v1/core/admin`: administrator login/current identity, session
list/revoke/logout, paginated node and machine lists, site settings read,
account list/detail, and native order list/detail. The source continuation also adds account creation/status,
publication/availability, refunds/reconciliation, subscription lifecycle, bounded network management,
content CRUD, mail templates, queue monitoring, module reads and audit. Runtime acceptance for this continuation is deferred;
previous green checks apply only to their recorded commit.
Other administrator operations are not automatically exposed by generation.

Enable `GATEWAY_CORE_ADMIN_OPERATIONS_ENABLED=true` with an isolated admin
service (`GATEWAY_CORE_ADMIN_ENABLED`, service id/credential/epoch and CA/cert/key
files). A Redis account limiter is required. `compose.core-admin.yaml` provides
an optional operator-configured overlay. The user service is never substituted.
Core must grant `system.capabilities.get` and each intended operation.

`GET /capabilities` intersects current service grants with the compiled surface.
It lists required permissions, not proof of the caller's actor permissions.
`POST /operations/<id>` accepts exactly `{"input":{...}}`, with generated
input/output/meta validation, no query parameters and no duplicate keys.
Administrator login is anonymous and account-throttled; Core verifies passwords,
CAPTCHA and current role before returning Bearer plus management path. Configure
CAPTCHA UI from the existing public site configuration; pass only the actual
provider response in the supported credential fields.

All authenticated operations require the original Bearer and
`X-Core-Admin-Path`. Core checks the current admin role, active account, exact
rotating management path, Sanctum ability and durable actor grant on every call.
An actor needs `identity.read`, `identity.accounts.read`, `commerce.orders.read`,
`identity.sessions.manage`, `network.nodes.read`,
`network.machines.read` or `settings.read` for the respective operations. These
permissions are provisioned by the existing operator authority, never by the
frontend or this namespace.

`createCoreAdminClient` in `@txboard/core-sdk` supplies bounded browser transport,
the current actor/path, canonical decimal strings and `callResult` metadata.
Commands require a logical-action UUID and are never retried automatically.
Session exit can succeed before its response is lost: clear local state and
distinguish server confirmation from transport failure. Service failures return
503; actor session failures return 401, permissions 403, and stale path 404.

Network responses contain only reviewed inventory fields, not private Node or
Machine credentials. Site settings are a limited typed projection. Discovery
schemas include only their transitive references to remain bounded.

Account filters accept an exact email and banned state; detail reads the current
Subscription entitlement through Identity. The projection excludes credentials,
balances and commissions. Native orders accept user ID, exact trade number and
status filters. Billing returns only `tx-core.purchase-terms/v2` orders and
validates their frozen purchase terms for the purchased name, amount and currency.
Legacy orders are excluded. Both resources use bounded page/per-page inputs,
primary-database reads, decimal-string IDs and generated output validation.
These operations do not modify accounts or financial state.

TX-F consumes the SDK package built in Actions without uploading its local-only
source. This interface slice does not deliver UI pages, financial writes,
inventory editing or module lifecycle administration. CI uses ephemeral mTLS and
a loopback PHP termination adapter; production PKI remains separate acceptance.


## Unified administration continuation

The reviewed browser surface is the explicit `surfaces.admin` array in
`contracts/core-api/operations.v1.json`. Both Gateway and SDK inventories, including
read/write types, are generated from it. Adding an admin-domain contract alone
does not expose it. `admin-bindings.v1.json` contains image-owned controller targets;
request input cannot select a class, method, URL or arbitrary route.

`createCoreAdminSession.read` accepts only read operations. `write` requires the
original action UUID and never retries; logout and session revocation keep their
dedicated lifecycle methods. Unknown mutation responses require state confirmation
or original-key recovery. The browser has no service credentials.

Generic non-financial SQL writes use a native-installation transaction boundary
and recheck the current account, token and actor grant under locks. Financial,
publication and subscription owners retain their own transaction and recovery
semantics. Machine notifications and template-cache invalidation run after commit.
Resource locators are removed from controller payloads and set only as explicitly
named route parameters; root-array sort requests use an explicit bounded payload field.

The inventory `contracts/core-api/admin-migration.v1.json` accounts for 151 legacy
admin routes: 57 bound routes, 13 native replacement mappings, 32 legacy-business
operations deferred by the user, 21 adapters still required, and 28 routes requiring
dedicated handling. A mapping can cover only a supported subset: site settings,
per-account status CAS, verified Offers and frozen native orders do not forward
legacy financial or entitlement fields. This continuation does not claim that all
legacy functions have been migrated.

The deferred legacy-business operations are recorded for later on-demand work.
Packages, secret-bearing responses and schema-driven extension configuration must
use purpose-built reviewed adapters rather than a generic JSON proxy. Existing
legacy URLs remain in place until replacement deployment acceptance.

Static generation, route-accounting, TypeScript and Kernel/boundary checks are
separate from the deferred PHP/MySQL/HTTP/browser acceptance. SDK 0.1.2 is authored
source only; TX-F continues using its previously recorded Actions SDK until a new
accepted artifact is available.
