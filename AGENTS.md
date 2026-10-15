# TXBoard Code Agent Instructions

These instructions apply to coding agents working in this repository.

## 1. Product boundary

### Core First v2 演进基线

2026-10-12 起，后续架构演进参考 `docs/architecture/v2/README.md`、`docs/architecture/v2/core-boundaries.md` 与 `contracts/v2/README.md`。2026-10-13 用户最新调整范围：管理前端与用户前端均暂缓，后续新增前端开发集中到独立 `TX-F` 项目，只本地提交、不推送；本仓只推进 Core、Gateway、Node，安装器仍独立成库。清理 Agent/MCP，取消插件迁移，见 `docs/architecture/v2/admin-only-scope.md`。不得依据目标方案提前开放管理员 Gateway、删除 User 字段或 Theme、改变插件包格式。跨仓改动先核对对应 Current 契约，按 Operation/数据迁移阶段逐项实施。

TXBoard is the Control Plane.

This repository contains three independently built runtimes: TXBoard Core at the root (`api/`, `web/`), Gateway in `gateway/`, and TX-Node in `node/`. The repository is being prepared for the name `tx-core`.

Repository consolidation does not merge runtime authority: TXBoard remains the Control Plane, Gateway is an optional BFF, and TX-Node remains the Agent / Data Plane. Keep their dependency locks, Docker contexts, credentials and release gates separate. Host installation and lifecycle scripts belong exclusively to TXBoard-Deploy; do not introduce another installer here. Nested `.github/workflows` do not run: active component workflows live in the root `.github/workflows/`.

The project is evolving toward a **Modular Control Plane Platform**.

Read first:

- `docs/architecture/module-platform-v1.md`
- `docs/architecture/v2/admin-only-scope.md`
- `docs/README.md`
- relevant files under `contracts/`

## TXAPI 与跨仓接口边界

- TXBoard 应用 API 统一使用已注册的 `/txapi/*` 路由；不要新建未注册的 V1/V2 入口。
- User、Admin、Node/Machine 与支付商回调使用各自独立的凭据和鉴权中间件；不得互相借用。退役 Agent 凭据始终拒绝。
- 当前 HTTP 路由以 `api/routes/txapi.php`、`api/routes/web.php` 和 `api/app/Providers/RouteServiceProvider.php` 为准；TXNode WSS 由 Workerman 管理，不属于 Laravel HTTP registry。
- 跨仓修改优先阅读 `contracts/http/external-adapter-current.md`、`contracts/http/theme-integration-current.md`、`contracts/node-protocol/txnode-integration-current.md`。
- 可选独立 Gateway 不持有 Laravel 用户/订单/资金/Node 状态，也不能接管当前管理员入口、支付 Webhook 或订阅密钥。
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

## 8. Retired Agent / MCP

Agent/MCP is removed under the 2026-10-13 product decision. Do not restore its API, token issuance, scheduled inspections, UI, embedded process or CI. Historical migrations/audit rows remain; legacy Agent tokens are denied by Sanctum. New Admin and User frontend development is deferred and belongs exclusively to the separate TX-F project. TX-F permits local commits only; existing push authorization for runtime/installer repositories does not authorize pushing TX-F. This repository develops Core, Gateway and Node; installation belongs to TXBoard-Deploy. Preserve the existing bundled Admin/User UI and Theme during the compatibility window.

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

Laravel TXAPI success responses normally have `data` and `request_id`; errors use `error.code`. Signed webhook bodies have their own explicit contract.

Do not create generic Module mutation endpoints until lifecycle contracts and adapters are stable.

Module HTTP contracts:

- read-only Registry: `contracts/http/module-registry-v1.md`;
- controlled lifecycle management: `contracts/http/module-management-v1.md`.

## 16. 开发与批次验收（2026-10-15 用户最新决定）

- 一个完整功能或多个相关阶段可以合为一批：连续开发 → 集中验收 → 汇总修复 → 相关复测 → 提交推送。此约定覆盖旧的每条小切片独立全量测试、提交和推送要求。
- 日常实现只做必要的语法、编译、类型和明显错误检查；文档修改只检查差异、引用和一致性，不启动应用、Docker 或数据库测试。
- 批次验收围绕新增功能、主要业务链路和直接受影响行为。达到标准后收尾；没有新改动、实际失败或未解决问题，不重复运行已通过的全量测试。
- 权限、扣费、支付和数据库写入的关键变更保留必要的针对性检查；发现权限绕过、丢数据或基础接口阻断时及时处理，不等待依赖工作堆积。
- 完整协议/双内核矩阵、复杂故障注入与备份恢复边缘组合集中到发布验收或相关实现变更时；已有协议不为测试而重新开发。
- 不关闭现有 required CI 或发布/迁移门禁。以批次末尾提交推送减少重复 CI；记录结果的文档不得造成无意义的相同组件复验循环。
- 代理负责各自实现与必要检查，协调者统一安排批次集成；不得默认让每个代理重复跑完整环境和相同回归。
- 进度以可用功能、实际问题与剩余工作为主；明确区分开发完成、已验证和已发布，延后验收的范围继续列出。

完整执行约定见 [开发与批次验收流程](docs/development/development-workflow.md)。以下是按本批影响范围选用的命令，不能理解为每次修改均需运行所有命令。

For API batch acceptance when relevant:

```bash
cd api
composer install --no-interaction --prefer-dist
cp .env.example .env
php artisan key:generate
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test
```

For web batch acceptance when relevant (frontend development remains deferred):

```bash
npm ci --no-audit --no-fund
npm run verify:web
```

