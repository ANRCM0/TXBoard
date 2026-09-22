# Code Agent 项目开发指南

这份文档用于给 Codex、Code Agent 或其他编码 Agent 提供 TXBoard 项目级开发约束。

根目录 `AGENTS.md` 是执行时应优先读取的精简规则；本文解释这些规则背后的工作方式。

## 1. Agent 的角色

Code Agent 的职责不是“尽可能多改代码”，而是：

- 先识别项目边界；
- 找到现有事实来源；
- 尽量复用现有 runtime；
- 用小 PR 推进架构；
- 保护兼容性；
- 用测试证明契约没有漂移。

TXBoard 当前处在 Module Platform v1 实施阶段。

已完成：

```text
PR A
Module Package v1
Contract / DTO / Capability vocabulary
        ✅

PR B
Read-only Module Registry
Plugin / Theme / Agent Ops adapters
        ✅

PR C
Plugin lifecycle integration
        ✅

PR D
Theme Package v1 / Theme lifecycle adapter
        ✅

PR E
Read-only Module Center
        ✅
```

当前：

```text
PR F
Controlled Module management API
        ▶
```

## 2. 开始任务前

Agent 应先阅读：

1. `AGENTS.md`
2. `docs/architecture/README.md`
3. 与任务对应的 architecture 文档
4. 对应 `contracts/`
5. 将被修改的现有 runtime 实现
6. 相关 tests / CI workflow

不要根据文件名猜架构。

例如 Plugin 生命周期的事实来源现在仍然是：

```text
PluginManager
```

Module Platform 不能自己重新实现一套安装器。

## 3. 每个任务先写清楚边界

Agent 在实施前应形成内部判断：

```text
Goal:
What should change?

Authoritative runtime:
Who owns the current behavior?

Contract:
Which public/stable contract is affected?

Compatibility:
What existing users/plugins/themes/nodes must keep working?

Non-goals:
What must not be changed in this PR?
```

如果无法回答这些问题，应继续检查仓库，而不是直接开始重构。

## 4. Module Platform 的开发方法

统一采用：

```text
contract-first
adapter-first
delegation-first
migration-last
```

### Contract-first

先确定公开语言：

- ID
- type
- capabilities
- lifecycle operation
- errors
- HTTP shape

再写实现。

### Adapter-first

旧系统先通过 Adapter 接入，不大爆炸重写。

### Delegation-first

统一平台负责 orchestration。

专业 runtime 负责执行。

例如：

```text
Module Lifecycle
      |
      v
PluginLifecycleAdapter
      |
      v
PluginManager
```

### Migration-last

只有当现有状态无法可靠派生时才新增持久化表。

不要因为“看起来更统一”就复制现有 Plugin/Theme 状态到新表。

## 5. Phase C / Phase D 已交付结构

Plugin lifecycle integration 已完成以下结构：

### C1 — lifecycle contract

定义支持的操作：

```text
install
enable
disable
upgrade
uninstall
```

定义成功/失败结果。

不要先加 HTTP POST。

### C2 — lifecycle interface

例如：

```text
ModuleLifecycle
ModuleLifecycleAdapter
LifecycleOperation
LifecycleResult
```

### C3 — PluginLifecycleAdapter

只负责：

- 校验 Module type；
- 把 Module ID 映射到 plugin code；
- 调用 `PluginManager`；
- 把异常转换为稳定 lifecycle error；
- 操作后重新读取 Registry 状态。

### C4 — tests

至少覆盖：

- install delegation；
- enable delegation；
- disable delegation；
- upgrade delegation；
- uninstall delegation；
- unknown Module；
- unsupported Module type；
- PluginManager error propagation；
- state refresh；
- Plugin Package v1 compatibility。

### C5 — API

只有前四步稳定后，才考虑 Module 管理 API。

## 6. 不允许的实现

下面这些是架构回退：

```text
ModuleLifecycle -> File::deleteDirectory(plugin)
ModuleLifecycle -> DB::table('v2_plugins')->update(...)
ModuleLifecycle -> run migrations directly
ModuleLifecycle -> require Plugin.php
ModuleLifecycle -> plugin->boot()
```

这些已经有专业 runtime。

正确方式：

```text
ModuleLifecycle -> PluginLifecycleAdapter -> PluginManager
```

## 7. 修改 Theme 时

不要重新引入：

```text
current_theme
```

主状态始终：

```text
frontend_theme
```

默认：

```text
TXBoard
```

如果 Module Registry、Theme Admin、用户端出现不同判断，优先统一到 `ThemeService`，而不是各写一套 fallback。

## 8. 修改 Agent / MCP 时

始终维护：

```text
MCP
  -> Agent Ops API
  -> permission
  -> target scope
  -> approval/audit
  -> domain service
  -> TX-Node typed operation
```

不要为了 Module Platform 把这个安全链路缩短。

## 9. 修改 Admin 时

Module Center 应消费：

```text
GET /api/v2/{secure_path}/module
```

而不是再次扫描 Plugin API、Theme API、Agent API，再在前端自己拼 Module 模型。

统一模型必须由后端 Registry 提供。

## 10. 完成任务前的检查

Agent 应检查：

### Architecture

- 有没有制造第二事实来源？
- 有没有重复专业 runtime？
- Core/Module 边界是否仍然清晰？

### Compatibility

- Plugin Package v1 是否仍然可用？
- Theme 默认/自定义主题是否仍然一致？
- Agent Ops 是否仍然可以脱离 MCP 使用？
- TX-Node contract 是否未被意外改变？

### Safety

- discovery error 是否可能泄露 secret？
- 一个坏 Module 是否会拖垮 Core？
- Octane 下是否存在长期可变全局状态？

### CI

根据变更范围运行：

```text
API -> api-ci
Web -> web-ci
runtime/image -> txboard-image
contract -> matching contract tests
```

## 11. 推荐 Agent 提示词

执行 TXBoard 开发任务时，可以使用：

> 你正在开发 TXBoard。先阅读仓库根目录 AGENTS.md、docs/architecture/README.md，以及与当前任务相关的 architecture/contracts 文档。遵守 contract-first、adapter-first、delegation-first 原则。不要重写已经存在的 PluginManager、ThemeService、Agent Ops 等专业 runtime；Module Platform 应通过 adapter/orchestrator 复用它们。保护 Plugin Package v1、Admin Bridge v1、TX-Node protocol 和 Agent Ops 安全边界。每个 PR 只解决一个架构阶段，先明确 Goal、authoritative runtime、contract、compatibility 和 non-goals，再实施并补 API/Web/Image/contract 测试。完成后复核最终 diff，更新对应开发文档和阶段状态。

## 12. 当前下一任务

当前推荐任务：

> 实现 Module Platform Phase F：controlled Module management API。

Phase E 已完成统一只读 Module Center。Phase F 必须让管理动作进入 `ModuleLifecycle`，再由 Plugin/Theme lifecycle adapter 委托现有专业 Runtime；Module Registry 继续保持只读。不要把 Plugin/Theme upload、配置或 Agent approval 强行塞进通用 lifecycle API。
