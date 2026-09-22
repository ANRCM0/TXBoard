# Module Platform v1 开发指南

> 用途：指导 TXBoard 下一阶段所有模块化改造。
>
> 前置阅读：[Module Platform v1](./module-platform-v1.md)。

## 1. 开发前先回答八个问题

任何新的扩展需求在写代码前都必须回答：

1. 它属于 Core 还是 Module？
2. 如果是 Module，它是什么 type？
3. 它声明哪些 capabilities？
4. 它的生命周期是什么？
5. 配置由谁持有？
6. 权限边界是什么？
7. 如何升级和回滚？
8. 如何判断 health？

如果一个第三方模块必须修改 TXBoard Admin 源码才能正常工作，应优先视为扩展接口不足，而不是直接接受耦合。

## 2. Core 判定规则

满足任一条件时，默认属于 Core：

- 是 TXBoard 身份/认证事实来源；
- 是 User / Plan / Subscription / Order 的核心业务事实来源；
- 是 Node / Machine / Route / Group 的控制面事实来源；
- 是 TXBoard ↔ TX-Node 协议或调度主链路；
- 是 Module Runtime 自身；
- 移除后 TXBoard 不再能作为独立控制面工作。

不要为了“模块化率”把核心领域强行插件化。

## 3. Module 判定规则

适合作为 Module 的能力通常具备：

- 可以独立启用/禁用；
- 有明确扩展边界；
- 不是核心业务唯一事实来源；
- 可以通过 contract 访问 Core；
- 可以独立版本化；
- 可以独立健康检查；
- 可以在不改 Core 主流程的前提下演进。

典型例子：

- payment provider；
- notification provider；
- audit extension；
- theme；
- third-party integration；
- Agent integration。

## 4. Contract-first

Module Platform 的开发顺序必须是：

```text
contract
  -> registry/model
  -> adapter/runtime
  -> API
  -> Admin UI
  -> migration
```

不要先做 Module Center 页面，再反推数据结构。

Contract 变化应优先落在：

```text
contracts/module-package/
```

如果是已有子系统的兼容契约，也同步维护其原 contract。

## 5. Capability 设计规则

Capability 必须描述“模块可以扩展什么”，而不是描述具体实现文件。

推荐：

```text
admin.app
api.route
database.migration
payment.provider
```

避免：

```text
has_admin_dist
has_routes_directory
has_migration_folder
```

Capability 是稳定产品语义；目录结构只是实现。

新增 capability 前检查：

- 是否已有相同语义；
- 是否过度细分；
- 是否能用于安全/权限判断；
- 是否能用于 health/compatibility；
- 是否需要成为稳定公开 contract。

## 6. Module Registry 规则

Module Registry 是模块元数据的统一入口，不是第二套业务数据库。

Registry 可以组合：

- filesystem discovery；
- database installation state；
- compatibility checks；
- runtime adapter state。

Registry 不应复制：

- User；
- Order；
- Node；
- Agent action；
- payment transaction；

等业务实体。

## 7. Adapter-first migration

现有系统不做大爆炸重写。

迁移顺序：

```text
existing subsystem
    -> adapter
    -> Module Registry
```

### Plugin

```text
PluginManager
Plugin Package v1
    -> PluginModuleAdapter
    -> Module Registry
```

### Theme

```text
ThemeService
Theme package
    -> ThemeModuleAdapter
    -> Module Registry
```

### Agent Ops

```text
Agent Ops Services
    -> AgentModuleAdapter
    -> Module Registry
```

Adapter 只描述/桥接能力，不复制 domain logic。

## 8. Plugin compatibility rule

Plugin Package v1 is a compatibility boundary.

Module Platform v1 must not require third-party v1 plugins to:

- rename their plugin code；
- rebuild against TXBoard Admin source；
- rewrite existing lifecycle code；
- move existing `admin/dist`；
- adopt a breaking new manifest immediately。

If new Module metadata is missing, the adapter should derive safe defaults where possible.

Breaking changes belong in a future Plugin Package v2 with explicit migration guidance.

## 9. Theme migration rule

Theme Runtime currently has more historical state drift than Plugin Runtime.

The target invariant is:

```text
frontend_theme = canonical active theme
TXBoard        = built-in default
```

Legacy names/settings may be read for migration, but code must not write multiple active-theme keys.

Theme Admin, user-facing rendering and Module Registry must resolve the same effective theme.

Regression tests should cover:

- no persisted theme；
- built-in default；
- stale historical theme name；
- missing/deleted theme；
- custom theme；
- switching back to default；
- system theme delete protection。

## 10. Module Health design

Health checks must be bounded and deterministic.

Good examples:

- manifest parsed；
- compatibility satisfied；
- required dependency present；
- declared Admin app exists；
- required migration state available；
- integration endpoint configured。

