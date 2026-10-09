# Agent Native Administrator — Phase 5

> 开发期实现。Agent 管理控制面 `/txapi/admin/{admin_path}/agents` 为官方入口；旧 V2 Agent **管理员**路由及控制器直接移除。Agent 自有 bearer 的 `/api/v2/agent/*` 是另一套执行权限边界，此阶段仅保留运行时业务入口，不作为管理界面的兼容回退；后续 Agent 主动迁入统一协议。

## API 与权限

所有管理接口均经过动态 `admin_path`、Sanctum 管理员身份、写入审计和 TXAPI 请求 ID 检查：

| 方法 | 路径 | 范围 |
|---|---|---|
| GET | agents/abilities | Agent capability 清单 |
| GET | agents/tokens | 只列 **当前管理员拥有** 的 `agent:*` Token；稳定倒序分页，每页不超过 100，只返回权限和目标，不返回密钥 |
| POST | agents/tokens | 颁发权限、目标范围和最长 90 天有效的 Agent Token；**仅本次响应**返回明文和 Pairing；`private, no-store` |
| DELETE | agents/tokens/{id} | 只能撤销当前管理员自己的 Agent Token，不能撤销其他用户或普通 Token |
| GET | agents/fleet/health | 运行状况概览 |
| GET/POST | agents/inspections | 有界巡检历史 / 触发巡检 |
| GET | agents/nodes/{nodeId}/timeline | 节点事件时间线，限制 1–168 小时和 1–100 条 |
| GET | agents/actions | 有界审批记录与状态过滤 |
| POST | agents/actions/approve、reject | 请求 ID 校验，执行现有 AgentActionService 状态流转 |
| GET | agents/support/reply-requests | 待审批客服回复（上限 50），只能管理员读取回复正文 |
| POST | agents/support/reply-requests/approve、reject | 调用 AgentSupportService 并保留专门的 `AgentAuditLog` 审批审计 |

安全边界：`AgentAbility::validate` 严格能力白名单，`AgentTargetScope::compile` 负责节点/机器范围，restricted Agent Token 不得携带跨客户的 support 能力。Node 运维操作仍依靠 Agent 操作提交→管理员审批→在后端指令服务执行的服务边界；直接的管理员身份不被转换成 Agent Token。

## 前端和兼容策略

React Admin `agent.ts` 对外保留组件现有导出，底层 `agent-admin.ts` 通过 nativeApiClient 调用全部官方管理 API。Token 列表采用有限分页合并，读取超过上限显式报错而非截断。历史 `AgentRoute` 管理路由注册及两个 V2 Admin 控制器删除，不创建桥接接口。

注意：这次**没有**替换 Agent 本身的执行端 `/api/v2/agent/*` 协议，因为 TX-Node/Agent 还没有实施新的 Wire 合同。这不是兼容承诺；该协议应在后续独立阶段直接重做，待所有调用方主动适配后按合同删除。日后不能用此处的后台管理 API 冒充 Agent 自身执行入口。

## 发布门槛

CI 覆盖授权/越权、动态路径、Token 创建与撤销、受限权限、审批、客服回复与日志、前端路径合同；真实环境 Agent Pairing、Token 失效、后台审批/服务端送达、并发与重放、错误回滚、升级备份及审计脱敏，仍需代表性部署验证。发布阻塞项见 Issue #168。
