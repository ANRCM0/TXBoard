# TXBoard 对外路由与身份边界

| 功能 | 接口 | 身份要求 |
| --- | --- | --- |
| 公开页面、套餐 | `/txapi/public/*`、`GET /txapi/plans` | 匿名 |
| 登录、账户 | `/txapi/auth/*`、`/txapi/me/*` | 登录/注册按接口校验，账户需用户 Bearer |
| 订单、余额、充值和工单 | `/txapi/orders/*`、`/txapi/billing/*`、`/txapi/tickets/*` | 用户 Sanctum Bearer |
| 管理员 | `/txapi/admin/{admin_path}/*` | 管理员 Bearer、动态路径与 RBAC |
| TXNode HTTP | `/txapi/node/v1/*` | Node/Machine Bearer + 节点/机器身份头 |
| TXNode WS | `wss://<host>/txapi/node/v1/ws` | Workerman Upgrade、专用身份头、功能开关 |
| Agent 运行时 | `/txapi/agent/v1/*` | Agent Bearer、目标范围和 abilities；配对入口单独限流 |
| 支付商回调 | `GET/POST /txapi/payment/webhook/{method}/{uuid}` | 提供商签名，原始 ACK |
| Telegram 回调 | `POST /txapi/integrations/telegram/webhook` | Telegram 验证摘要 |
| 订阅链接 | `GET /{subscribe_path}/{token}` | 私密 URL |
| 动态插件接口 | 插件自行注册的接口 | 由实际插件负责鉴权与路由 |

普通 TXAPI JSON 响应包含 `data`、`request_id`，分页可含 `meta`；错误通常为 `error.code`、`error.message` 和 `request_id`，响应头包含 `X-Request-Id`。Agent、第三方回调有各自响应约定。存活检查：`GET /txapi/health`。

## 具体对接

- **[外部主题：HTTP 请求、字段、完整用户路由](theme-integration-current.md)**
- **[TXNode：HTTP/WSS、握手、流量与机器状态](../node-protocol/txnode-integration-current.md)**
- [主题包格式](../theme-package/README.md)
- [HTTP 路由导出与开发要求](../../AGENTS.md)
- [真实环境对接检查](../../docs/operations/release-staging-acceptance.md)

HTTP 路由由 `api/routes/txapi.php` 和 `RouteServiceProvider.php` 注册；Workerman WebSocket、插件动态路由以及反向代理规则不包含在 Laravel route-list 内。
