# TXBoard Gateway 文档中心

> Gateway 是可选的用户主题 API BFF / SDK，不是 TXBoard 内置的 Agent Ops MCP Gateway。当前运行前缀为 `/gateway/v1`；`/txapi/bff/v1` 是目标方案，不能当作已经发布。

## 阅读路线

- **开发入门**：[架构](architecture.md)、[开发者指南](developer-guide.md)、[Docker 部署](docker-deployment.md)。
- **现有协议与 SDK**：[Gateway v1](../contracts/gateway-v1.md)、[Theme SDK](../packages/theme-sdk/README.md)、[主题模板](theme-templates.md)。
- **部署运维**：[限流设计](rate-limiting.md)、[限流恢复](runbook-rate-limit-recovery.md)、[发布检查](release-checklist.md)。
- **目标架构**：[TXAPI 集成方案](txapi-integration.md)、[目标 BFF 契约](../contracts/txapi-bff-target-v1.md)。
- **路线图与状态**：[开发状态台账](development-status.md)、[Roadmap](roadmap.md)、[Task Backlog](task-backlog.md)。
- **历史决策**：各 `adr-*.md` 是设计及决策记录，不能仅因文件存在便视为功能已上线。

## 如何理解状态

- **Current**：已实现于当前代码，但不能自动推断通过生产联调。
- **Experimental**：仅在开关、沙盒或受限环境下使用。
- **Target / Draft**：未来目标或待评审设计。
- **Historical**：历史讨论、阶段性计划与过往验收记录。

**唯一的当前进展来源是 [development-status.md](development-status.md)**。README 只保留稳定的产品介绍和入口，不维护另一份容易过时的 PR / 测试数字。

[返回 TXBoard 文档中心](https://github.com/ANRCM0/TXBoard/blob/main/docs/README.md)。