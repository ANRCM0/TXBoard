# Xboard API Contract Audit

> Baseline upstream: `cedar2025/Xboard` `master`
>
> Audited commit: `4f48e61a2cbc6db5338872b6bdb45ef954ec1256`
>
> TXBoard goal: run against current Xboard core while remaining tolerant of Xboard-derived forks and plugin extensions.

## 1. Architecture baseline

Current Xboard does **not** use one homogeneous API surface.

- User business APIs are still primarily under `/api/v1/user/*`.
- Public Passport APIs exist under `/api/v1/passport/*` and `/api/v2/passport/*`.
- Admin business APIs are under `/api/v2/{secure_path}/*`.
- Admin `secure_path` is a runtime setting and must not be compiled permanently into the frontend.
- Xboard's Blade admin shell exposes runtime values through `window.settings`, including `base_url` and `secure_path`.
- Authentication uses Laravel Sanctum bearer tokens.
- Login `auth_data` is already formatted as `Bearer <token>`.

TXBoard therefore uses two Admin clients:

1. Public Passport client: `/api/v2`
2. Secure Admin client: `/api/v2/{secure_path}`

The secure prefix is resolved in this order:

1. `VITE_API_V2_ADMIN_PREFIX`
2. legacy `VITE_API_V2_PREFIX`
3. `window.settings.secure_path`
4. development fallback

## 2. Response conventions

Xboard currently has several response shapes.

### Standard API envelope

```json
{
  "status": "success",
  "message": null,
  "data": {},
  "error": null
}
```

### Standard paginator

```json
{
  "total": 100,
  "current_page": 1,
  "per_page": 10,
  "last_page": 10,
  "data": []
}
```

### Legacy/custom top-level result

Examples:

```json
{
  "data": [],
  "total": 42
}
```

and:

```json
{
  "data": [],
  "pagination": {
    "current_page": 1,
    "last_page": 2,
    "per_page": 15,
    "total": 20
  }
}
```

TXBoard must not globally unwrap every object containing a `data` key.

The User API adapter now unwraps only responses that explicitly contain Xboard's `status` envelope field. Raw top-level pagination/custom structures are preserved.

## 3. Authentication audit

| Contract | Xboard current | TXBoard status |
| --- | --- | --- |
| User login | `POST /api/v1/passport/auth/login` | ✅ compatible |
| Admin login | `POST /api/v2/passport/auth/login` | ✅ fixed |
| Admin business API | `/api/v2/{secure_path}/*` | ✅ runtime-aware |
| Sanctum auth | `Authorization: Bearer <token>` | ✅ normalized |
| Login admin check | `is_admin` | ✅ enforced |
| Core logout API | no `/user/logout` route | ✅ local logout only |
| Mail-link login | Passport core route | ✅ compatible |
| Telegram login | plugin-defined behavior | ⚠️ plugin-dependent |

Important: Xboard login returns both `token` and `auth_data`. The `token` field is not a replacement for the Sanctum Admin bearer. TXBoard Admin now requires `auth_data` (or a compatible `access_token`) and no longer falls back to the user token.

## 4. Admin API audit

### Config

| Area | Status | Notes |
| --- | --- | --- |
| `config/fetch` | ✅ | scoped result may be nested by key; TXBoard already unwraps it |
| `config/save` | ✅ | current field schemas already aligned |
| Telegram webhook | ✅ | current body uses `telegram_bot_token` |
| SMTP test | ✅ | aligned |
| Mail templates | ✅ | list/get/save/reset/test aligned |

### Server / machine

| Area | Status |
| --- | --- |
| Machine fetch/save/drop | ✅ |
| Machine token/reset/install command | ✅ |
| Machine nodes/history | ✅ |
| Node fetch/save/update/drop | ✅ |
| Batch node update/bind | ✅ |
| Group CRUD | ✅ |
| Route CRUD | ✅ |

### Finance

| Area | Status | Notes |
| --- | --- | --- |
| Plan fetch/save/drop/update/sort | ✅ | current prices JSON model normalized in TXBoard |
| Order fetch | ✅ | custom pagination already handled |
| Order detail | ✅ fixed | current Xboard requires `POST /order/detail { id }` |
| Order paid/cancel/update/assign | ✅ |
| Coupon fetch/generate/show/drop/update | ✅ |
| Gift Card admin | ✅ route-compatible | paginator responses stay top-level |
| Payment admin | ✅ implemented | schema-driven gateway form / CRUD / enable / fees / sort |

