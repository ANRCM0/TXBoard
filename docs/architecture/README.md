# 系统架构与仓库职责

**TXBoard = Control Plane；TX-Node = Agent / Data Plane。** 不应在主仓库重复实现节点运行时或 TX-Node 安装器。

## 组件与信任边界

| 组件 | 职责与边界 | 进一步阅读 |
| --- | --- | --- |
| Laravel TXAPI | 用户、订单、资金、Node、Agent、配置等核心领域的唯一运行时真相 | [接口总览](../../contracts/README.md) |
| Admin / User Web | 分别面向管理员和用户；复用服务端授权 | [开发入口](../development/README.md) |
| Module Platform | Registry 提供只读清单与健康，Lifecycle 委托 Plugin/Theme 专有运行时修改状态 | [模块架构](module-platform-v1.md) |
| Agent Ops | 固定能力、授权、范围、审批及审计的操作层 | [Agent Ops 架构](agent-ops.md) |
| 内置 MCP Gateway | Agent Ops 的可选协议适配器，不能直接访问 MySQL / Redis / TX-Node | [MCP 使用](../guides/agent-mcp.md) |
| TX-Node | Go Runtime、代理内核、机器/节点同步与上报 | [TX-Node](https://github.com/ANRCM0/TX-Node) |
| 可选 TXBoard-Gateway | 独立用户主题 API BFF / SDK；不是 MCP | [Gateway](https://github.com/ANRCM0/TXBoard-Gateway) |
| TXBoard-Deploy | 独立部署和升级脚本；不是 Laravel 应用仓库 | [Deploy](https://github.com/ANRCM0/TXBoard-Deploy) |

## 规范来源

- [扩展包与跨仓 HTTP/Node 协议](../../contracts/README.md)
- [Module Platform](module-platform-v1.md)
- [Agent Ops / MCP 架构](agent-ops.md)
- [AGENTS.md 开发边界](../../AGENTS.md)

设计草案或未来目标必须标为 `Target`/`Draft`，不得仅凭文档声称已经上线。

## Core First v2 演进

下一轮开发由 [B0 架构基线](v2/README.md)、[Core First ADR](v2/core-boundaries.md)、[实施台账](v2/implementation-plan.md) 和 [v2 契约](../../contracts/v2/README.md) 统一。当前架构继续按上表运行；目标模型、统一 User/Admin Gateway、插件隔离与升级编排尚未落地。
