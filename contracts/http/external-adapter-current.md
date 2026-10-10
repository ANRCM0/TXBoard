# TXBoard 外部服务接入：当前 HTTP / WS 路由与迁移边界

> **CURRENT SERVER CODE — 2026-10-10（不是线上环境通过证明）**  
> 唯一可信依据：`api/app/Providers/RouteServiceProvider.php`、`api/routes/txapi.php`、`api/app/Http/Routes/{V1,V2}/*`、Workerman WS 接入代码，以及 CI 中真正执行的 `php artisan route:list --json`。**本文不是未来 BFF/Agent 协议已经实现的承诺。**
>
> 全量 HTTP 注册表每次 CI 自动导出为 `txboard-http-route-catalog` Artifact（JSON + Markdown），生成方式见 [路由快照说明](../../docs/architecture/http-route-inventory.md)。人工协议本文用于外部客户端适配，自动快照用于确认端点/method/middleware 是否存在。动态插件、Caddy/OpenResty 转发和 Workerman WS **无法**仅从 Laravel `route:list` 证明。

## 1. 版本、归属与选型（必须先读）

| 范围 | 当前代码中可调用 | 凭据与责任 | 注意 |
|---|---|---|---|
| TXAPI 公共/用户 | `/txapi/public/*`, `/txapi/auth/*`, `/txapi/me/*`, `/txapi/orders/*`, `/txapi/billing/*`, `/txapi/plans` | 匿名或用户 Sanctum Bearer | 官方前台使用；金额单位为分（*_minor）、流量 bytes |
| TXAPI 后台管理 | `/txapi/admin/{admin_path}/*` | **管理员** Bearer + 动态安全路径 + 审计 | `{admin_path}` 不是密码的替代品；**旧 `/api/v2/{admin_path}/*` 全部退役** |
| TX-Node HTTP v1 | `/txapi/node/v1/*` | **节点或机器** Bearer + ID 请求头 | 当前已有服务端，不代表独立 TX-Node 已完成对接 |
| TX-Node WS v1 | `wss://<host>/txapi/node/v1/ws` | WS Upgrade 携带相同的 Bearer + Node/Machine ID | **Workerman** 路由，非 Laravel `route:list`；功能开关默认关闭 |
| Agent Ops（现行） | `/api/v2/agent/*` | 管理员签发的 **Agent Bearer** + abilities/targets + 审批 | 不得伪称 `/txapi/agent/v1/*` 已上线；Agent 自身的运行 API 尚未原生迁移 |
| 支付提供方通知 | 现行 `/api/v1/guest/payment/notify/{method}/{uuid}`；可选择 `/txapi/payment/webhook/{method}/{uuid}` | 提供方签名/商户配置；**不是 Bearer** | 原生通知生成默认未切换，不能自行硬改已有订单的回调地址 |
| 订阅客户端下载 | 由 `subscribe_path` 设置决定的 `/{subscribe_path}/{token}`（通常 `/s/{token}`）；另有旧客户端入口 `/api/v1/client/subscribe` | 订阅 URL 含秘密 token；客户端格式协商 | 浏览器用户 API Bearer **不等于订阅 token** |
| V1/V2 兼容运行接口 | `/api/v1/server/*`、`/api/v2/server/*`、`/api/v1,2/client/*`、剩余 `passport,guest,user` | 各路由自己的旧鉴权/协议 | **仍注册**，不得批量删、不得作为新集成首选 |
| 插件自有 API | `/plugin/{code}/*` 和插件声明的资源路径 | 插件独立路由/权限，需阅读其 manifest | Laravel 路由数量可能依已安装/启用插件而变化 |
| Gateway BFF | **TARGET:** `/txapi/bff/v1/*` | 独立 Hono Gateway，另行部署 | 本仓 Laravel **没有**这组 CURRENT handler；`/gateway/v1/*` 亦属于独立外部仓与部署 |
| Agent Native | **TARGET:** `/txapi/agent/v1/*` | 拟议 versioned Agent control-plane | 不能替代 CURRENT `/api/v2/agent/*` |
| Extension Native | **TARGET:** `/txapi/extensions/{code}/v1/*` | 后续模块协议 | 不能和 CURRENT `/plugin/*` 混用 |

