# 管理后台使用指南

管理员后台负责用户、套餐、订单、节点/机器、插件/主题、站点设置及审计。具体菜单及权限取决于部署的镜像版本与启用模块；本页为管理流程导航，不复制易变的 UI 菜单树。

## 首次登录前

1. 确认服务健康、HTTPS 和公网 `APP_URL` 配置正确。
2. 妥善保存管理员凭据与动态 `secure_path`；**安全路径不替代管理员身份认证**。
3. 确认具备对应 RBAC 权限；管理员、普通用户、Node/Machine 和 Agent 使用不同身份边界。
4. 检查备份、恢复和设置修改后的审计记录。

## 常见工作流

| 工作 | 检查重点 | 相关资料 |
| --- | --- | --- |
| 用户管理 | 账号状态、套餐/流量、权限、修改审计 | [首次配置](../getting-started/first-setup.md) |
| 套餐与订单 | 定价、订阅规则、订单与金额一致性 | [支付与订单](payments.md) |
| 节点管理 | Node/Machine 配对身份、分组路由、流量上报 | [节点与机器](node-management.md) |
| 插件 / 主题 | 可信包来源、依赖、激活与升级回滚 | [模块扩展](plugins-and-themes.md) |
| Agent | Token ability、目标范围、审批与审计 | [Agent Ops / MCP](agent-mcp.md) |

## 安全路径恢复

在拥有服务器运维权限的终端，使用部署工具提供的 `sudo txboard admin-reset-path`；该操作轮换入口路径，但不更改管理员密码或角色权限。新路径属于敏感信息，不应粘贴到公开 Issue 或日志中。以 [部署管理指南](https://github.com/ANRCM0/TXBoard-Deploy/blob/main/docs/operations.md) 和 [安全基线](../security/README.md) 为准。

## 后端契约

管理员接口使用动态管理路径和独立的 Admin Bearer / RBAC；不能将普通用户或 Agent Token 当作管理员凭据。开发或集成前阅读 [跨身份边界](../../contracts/http/external-adapter-current.md) 与 [AGENTS.md](../../AGENTS.md)。