### Theme

| Area | Status |
| --- | --- |
| Get themes | ✅ |
| Get theme config | ✅ fixed to POST `{ name }` |
| Save theme config | ✅ fixed to `{ name, config }` |
| Delete theme | ✅ fixed to `{ name }` |
| Upload theme | ✅ ZIP |

Current `getThemes` backend shape is `{ themes, active }`; TXBoard now normalizes that structure.

### Tickets

Current Xboard core no longer exposes `/ticket-type/*` routes, even though one upstream frontend snapshot still contains Ticket Type UI.

TXBoard follows the **backend master contract**, not the stale frontend contract.

| Area | Status |
| --- | --- |
| Ticket list | ✅ normalized from `{ data, total }` |
| Ticket detail | ✅ |
| Reply | ✅ |
| Close | ✅ |
| Ticket Type CRUD | ❌ removed from TXBoard |
| Withdrawal auto-settlement through ticket close | ❌ removed; backend does not implement it |

Important: current `POST /ticket/close` only closes a ticket. Extra `withdraw_paid` or `withdraw_rejected` flags are not processed by core Xboard, so TXBoard no longer presents those actions as if money were settled automatically.

### Users

| Area | Status |
| --- | --- |
| User paginated fetch | ✅ |
| User detail/update | ✅ |
| Reset secret | ✅ |
| Generate user | ✅ |
| Destroy | ✅ |
| Bulk mail / ban | ✅ |

Admin user fetch uses standard paginator output, and money fields are transformed by the backend to major currency units.

### Plugin

Core plugin management endpoints are compatible:

- list/types
- install/uninstall
- enable/disable
- upload/delete/upgrade
- config get/save

⚠️ Plugin runtime routes are **not automatically namespaced by Xboard**. `PluginManager::loadRoutes()` directly loads each plugin's `routes/api.php`; the plugin author controls the real URL.

Therefore:

- `admin_crud.api.list/save/delete` is the authoritative CRUD contract.
- TXBoard no longer fabricates `/plugin/{code}/...` CRUD paths when a plugin omits API metadata.
- Plugin menu pages are loaded only from explicitly declared `url`, `embed`, or `component` values.
- Telegram Login is similarly plugin-dependent.

## 5. User API audit

### Plans

Current `PlanResource` converts the new `prices` JSON model back to legacy user-facing fields:

- `month_price`
- `quarter_price`
- `half_year_price`
- `year_price`
- `two_year_price`
- `three_year_price`
- `onetime_price`
- `reset_price`

Values are returned in cents. TXBoard's User Plan model remains compatible.

### Orders

Current User order list endpoint returns the **entire order collection**, not a pagination object.

TXBoard now:

- fetches the full list;
- filters status locally;
- paginates locally.

This also avoids sending status `4` to the backend, because current Xboard validates list status as only `0..3`.

Order cancellation in current core is only allowed for status `0`. TXBoard's cancel button now follows that rule.

Other User order contracts:

| Endpoint | Status |
| --- | --- |
| save | ✅ |
| detail GET by `trade_no` | ✅ |
| payment methods | ✅ |
| checkout | ✅ raw `{type,data}` handled |
| check | ✅ |
| cancel | ✅ |

### Tickets

Current user ticket list returns the entire collection.

TXBoard now client-side paginates it.

Current ticket save accepts only:

- `subject`
- `level`
- `message`

and returns `success(true)`.

TXBoard removed the unsupported `ticket_type_id` field and Ticket Type selector.

### Invite / commission

Current `stat` indexes are:

| Index | Meaning |
| --- | --- |
| 0 | invited registered users |
| 1 | confirmed/valid commission sum, cents |
| 2 | unconfirmed commission balance, cents |
| 3 | commission rate, percent |
| 4 | available commission balance, cents |

TXBoard UI has been corrected to this mapping.

`GET /user/invite/details` returns top-level `{ data, total }`; the User API adapter now preserves it.