注意：`/api/health` 和 `/txapi/health` 是**进程存活**探针，不代表 MySQL/Redis/支付/Node 可用。部署前实际可达性见 [预发验收](../../docs/operations/release-staging-acceptance.md)。

## 2. HTTP 协议：请求、响应、认证和错误

### 2.1 原生 TXAPI 统一响应

成功（HTTP 2xx）：

```json
{"data":{"status":"ok"},"request_id":"server-generated-trace"}
```

错误（HTTP 4xx/5xx）：

```json
{"error":{"code":"ORDER_NOT_FOUND","message":"Order not found"},"request_id":"server-generated-trace"}
```

若分页，增加 `meta:{page,per_page,total,last_page}`。原生请求和响应提供 `X-Request-Id`。**不可**沿用旧 V1/V2 的 `status:success|fail` / `data` 解包假设。用户列表受登录态和 SQL ownership 约束；不要共享用户响应缓存。退款/支付 Provider ACK 是例外：**原始文本响应，不包 JSON**。

- `Authorization: Bearer <...>`：仅用于对应身份作用域；管理员/普通用户/Node/Machine/Agent 不能互用。
- `Content-Type: application/json`：JSON 写请求；支付回调按提供方实际约定的 GET/POST 格式，不要求统一 JSON。
- `401` 未认证/无效凭据，`403` 缺权限/目标 scope，`404` 资源不可见/动态管理员路径错误，`409` 冲突/重复业务状态，`422` 数据校验失败，`429` 限流，`5xx` 服务故障（不同端点可能还有特殊状态）。
- 支持安全重试的是**幂等读取**、Node 原样重发的**相同批次**和明确声明幂等的业务接口；不要自动重放创建订单、提现、运维审批或充值请求。
- 不要在 URL、日志、Github Issue、支持工单里放真实 Bearer、APP_KEY、订阅 URL 或提供方签名/商户密钥。

### 2.2 主要用户 / 管理 API（当前已实现）

| 方法 | 路径示例 | 用途 |
|---|---|---|
| GET | `/txapi/public/site-config`, `/txapi/public/config` | 匿名公开站点配置 |
| POST | `/txapi/auth/register`, `/txapi/auth/login` | 用户注册、登录；依站点设置启用验证码 |
| POST | `/txapi/auth/admin/login` | 管理员专用登录，校验角色后给管理员 Bearer 与当前 secure path |
| GET | `/txapi/me`, `/txapi/me/subscription`, `/txapi/me/nodes` | 用户资料、订阅与获准节点 |
| GET | `/txapi/plans`, `/txapi/plans/{planId}` | 匿名套餐目录/用户已获准套餐详情 |
| GET/POST | `/txapi/orders` | 本人订单列表 / 新购单 |
| GET | `/txapi/orders/{tradeNo}` | 本人订单状态；严格校验所有权 |
| POST | `/txapi/orders/{tradeNo}/checkout` | 以服务端计算的金额发起支付 |
| POST | `/txapi/orders/{tradeNo}/cancel` | 本人待支付订单取消 |
| GET | `/txapi/billing/wallet`, `/txapi/billing/commissions` | 余额分单位与返佣，限于本人 |
| GET/POST | `/txapi/billing/recharges` | 本人钱包充值列表 / 创建 |
| POST | `/txapi/billing/recharges/{tradeNo}/checkout` | 本人充值单付款 |
| POST | `/txapi/gift-cards/check`, `/txapi/gift-cards/redeem` | 验码、兑换；失败/并发不可重复入账 |
| GET/POST | `/txapi/tickets` | 本人工单读/写 |
| GET | `/txapi/traffic/logs` | 本人流量账本分页 |
| GET | `/txapi/admin/{admin_path}/audit-logs` | 管理员操作审计（脱敏） |
| GET/POST | `/txapi/admin/{admin_path}/network-nodes`, `network-machines`, `network-routes` | 管理员控制平面变更 |
| GET/POST | `/txapi/admin/{admin_path}/agents/tokens` | Agent Token 列表/签发；Token 明文仅首次返回 |
| POST | `/txapi/admin/{admin_path}/agents/actions/approve`、`reject` | 受审计审批，非 Agent 自批 |
| POST | `/txapi/admin/{admin_path}/agents/support/reply-requests/approve` | 人工批准客服回复；由权威工单服务实际执行 |
| GET | `/txapi/admin/{admin_path}/modules`, `plugins`, `themes` | 模块/插件/主题管理 |
| GET | `/txapi/admin/{admin_path}/analytics/dashboard`, `queue/snapshot` | 后台只读概览、队列健康 |

