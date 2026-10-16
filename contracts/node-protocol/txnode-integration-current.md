# TXNode HTTP / WebSocket 对接手册

> 接口以 `api/routes/txapi.php`、`TxNodeAuth`、`NodeProtocolController` 和 `NativeNodeWebSocket` 为准；两端实际环境联调需另行验证。

节点安装与主机更新统一由 TXBoard-Deploy 管理。管理员获取 Machine 凭据时，`install_command` 使用 `https://raw.githubusercontent.com/ANRCM0/TXBoard-Deploy/main/node/deploy.sh`；仍传递 Machine 模式、`txboard` provider、镜像 channel、面板 URL 和 Machine 凭据。原 TX-Node-Installer 地址仅保留兼容转发，Node HTTP/WSS 的身份与协议不变。

## 1. 身份与连接模式

基础 HTTPS URL：`https://<panel-host>/txapi/node/v1`。TX-Node 作为客户端主动访问 TXBoard；公网必须使用受信任 TLS、正确反代，并允许 `Authorization` 与身份头透传。

| 场景 | HTTP / WSS 请求头 | 约束 |
| --- | --- | --- |
| 单节点 Node | `Authorization: Bearer <server-token>` + `X-TX-Node-ID: <node-id>` | `node-id` 是 TXBoard 节点数据的 **主键 ID**，不是配置中的 code |
| Machine 管理与初始握手 | `Authorization: Bearer <machine-token>` + `X-TX-Machine-ID: <machine-id>` | 机器须启用；仅用于 `handshake` 的 machine 模式、`machine/nodes`、`machine/status` |
| Machine 下某个节点的 `config/users/report` | 上述 Machine 两个头 + `X-TX-Node-ID: <assigned-node-id>` | 节点必须归属该启用机器，且仍启用 |

不要将 Bearer 放入 query string、JSON body、日志或 URL；Node / Machine 身份不得替换普通用户 Sanctum 或管理员身份。当前单节点模式使用全局配置的 `server_token`；机器模式使用独立且可轮换的 Machine Token。

`X-TX-Node-ID`、`X-TX-Machine-ID` 是协议对象的 ID，不依赖内部数据库表名。HTTP 身份校验可返回 `401 NODE_UNAUTHORIZED`、`422 NODE_ID_REQUIRED` 或 `404 NODE_NOT_FOUND`。客户端不要靠自动猜测节点 ID 重试。

## 2. HTTP 路由

| 方法 | 完整路径 | 身份 | 关键行为 |
| --- | --- | --- | --- |
| POST | `/txapi/node/v1/handshake` | Node 或 Machine | 协商 `protocol_version:1`、`mode`、intervals、WS 能力；无需额外请求字段 |
| GET | `/txapi/node/v1/config` | 指定 Node | 节点 `config`、`base_config`；支持 `ETag`、`If-None-Match` / 304 |
| GET | `/txapi/node/v1/users` | 指定 Node | 仅 Provider 完整签名快照；凭据为 SHA-256 承诺，`no-store`，无 304 或裸 UUID 兜底 |
| POST | `/txapi/node/v1/report` | 指定 Node | 在线/状态/指标；native 模式拒绝旧 traffic 扣量，计费使用签名会话的 v2 回执 |
| GET | `/txapi/node/v1/audit/rules` | 指定 Node | 可选 AccessAudit 已启用规则快照；TXAPI JSON envelope |
| POST | `/txapi/node/v1/audit/report` | 指定 Node | 可选访问审计事件，最多 200 条/批、1 MiB，按节点/事件 ID 幂等；HTTP 200 |
| GET | `/txapi/node/v1/machine/nodes` | Machine | 仅当前机器的 `nodes:[{id,type,name}]` 和 `base_config` |
| POST | `/txapi/node/v1/machine/status` | Machine | 机器 CPU、内存/磁盘、网络与 Runtime 状态快照 |
| WS | `wss://<panel-host>/txapi/node/v1/ws` | Node 或 Machine 握手头 | Workerman 升级路径，**不是 Laravel HTTP Route**；功能开关默认关闭 |

### 2.1 Handshake（建议启动时第一个调用）

```bash
curl -fsS -X POST 'https://<panel-host>/txapi/node/v1/handshake' \
  -H 'Authorization: Bearer <server-token>' \
  -H 'X-TX-Node-ID: 42' -H 'Accept: application/json'
```

