# 系统架构与仓库职责

**TXBoard Core = Control Plane；TX-Node = Agent / Data Plane。** 源码统一在本仓，运行时分别构建；安装器统一在 TXBoard-Deploy。目录与历史来源见 [双仓布局](../development/repository-layout.md)。

当前主线包含原生订阅、资金及服务 API 子集，支持限制见[实现基线](v2/current-progress.md)。项目尚未接入正式测试，不承担旧版本过渡兼容；当前边界见 [Core First 总览](v2/README.md)及[Native 开发基线](../development/native-development-baseline.md)。

## 组件与信任边界

| 组件 | 职责与边界 | 进一步阅读 |
| --- | --- | --- |
| Laravel TXAPI | 账户、订阅、订单、权限、用量和节点控制的唯一业务权威，包含当前支付、账本/钱包与退款 | [接口总览](../../contracts/README.md) |
| Admin / User Web | 分别面向管理员和用户；复用服务端授权 | [开发入口](../development/README.md) |
| Module Platform | Registry 提供只读清单与健康，Lifecycle 委托 Plugin/Theme 专有运行时修改状态 | [模块架构](module-platform-v1.md) |
| Credential Provider | 独立保管代理凭据，执行签发、轮换、撤销及接收者渲染 | [C2 运行契约](../../contracts/v2/native-c2-runtime-v1.md) |
| TX-Node | Go Runtime、代理内核、机器/节点同步与上报 | [node/](../../node/README.md) |
| 可选 TXBoard-Gateway | 独立用户主题 API BFF / SDK；不是 MCP | [gateway/](../../gateway/README.md) |
| TXBoard-Deploy | 独立部署和升级脚本；不是 Laravel 应用仓库 | [Deploy](https://github.com/ANRCM0/TXBoard-Deploy) |

## 规范来源

- [扩展包与跨仓 HTTP/Node 协议](../../contracts/README.md)
- [Module Platform](module-platform-v1.md)
- [Core 内核与插件扩展边界](v2/core-extension-boundaries.md)
- [当前范围与 Agent/MCP 退役](v2/admin-only-scope.md)
- [AGENTS.md 开发边界](../../AGENTS.md)

设计草案或未来目标必须标为 `Target`/`Draft`，不得仅凭文档声称已经上线。

## 当前 Core

当前实现由 [Core First 总览](v2/README.md)、[Core 边界](v2/core-boundaries.md)、[当前进度](v2/current-progress.md)和 [v2 契约](../../contracts/v2/README.md)说明。统一 User/Admin 浏览器 Gateway、通用插件隔离及完整安装发布没有完成验收；不再维护旧阶段台账或开发顺序。
