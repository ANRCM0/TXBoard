# TXBoard 对外路由与身份边界

| 功能 | 接口 | 身份要求 |
| --- | --- | --- |
| 公开页面、套餐 | `/txapi/public/*`、`GET /txapi/plans` | 匿名 |
| 安装就绪 | `GET /txapi/readiness` | 匿名、只读；安装权威未就绪或不匹配返回503，不公开安装标识或诊断明细 |
| 登录、账户 | `/txapi/auth/*`、`/txapi/me/*` | 登录/注册按接口校验，账户需用户 Bearer |
| 订单、余额、充值和工单 | `/txapi/orders/*`、`/txapi/billing/*`、`/txapi/tickets/*` | 用户 Sanctum Bearer |
| 管理员 | `/txapi/admin/{admin_path}/*` | 管理员 Bearer、动态路径与 RBAC |
| TXNode HTTP | `/txapi/node/v1/*` | Node/Machine Bearer + 节点/机器身份头 |
| Node Usage v2 接收 | `/txapi/node/v2/usage/*` | 同一 Node/Machine 身份；legacy 仅显式 local/testing 副本，native 受就绪与签名 Session 门禁约束 |
| TXNode WS | `wss://<host>/txapi/node/v1/ws` | Workerman Upgrade、专用身份头、功能开关 |
| 支付商回调 | `GET/POST /txapi/payment/webhook/{method}/{uuid}` | 提供商签名，原始 ACK |
| Telegram 回调 | `POST /txapi/integrations/telegram/webhook` | Telegram 验证摘要 |
| 订阅交付 | `POST /txapi/me/subscription-deliveries` | User Bearer + 客户端 PoP，Provider 直出材料 |
| 订阅链接 | `/txapi/me/subscription-links*`；`POST /txapi/subscription-links/{linkRef}/redeem` | 管理需 User Bearer；兑换使用独立链接 bearer + 接收者 PoP，不借用用户/服务凭据 |
| 正式服务入口 | `POST /txapi/internal/native/v1/{domain}/operations/{operation}` | 独立 User/Admin 服务凭据、可信 mTLS 证书绑定与实际调用者凭据；不是浏览器通道 |
| 动态插件接口 | 插件自行注册的接口 | 由实际插件负责鉴权与路由 |

普通 TXAPI JSON 响应包含 `data`、`request_id`，分页可含 `meta`；错误通常为 `error.code`、`error.message` 和 `request_id`，响应头包含 `X-Request-Id`。第三方回调有各自响应约定。存活检查：`GET /txapi/health`。

存活检查与安装就绪分开：`/api/health`、`/txapi/health`不依赖数据库；
`/txapi/readiness`在安装权威校验后返回`data.ready=true`。全新 native 安装
初始处于`initializing`，完成 C2 配置、真实 Provider 探测和持久激活后才开放
受支持的业务入口；不能通过修改环境变量回 legacy 绕过。
既有未初始化legacy部署保持原业务模式。边界见[Core Subscription v1](../v2/core-subscription-v1.md)。

C2-B已注册原生订阅应用适配，但仍受上述安装门禁约束：
`POST /txapi/orders`在native模式只接受`offer_id`并要求`Idempotency-Key`；
`POST /txapi/admin/{admin_path}/subscriptions/{id}/operations/{operation}`要求管理员身份、
`expected_version`和`Idempotency-Key`，操作仅限暂停、恢复、撤销、到期及重置。
账户归属由Core读取，不能通过请求选择。账户权益读取返回非秘密订阅投影，
不返回旧UUID作为原生代理凭据。legacy安装的订单与账户契约保持现状；
原生 Node 授权、Provider 凭据、订阅分发与 UsageV2 使用
[C2 有界运行契约](../v2/native-c2-runtime-v1.md)的独立准入条件。
C2 合并基线仅支持零价 Offer。2026-10-17 合并的 C3/C4 实现进一步支持 CNY
无手续费渠道插件、钱包/混合支付、充值、退款至钱包、升级折抵与持久履约恢复；
合并不代表完整内核或生产验收完成。
免费 checkout 同样必需永久幂等键。退款仅入钱包，不做权益精准回滚或原路退款；
旧支付插件不兼容，不能启用旧协议交接或猜测资金与权益。
详见[结算](../v2/native-payment-settlement-v1.md)、[钱包退款](../v2/native-wallet-refunds-v1.md)、
[升级折抵](../v2/native-upgrade-credit-v1.md)和[内核成熟度](../../docs/architecture/v2/core-maturity-review.md)。

C2-C1另注册以下用户Bearer入口，均保留native安装门禁：
`POST /txapi/me/access-subjects`要求订阅ID、已配置Provider/Profile、预期订阅版本和幂等键；
`POST /txapi/me/access-subjects/{id}/revoke`要求预期Subject版本和幂等键；
`GET /txapi/me/credential-operations/{operation_ref}`只读查询，
`POST /txapi/me/credential-operations/{operation_ref}/recover`恢复原操作。
账户身份取自认证上下文，不接受请求提供的owner、URL、密钥或Provider Subject引用。
响应仅包含非秘密回执和操作状态；写入/恢复的unknown返回202，只读查询成功返回200。
这些入口不返回代理UUID或订阅配置。
具体传输与字段见[真实Provider发行契约](../v2/credential-provider/native-issuance-v1.md)。

原生运行时还提供 `POST /txapi/me/access-subjects/{id}/rotate`，
要求预期 Subject 版本和幂等键，保留 Subject 并轮换凭据代次。
`POST /txapi/me/subscription-deliveries`接受本人 Subject 与独立客户端生成的
`recipient_public_key`，只返回短期签名策略；实际配置由该接收密钥持有者
直接向 Provider 取回。Core 不返回代理 UUID。

## 具体对接

- **[外部主题：HTTP 请求、字段、完整用户路由](theme-integration-current.md)**
- **[TXNode：HTTP/WSS、握手、流量与机器状态](../node-protocol/txnode-integration-current.md)**
- [主题包格式](../theme-package/README.md)
- [HTTP 路由导出与开发要求](../../AGENTS.md)
- [真实环境对接检查](../../docs/operations/release-staging-acceptance.md)

HTTP 路由由 `api/routes/txapi.php` 和 `RouteServiceProvider.php` 注册；Workerman WebSocket、插件动态路由以及反向代理规则不包含在 Laravel route-list 内。

Node Usage v2 的当前接收、持久回执与原周期结算见 [Native 用量契约](../v2/node-usage/README.md)。
legacy Node 保持 v1 默认行为；显式 native v2 模式要求同一签名中的有限授权与
完整 UsageV2 Session，并保留原批次、原周期和真实持久回执。

## 基础账户状态即时授权

管理员入口除校验当前 `is_admin`、动态路径和 RBAC 外，必须拒绝当前已封禁账户，返回403。封禁前已发行且未过期的普通管理员 Bearer 不得绕过当前账户状态；退役Agent凭据仍拒绝。此校验适用于原生管理员入口，并不开放管理员Gateway。
