# Management frontend access

The first F2-B management API slice exposes eight reviewed operations at
`/gateway/v1/core/admin`: administrator login/current identity, session
list/revoke/logout, paginated node and machine lists, and site settings read.
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
An actor needs `identity.read`, `identity.sessions.manage`, `network.nodes.read`,
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

TX-F consumes the SDK package built in Actions without uploading its local-only
source. This interface slice does not deliver UI pages, financial writes,
inventory editing or module lifecycle administration. CI uses ephemeral mTLS and
a loopback PHP termination adapter; production PKI remains separate acceptance.
