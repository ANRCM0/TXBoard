# Module Platform v1 稳定化开发计划与交付流程

> 基线：截至 2026-09-24，A–H 阶段已经完成。本计划安排 v1 的验证、兼容性加固和小范围改进，**不是 Phase I**，也不修改已有 v1 契约的兼容性承诺。
>
> 架构与规则仍以 [`AGENTS.md`](../../AGENTS.md)、[Module Platform v1](../architecture/module-platform-v1.md)、[开发指南](../architecture/module-platform-development-guide.md) 和 [`contracts/`](../../contracts/README.md) 为准。

## 目标与边界

- 确保 Plugin Package v1、Theme Package v1、Admin Bridge v1 与 TX-Node 协议继续兼容；现有插件代码、`admin/dist` 和生命周期无需重写。
- 维持 `Registry -> 只读发现/健康/导航`，`ModuleLifecycle -> 专门适配器 -> PluginManager / ThemeService`；Core 业务事实来源不迁移到 Module 数据库。
- 保持 Agent Ops API 独立于 MCP；所有动作经过权限、目标范围、审批、审计和类型化 TX-Node 操作。
- 优先修复可重现的回归和故障隔离问题，再做有用户价值、且有现成契约支撑的小改进。不以新增框架、插件化率或 UI 页面数作为目标。

## 优先顺序（先验证基线，后续每项独立 PR）

| 顺序 | 工作包 | 产出与验收条件 |
| --- | --- | --- |
| 0. 基线闭环 | 在开始新功能前，确认最近一次模块发现错误处理改动通过 `api-ci` 和 `txboard-image` 的镜像/Octane smoke；前端以 `verify:web` 为基线。当前本地没有 PHP 依赖时，不把未运行的 API 测试写成“已通过”。 | 相关 CI 全绿；失败先修复，再进入后续工作。此项是发布验证，不自动推送或部署。 |
| 1. CI 与契约门禁 | 盘点 `contracts/` 各目录到 API、Web、MCP CI 的触发映射；单独调整遗漏的 `paths` 或采用稳定的必跑门禁，避免只改 HTTP/Bridge/Agent 契约而漏跑相应测试。 | 对每类公开契约给出对应测试和触发工作流；若采用分支保护，必需检查不能因路径过滤而长期等待。 |
| 2. v1 兼容回归 | 按 Plugin、Theme、Admin Bridge/导航、Agent Ops 四个边界分别补最小复现用例，不合并为一次大重构。覆盖旧插件 `config.json` 与 `admin/dist`、系统 ID 冲突、默认/自定义主题、`frontend_theme` 主状态与 `current_theme` 只读回退、动态 `secure_path`、Agent 无 MCP 时的 API 行为。 | 每个 PR 的新增用例先复现一个风险；正常行为与兼容行为均通过；Schema、PHP DTO、示例和前端消费在发生契约变更时保持同步。 |
| 3. 故障隔离与运行时安全 | 按测试发现的问题修正：坏插件/主题的发现错误与健康状态、无敏感信息的诊断、Registry 读取副作用、生命周期委托与状态刷新、Octane 请求作用域。 | 一个可选模块故障不拖垮 Core 或 `/api/health`；不引入全局可变状态、第二份安装/主题状态或直接模块文件/数据库操作。任何 v1 状态字段语义变更先评估契约兼容性。 |
| 4. 小范围产品改进 | 仅在前述基线稳定后，从明确的使用问题出发改进 Module Center 的库存/诊断展示或 Agent Ops 操作体验；分别立项。 | Module Center 继续只读消费 Registry；Plugin/Theme 管理仍进入专门页面；Agent 操作仍需原权限、审批与审计链路，并有对应 API/Web 测试。 |

以上是**依赖顺序**，不是日期承诺。第 2、3 项中不同领域可以分别排队，但同一 PR 只解决一个边界和一个可验收问题。发现不兼容需求时先停在设计/契约评审，不借稳定化之名隐式扩展 v1。

## 契约与 CI 对应关系

所有面向 `main` 的 PR 都运行 API、Web、MCP 和镜像验证，避免新增契约目录时漏掉检查；**只有 push 事件继续按代码路径过滤**，防止纯文档变更触发镜像发布。Web CI 的 `scripts/check-ci-pr-coverage.mjs` 守护这条规则。该方案以更多 CI 时间换取契约变更不漏检；若以后收紧运行范围，须先提供同等有效的必跑门禁。

