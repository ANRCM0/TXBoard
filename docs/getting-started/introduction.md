# 认识 TXBoard

TXBoard 是订阅业务与节点资源的**控制平面**，管理用户、套餐、订单、余额、节点、机器、后台配置和扩展系统。它不承担代理内核的数据转发：对应职责属于独立的 [TX-Node](https://github.com/ANRCM0/TX-Node)。

## 一张图理解组件

- **TXBoard 主应用**：Laravel TXAPI、Admin（React）、User（Vue）、MySQL、Redis、模块/插件/主题以及受控的 Agent Ops。
- **TXBoard-Deploy**：面向服务器的安装器和运维工具，按镜像部署，而不是构建源码。
- **TX-Node**：独立的 Go Agent / Data Plane，向面板报告运行与流量状态。
- **内置 MCP Gateway**：可选的 Agent Ops 协议适配器，所有操作仍经 TXBoard 的权限、范围、审批及审计。
- **TXBoard-Gateway**：另一个独立、可选的**用户主题 API BFF** 项目，不接管管理员、支付回调或 Node 身份。

[架构与职责详解](../architecture/README.md) · [接口协议](../../contracts/README.md)

## 我应该先看什么？

1. **第一次部署**：阅读 [部署准备与安装](installation.md)，使用 TXBoard-Deploy 官方工具。
2. **已完成安装**：按 [首次配置检查表](first-setup.md) 验证管理员入口、HTTPS、邮件、套餐与节点。
3. **已有历史数据**：先阅读 [原生数据库边界](../operations/native-schema.md) 和部署仓库中的隔离迁移指南；不执行覆盖式全新安装。
4. **需要开发**：阅读 [开发文档](../development/README.md) 和 [AGENTS.md](../../AGENTS.md)。

## 重要限制

CI 覆盖部分程序与合约行为，但不能自动证明生产环境可用。真实数据库迁移、财务结算、支付回调、反代、Node/WSS、第三方插件与 Gateway 需要单独验收。