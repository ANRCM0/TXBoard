# TXBoard Code Agent Instructions

These instructions apply to coding agents working in this repository.

## 1. Product boundary

TXBoard is the Control Plane.

TX-Node is a separate Agent / Data Plane repository. Do not copy TX-Node runtime or installer responsibilities into TXBoard.

The project is evolving toward a **Modular Control Plane Platform**.

Read first:

- `docs/architecture/module-platform-v1.md`
- `docs/architecture/module-platform-development-guide.md`
- `docs/architecture/README.md`
- relevant files under `contracts/`

## TXBoard Native target direction (design approved, not implemented)

- Full plan: `docs/architecture/txboard-native-development-plan.md`; evidence-based inventory: `docs/architecture/legacy-inventory-and-work-packages.md`.
- All future TXBoard HTTP APIs MUST be under `/txapi/*`. Do not create new `/api/v1` or `/api/v2` endpoints. Existing paths are transitional until confirmed migration.
- Distinguish CURRENT effective wire contracts from TARGET `contracts/http/txapi-target-v1.md`. Never present proposed paths as deployed.
- Upgrade by domain with performance/security baseline, tests, feature flags, consumer inventory, rollback and signed-off deprecation.
- Protect payment callbacks, historical balances/orders, TX-Node, Gateway, Agent/MCP, installed plugins and runtime healthchecks; no mass database renaming.
- Prefer meaningful optimization to mechanical renaming; preserve Module Platform v1 and source licensing in `api/LICENSE` / `THIRD_PARTY_NOTICES.md`.

## 2. Current Module Platform status

Completed:

- PR A: Module Package v1 contract / DTOs / capability vocabulary.
- PR B: read-only Module Registry with Plugin, Theme and Agent Ops adapters.
- PR C: Plugin lifecycle integration through `ModuleLifecycle -> PluginLifecycleAdapter -> PluginManager`.
- PR D: Theme Package v1, canonical active-theme state and `ThemeLifecycleAdapter -> ThemeService` delegation.
- PR E: read-only Module Center consuming the Module Registry API as the single inventory model.
- PR F: controlled Module management API delegating supported lifecycle operations through `ModuleLifecycle`.
- PR G: Admin Navigation Registry projected through Module Registry plus optional Admin Bridge v2 host services.
- PR H: Agent Ops registry enrichment with bounded runtime health details and preserved Agent/MCP security boundaries.

Current target:

- Module Platform v1 stabilization and compatibility hardening. Do not invent a Phase I without an explicit versioned architecture proposal.

Required direction:

```text
ModuleLifecycle
      -> PluginLifecycleAdapter
      -> existing PluginManager
```

Do not reimplement plugin lifecycle behavior in Module Runtime.

## 3. Core vs Module

Keep these as Core:

- authentication and administrator access;
- User;
- Plan / Subscription;
- Order;
- Node / Machine / Group / Route;
- TXBoard ↔ TX-Node control plane;
- base settings / audit / authorization;
- Module Runtime itself.

Good Module candidates include:

- Plugin;
- Theme;
- payment provider;
- notification provider;
- external integration;
- Agent integration.

Do not modularize Core domains merely to increase modularity.

## 4. Contract-first

For extension architecture work, use this order:

```text
contract
  -> DTO / model
  -> adapter
  -> runtime delegation
  -> API
  -> Admin UI
  -> migration
```

Do not build UI first and invent the contract afterward.

If a public or cross-repository behavior changes, update the relevant file under `contracts/`.

## 5. Adapter-first migration

Existing runtimes are authoritative.

Use adapters to normalize them:

```text
PluginManager  -> Plugin adapter -> Module Platform
ThemeService   -> Theme adapter  -> Module Platform
Agent Ops      -> Agent adapter  -> Module Platform
```

Adapters may describe and delegate. They must not duplicate domain logic.

## 6. Plugin compatibility

Plugin Package v1 is a compatibility boundary.

Do not require existing plugins to:

- rename plugin codes;
- add a new manifest immediately;
- rebuild against TXBoard Admin source;
- move `admin/dist`;
- rewrite lifecycle code.

Breaking plugin contract changes require a future explicit versioned contract.

## 7. Theme rules

Canonical active-theme state:

```text
frontend_theme
```

Built-in default:

```text
TXBoard
```

`current_theme` is legacy compatibility only. Do not write a second active-theme source of truth.

Theme Admin, user rendering and Module Registry must resolve the same effective theme.

## 8. Agent Ops / MCP rules

MCP is a protocol adapter, not a second control plane.

Never add:

- direct MCP -> MySQL access;
- direct MCP -> Redis publish;
- direct MCP -> TX-Node connection;
- generic shell;
- generic filesystem access;
- automatic approval bypass.

Agent actions continue through Agent Ops permissions, target scope, approval and audit.

## 9. Module Registry rules