For relevant production-image changes, preserve these checks at batch/release acceptance:

- Docker build;
- embedded healthcheck;
- PHP PDO runtime dependencies;
- Octane smoke test.

Whenever Module Package contract changes, keep JSON Schema, PHP vocabulary/DTOs, examples and tests in sync.

## 17. PR 与多团队协作（2026-10-15 用户最新决定）

后续团队通过 PR 贡献源码、契约与协作文档；主题分支推送后经审阅合并，不直接推送默认分支。一个 PR 对应一个连贯功能批次，可包含多个相关阶段。完整分工和合并流程见[多团队方案](docs/development/multi-team-development.md)。

- 按 Core、Node、Gateway、Deploy 划分工作线；共享模型、数据库迁移、接口契约和根 CI 由相关负责人协调，实际团队账号未指定前由维护者分配审阅人。
- 每任务使用独立分支和工作目录；跨仓或 stacked PR 标明 base、依赖与发布顺序，不把未合入审阅分支当作稳定默认基线。
- 可提前开 Draft 讨论契约；完成相关阶段后集中验收修复再请求正式审阅，不能把开 PR 当作重复全量测试的理由。
- 至少一名非作者领域审阅人批准；跨团队接口须双方确认，权限/资金/迁移/发布变更加相关领域或集成负责人确认。实质修改后复审受影响部分。
- 合并须依赖就绪、意见处理和相关CI/已配置required checks满足，由获授权维护者执行；提交/推送授权不自动扩大到自审自合、默认分支合并或镜像发布。
- 使用[PR模板](.github/pull_request_template.md)说明实际行为、影响、依赖、验证和恢复条件。CODEOWNERS与远端分支保护需维护者配置，不能把协作文档当作已生效的权限控制。

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

## Parallel development

2026-10-13 用户明确授权在开发中派代理并行加速。可以将契约已明确、文件范围不重叠的 Core/Gateway/Node 或独立安装器工作交给开发代理；协调者负责依赖、边界、验收和提交推送，不以代理数量替代检查。

- 分配前确定每个代理的文件所有权、依赖和实际验收范围。共享注册表、契约词汇、迁移号和中央台账的修改须协调；不得覆盖他人未完成代码。
- 先明确本批契约和文件边界；需要真实环境验收时统一固定本批源码、依赖锁和必要证据，不要求每个小修改或代理独立重复整套验收。各代理使用独立专用测试库和夹具，不并发删除共享数据库。
- 开发代理冻结可评审结果后由协调者审查。协调者在一个或多个相关阶段完成后统一安排适当的集成验收与修复，精确暂存已验收文件，按批次提交/推送并核对远端CI；不要将未验收的独立代理改动一起提交。
- 此授权适用于开发协作，不恢复产品中已退役的 Agent/MCP 功能，也不解除生产写入、凭据或迁移门禁。
- TX-F 的管理/用户前端仍暂缓；该独立项目只本地提交，不推送。其他仓库的推送授权不能扩展到 TX-F。

## 18. Module Platform v1 stabilization rules

The implemented control flow remains:

```text
Module Registry
    -> read-only inventory / health / navigation

Module Management API
    -> ModuleLifecycle
    -> specialized Plugin / Theme runtime

```

Stabilization work must preserve these invariants:

- do not create a second source of truth for Plugin, Theme or Core domains;
- keep Plugin Package v1, Theme Package v1 and Admin Bridge v1 compatibility;
- keep Registry reads side-effect free;
- keep lifecycle mutations behind specialized adapters;
- keep retired Agent tokens invalid and preserve historical audit records;
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

- 访问控制按匿名、用户、管理员、Node/Machine、支付/Telegram 回调分别校验，并验证退役 Agent 凭据拒绝；不得仅以 URL 前缀判断授权。跨仓变更同时更新消费方和 `contracts/`。

## 20. 新增 Module 能力的验收规则

- **Module**：先明确稳定 ID、type、version、capabilities、依赖、配置归属、运行时状态、升级/回滚方式和管理权限；capability 只描述产品能力，不代表管理员权限或 Agent ability。Health 检查必须只读、有界、可预测，enabled 不等于 healthy。
- **Theme / Plugin**：保持现有包格式与 Admin Bridge 协商规则；对依赖冲突、系统/当前主题保护、禁用后 stale route、坏包、失败升级、Octane worker 缓存做负向测试。若引入持久化表，先证明不能从既有数据安全派生状态。
- **Agent/MCP**：已退役；需要重建时先建立独立版本契约，不恢复旧接口或旧凭据。
- **Node operation**：先定义 `operation/input/output/timeout/error_code/idempotency/verification` 契约，再实现固定类型操作；同一 request_id 不得重复执行非幂等动作，`ops.result` 只说明执行报告，独立观测通过后才能称为恢复成功，未知状态返回 inconclusive。
- **安全输入与审计**：禁止任意 URL、内网元数据探测、文件路径、命令行或进程名注入；诊断只允许固定来源和有界参数。审计包含 actor/client/protocol/tool/target/risk/request_id/status/error code，敏感 token、password、authorization、private key 和输入内容须脱敏。验证 403 scope/ability、404 目标丢失、422 策略/参数拒绝、重放和撤销的负向用例。

## 原生表命名规范

- TXBoard 仅支持 `tx_*` 应用数据表，所有 Model、Schema Migration、SQL、校验规则直接使用原生表名。
- 禁止重新引入表名前缀动态切换、旧版导入命令或兼容 Trait。
- 对旧版本真实数据的转换必须先在离线副本验证，不得自动删除原有表。
