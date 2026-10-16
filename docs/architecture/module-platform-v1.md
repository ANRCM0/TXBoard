# TXBoard Module Platform

Module Platform 为 Plugin、Theme 等扩展提供**统一只读目录、能力和健康信息，以及经过授权的生命周期委托**。它不复制用户、订阅、订单、支付事实/账本、Node/Machine 等 Core 领域。具体支付商的下单、验签、查询和渠道退款协议属于支付插件，不属于资金内核；完整责任矩阵见 [Core 内核与插件扩展边界](v2/core-extension-boundaries.md)。

## 组件关系

```text
Module Registry (read-only)
  -> Plugin adapter -> PluginManager
  -> Theme adapter  -> ThemeService

Module Management (authorized mutations)
  -> ModuleLifecycle
     -> PluginLifecycleAdapter -> PluginManager
     -> ThemeLifecycleAdapter  -> ThemeService
```

- Core 直接负责身份与鉴权、订单与账本、订阅、节点和机器、基础设置与审计。扩展接口只能委托这些领域服务，不得替代其权限与财务真相。
- 内置或随包发布不改变扩展归属。停用支付插件应停止新渠道请求，不能删除财务事实或切断原交易验证/恢复；原交易排空及破坏性升级/卸载保护须由对应契约和运行时实现，不能由 Registry 读取代劳。
- Module Registry 的 `installed`、`enabled`、`active`、`health` 由运行时派生，不可相信第三方包声明的状态。
- Module ID、类型、能力、依赖、展示信息按统一描述符输出；不同来源的 ID 冲突必须可预测处理。
- Registry 读取应隔离单个扩展加载失败，不在读路径安装、启用、卸载或修改主题。

## HTTP 入口与权限

由动态路径和 Admin 鉴权保护的管理端入口：

| Method | 路由 | 用途 |
| --- | --- | --- |
| GET | `/txapi/admin/{admin_path}/modules` | 模块清单 |
| GET | `/txapi/admin/{admin_path}/modules/{id}` | 模块详情 |
| GET | `/txapi/admin/{admin_path}/modules/{id}/operations` | 可执行操作 |
| POST | `/txapi/admin/{admin_path}/modules/{id}/operations/{operation}` | 经允许的生命周期委托 |

实际允许的操作由模块类型、当前状态、权限和运行时适配器确定。不得用普通用户、Agent 或 Node 令牌调用管理员接口；变更按管理端审计策略记录。具体响应 DTO 和错误语义参阅 [Module Registry](../../contracts/http/module-registry-v1.md)、[Module Management](../../contracts/http/module-management-v1.md)。

## 包规范与扩展隔离

- Plugin 包：[Plugin Package v1](../../contracts/plugin-package/README.md)。PluginManager 仍持有插件安装、升级与卸载真相。
- Theme 包：[Theme Package v1](../../contracts/theme-package/README.md)。ThemeService 决定唯一活跃主题 `frontend_theme`；管理员禁止卸载当前活跃或系统主题。
- 统一模块包：[Module Package v1](../../contracts/module-package/README.md)；生命周期：[Module Lifecycle v1](../../contracts/module-lifecycle/README.md)。
- PHP 插件在 Laravel/Octane 进程中执行，不是安全沙箱；只安装可信来源，解压前验证路径、文件类型、总量、元数据。插件故障不应绕开 enabled guard。
- Agent/MCP 已退役；Module Registry 不注册内置 Agent Ops。

## 变更规则

新增扩展类型先定义契约与适配器，再修改运行时、Admin UI 和测试。稳定字段的删除、权限扩展、安装升级或包格式变化须做版本化评审。实现时遵循 [项目开发约束](../../AGENTS.md) 与 [安全基线](../security/README.md)。

## 开发与验证要求

- 新增模块前明确 Core/Module 归属、稳定标识、类型、依赖、声明的 product-level capabilities、运行时配置所有者、版本兼容性及独立回退方法。不要使用具体文件是否存在替代能力语义。
- 模块 `capability` 只描述能力，不授予 Admin permission 或资源操作范围；Bridge 消息和 Admin 导航路径要进行版本校验及安全限制。Agent/MCP 已退役，不为扩展恢复其授权层。
- Module Health 为只读、有限、可预测的检查，可包含 manifest、依赖、兼容性、Admin 资源和配置状态；禁止任意 HTTP 请求、脚本或不受限扫描。模块可以 enabled 但 degraded；可选模块错误应独立暴露，不能拖垮 Core。
- 插件/主题升级通过各自 runtime 处理：依赖关系、已有包兼容、活跃系统主题保护、Octane 缓存失效与 Worker 重启行为都需要回归测试；数据库变更不能创建第二套真相。
- 变更清单必须验证包 Schema / DTO、Registry API、Lifecycle 权限、Admin UI、插件/主题兼容性以及镜像运行时；具体测试与约束由 [AGENTS.md](../../AGENTS.md) 维护。