响应形状（示意，数值取运行时配置）：

```json
{
  "data": {
    "protocol_version": 1,
    "node_id": 42,
    "mode": "node",
    "capabilities": ["http_poll", "machine_discovery", "access_audit_v1", "node_authorization_snapshot_v1", "node_provider_credential_delivery_v1"],
    "websocket": {
      "enabled": false,
      "path": "/txapi/node/v1/ws",
      "heartbeat_interval_seconds": 55
    },
    "settings": {"push_interval": 60, "pull_interval": 60}
  },
  "request_id": "<server-request-id>"
}
```

握手同时返回非秘密 `authorization_trust` 和 30 秒授权刷新间隔。Provider 注册或当前安装权威不可用时返回 `503 NODE_AUTHORIZATION_UNAVAILABLE`，不得退回裸 UUID、旧有限签名策略或隔离 Usage capability。

机器仅带 Machine ID 时，`data.mode="machine"` 且 `data.node_id=null`。客户端必须依据 `data.websocket.enabled` 决定是否升级 WSS；即使为 false，HTTP 轮询仍可运行。

### 2.2 配置与用户快照

```http
GET /txapi/node/v1/config HTTP/1.1
Authorization: Bearer <server-token>
X-TX-Node-ID: 42
If-None-Match: "<previous-etag>"
```

`/config` 的 200 返回 `data.protocol_version`、`data.node_id`、`data.config`，按节点和身份缓存其不透明 ETag；304 仅复用匹配的配置正文。

`/users` 每次返回 `data.protocol_version`、`data.node_id`、完整 `data.users` 和 `data.authorization`。用户 UUID 字段是 `sha256:<64hex>` 凭据承诺；Node 验证 Core 签名、当前目标、revision 和有效期后，使用独立 mTLS 身份从 Provider 获取真正凭据并核对摘要。空集合仍为完整签名快照；304 不构成续期。配置由 `ServerService` 与 ProtocolRegistry 生成，用户授权由 `NativeProviderNodeAuthorization` 生成。

当前 native 计量会话来自 `native-provider-authorization/v2` 签名中的 `usage_v2`，不从 User.uuid、旧 traffic_period 或 handshake 孤立 capability 生成授权。

### 2.3 当前用量与遥测

native 模式下 `/txapi/node/v1/report` 继续接收 `protocol_version:1` 的在线、存活、负载和指标。它不接管签名会话的资金结算；包含非空旧 `traffic` 的请求返回 `NATIVE_TRAFFIC_SETTLEMENT_UNAVAILABLE`，不得将 HTTP 遥测 ACK 当作已扣量。

`native-provider-authorization/v2` 的签名包含完整计量 session，Node 使用对应 `POST /txapi/node/v2/usage/submit` 与 `/batch/get` 交付持久回执。超时或 ACK 不确定时，保留原 Session、batch ID、摘要及正文重试；收到 persisted 只证明接收，settled 才证明 SQL 结算。reset、撤销、到期、重启和隔离余额变化不能重写已经冻结的归属及原始批次。详见 [当前 Node Usage 契约](../v2/node-usage/README.md)。

```http
POST /txapi/node/v1/report HTTP/1.1
Authorization: Bearer <server-token>
X-TX-Node-ID: 42
Content-Type: application/json

{"protocol_version":1,"online":{"1001":1},"status":{"cpu":12}}
```

协议的历史 v1 traffic 接收实现与账本记录没有被转译成当前 native 授权来源，也不提供旧 UUID 用户下发入口。

### 2.4 原生 AccessAudit（可选、默认关闭）

同一 `TxNodeAuth` 认证边界；Machine 的每节点访问也必须在 Machine 身份头之外携带 `X-TX-Node-ID`。**不要**向 query string 或 JSON body 传入 Token，也不要添加额外的节点访问入口。只有 `audit.enabled: true` 的 sing-box 节点会发起这些请求。

```http
GET /txapi/node/v1/audit/rules
Authorization: Bearer <server-token>
X-TX-Node-ID: 42
```

`data.rules` 为启用的规则集合，字段 `id,name,match_type,match_value`；匹配类型为 `domain`、`domain_suffix`、`keyword`、`ip_cidr`。每个规则可用换行或逗号分隔多项。