`GET /user/invite/save` is used to generate a new invite code.

### Gift Card

- check/redeem/detail use normal envelopes.
- history uses top-level `{ data, pagination }`.
- TXBoard's updated User response adapter preserves the full history result.

### User account

| Endpoint | Status |
| --- | --- |
| info | ✅ |
| subscribe | ✅ |
| getStat | ✅ |
| changePassword | ✅ |
| update | ✅ |
| resetSecurity | ✅ fixed to GET |
| getActiveSession | ✅ |
| removeActiveSession | ✅ |
| getQuickLoginUrl | ✅ |

## 6. Comm config and feature gates

Current Xboard core Guest config exposes a relatively small base set:

- TOS URL
- email verification
- forced invite
- email suffix whitelist
- CAPTCHA config
- app description / URL / logo

It then passes the object through:

`HookManager::filter('guest_comm_config', ...)`

Plugins can inject additional frontend settings.

Current User Comm config exposes:

- Telegram bot enable/discussion link
- Stripe public key fallback
- withdrawal methods / close flag
- currency
- commission distribution L1/L2/L3

It does **not** expose many feature flags that older frontends expected, such as `ticket_enable`, `knowledge_enable`, or `gift_card_enable`.

TXBoard feature-gate policy is now:

- config not loaded: do not claim feature state;
- explicit `0/false`: disabled;
- missing value: assume built-in core route remains available.

This prevents current Xboard core features from disappearing simply because the backend did not expose a UI flag.

## 7. Known upstream frontend/backend drift

The audit found at least one clear drift inside upstream itself:

- one Admin frontend snapshot still implements Ticket Type CRUD;
- current backend `master` contains no Ticket Type routes/model contract.

TXBoard uses the current backend source as the contract authority.

## 8. Current unresolved / extension-dependent areas

### Payment runtime verification

TXBoard now implements the current Xboard PaymentController contract, including dynamic plugin forms, CRUD, enable/disable, fees and sorting.

Status: **route/source compatible; live-backend verification still pending**.

### Telegram Login

Core Guest config supports plugin injection and the plugin development guide demonstrates Telegram Login as a plugin.

The core Passport route does not guarantee `/passport/auth/telegramLogin`.

TXBoard renders the Telegram Login widget only when plugin-injected Guest config provides `telegram_login_enable`, `telegram_bot_username`, and either a redirect URL in `telegram_login_domain` or an AJAX endpoint in `telegram_login_endpoint`. Callback-mode login posts Telegram user data to the declared endpoint instead of guessing a core route.

Status: **plugin-dependent**.

### Plugin CRUD routing

Schema-provided API paths are required for dynamic CRUD requests. TXBoard intentionally avoids guessed plugin routes because Xboard loads plugin `routes/api.php` without adding a universal path prefix.

Status: **explicit-contract only**.

### Fork-specific fields

Xboard-derived backends may add config fields, plugin routes, payment methods, or user capabilities.

TXBoard should keep fork-specific differences inside adapters and schema-driven configuration rather than hard-coding them into page components.

## 9. Recommended fork compatibility contract

For an Xboard-derived backend, preserve these stable boundaries whenever possible:

1. Keep core Xboard route paths and response shapes for unchanged features.
2. Add new fields rather than renaming existing fields.
3. Expose optional feature switches explicitly as `0/1` or booleans.
4. Put plugin/fork UI metadata in Guest/User Comm config or plugin schemas.
5. Provide explicit API paths inside plugin `admin_crud` metadata.
6. Preserve Sanctum `Bearer` authentication semantics.

A future fork can optionally expose a capability endpoint such as:

```json
{
  "product": "xboard-fork",
  "api_version": "2",
  "txboard_contract": 1,
  "features": {
    "gift_card": true,
    "ticket": true,
    "payment_admin": true
  }
}
```

TXBoard can then select adapters and feature gates without guessing from failed requests.

## 10. Next integration steps

The next practical phase is runtime integration against a real Xboard instance:

- Admin Passport login
- runtime `secure_path`
- System Config
- User login
- Plans and Orders
- Machine and Nodes
- Users and Tickets
- Coupon and Gift Card
- Plugin manager
- Payment manager

Each runtime test should be recorded as:

- ✅ verified against live backend
- ⚠️ adapter required
- ❌ unavailable in tested backend
- ➕ backend capability not yet exposed in TXBoard

## 11. TXBoard sweep deltas

Recorded fixes applied while aligning the TXBoard frontends, API and node runtime
against the current Xboard core. Each entry states the drift and its resolution.

### 11.1 Admin secure path

- Drift: the Admin SPA compiled a hard-coded `/api/v2/<hash>` prefix, which breaks on
  any instance whose `secure_path` has been rotated.
- Resolution: the prefix is resolved at runtime from `VITE_API_V2_ADMIN_PREFIX`, then
  `window.settings.secure_path`, then the value cached from a previous sign-in.
  `POST /api/v2/passport/auth/login` now returns `secure_path` so a fresh browser can
  bootstrap. A rotated path that starts answering 404 drops the cache and returns the
  operator to sign-in.

### 11.2 Admin login CAPTCHA

- Drift: the backend verified CAPTCHA on user login only; admin login accepted a
  password with no challenge even when `captcha_enable` was on.
- Resolution: `V1\Passport\AuthController::login` now runs `CaptchaService::verify`
  first and fails with the service error. `GET /api/v2/guest/comm/config` exposes the
  public CAPTCHA settings to the Admin SPA, which renders reCAPTCHA v2, v3 or Turnstile
  and forwards `recaptcha_data` / `recaptcha_v3_token` / `turnstile_token`.

### 11.3 Comm config field coverage

- `GET /api/v1/guest/comm/config` additionally returns `app_name`, `stop_register`,
  `login_with_mail_link_enable`, `try_out_enable` and `try_out_plan_id`.
- `GET /api/v1/user/comm/config` additionally returns `commission_withdraw_limit`,
  `ticket_must_wait_reply`, `plan_change_enable`, `try_out_enable` and `try_out_plan_id`.
- Feature switches now **are** persisted: `invite_enable`, `commission_enable`,
  `gift_card_enable`, `coupon_enable`, `ticket_enable`, `knowledge_enable`,
  `traffic_log_enable`, `announcement_enable`, `register_enable` and `traffic_warn_rate`
  are accepted by `ConfigSave`, editable on the admin site settings page, and returned by
  both comm config endpoints. A missing flag still means "enabled", so an install that
  never touched them keeps every entry available.
- Still **not** exposed: `withdraw_fee_rate`. No core code path charges a transfer or
  withdrawal fee, so exposing it would advertise behaviour that does not exist (see 11.9).

### 11.4 Commission transfer and withdrawal

- Units: `commission_withdraw_limit` is a major-unit amount. `stat[4]` and
  `commission_balance` are cents. Both the User SPA and `TicketController::withdraw`
  compare major against major, which is consistent and was left unchanged.
- Drift: the User SPA enforced a minimum transfer amount (`invite.minimum`) that
  `POST /user/transfer` did not, so a direct API call could move any amount above one
  cent. `UserController::transfer` now enforces the same minimum.
- Drift: the User SPA rendered a transfer/withdrawal fee preview sourced from
  `withdraw_fee_rate`, which no core setting provides and no code path charges. The fee
  UI and the `withdraw_fee_rate` field were removed; `POST /user/transfer` moves the
  full amount, which is what the UI now states.
- Drift: `commission_withdraw_limit` also governed transfers, so an operator could not
  set independent minimums. `commission_transfer_limit` is now a first-class setting
  (`ConfigSave`, admin invite page, `GET /user/comm/config`). `UserController::transfer`
  and the User SPA use it, falling back to `commission_withdraw_limit` when it is unset,
  so existing installs keep their current behaviour.

### 11.5 Localized minimum-amount message

- Drift: the zh-CN and zh-TW strings for
  `The current required minimum withdrawal commission is :limit` used `:limitCNY`, a
  placeholder the controller never supplies, so users saw a literal `:limitCNY`.
- Resolution: both locales now use `:limit`, and a matching transfer message was added
  to zh-CN, zh-TW, ru-RU and en-US.

### 11.6 Node handshake settings

- Drift: `GET|POST /api/v2/server/handshake` returned no intervals, so a node fell back
  to its local defaults instead of the panel `server_push_interval` /
  `server_pull_interval` settings.
