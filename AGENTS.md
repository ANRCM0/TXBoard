# TXBoard Code Agent Instructions

These instructions apply to coding agents working in this repository.

## 1. Product boundary

TXBoard is the Control Plane.

TX-Node is a separate Agent / Data Plane repository. Do not copy TX-Node runtime or installer responsibilities into TXBoard.

The project is evolving toward a **Modular Control Plane Platform**.

Read first:

- `docs/architecture/module-platform-v1.md`
- `docs/architecture/agent-ops.md`
- `docs/README.md`
- relevant files under `contracts/`

## TXAPI 与跨仓接口边界

- TXBoard 应用 API 统一使用已注册的 `/txapi/*` 路由；不要新建未注册的 V1/V2 入口。
- User、Admin、Node/Machine、Agent 与支付商回调使用各自独立的凭据和鉴权中间件；不得互相借用。
- 当前 HTTP 路由以 `api/routes/txapi.php`、`api/routes/web.php` 和 `api/app/Providers/RouteServiceProvider.php` 为准；TXNode WSS 由 Workerman 管理，不属于 Laravel HTTP registry。
- 跨仓修改优先阅读 `contracts/http/external-adapter-current.md`、`contracts/http/theme-integration-current.md`、`contracts/node-protocol/txnode-integration-current.md`。
- 可选独立 Gateway 不持有 Laravel 用户/订单/资金/Node 状态，也不能接管管理员、Agent、支付 Webhook 或订阅密钥。
- 对外协议修改必须同时更新对应契约和真实消费者，按现有回归测试验证权限、事务及重试语义。

## 2. Module Platform

Module Registry 提供只读库存和健康视图；Mutation 由 Module Management API 通过 `ModuleLifecycle` 委托各自 Plugin/Theme 运行时执行。适配器不得重新实现核心域逻辑。API 和扩展契约见 `contracts/module-package/`、`contracts/module-lifecycle/`、`contracts/http/module-registry-v1.md` 和 `contracts/http/module-management-v1.md`。

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

`frontend_theme` is the only active theme setting; do not add an alternate source of truth.

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

Admin requests use `/txapi/admin/{admin_path}/*` with validated dynamic `secure_path`, Admin Bearer, RBAC and audit. User API, Agent and Node credentials cannot authorize Admin operations.

Laravel TXAPI success responses normally have `data` and `request_id`; errors use `error.code`. Agent runtime and signed webhook bodies have their own explicit contract.

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
5. update current interface/architecture docs when behavior or invariants change.

Use merge commits for project PRs unless explicitly instructed otherwise.

## 18. Module Platform v1 stabilization rules

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

Changes must preserve these invariants and update the applicable versioned contract.

## 19. 路由及外部契约校验

- Laravel HTTP 注册入口：`api/routes/txapi.php`、`api/routes/web.php`、`api/app/Providers/RouteServiceProvider.php`；节点 WebSocket 由 Workerman 单独处理，不能以 `route:list` 证明 WSS 已启用。
- 修改路由时导出并核对注册表；插件动态路由、网关规则和反向代理仍须在对应运行时核对：

```bash
cd api && php artisan route:list --json > ../route-list.json
cd .. && node scripts/export-route-catalog.mjs --routes route-list.json --json artifacts/routes.json --markdown artifacts/routes.md --check
```

- 访问控制按匿名、用户、管理员、Node/Machine、Agent、支付/Telegram 回调分别校验；不得仅以 URL 前缀判断授权。跨仓变更同时更新消费方和 `contracts/`。

## 20. 新增 Module / Agent 能力的验收规则

- **Module**：先明确稳定 ID、type、version、capabilities、依赖、配置归属、运行时状态、升级/回滚方式和管理权限；capability 只描述产品能力，不代表管理员权限或 Agent ability。Health 检查必须只读、有界、可预测，enabled 不等于 healthy。
- **Theme / Plugin**：保持现有包格式与 Admin Bridge 协商规则；对依赖冲突、系统/当前主题保护、禁用后 stale route、坏包、失败升级、Octane worker 缓存做负向测试。若引入持久化表，先证明不能从既有数据安全派生状态。
- **Agent**：分类 READ、INSIGHT、OPERATE、DANGEROUS；敏感操作默认进入服务端审批，Agent 不能直接标记 approved/running/succeeded。每个 READ 端点按 ability + target scope 过滤，每个操作还须经过输入 allow-list 和目标范围校验。
- **Node operation**：先定义 `operation/input/output/timeout/error_code/idempotency/verification` 契约，再实现固定类型操作；同一 request_id 不得重复执行非幂等动作，`ops.result` 只说明执行报告，独立观测通过后才能称为恢复成功，未知状态返回 inconclusive。
- **安全输入与审计**：禁止任意 URL、内网元数据探测、文件路径、命令行或进程名注入；诊断只允许固定来源和有界参数。审计包含 actor/client/protocol/tool/target/risk/request_id/status/error code，敏感 token、password、authorization、private key 和输入内容须脱敏。验证 403 scope/ability、404 目标丢失、422 策略/参数拒绝、重放和撤销的负向用例。

## 原生表命名规范

- TXBoard 仅支持 `tx_*` 应用数据表，所有 Model、Schema Migration、SQL、校验规则直接使用原生表名。
- 禁止重新引入表名前缀动态切换、旧版导入命令或兼容 Trait。
- 对旧版本真实数据的转换必须先在离线副本验证，不得自动删除原有表。