**完整管理端路径**：CI 自动生成的路由目录为准；管理员管理端是 TXBoard Control Plane，不应让 Node、主题 Gateway 或访客聊天服务直接持有 Admin Bearer。

## 3. TX-Node HTTP v1：供独立 TX-Node 实现适配

Base: `https://<txboard-host>/txapi/node/v1`。

| 方法 | 资源 | 鉴权 | 行为 |
|---|---|---|---|
| POST | `/handshake` | Node 或 Machine | 返回 mode、protocol_version、capabilities、push/pull 间隔及可用 WS |
| GET | `/config` | Node（或有权限绑定的 Machine + Node） | Node 配置，`ETag` / `If-None-Match` → 304 |
| GET | `/users` | 同上 | 仅有权节点的用户快照，支持 ETag/304 |
| POST | `/report` | 同上 | 202 accepted + queued，**不是完成扣量** |
| GET | `/machine/nodes` | Machine | 只返回该机器已绑定节点 |
| POST | `/machine/status` | Machine | CPU、mem、disk、swap、net 与受限 runtime 元数据 |

Node 每个 HTTP 请求必须带 `Authorization: Bearer <node-or-machine-credential>` 和节点对应 `X-TX-Node-ID: <v2_server.id>`；机器身份需 `X-TX-Machine-ID: <machine-id>`。机器独立 handshake、机器节点发现与状态端点可不带 Node ID；具体所有权在服务端重新核验。不得使用查询字符串传 Token。身份错误通常 401/404。

握手：

```bash
curl -fsS -X POST 'https://txboard.example/txapi/node/v1/handshake' \
  -H 'Authorization: Bearer <TEST_NODE_TOKEN>' \
  -H 'X-TX-Node-ID: <NODE_DB_ID>' \
  -H 'Content-Type: application/json' \
  -d '{}'
```

握手 `data` 有 `protocol_version:1`、`mode:"node"|"machine"`、`capabilities`、`websocket.enabled/path`、`settings.push_interval/pull_interval`。如果返回 WS 未启用，应继续使用 HTTPS 轮询，**不要强行 Upgrade**。

流量报告示例：

```json
{
  "protocol_version":1,
  "traffic_batch_id":"node42-batch-0000001",
  "traffic":{"1001":[1024,2048]},
  "online":{"1001":1}
}
```

`traffic` 是 userId → [上传字节, 下载字节]；Server 会按既定倍率计算，客户端**不得提前乘倍率**。非空 traffic 必须带 8–80 字符不可变 batch ID，最多 10,000 用户、最大报文 1MiB、单方向最大 1PiB。**超时后必须重用完全相同的 batch ID 和原始内容，不能换新 ID 重记账**。

`202` 只证明入队；当前没有稳定的**持久结算 receipt 查询/确认**端点，需以 MySQL `(server_id,batch_id)` 唯一流水和 Worker 运行状态排查。队列丢失后“不确定是否已结算”的完整恢复流程尚待真实故障注入验收。这是正式切换的阻塞风险。