- Resolution: the response now carries `settings.push_interval` and
  `settings.pull_interval`, which is exactly what `node/internal/controlplane` decodes.

### 11.7 Ingress route surface

`web/Caddyfile` previously proxied `/api/*` only, which left the WebSocket endpoint,
plugin pages, subscription output and uploaded assets unreachable behind the gateway.
It now also proxies `/ws`, `/plugin/*`, `/storage/*` and `/{SUBSCRIBE_PATH}/{token}`
(env `SUBSCRIBE_PATH`, default `s`) to the API container, and keeps the Admin SPA on
`/admin/` and the hash-routed User SPA on `/`.

The subscription matcher is the one place the gateway has to agree with a panel setting.
That agreement is now rendered instead of hand-maintained: `php artisan
panel:subscribe-path --export` prints the DB value and `deploy/sync-gateway.sh` writes it
to `deploy/.env`, which `deploy/compose.yaml` feeds to the `web` service as
`SUBSCRIBE_PATH`. The default `s` matches the Caddyfile fallback, so an unsynced install
still boots.

### 11.8 AccessAudit plugin deployment

- Drift: the plugin lived in `integrations/AccessAudit`, outside the `plugins` scan
  path, so `PluginManager` never loaded it.
- Resolution: `deploy/compose.yaml` mounts it at `/www/plugins/AccessAudit`. Both
  `admin_menus` entries in `config.json` now declare a `url`, which is what
  `PluginMenuPanel` needs in order to embed the plugin pages.

### 11.9 Legacy admin delivery path

- Drift: upstream kept a Blade shell (`resources/views/admin.blade.php`) served from
  `GET /{secure_path}`, loading `/assets/admin/*`, while TXBoard ships the admin SPA at
  `/admin/` and never builds those assets. The legacy URL rendered a broken page.
- Resolution: the route now answers `302` to `/admin/` and the dead view was removed.
  `tests/Feature/Admin/AdminEntryRedirectTest.php` pins the redirect.

### 11.10 Automated gates

- `api/phpunit.xml` plus a `composer test` script wire the previously orphaned
  `api/tests` suite; `php artisan test` (the CI command) runs 40 tests. The SQLite
  `:memory:` DSN is honoured directly, and the settings cache store is configurable
  (`cache.setting_store`, pinned to `array` in tests) so the suite does not need a live
  Redis or MySQL. `AdminContractRegressionTest` and `UserKnowledgeCategoryTest`
  pin the runtime contract fixes from 11.13 and fail against the pre-fix code.
- Both frontends now run Vitest (`npm run test` per workspace, folded into
  `npm run verify:web`): 27 tests across `@txboard/admin`, `@txboard/user` and
  `@txboard/shared`. The adapter suites cover request path/method/body shaping, envelope
  unwrapping, bearer-token injection and legacy response preservation; the shared suite
  covers captcha provider selection and controller behaviour against stubbed SDKs.
- `web/admin/src/router.test.ts` imports the whole route tree, which costs ~4s against
  the default 5s Vitest timeout and made the gate flaky on a loaded machine; it now
  carries an explicit 30s budget and passes repeatedly.

### 11.11 Shared frontend module

- Drift: the Admin SPA (React) and the User SPA (Vue) each carried their own Cloudflare
  Turnstile / Google reCAPTCHA implementation, so every captcha fix had to be applied
  twice and the two copies had already diverged (different script ids, different
  reCAPTCHA v3 action labels, different unavailable-token payloads).
- Resolution: `web/shared` is a new workspace package (`@txboard/shared`) holding the
  framework-agnostic captcha core — provider/site-key selection, script loading, widget
  mounting with render-key deduplication, token retrieval and reset. Each SPA keeps a
  thin binding (`CaptchaWidget.tsx` / `CaptchaWidget.vue`) that supplies only its action
  label and fallback payload. `CaptchaPayload` is re-exported from the shared package so
  there is a single definition.

### 11.12 Remaining residuals

- No transfer/withdrawal **fee** support exists; adding it is a product decision and a new
  charge path (see 11.3/11.4). Reviewed and deliberately deferred.