| 契约范围 | 优先检查的现有测试或验证 |
| --- | --- |
| `module-package/`、`module-lifecycle/`、`http/module-*.md` | PHP `ModuleManifestTest`、`ModuleRegistryTest`、`ModuleLifecycleTest` 与 Admin `ModuleRegistryApiTest`、`ModuleManagementApiTest`；Web `api/contracts.test.ts`。 |
| `plugin-package/`、`admin-bridge/`、`admin-navigation/` | PHP `PluginPackageTest`、模块 Registry/API 测试；Web `plugins/bridge.test.ts`、`navigation/registry.test.ts`。 |
| `theme-package/` | PHP `ThemePackageManifestTest`、`ThemePackageTest`、`ThemeServiceTest` 及 Theme lifecycle 测试。 |
| `http/agent-*.md`、`agent-ops-module/`、`agent-self-connect/`、`node-protocol/` | PHP Agent Ops/Pairing/Support 与 Machine/Server 协议测试；Web Agent 客户端测试；MCP typecheck/build；镜像 smoke 验证 MCP 开关和 Octane。 |

上述是**现有基线**，不是覆盖率承诺：改动某项公开行为时，还须为该行为新增或更新对应的定向测试。若仓库无法配置自动保护规则，由合并者逐一核对 PR 检查结果；`SKIPPED` 的镜像发布 job 在 PR 上属于预期，但验证 job 必须成功。

## 单个任务从立项到合并

1. **建任务卡**：写明 `Goal`、当前权威 runtime、涉及契约、必须保留的兼容行为、明确的 `Non-goals`；给出复现步骤、预期结果和受影响范围。无法确认事实来源时先检查实现。
2. **确定边界**：纯 bug 先补失败用例；公开或跨仓库行为变化先更新对应 `contracts/`，再按 `DTO/model -> adapter -> runtime delegation -> API -> Admin UI -> migration` 实现。没有必要不新增依赖、表或通用 Module 写 API。
3. **实现与测试**：包含正常、失败和旧版本兼容路径；对非可信包输入检查归档限制、导航和声明 Admin App；对 Agent 操作检查权限/范围/审批/审计。错误不得暴露 token、连接串、任意异常原文。
4. **本地验证**：API 变更按仓库 `AGENTS.md` 的 Composer + SQLite 测试步骤；Web 变更运行 `npm ci --no-audit --no-fund` 与 `npm run verify:web`；MCP 变更运行其 typecheck/build；镜像敏感变更验证 Docker 构建、内嵌 healthcheck、PDO 依赖与 Octane/MCP smoke。本地缺少工具时在 PR 中如实标明，由 CI 补齐，不宣称已验证。
5. **PR 评审**：审视最终 diff，只保留该 concern；列出契约差异、兼容性矩阵、测试结果、部署/回滚影响和未验证事项。相应 API/Web/MCP/Image CI 通过后按仓库规则用 **merge commit** 合并；有失败检查不合并。
6. **发布观察**：以 CI 构建的不可变镜像版本发布；由独立的 TXBoard-Deploy 流程部署，先备份，再核查 `GET /api/health`、Admin/User、可选 MCP 和关键 Plugin/Theme 路径。异常按部署仓库回滚至上一版本，记录回归用例；代码提交不等于生产已部署。

## 完成标准

- 影响的契约、Schema/DTO/示例、测试和文档相互一致；没有静默改变 v1 公共行为。
- Registry 不执行生命周期变更，PluginManager/ThemeService 仍是权威，`frontend_theme` 仍是唯一写入的主题状态。
- 坏模块可诊断但不泄漏秘密、不拖垮 Core；Agent 无 MCP 仍可按原安全链路工作。
- PR 对应的 CI 与必要镜像 smoke 全绿；未完成的验证或发布明确标注，不用“本地构建通过”代替交付。

## 暂不做

- 不创建“Phase I”、Module 数据表或通用沙箱；PHP 插件仍是可信进程内代码。
- 不要求旧插件改 code、新清单、`admin/dist` 位置或依赖 TXBoard React 源码。
- 不把 TX-Node 安装器、直接 MySQL/Redis/TX-Node/MCP 通道、通用 shell/文件系统或自动审批加入 TXBoard Module Runtime。
- 不把产品部署与 git commit、合并 PR 混为一谈。