```http
POST /txapi/node/v1/audit/report
Authorization: Bearer <server-token>
X-TX-Node-ID: 42
Content-Type: application/json

{"protocol_version":1,"events":[{"event_id":"0123456789abcdef0123456789abcdef","user_id":1001,"target":"example.net","target_ip":"203.0.113.1","matched":true}]}
```

`event_id` 是客户端为**每次观察**生成的 32 位小写十六进制随机 ID，失败重试必须保持原 ID。服务端按 `(server_id,event_id)` 唯一键去重；单批上限 200 条，总请求体 1 MiB。正常 200 返回 `data:{protocol_version:1,accepted:true,received:N,inserted:M}`，其中重复记录 `inserted=0`。不合法事件或用户应返回 422。审计数据与流量账本独立，不影响流量统计或充值；内存队列丢失不能当作持久流量回执。

后台访问审计的管理端点见 [AccessAudit v1](./access-audit-v1.md)。面板端须部署新审计表并运行定时清理任务；默认保留 30 天。

### 2.5 Machine 发现与状态

```bash
curl -fsS 'https://<panel-host>/txapi/node/v1/machine/nodes' \
  -H 'Authorization: Bearer <machine-token>' \
  -H 'X-TX-Machine-ID: 7'
```

```http
POST /txapi/node/v1/machine/status HTTP/1.1
Authorization: Bearer <machine-token>
X-TX-Machine-ID: 7
Content-Type: application/json

{
  "protocol_version": 1,
  "cpu": 25.5,
  "mem": {"total": 1073741824, "used": 536870912},
  "disk": {"total": 53687091200, "used": 10737418240},
  "net": {"in_speed": 8, "out_speed": 12},
  "runtime": {
    "version": "v1.0.0",
    "deployment": "docker",
    "updater_available": true
  }
}
```

`cpu` 范围 0–100；`mem.total/used` 为非负整数且 `used<=total`；`swap`、`disk`、`net`、`runtime.update` 可选。成功 `data:{protocol_version:1,accepted:true}`。运行时更新的字段另见 [Machine Runtime Update v1](./machine-runtime-update-v1.md)。

## 3. 原生 WebSocket：启用条件及帧协议

- 端点 `wss://<panel-host>/txapi/node/v1/ws`，由 **Workerman（默认内部 8076）** 处理 Upgrade，不能转发给 Octane。实例须设置 `TXBOARD_NATIVE_NODE_WS_ENABLED=true`，同时完成 TLS/代理/Worker 健康验证；默认 false。
- Upgrade 携带与 HTTP 相同的 `Authorization` 及 Node/Machine ID 请求头。**任何 query string 都会被拒绝**；浏览器原生 WebSocket API 不支持设置这些自定义 Header，不能使用 `?token=...` 绕过，须使用支持 Header 的 Node/WebSocket客户端。
- 每条 JSON frame 最大 1 MiB，固定结构：`{"protocol_version":1,"event":"...","data":{},"request_id":"client-unique-id"}`。客户端 `request_id` 格式为 `[A-Za-z0-9._:-]{1,80}`；`data` 是 JSON object，不能是数组。
- 连接成功先收到 `session.ready`（包含 `mode,node_id,machine_id,heartbeat_interval_seconds,capabilities`），随后可能收到 `sync.config`、`sync.users:{refresh_required:true}`、`sync.nodes` 或安装器拥有的有限机器升级指令；发来的数据应按 `event` 区分处理。

### 客户端可发送的事件

| event | data | 服务端响应 |
| --- | --- | --- |
| `heartbeat.pong`（或主动 `heartbeat.ping`） | `{}` | `heartbeat.ack` |
| `sync.request` | 单节点可 `{}`；Machine 必须 `{"node_id":42}` | 全量同步消息与 `sync.ack` |
| `traffic.report` | HTTP `report` 的相同校验字段；Machine 额外带 `node_id` | `traffic.ack`（遥测 ACK；native 计费使用 v2 回执） |

通用Agent/MCP和旧`ops.*`已退役；当前原生入站事件白名单见上表，旧`ops.result`按未知事件拒绝。安装器拥有的有限Machine升级操作由[独立契约](machine-runtime-update-v1.md)规定，不使用旧Agent动作结果接口。

