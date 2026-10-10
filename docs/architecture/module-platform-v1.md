# TXBoard Module Platform

Module Platform 为 Plugin、Theme、Agent Ops 等扩展提供**统一只读目录、能力和健康信息，以及经过授权的生命周期委托**。它不复制用户、订阅、订单、支付、Node/Machine 等 Core 领域。

## 组件关系

```text
Module Registry (read-only)
  -> Plugin adapter -> PluginManager
  -> Theme adapter  -> ThemeService
  -> Agent Ops adapter -> Agent Ops services

Module Management (authorized mutations)
  -> ModuleLifecycle
     -> PluginLifecycleAdapter -> PluginManager
     -> ThemeLifecycleAdapter  -> ThemeService
```

- Core 直接负责身份与鉴权、订单与账本、订阅、节点和机器、基础设置与审计。扩展接口只能委托这些领域服务，不得替代其权限与财务真相。
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
- Agent/MCP 仍执行自身 abilities、target scopes、审批与审计，不通过 Module Registry 自动授予权限。

## 变更规则

新增扩展类型先定义契约与适配器，再修改运行时、Admin UI 和测试。稳定字段的删除、权限扩展、安装升级或包格式变化须做版本化评审。更多设计与实现流程见 [Module Platform 开发指南](module-platform-development-guide.md) 和 [扩展运行时安全要求](extension-runtime-policy.md)。
