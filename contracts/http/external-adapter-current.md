# TXBoard 对外路由与身份边界

| 功能 | 接口 | 身份要求 |
| --- | --- | --- |
| 公开页面、套餐 | `/txapi/public/*`、`GET /txapi/plans` | 匿名 |
| 安装就绪 | `GET /txapi/readiness` | 匿名、只读；安装权威未就绪或不匹配返回503，不公开安装标识或诊断明细 |
| 登录、账户 | `/txapi/auth/*`、`/txapi/me/*` | 登录/注册按接口校验，账户需用户 Bearer |
| 订单、余额、充值和工单 | `/txapi/orders/*`、`/txapi/billing/*`、`/txapi/tickets/*` | 用户 Sanctum Bearer |
| 管理员 | `/txapi/admin/{admin_path}/*` | 管理员 Bearer、动态路径与 RBAC |
| TXNode HTTP | `/txapi/node/v1/*` | Node/Machine Bearer + 节点/机器身份头 |
| Node Usage v2 隔离接收 | `/txapi/node/v2/usage/*` | 同一 Node/Machine 身份；仅显式 local/testing 副本启用，生产拒绝 |
| TXNode WS | `wss://<host>/txapi/node/v1/ws` | Workerman Upgrade、专用身份头、功能开关 |
| 支付商回调 | `GET/POST /txapi/payment/webhook/{method}/{uuid}` | 提供商签名，原始 ACK |
| Telegram 回调 | `POST /txapi/integrations/telegram/webhook` | Telegram 验证摘要 |
| 订阅链接 | `GET /{subscribe_path}/{token}` | 私密 URL |
| 动态插件接口 | 插件自行注册的接口 | 由实际插件负责鉴权与路由 |

普通 TXAPI JSON 响应包含 `data`、`request_id`，分页可含 `meta`；错误通常为 `error.code`、`error.message` 和 `request_id`，响应头包含 `X-Request-Id`。第三方回调有各自响应约定。存活检查：`GET /txapi/health`。

存活检查与安装就绪分开：`/api/health`、`/txapi/health`不依赖数据库；
`/txapi/readiness`在安装权威校验后返回`data.ready=true`。C2-A/C2-B的native安装
始终处于`initializing`，业务HTTP请求统一拒绝；不能通过修改环境变量回legacy绕过。
既有未初始化legacy部署保持原业务模式。边界见[Core Subscription v1](../v2/core-subscription-v1.md)。

C2-B已注册原生订阅应用适配，但仍受上述安装门禁约束：
`POST /txapi/orders`在native模式只接受`offer_id`并要求`Idempotency-Key`；
`POST /txapi/admin/{admin_path}/subscriptions/{id}/operations/{operation}`要求管理员身份、
`expected_version`和`Idempotency-Key`，操作仅限暂停、恢复、撤销、到期及重置。
账户归属由Core读取，不能通过请求选择。账户权益读取返回非秘密订阅投影，
不返回旧UUID作为原生代理凭据。legacy安装的订单与账户契约保持现状；
生产原生Node授权、Provider凭据及订阅分发须完成C2-C后才可启用。

## 具体对接

- **[外部主题：HTTP 请求、字段、完整用户路由](theme-integration-current.md)**
- **[TXNode：HTTP/WSS、握手、流量与机器状态](../node-protocol/txnode-integration-current.md)**
- [主题包格式](../theme-package/README.md)
- [HTTP 路由导出与开发要求](../../AGENTS.md)
- [真实环境对接检查](../../docs/operations/release-staging-acceptance.md)

HTTP 路由由 `api/routes/txapi.php` 和 `RouteServiceProvider.php` 注册；Workerman WebSocket、插件动态路由以及反向代理规则不包含在 Laravel route-list 内。

Node Usage v2 的已实现范围与回执契约见 [隔离接收切片](../v2/node-usage/isolated-receipt-current.md)。TX-Node 默认 Service 和生产计费仍使用 v1，注册隔离路由不构成运行路径切换。

## 基础账户状态即时授权

管理员入口除校验当前 `is_admin`、动态路径和 RBAC 外，必须拒绝当前已封禁账户，返回403。封禁前已发行且未过期的普通管理员 Bearer 不得绕过当前账户状态；退役Agent凭据仍拒绝。此校验适用于原生管理员入口，并不开放管理员Gateway。