```json
{
  "protocol_version": 1,
  "event": "traffic.report",
  "request_id": "node42-status-0001",
  "data": {"protocol_version":1,"status":{"cpu":12}}
}
```

上例为单节点 WS。Machine WS 必须在 `data` 内显式携带 `node_id`，节点须属于当前机器。收到服务器 `heartbeat.ping` 时回 `heartbeat.pong`，断线后重连、重新获取快照；未确定结算的批次保持原 ID 与数据重发。错误帧使用 `event:"error"` 与 `data:{code,message}`，不要把 WS 协议错误当 HTTP 状态码。

### OpenResty / Nginx 路由示例（部署者自行按拓扑调整）

```nginx
location = /txapi/node/v1/ws {
    proxy_pass http://127.0.0.1:8076;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header Authorization $http_authorization;
    proxy_set_header X-TX-Node-ID $http_x_tx_node_id;
    proxy_set_header X-TX-Machine-ID $http_x_tx_machine_id;
    proxy_read_timeout 120s;
}
# Normal /txapi/node/v1/* HTTP requests go to Laravel/Octane,
# NOT to the above Workerman listener.
```

容器化部署中 `127.0.0.1` 指向代理**自身**；若 Workerman 不在同一网络命名空间，须改成实际可解析的服务地址。不能在任意公网端口裸露 Workerman 作为 TLS 替代品。断开 WS 只需关闭开关并重启相关 Worker，不要自动删除或回滚账本。

## 4. 联调检查表

- Node/Machine Bearer 及 ID 隔离；禁用机器、错误节点、轮换 Token 后立即拒绝。
- 握手能力与 Provider 信任正确；仅 `/config` 支持 ETag/304，`/users` 每次必须重新完整签名并校验。
- 带 ID 的流量批次与完全相同重试、乱序重发、一致性校验；失败队列与 SQL 故障处置。
- 机器发现、状态、并行多个节点；WS 连接认证、HTTP 降级、心跳和重连；安装器有限升级操作按独立契约验证。
- 正常用户订阅、节点实际核心协议和真实 TX-Node 二进制互通；生产 DNS/TLS/反代、负载均衡。
- 使用实际 TXNode 客户端验证上述场景；服务端自动化测试不能代替跨组件联调。

相关源码：`api/app/Http/Middleware/TxNodeAuth.php`、`api/app/Http/Controllers/Txapi/NodeProtocolController.php`、`api/app/Domains/Network/NativeNodeReport.php`、`api/app/WebSocket/NativeNodeWebSocket.php`。补充：[Machine Runtime Update v1](./machine-runtime-update-v1.md)。

## 当前用量与变更同步

Core 的 `POST /txapi/node/v2/usage/submit` 与 `POST /txapi/node/v2/usage/batch/get` 复用当前 Node/Machine Bearer 和节点范围，仅接收当前 native 授权账本的会话。isolated-copy 配置、手动 source/tariff/grant 原型与 Node 孤立 Service 已退役。

当前 Node 资格、配额、凭据代次和到期来自 Subscription/AccessSubject/Provider 的原生权威投影；已用流量或 User.group_id 不直接形成代理凭据。订阅领域的原生 outbox 与周期性完整刷新提供变更通知。WS 不下发凭据、用户 delta 或旧设备用户列表；`sync.users` 只是完整 HTTP 刷新提示，服务端忽略排队载荷中的旧 users/UUID。有限签名到期、目标撤销与原始未确认用量的关闭和保留规则继续生效。

## Native Provider 消费补充（C2-C2）

`GET /txapi/node/v1/users` 在已配置的原生 Provider 策略下返回完整签名及非秘密
`sha256:` 凭据摘要，明文 UUID 由 Node 通过独立 mTLS 直接向 Provider 获取。
签名绑定当前权益、Node/Machine、接收证书指纹和有限期限；旧 UUID 不作为兜底。
握手能力 `node_provider_credential_delivery_v1` 仅表示该消费能力，不表示 native
安装已激活。WebSocket 只提示重新获取完整签名，旧流量计费写入在 native 下拒绝。
具体字段、版本和剩余门禁见[材料消费契约](../v2/credential-provider/native-node-delivery-v1.md)。
前述基于 User 旧字段的资格与流量 v1 计费描述适用于既有模式；本策略使用真实
Subscription/Terms 权益，不能调用旧计费路径，也不能由路由存在推断生产可就绪。