Native WS: `wss://<host>/txapi/node/v1/ws`，实际由 Workerman 提供，需 `TXBOARD_NATIVE_NODE_WS_ENABLED=true` 并正确转发 Upgrade + Authorization/Node/Machine ID。使用 `{protocol_version:1,event,data,request_id}` 事件结构；`traffic.report` 返回 `traffic.ack`，同样仅确认 queued。心跳需回应 `heartbeat.ping` → `heartbeat.pong`。老 `/ws` 另属遗留协议，绝不可混用 frame。

详细、强约束的版本化契约以 [Node Native v1](../node-protocol/node-native-v1.md) 为准。

## 4. Agent Ops：**CURRENT 是 V2**

Base: `https://<txboard-host>/api/v2/agent`，由管理员 `POST /txapi/admin/{admin_path}/agents/tokens` 签发最小权限 Token。Agent 运行 Token 名称以 `agent:` 开头，具有明确 `agent:* ` abilities，必要时携带 `agent:target:restricted` 与节点/机器白名单。**不是用户 Bearer，也不是管理 Bearer。**

- 匿名一次性配对：`POST /api/v2/agent/pairings/redeem`，body `{"pairing_code":"txbp_..."}`，只可兑换一次（默认 600 秒）；重试/过期/撤销须重新发起，勿在日志记录 code/token。
- 只读：`GET /whoami`、`system/status`、`machines`、`nodes`、`nodes/{nodeId}/metrics`、`nodes/{nodeId}/diagnose`、`fleet/health`、`inspections`、`nodes/{nodeId}/remediation`、`nodes/{nodeId}/timeline`、`traffic/summary`、`queue/status`、`audit`。
- 运维动作：`POST /nodes/{nodeId}/actions` 提交 `{"action":"ops.kernel.status","input":{}}`，创建 pending；`GET /actions/{requestId}` 查询结果，`GET /actions/{requestId}/verify` 查询验证。**不自动批准**，管理员需在自己的 TXAPI 管理接口人工审批。
- 客服：`GET /support/overview`、`support/tickets`、`support/tickets/{ticketId}`；`POST /support/tickets/{ticketId}/reply-requests` **仅创建待审回复**，`GET /support/reply-requests/{requestId}` 读状态。客服回复与 Node 限定 scope 不能简单混合。
- 所有资源返回由 ability + target scope 过滤；目标越权是 403，pending≠已执行，异常 `unknown` 不应自动重试有副作用的回复。

具体 ability、动作白名单/输入限制、审批与状态机参考 [Agent Ops v1](agent-ops-v1.md)、[Agent Support](agent-support-v1.md) 和 [Pairing](../agent-self-connect/v2.md)。

**迁移边界**：文档中的 `/txapi/agent/v1/*` 是待实现的跨仓目标。当前不要修改 Hermes/MCP/外部 Agent 的 URL 为该路径，也不要把 V2 JSON envelope 当成原生 TXAPI。

## 5. 支付商通知与订阅协议

**支付通知**：通常由历史订单的提供方调用 `GET/POST /api/v1/guest/payment/notify/{method}/{uuid}`。可选原生端点 `GET/POST /txapi/payment/webhook/{method}/{uuid}` 已实现共用签名处理器，但 **`TXBOARD_NATIVE_PAYMENT_WEBHOOK=false` 默认关闭出站 URL 切换**。新旧回调都要由 Laravel 严格验证签名、金额、商户、支付状态、交易唯一性；成功回复是提供方要求的 **原始 ACK 文本**，非 TXAPI JSON。不可拿 GET 存活检查来当签名验证，不要人工伪造真实商户成功通知。用沙盒验收并确保仍在途的订单回调 URL 可用。

**订阅分发**：当前主入口 `GET /{subscribe_path}/{token}`，默认路径设置可能为 `s`，此外 `GET /api/v1/client/subscribe` 由旧 `client` 中间件处理。订阅下载会按客户端 UA/格式协商使用旧 ClientController，**不是** `GET /txapi/me/subscription`（后者是登录用户资料）。订阅链接视为秘密，不得展示于公开日志/分享/CI Artifact。更改订阅路径、Token 或迁移客户端时必须保留受支持客户端的过渡窗口。

