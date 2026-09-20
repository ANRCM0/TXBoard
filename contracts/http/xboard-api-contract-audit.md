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
