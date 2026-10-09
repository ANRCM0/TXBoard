# TXAPI Target Contract v1 (PROPOSED / NOT LIVE)

**这不是已部署的 HTTP 协议。** 当前 Node、Agent、插件及管理 API 仍以现行 Laravel route:list 和 CURRENT contracts 为准；需要通过后续 PR 实现。

## Root: /txapi

全部未来 TXBoard-owned HTTP API 使用 `/txapi/*`，不再新增 `/api/v1/*`、`/api/v2/*`。

| Scope | Target | Required boundary |
|---|---|---|
| Gateway BFF (optional) | `/txapi/bff/v1/*` | Hono 独立进程，Edge 分流；v1 SDK envelope，不是 Laravel handler |
| Health | `GET /txapi/health` | liveness，不依赖 DB/Redis |
| Public | `/txapi/public/*` | 匿名，速率限制，公开字段 |
| Authentication | `/txapi/auth/*` | 验证码/反枚举/限流 |
| User | `/txapi/me/*`、`/txapi/plans/*`、`/txapi/orders/*` | Sanctum user/ownership |
| Admin | `/txapi/admin/{secure_path}/*` | Admin + dynamic path + RBAC + audit |
| TX-Node | `/txapi/node/v1/*` | 独立 Node identity/protocol |
| Agent Ops | `/txapi/agent/v1/*` | Agent abilities/scope/approval |
| Extensions | `/txapi/extensions/{code}/v1/*` | Module enabled + permissions |
| Payment webhook | `/txapi/payment/webhooks/*` | 签名 + 重放防护 + 幂等 |

Hono Gateway 独立的 [TXAPI BFF Target](txapi-bff-target-v1.md) 保留 `{ok,data,meta}` 的 v1 SDK envelope，和 Laravel 原生响应不同。

## Proposed response/error policy

- 成功：HTTP 2xx、`data`、可选 `meta` 和 `request_id`。
- 失败：HTTP 4xx/5xx、`error.code` 与安全的 `error.message`。新端点不继续传播 Xboard 的 status 字段。
- 分页：`meta.page/per_page/total/last_page`，服务端筛选与稳定排序；拟定 per_page 最大 100，P1 后冻结。
- 金额：整数最小货币单位 + 明确货币；流量：int64 字节；时间：RFC3339 UTC；输出字段白名单。
- 安全：User/Admin/Node/Agent 令牌互不提权；服务端资源隔离、权限、日志脱敏和速率限制。
- 状态变更：经过验证、审计；外部可重试的结算事件使用数据库幂等唯一键。

## 协议演进与迁移窗口

1. 先契约和客户端 schema 测试，再实现新 handler，旧路径暂不删除。
2. 旧路径仅是 legacy adapter，不可绕过新域服务的鉴权与事务。
3. 逐端验证 User/Admin、TX-Node、Agent/MCP、Gateway/Deploy、插件和支付平台消费者。
4. 在监控窗口内脱敏统计旧路由使用；保留线上回滚和未完成支付回调能力。
5. 全部确认后移除旧路由，另行发布 BREAKING CHANGE 说明。

## 进入实现的验收项目

- [ ] Method/path/auth/error schema / HTTP 状态契约
- [ ] 负向权限与身份混用测试
- [ ] 并发、重放、幂等与数据一致性测试
- [ ] User/Admin API typings；Node/Agent/插件消费者互通
- [ ] MySQL/Redis 测试、镜像探针与回滚
- [ ] 生产调用者清单/兼容窗口/监控验证