- The Admin SPA's unmatched routes render an explicit in-app 404; the old `user/*`
  placeholder ("用户扩展" skeleton) was removed as dead code.
- Automated gates now cover PHPUnit, Vitest, typecheck and production builds.
- A live backend was subsequently booted (Octane + SQLite/MySQL + Redis) and the
  frontends' request set was replayed against it; 11.13 records the contract
  defects that surfaced and their fixes.
- `GET {secure}/system/getQueueMasters` answers `403` for every account, because
  `HorizonServiceProvider::gate()` is still the Laravel skeleton placeholder
  (`in_array($user->email, [])`). Deciding who may inspect Horizon is an operator
  security decision, so the gate is left untouched and flagged here rather than
  silently broadened.
- `StatisticalService` opens Redis through the raw `Redis` facade rather than a
  cache store, so the ranking/report paths need a live Redis and cannot run under
  the suite's `array` cache driver — which is why the ranking regression test mocks
  the service. This works in the compose deployment, where Redis is bundled.

### 11.13 Runtime sweep against a live backend

The full parameterless `GET` surface (80 routes) plus 15 parameterised calls was
replayed against a running containerised instance, and the results compared with
what the two SPAs actually consume. Before the fixes, 43 answered `200` and one
answered `500`; eight of the `200`s carried a shape the frontend could not use.
A later sweep widened the net to the whole route table and found two more `500`s.
Each row below is now pinned by a regression test in
`api/tests/Feature/Admin/AdminContractRegressionTest.php` and
`api/tests/Feature/User/UserKnowledgeCategoryTest.php`, which fail against the
pre-fix code.

After the fixes the sweep reports **no response in the `5xx` class**. The
remaining non-2xx replies are `400`/`422` validation answers and one `403` from
Horizon's authorization gate, all of them returned to deliberately
parameterless or unauthenticated calls.

| # | Symptom before the fix | Fix |
| --- | --- | --- |
| 1 | `user/getUserInfoById` returned `balance`/`commission_balance` in cents while `user/fetch` returned major units (`1234` vs `12.34` for the same user), so the admin edit form multiplied every balance by 100 on save. | `transformUserData()` applies the same `/100` conversion as the list. |
| 2 | `GET /api/v1/user/knowledge/getCategory` returned `500 Method ...::getCategory does not exist`, so the user SPA knowledge page never loaded. | Method implemented (language filter, dedupe, `show=1` gate). |
| 3 | `system/getAuditLog` returned only `{total,data}`, so the audit table had no page count. | Returns the standard paginator shape. |
| 4 | `traffic-reset/logs` nested everything under a `pagination` key. | `total/current_page/per_page/last_page` at the top level. |
| 5 | `gift-card/templates` and `gift-card/codes` discarded the enrichment mapper, so `type_name`/`codes_count`/`used_count`/`template_name`/`status_name`/`user_email` were missing from every row. | `setCollection($data)` before paginating. |
| 6 | Coupon create required both dates (`422 开始时间不能为空`) while the admin form has an explicit "不限" state that omits them. | Both columns nullable (new migration), `nullable` validation, and the coupon service treats an empty bound as unbounded. |
| 7 | `notice/fetch` and `knowledge/fetch` ignored `current`/`pageSize`/`title`/`category` and returned the whole table, so admin search and paging were no-ops. | Server-side pagination and filters; `knowledge/fetch?id=` also no longer fatals on a missing row. |
| 8 | The user SPA emitted `skip_recaptcha_v3` / `skip_recaptcha_v3_error`, which nothing in PHP ever read (0 grep hits) — a dead client-side captcha opt-out. | Removed. An unavailable reCAPTCHA v3 token now throws `CaptchaUnavailableError`, which both SPAs already surface as an inline error, so the flow fails closed instead of posting a request the API rejects. |
| 9 | `GET {secure}/mail/template/get` answered `500`: `MailTemplate::getMeta()` is typed `string $name`, and the controller passed `$request->input('name')`, which is `null` when the query parameter is absent — a `TypeError`. | The parameter is validated (`required|string`), so the call answers `422` like its sibling routes. |
| 10 | `GET {secure}/stat/getRanking` answered `500` for two independent reasons: `AdminRoute.php` routed it to a controller method that did not exist (`BadMethodCallException`), and `StatisticalService::getRanking()` queries on `$startAt`/`$endAt`, which only `setStartAt()`/`setEndAt()` populate — null bounds make the query builder throw `Illegal operator and value combination`. | The method exists, validates `type`/`limit`/window, defaults to the last 30 days and answers inside the standard envelope. |

