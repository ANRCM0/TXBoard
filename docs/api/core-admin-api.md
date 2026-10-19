# Management frontend access

The reviewed administrator surface contains 119 explicit operations in
`contracts/core-api/operations.v1.json`. The full Core contract contains 173
operations. Generation never admits an operation merely because it belongs to
the admin domain. Core browser ingress, SDK and PHP use the same generated schemas.

## Admission and identity

The PHP Core process admits this surface when `CORE_BROWSER_ENABLED` and
`CORE_BROWSER_ADMIN_ENABLED` are enabled (the default). Redis provides ingress
and login limits. Browser requests use the configured public origin; they do
not carry internal service credentials or certificates.

`GET /gateway/v1/core/admin/capabilities` returns the compiled surface admitted
by the current browser feature gates. Discovery does not authorize the original
administrator.
`POST /gateway/v1/core/admin/operations/<id>` accepts exactly `{"input":{...}}`,
with generated input/output/meta validation, no query parameters and no duplicate
JSON keys. Login is anonymous and throttled. Authenticated operations require the
original Bearer and `X-Core-Admin-Path`; Core rechecks the current account, path,
Sanctum ability and durable actor grant. Browser code has no service credentials.

Commands require an original action UUID and never retry automatically. Unknown
results retain the same input and key in session memory across navigation. A
replaced session discards old inputs and acknowledgements. A rejected read hides
cached data; recovery requires a new authorized read. Financial commands retain
their existing domain transaction and durable recovery rules.

## Current frontend entries

Admin and user sources, the SDK and frontend builds belong to TXBoard. TX-F is a
historical import recorded in the frontend provenance manifest.

The admin router connects accounts, orders, catalog publications, groups, routes,
access audit, support, content, coupons, gifts, reviewed commissions, sessions,
audit logs, module/plugin/theme inventories, queue observation, mail templates,
payment configuration and categorized site settings. Node and machine detail
routes read their exact IDs; they do not scan a cached inventory page. The traffic
reset page reads the current native subscription and uses its observed version;
legacy User traffic fields are not the reset authority.

Module/plugin/theme entries expose registry state, compatibility and supported
lifecycle declarations. Installation, activation and upgrades remain owned by the
controlled deployment/runtime flow; these pages do not create a generic mutation
or archive upload proxy. Queue views expose bounded metadata, not job payloads or
raw exception messages. External commission payout remains outside this scope.

## Settings and payment configuration

`settings.configuration.get` returns a fresh native settings view, an opaque
version and configured flags for write-only credentials. The reviewed field
metadata is `contracts/core-api/admin-settings.v1.json`. Public login-limiter
names map to the existing settings owner; they do not create a second setting.
Unsupported legacy business and global Node credential fields are not admitted.

`settings.configuration.update` requires the observed version and a nonempty
patch. The owner validates the patch, serializes it with the compatibility
`settings.site.update` writer and invalidates caches after SQL commit. The browser
sends only changed fields in the selected category. Blank credential inputs keep
stored values. A conflict requires a fresh read and explicit re-editing.

Payment configuration uses four dedicated operations: list current methods,
discover admitted native providers, read their bounded form declarations, and
save an explicit versioned configuration. Reads never return merchant values,
callback UUIDs or stored configuration. Form fields report only whether a value
exists. Save validates declared string fields and the same provider account
adapter used by checkout; omitted configuration values remain unchanged. Existing
payment attempts retain their frozen dispatch. Current native checkout supports
zero channel fees; merchant qualification and production callbacks are separate
operational acceptance.

Non-financial SQL mutations recheck current session and grants inside the native
transaction. `admin-bindings.v1.json` fixes controller targets; request input cannot
select a PHP class, method, arbitrary route or URL.

## Legacy route accounting and acceptance

`admin-migration.v1.json` records 151 legacy routes: 57 bound, 14 native replacement
mappings, 31 user-deferred native business operations, 21 adapter-required and 28
requiring dedicated handling. This inventory is historical route accounting, not
an overall feature completion percentage. A supported native replacement can
exist alongside an unadapted historical URL; old payloads are not forwarded.

Local checks cover generation, types, syntax, source ownership and boundaries.
Functional, MySQL, browser and actual immutable-image checks run in Actions for
the exact candidate. Browser fixtures explicitly use synthetic Core browser ingress responses
validated against generated input/output/meta. API/MySQL and actual Core image
acceptance remain separate evidence; they do not certify live production UI,
production PKI, load capacity or a formal release. New user product work remains
deferred.
