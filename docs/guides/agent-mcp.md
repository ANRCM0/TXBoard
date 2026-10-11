# Agent Ops 与内置 MCP Gateway

TXBoard 的 **Agent Ops** 把部分运维能力封装为可审计的受限操作。**MCP Gateway 只是适配器**，不能越过 TXBoard 的 ability、target scope、审批与审计，也不能直接访问数据库、Redis 或 TX-Node。

## 接入方式

1. 先确认当前镜像包含内置 MCP 功能；它默认关闭。
2. 在部署工具中按需启用 MCP。当前主镜像按 `TXBOARD_ENABLE_MCP` 控制功能，启用后由同一 Caddy 入口代理 `https://<panel-host>/mcp`。
3. 在管理员后台创建权限和资源范围最小化的 Agent Token；优先使用有时效的一次性配对码。
4. Agent 客户端参照面板提供的 `/.well-known/txboard-agent-connect.md` 进行自助接入，先只读验证身份与目标。
5. 高风险操作始终经管理员审批与独立验证；不要授予自动批准、任意 shell、任意文件系统或数据库访问能力。

**注意**：`/mcp` 在未启用时返回 404；配对码只负责一次性交付凭据，不能扩大授权。任何 Token 或配对码都不要贴在 Issue 或公开日志中。

## 不要混淆两个 Gateway

- **内置 MCP Gateway**：属于 TXBoard 主镜像中的 Agent Ops 协议适配器，默认关闭。
- **TXBoard-Gateway**：独立的、可选的 **用户主题 API BFF / SDK** 项目，不接管 Agent 运维权限、支付 Webhook 或 Node Token。

## 更多信息

- [MCP 运行文档](../../mcp/README.md)
- [Agent Self-Connect Guide v2](../../api/resources/agent/txboard-agent-connect.md)
- [Agent Ops HTTP 契约](../../contracts/http/agent-ops-v1.md)
- [Agent Ops 架构](../architecture/agent-ops.md)
- [安全基线](../security/README.md)

当前 Agent Ops HTTP 路径以 `/txapi/agent/v1` 为前缀；管理端使用独立的 `/txapi/admin/{admin_path}/agents`。Agent 的响应封装与普通 TXAPI 响应存在差异，集成前请核对对应接口契约和实际路由。