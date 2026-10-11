# TXBoard 文档中心

> 文档目标：让使用者能够从安装到运维完成闭环，让开发者能够找到版本化协议的权威定义。**文档只描述当前代码和已验证行为；设计目标不得冒充已实现功能。**

## 阅读路线

| 你的目标 | 从这里开始 |
| --- | --- |
| 第一次接触 TXBoard | [项目介绍](getting-started/introduction.md) → [部署](getting-started/installation.md) → [首次配置](getting-started/first-setup.md) |
| 管理和使用面板 | [用户/管理员指南](guides/README.md) |
| 升级、备份、排障 | [运维导航](operations/README.md) |
| 理解组件边界 | [架构总览](architecture/README.md) |
| 修改源码或开发扩展 | [开发文档](development/README.md) |
| 接入外部客户端/Node | [版本化接口与扩展契约](../contracts/README.md) |
| 审查权限与风险 | [安全基线](security/README.md) |

## 权威资料与跨仓边界

- **API 方法、鉴权、错误语义**：[contracts/](../contracts/README.md) 与注册路由；WebSocket 和动态插件路由还需要在各自运行时验证。
- **生产安装/升级/迁移/备份命令**：[TXBoard-Deploy](https://github.com/ANRCM0/TXBoard-Deploy)；不要从本目录复制另一套安装器规则。
- **TX-Node 运行时和安装**：[TX-Node](https://github.com/ANRCM0/TX-Node)；TX-Node 与 TXBoard 不共用部署职责。
- **可选主题 BFF 与 SDK**：[TXBoard-Gateway](https://github.com/ANRCM0/TXBoard-Gateway)；与 TXBoard 镜像内置 MCP Gateway 不是同一组件。
- **代码 Agent 的实现约束**：[AGENTS.md](../AGENTS.md)。

## 维护与状态

[文档贡献规范与责任边界](DOCUMENTATION_GUIDE.md) 约定 Markdown 格式、来源、状态标签以及变更时应同步的文件。跨仓设计中的 `Target`、`Draft`、`Experimental` 不代表生产可用。

**验收边界**：CI 通过不代表真实数据库恢复、支付沙盒、外部反向代理、TX-Node 和 Agent 联调已完成。部署前参照 [发布验收 Runbook](operations/release-staging-acceptance.md)。