The Registry is currently read-only inventory/normalization infrastructure.

It may:

- discover modules;
- normalize descriptors;
- expose health/capabilities;
- isolate discovery errors.

It must not:

- install;
- enable;
- disable;
- upgrade;
- uninstall;
- switch themes;
- approve Agent actions.

Lifecycle changes belong behind explicit lifecycle adapters.

System-owned Module IDs must win deterministic collision resolution over third-party modules.

## 10. Runtime state vs package declaration

A package may declare:

- identity;
- version;
- type;
- compatibility;
- capabilities;
- dependencies;
- Admin navigation metadata.

A package must not be trusted to declare runtime facts such as:

- installed;
- enabled;
- active;
- healthy.

Those values are derived by TXBoard runtime.

## 11. Octane safety

TXBoard runs under Laravel Octane / Swoole.

Avoid unmanaged process-global mutable state.

For caches, registries or hooks, define:

- scope;
- invalidation;
- worker restart behavior;
- upgrade behavior.

Prefer scoped/container-managed services where request-local state is involved.

## 12. Failure isolation

Optional Module failures should not unnecessarily break TXBoard Core.

A malformed third-party module should normally become:

- discovery error;
- failed/degraded Module health;

rather than a global 500 or failed `/api/health`.

Never hide errors silently. Return structured, non-secret diagnostic state.

## 13. Security

PHP plugins are trusted in-process code, not a sandbox.

Do not describe them as isolated.

Keep archive protections for plugin/theme packages:

- path traversal rejection;
- absolute-path rejection;
- symlink rejection;
- size/count limits;
- manifest validation;
- declared Admin app validation.

Never expose credentials in Module discovery errors, logs or Admin responses.

## 14. Admin extension rules

The Admin shell owns shared UX and security context.

Prefer:

- schema-driven UI for simple modules;
- plugin-owned `admin/dist` for complex independent apps;
- Admin Bridge for host navigation/context.

Do not require third-party plugins to modify TXBoard React source.

## 15. API rules

CURRENT: preserve the dynamic `secure_path` Admin boundary and current response conventions. TARGET: `/txapi/admin/{secure_path}` with native schemas, RBAC and audit, only when migrated.

Follow existing response conventions on deployed legacy endpoints; new TXAPI responses follow their separately versioned contract.

Do not create generic Module mutation endpoints until lifecycle contracts and adapters are stable.

Module HTTP contracts:

- read-only Registry: `contracts/http/module-registry-v1.md`;
- controlled lifecycle management: `contracts/http/module-management-v1.md`.

## 16. Testing requirements

For API changes:

```bash
cd api
composer install --no-interaction --prefer-dist
cp .env.example .env
php artisan key:generate
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test
```

For web changes:

```bash
npm ci --no-audit --no-fund
npm run verify:web
```

For production-image-sensitive changes, preserve:

- Docker build;
- embedded healthcheck;
- PHP PDO runtime dependencies;
- Octane smoke test.

Whenever Module Package contract changes, keep JSON Schema, PHP vocabulary/DTOs, examples and tests in sync.

## 17. PR discipline

Prefer one architecture concern per PR.

Before coding:

1. inspect current implementation;
2. identify the authoritative runtime;
3. identify the contract being changed;
4. define backward-compatibility requirements;
5. state explicit non-goals.

Before merge:

1. inspect final diff;
2. remove accidental unrelated changes;
3. ensure tests cover the new contract;
4. verify API/Image/Web CI as relevant;
5. update architecture/development docs when milestone status changes.

Use merge commits for project PRs unless explicitly instructed otherwise.

## 18. Module Platform v1 stabilization rules

Phases A–H complete Module Platform v1.

The implemented control flow remains:

```text
Module Registry
    -> read-only inventory / health / navigation

Module Management API
    -> ModuleLifecycle
    -> specialized Plugin / Theme runtime

Agent Ops / MCP
    -> Agent Ops API
    -> permission / target scope / approval / audit
    -> TXBoard domain service
    -> TX-Node typed operation
```

Stabilization work must preserve these invariants:

- do not create a second source of truth for Plugin, Theme, Agent Ops or Core domains;
- keep Plugin Package v1, Theme Package v1 and Admin Bridge v1 compatibility;
- keep Registry reads side-effect free;
- keep lifecycle mutations behind specialized adapters;
- keep Agent Ops API usable without MCP and preserve permission/scope/approval/audit;
- keep health details bounded, runtime-derived and non-secret;
- do not add direct Module/MCP access to MySQL, Redis, TX-Node, SSH, Docker, shell or arbitrary filesystem;
- do not add a Module database table unless runtime state cannot be reliably derived;
- treat any breaking package/Bridge/Module contract as an explicit future version, not an implicit v1 extension.

The next work should be compatibility hardening, bug fixes and targeted product improvements against the completed v1 contracts.