Avoid health checks that:

- mutate state；
- run arbitrary external requests；
- scan unrestricted filesystem paths；
- execute arbitrary commands。

A module may be enabled while degraded. Do not collapse health into enabled state.

## 11. Admin UI rules

The Admin shell owns common UX.

Module-owned Admin apps should not reimplement host-wide concerns when Bridge APIs exist.

Host responsibilities include:

- navigation；
- secure admin path；
- auth context；
- common confirmation；
- toast/notification；
- deep links to core entities；
- theme context。

Complex independent modules should ship `admin/dist`.

Host-native React renderers remain exceptional.

## 12. Admin Bridge evolution

Bridge changes must be versioned.

Rules:

1. Bridge v1 remains accepted while v2 is introduced.
2. A module declares/negotiates the bridge version it understands.
3. Unknown message types are ignored safely.
4. Navigation targets remain constrained to safe relative module paths.
5. Authorization data is only delivered to trusted same-origin plugin app contexts.
6. Secrets beyond the necessary admin bearer context are never exposed.

## 13. Permissions

Do not mix three different concepts:

```text
module capability
administrator permission
agent ability/scope
```

They may relate, but they are not equivalent.

Example:

```text
Capability:
admin.app

Admin permission:
module:access_audit:manage

Agent ability:
agent:audit:read
```

Agent target scope remains a separate resource boundary.

## 14. Agent Ops / MCP rule

Module Platform must not weaken Agent Ops boundaries.

MCP remains a protocol adapter.

It must not gain:

- direct DB access；
- direct Redis publish；
- direct TX-Node connection；
- generic shell；
- generic filesystem；
- automatic approval。

Registering Agent Ops as a Module means describing its capabilities and health, not moving policy into Module Runtime.

## 15. Database changes

Module Runtime database tables, if required, should only store module-platform state such as:

- installation/enabled state；
- manifest snapshot/version；
- compatibility state；
- health snapshot；
- optional module metadata。

Do not duplicate existing Plugin tables without a migration plan.

Prefer adapters first. Introduce new persistent schema only when normalized state cannot be derived safely.

## 16. Failure handling

Module initialization failure must not unnecessarily take down TXBoard Core.

Rules:

- system-critical Core modules may fail closed；
- optional modules should fail isolated where possible；
- failure must be visible in Module Health；
- one broken optional plugin should not make `/api/health` unusable；
- failed module boot should produce structured logs with module ID/version。

## 17. Octane and long-lived process safety

All Module Runtime state must remain safe under Octane/Swoole.

Avoid unmanaged process-global mutable state.

Hooks, registries and caches need explicit lifecycle semantics.

Any new runtime cache must define:

- scope；
- invalidation；
- upgrade behavior；
- worker restart expectations。

## 18. Testing matrix

Every Module Platform change should consider:

### API

- discovery；
- compatibility；
- lifecycle state；
- health；
- permission failures；
- missing module；
- malformed manifest。

### Plugin compatibility

- existing Plugin Package v1；
- plugin with Admin App；
- schema-only plugin；
- core plugin；
- user plugin；
- upgrade path。

### Theme

- default TXBoard theme；
- custom theme；
- legacy state；
- invalid theme state。

### Admin

- Module Center list；
- health/status rendering；
- deep-link navigation；
- plugin app loading；
- Bridge compatibility。

### Agent

- Agent Ops remains available without MCP；
- MCP remains optional；
- approval and target scope unchanged。

### Image

- production image contains declared system modules/assets；
- runtime healthcheck still works before installation；
- optional broken module cannot break base container health unexpectedly。

## 19. PR structure

Prefer small architecture-preserving PRs.

Recommended sequence:

### PR A — Module contract and DTOs

No behavior changes.

### PR B — Module Registry foundation

Read-only discovery.

### PR C — Plugin adapter

Existing behavior unchanged.

### PR D — Theme contract + adapter

Canonical theme state enforced.

### PR E — Module Center

Read-only unified inventory first.

### PR F — lifecycle management through Module API

Only after registry/state is stable.

### PR G — Admin Bridge v2

Incremental compatibility.

### PR H — Agent Ops registration

Metadata/health integration only.

Avoid combining all phases into one large refactor.

## 20. Definition of Done for each Module

A production-ready Module integration should answer:

- What is its stable ID?
- What version is installed?
- What type is it?
- Where did it come from?
- Is it installed?
- Is it enabled/active?
- Is it healthy?
- Which capabilities does it expose?
- Which TXBoard versions are compatible?
- Where is its configuration stored?
- How is it upgraded?
- How is it disabled/uninstalled?
- What is the rollback behavior?
- What permissions protect management actions?
- What CI covers the contract?

If these cannot be answered deterministically, the module integration is not finished.