**Telegram**：现行 `POST /api/v1/guest/telegram/webhook`，不能错误地归类为支付/Agent/WebSocket；需要实际 Telegram webhook 投递验收。

## 6. Gateway / 插件 / WebSocket 的特殊边界

- **独立 Hono Gateway** 是可选 BFF，不是 Laravel 的代理中间件。目前 `/txapi/bff/v1/*` 在本仓未注册，参考 [Gateway ADR](../../docs/architecture/gateway-integration.md)。未来必须由 HTTPS Edge 精确分流，不能以 `/txapi/*` 泛匹配强迫管理员、Node、支付商或 Agent 经过 Gateway。
- Gateway 若使用用户操作，必须转发当前用户身份给 Laravel 权威鉴权，不能缓存私有响应、伪造 admin bearer、代替支付校验、直接写 MySQL 或用前端提供的 arbitrary upstream URL。
- 插件的 `/plugin/{code}/*` 受插件路由注册、启停和独立权限约束；公开的 Plugin Package/Module/Theme contracts 见 [Contracts 索引](../README.md)。动态插件路径不保证出现在无插件的 CI 路由表。
- Native WS 与 legacy `/ws` 的 HTTP Upgrade 位于独立 Workerman/Caddy 路由层；`route:list --json` 不显示，必须结合 TLS/WS 实际握手与 Worker 日志验证。

## 7. 外部适配的落地顺序

1. 固定 TXBoard commit SHA 与镜像 digest；在同版本的 CI 下载最新 `txboard-http-route-catalog` Artifact，同时用 `api/routes/txapi.php`、旧 RouteModule、Contract 文件确认认证/内容细节。
2. 明确调用者身份：普通用户 / 管理员 / Node / Machine / Agent / 支付商 / 订阅客户端 / 插件 / 主题 Gateway。**每种身份只能访问对应的协议边界。**
3. 对照 CURRENT 入口先写协议适配器和负面测试（错 Token、越权、重复、超时、断线重试），不能根据 TARGET URL 猜路径。Node Agent 和 Provider 需要真实联调。
4. 支持 ETag/304、错误码、HTTP 202 的 queued 语义、非 JSON Webhook ACK、动态用户订阅路径与旧 V1/V2 的专属 envelope。
5. 做跨服务联调并保留脱敏证据：正常、无权限、过期撤销、失败重试、双发和回滚。完成前不要开启 Native WS/Payment URL 切流，也不要停用有活跃外部消费者的 V1/V2。
6. 路由新增/删除时须同步本说明和相应 CURRENT Contract，CI 以实际路由表检查关键边界。外部系统应遵循自己部署版本的实际路由快照。

## 8. 当前未完成、不能隐藏的开发/验收

- **代码/协议后续工作**：Node durable settlement receipt/queue-loss recovery 设计与落实；跨仓 TX-Node 适配；Agent 从 V2 到版本化 native 的迁移与外部消费者适配；独立 Gateway BFF 实现；剩余 V1/V2 非管理路由逐消费端退役。此类变更不属于纯粹“部署验证”。
- **真实环境验收**：1Panel/外部 MySQL/Redis/反代、真实历史数据升级恢复、支付沙盒与订单/钱包对账、Node WS/token 轮换、Agent 权限/审批/发送、主题插件升级、Telegram/SMTP 投递、性能与回滚。见 [Release staging](../../docs/operations/release-staging-acceptance.md) 和 [Issue #168](https://github.com/ANRCM0/TXBoard/issues/168)。
- **文档更新策略**：早期 `txapi-target-v1.md`、`txboard-native-development-plan.md` 和 P1–P4 阶段快照保留历史，不作为 CURRENT 路由引用。遇到冲突：**实际部署的路由表与运行服务 > 当前源码注册 > CURRENT 已验证 Contract > TARGET 设计**。