### 11.14 Deployment blockers found while building the images

| # | Symptom before the fix | Fix |
| --- | --- | --- |
| 1 | `xboard:install` read `INSTALLED` with `getenv()`, which returns the string `"false"` for `INSTALLED=false`; `(bool) "false"` is `true`, so a fresh container reported itself as already installed and never migrated. | The flag is parsed as a boolean, preferring the `.env` file over a possibly stale process environment. |
| 2 | Under Docker the installer could not run unattended: it prompted for the database type even though compose already injects the connection. | When `DB_CONNECTION` comes from the container environment the prompts are skipped. |
| 3 | The generated `APP_KEY` was written to `.env` but `config:cache` then froze the placeholder value, because an exported `APP_KEY` wins over the file. | The new key is exported to the running process as well before caching. |
| 4 | `api/.env` and `api/storage/logs`/`theme` were the only mounts, so `INSTALLED=true`, the `APP_KEY`, uploads, sessions and views were lost on every container recreate and the panel reverted to "not installed". | `api/.env` and `api/storage` are bind-mounted. |
| 5 | The image ships Redis on a unix socket (`/data/redis.sock`, `--port 0`) but compose injected nothing, so the app dialled `127.0.0.1:6379`, every cache/queue/setting call failed and the panel silently served defaults. | `REDIS_HOST`/`REDIS_PORT` default to the socket, overridable via `TXBOARD_REDIS_*`. |
| 6 | `api/.env.example` shipped a real `APP_KEY`, letting anyone forge tokens on an instance that never regenerated it. | The key is blank; the installer (Docker) or `php artisan key:generate` fills it. |
| 7 | `deploy/.env.example` documented only `TXBOARD_SUBSCRIBE_PATH`, so the database credentials came from undocumented `:?` defaults and `sync-gateway.sh` truncated `deploy/.env` on every run. | Credentials are required and documented; `sync-gateway.sh` rewrites only its own key. |
| 8 | The API started against a MySQL that was still initialising, and no service had a healthcheck. | `database` has a healthcheck and `api` waits for `service_healthy`. |
| 9 | Mail config used `MAIL_DRIVER` (Laravel 7 era) and a literal `null` `MAIL_FROM_ADDRESS`, so no mail could be sent. | `MAIL_MAILER` and a real `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME`. |
| 10 | The installer pointed operators at `/{secure_path}` as the panel URL, but the gateway's catch-all serves the *user* SPA there (`/{secure_path}` only 302s to `/admin/`), and it printed a re-derived default rather than the configured `secure_path`. | The message now points at `/admin/` and prints the effective admin API prefix. |
| 11 | Following the documented order (`up -d`, then immediately `xboard:install`) left the panel permanently `502`: with `APP_KEY` blank Octane died with `MissingAppKeyException`, supervisord exhausted its retry budget and entered `FATAL`, and `docker compose restart api` did **not** recover it. | The entrypoint generates and persists an `APP_KEY` on first boot, before any service starts, and `startretries` is raised so a transient cold-start failure cannot permanently kill a service. |
| 12 | `env_file: ../api/.env` froze the blank `APP_KEY` into the container environment at create time; an immutable Dotenv never overrides a present variable, so every `docker compose exec` process (the installer, `route:list`, `tinker`) read an empty key while PID 1 used the entrypoint's. The installer consequently minted a **third** key, and the CLI and the running server disagreed on the admin prefix. | `env_file` is removed: the bind-mounted `/www/.env` is the single source of truth, and the installer reuses the key already in the file instead of regenerating it. |
| 13 | `xboard:install` aborted before migrating whenever the container's embedded Redis was not yet accepting connections (the `cache:clear` step threw), leaving an empty schema and a `500` panel. | `api` has a readiness healthcheck so `docker compose up -d --wait` gates the install, and a failed cache clear is downgraded to a warning rather than a fatal. |

