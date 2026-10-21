# Management frontend access

The reviewed administrator surface is a strict subset of the generated Core
contract in `contracts/core-api/operations.v1.json`. Generation never admits an
operation merely because it belongs to the admin domain. Core browser ingress,
SDK and PHP use the same generated schemas.

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

Admin and user sources, the SDK and frontend builds belong to TXBoard.

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

### Subscription lifecycle

`subscription.admin.get` is an administrator read requiring `subscription.manage`.
It returns the slotted subscription, or the latest historical record when the slot
is empty, with `subscription_id`, `subscription_version`, `status`, suspension
reason, plan/terms/period identifiers, expiry, next reset and end timestamps.
It returns `lifecycle: null` when the user has no subscription record. This read
is required because the account entitlement projection intentionally omits the
subscription identifier and version when `authorized` is false.

The existing `subscription.lifecycle.suspend`, `resume`, `revoke`, `expire` and
`reset` commands retain their owner transactions, observed versions, bounded
suspension reason and durable recovery rules. Suspension keeps the slot and
entitlement in place but makes the entitlement unauthorized; resume requires the
observed suspended version; revoke and expire close the period and release the
slot.

`identity.accounts.create` remains a versioned administrator workflow for
creating an account with an initial password. The password is never returned.

### Daily node traffic history

`network.nodes.traffic-history.get` is an administrator read requiring the current
`network.nodes.read` grant. Supply `days` as 7, 14 or 30 and 1-20 distinct string
`node_ids`; duplicates return 422 and any missing node returns 404 for the whole
request. Node results retain input order. Responses are private and no-store.

The UsageV2 read owner returns `time_zone: UTC`, `basis: settled-raw-usage/v1`,
`bucket: day`, integer Unix `starts_at`/`ends_at`, and per-node daily `points`
with `date` and exact canonical decimal-string `total_bytes` (up to 40 digits).
The current UTC day and preceding days are zero-filled. Only settled UsageV2
entries count; raw upload plus raw download is attributed to the UTC day of
`batch.ended_at - 1`. Future batch endings are excluded. This history does not
use resettable node counters, billed bytes or legacy statistics. GB presentation
uses decimal 1,000,000,000 bytes per GB.

The owner streams at most 100,000 entries using exact decimal addition. An
additional entry fails the whole read with `USAGE_HISTORY_ENTRY_LIMIT` (422),
without partial data. Invalid or overflowing stored byte values also fail closed
with `USAGE_HISTORY_BYTES_INVALID` or `USAGE_HISTORY_BYTES_OVERFLOW` (422).


## Native administrator repair additions

The current generated catalog and browser allowlist are authoritative for the
operations below. `admin-native-repair-progress.md` records source and acceptance
states; adding a declaration does not itself establish functional acceptance.

- Network machine creation, explicit credentials, token rotation and bounded
  history use `network.machines.create`, `credentials.get`, `token.rotate` and
  `history.get`. Write receipts omit credentials. Credential reads require the
  current management grant, bypass browser query caches and expire in component
  memory. Native node metadata, configuration reads, creation, replacement and
  key material use `network.nodes.protocols.list`, `configuration.get`, `create`,
  `configuration.replace` and `material.generate`. Configuration replacement
  requires its opaque observed revision; the owner validates actual persisted
  protocol fields and rejects unknown input.
- Native dashboard statistics come from `analytics.native-dashboard.get`.
  UTC date windows and per-currency money remain distinct. Rankings use settled
  raw UsageV2 evidence. Missing online/device authority is displayed as unknown.
  Route reordering uses a complete ordered snapshot and CAS under the stable
  ordering head; current-page order is not a substitute for the full set.
- Identity profile, bounded batch creation and metadata keep identity authority,
  protected-target checks and permanent receipt recovery. Subscription amendment
  reads/history/writes are under `subscription.admin.amendment.*`; writes create
  explicit new terms and transition evidence, preserve the current period and
  reset anchors, and synchronize access through its existing owner.
- `billing.admin-account-balances.get` and
  `billing.admin-account-adjustments.create/list` read and adjust the current
  ledger using exact minor amounts and an observed ledger revision. Adjustments
  append balanced evidence with their administrator/reason and dedicated contra
  accounts. They do not count as purchase income or earned commissions and do
  not rewrite historical payment evidence.
- `subscription.admin-templates.list/save/reset` maintain eight versioned output
  formats: the six reference client families plus explicit Surge platform and
  Shadowrocket compatibility entries. Saving binds the source, engine, format
  and digest into the signed Node exchange and invokes the selected Node's real
  bounded template validator. Core does not receive connection plaintext.
  Unsupported engines fail explicitly. Local Monaco assets and workers load
  only when editing; plain text fallback preserves the current source.
- Module cards, configuration, declared presentation operations and theme package
  commands remain separate admitted operations under `platform.module-*` and
  `platform.theme-packages.*`. Their controllers call existing deployment,
  configuration and theme owners. Browser input cannot select a runtime class,
  upload arbitrary archives or obtain stored credential values or references.
- SMTP inspection and Telegram inspection/registration use the native settings
  owner and observed revisions. Diagnostic results are bounded and omit secrets.
  Administrator appearance persists four dedicated settings and is projected by
  the public bootstrap; per-device light/dark/system preference remains local.
  Guest branding validation is independent of CAPTCHA configuration validation.

Functional unit, application, MySQL, Node, network and browser acceptance for
these additions belongs to the exact candidate's GitHub Actions runs according
to `docs/development.md`. Historical local diagnostic results, synthetic browser
fixtures and static builds do not establish that acceptance. The browser workflow
includes the local editor/worker/CSP and template save/reset scenario, and retains
its bounded evidence separately from real Core HTTP acceptance.
