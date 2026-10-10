# TXNode HTTP / WebSocket 对接手册

> 接口以 `api/routes/txapi.php`、`TxNodeAuth`、`NodeProtocolController` 和 `NativeNodeWebSocket` 为准；两端实际环境联调需另行验证。

## 1. 身份与连接模式

基础 HTTPS URL：`https://<panel-host>/txapi/node/v1`。TX-Node 作为客户端主动访问 TXBoard；公网必须使用受信任 TLS、正确反代，并允许 `Authorization` 与身份头透传。

| 场景 | HTTP / WSS 请求头 | 约束 |
| --- | --- | --- |
| 单节点 Agent | `Authorization: Bearer <server-token>` + `X-TX-Node-ID: <node-id>` | `node-id` 是 TXBoard 节点数据的 **主键 ID**，不是配置中的 code |
| Machine 管理与初始握手 | `Authorization: Bearer <machine-token>` + `X-TX-Machine-ID: <machine-id>` | 机器须启用；仅用于 `handshake` 的 machine 模式、`machine/nodes`、`machine/status` |
| Machine 下某个节点的 `config/users/report` | 上述 Machine 两个头 + `X-TX-Node-ID: <assigned-node-id>` | 节点必须归属该启用机器，且仍启用 |

不要将 Bearer 放入 query string、JSON body、日志或 URL；Node / Machine 身份不得替换普通用户 Sanctum 或管理员身份。当前单节点模式使用全局配置的 `server_token`；机器模式使用独立且可轮换的 Machine Token。

`X-TX-Node-ID`、`X-TX-Machine-ID` 是协议对象的 ID，不依赖内部数据库表名。HTTP 身份校验可返回 `401 NODE_UNAUTHORIZED`、`422 NODE_ID_REQUIRED` 或 `404 NODE_NOT_FOUND`。客户端不要靠自动猜测节点 ID 重试。

## 2. HTTP 路由

| 方法 | 完整路径 | 身份 | 关键行为 |
| --- | --- | --- | --- |
| POST | `/txapi/node/v1/handshake` | Node 或 Machine | 协商 `protocol_version:1`、`mode`、intervals、WS 能力；无需额外请求字段 |
| GET | `/txapi/node/v1/config` | 指定 Node | 节点 `config`、`base_config`；支持 `ETag`、`If-None-Match` / 304 |
| GET | `/txapi/node/v1/users` | 指定 Node | `users` 可用用户快照；支持 `ETag` / 304 |
| POST | `/txapi/node/v1/report` | 指定 Node | 统计流量/在线/状态/指标；HTTP 202 **只代表入队** |
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
    "capabilities": ["http_poll", "etag", "traffic_batch_v1", "machine_discovery", "access_audit_v1"],
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

机器仅带 Machine ID 时，`data.mode="machine"` 且 `data.node_id=null`。客户端必须依据 `data.websocket.enabled` 决定是否升级 WSS；即使为 false，HTTP 轮询仍可运行。

### 2.2 配置与用户快照

```http
GET /txapi/node/v1/config HTTP/1.1
Authorization: Bearer <server-token>
X-TX-Node-ID: 42
If-None-Match: "<previous-etag>"
```

`/config` 的 200 返回 `data.protocol_version`、`data.node_id`、`data.config`；`/users` 返回 `data.protocol_version`、`data.node_id`、`data.users`。响应头 `ETag` 是服务端计算的**不透明**字符串，包含引号，复用时原样放入 `If-None-Match`。304 没有需要解析的 JSON `data`，继续使用**同一节点+同一认证身份**的本地快照。首次拉取、凭据/节点变化、断线后不确定状态时要重新获取。

用户集合、节点配置的具体字段由当前 `ServerService` 和 ProtocolRegistry 返回，不能把另一种协议的配置 DTO 套到当前节点类型上；以服务端样本与真实节点类型的联调结果实现解析器。

### 2.3 流量结算与幂等重试

```http
POST /txapi/node/v1/report HTTP/1.1
Authorization: Bearer <server-token>
X-TX-Node-ID: 42
Content-Type: application/json

{
  "protocol_version": 1,
  "traffic_batch_id": "node42-20261010-0001",
  "traffic": {"1001": [1024, 2048]},
  "online": {"1001": 1}
}
```

`traffic` 的 key 是用户 ID，value 为 **[上传字节, 下载字节]**，两者是非负整数。每方向上限 1 PiB；最多 10,000 个用户，整个 HTTP 请求体上限 1 MiB。**有非空 `traffic` 就必须提供** `traffic_batch_id`（ASCII `[A-Za-z0-9:_-]{8,80}`），并保证每个新样本使用全新 ID。遇到超时或不确定 ACK 时重发**同一个 ID + 完全相同的数据**；不要给旧流量重新分配 ID，否则可能双计费。节点倍率由 TXBoard 服务端处理，Agent 不要乘第二次。

正常 202：

```json
{
  "data": {
    "protocol_version": 1,
    "accepted": true,
    "traffic_batch_id": "node42-20261010-0001",
    "settlement": "queued"
  },
  "request_id": "<server-request-id>"
}
```

**`queued` 不是 SQL 已结算回执。** 幂等键为 `(server_id,batch_id)`，流量、用户统计、节点统计和账本写入在事务中完成。服务端有异步队列失败/重试窗口；当前接口未提供可查询的永久结算回执；客户端应有持久化发送队列、相同批次重试和对账监测。

仅 `status` / `alive` / `online` / `metrics` 的报告可不包含非空流量；正常返回 `settlement:"none"`。参数不合法时可能收到 `422 INVALID_REPORT`、`422 INVALID_TRAFFIC`、`422 BATCH_ID_REQUIRED`；超大 HTTP 报文为 413。

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
- Upgrade 携带与 HTTP 相同的 `Authorization` 及 Node/Machine ID 请求头。**任何 query string 都会被拒绝**；浏览器原生 WebSocket API 不支持设置这些自定义 Header，不能使用 `?token=...` 绕过，须使用支持 Header 的 Agent/WebSocket 客户端。
- 每条 JSON frame 最大 1 MiB，固定结构：`{"protocol_version":1,"event":"...","data":{},"request_id":"client-unique-id"}`。客户端 `request_id` 格式为 `[A-Za-z0-9._:-]{1,80}`；`data` 是 JSON object，不能是数组。
- 连接成功先收到 `session.ready`（包含 `mode,node_id,machine_id,heartbeat_interval_seconds,capabilities`），随后可能收到 `sync.config`、`sync.users`、`sync.user.delta`、`sync.nodes`、`sync.devices` 或 `ops.*` 指令；发来的数据应按 `event` 区分处理。

### 客户端可发送的事件

| event | data | 服务端响应 |
| --- | --- | --- |
| `heartbeat.pong`（或主动 `heartbeat.ping`） | `{}` | `heartbeat.ack` |
| `sync.request` | 单节点可 `{}`；Machine 必须 `{"node_id":42}` | 全量同步消息与 `sync.ack` |
| `traffic.report` | HTTP `report` 的相同校验字段；Machine 额外带 `node_id` | `traffic.ack`（仅 queued） |
| `ops.result` | `{request_id:"ops_...",ok:true/false,result?:{},message?:string,error_code?:string,node_id?:number}` | `ops.ack`，以 `data.action_request_id` 回指动作 |

**注意两层 request_id：** WS 外层 `request_id` 是消息关联 ID；`ops.result.data.request_id` 是之前批准的 Agent 操作 ID。一个已完成操作的重试不能覆盖原结果。

```json
{
  "protocol_version": 1,
  "event": "traffic.report",
  "request_id": "node42-req-0001",
  "data": {
    "protocol_version": 1,
    "traffic_batch_id": "node42-20261010-0001",
    "traffic": {"1001": [1024, 2048]}
  }
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
- 握手能力协商正确；`/config`、`/users` 的 200/304 与 ETag 按节点隔离。
- 带 ID 的流量批次与完全相同重试、乱序重发、一致性校验；失败队列与 SQL 故障处置。
- 机器发现、状态、并行多个节点；WS 连接认证、HTTP 降级、心跳和重连；Agent Ops 收到/回执。
- 正常用户订阅、节点实际核心协议和真实 TX-Node 二进制互通；生产 DNS/TLS/反代、负载均衡。
- 使用实际 TXNode 客户端验证上述场景；服务端自动化测试不能代替跨组件联调。

相关源码：`api/app/Http/Middleware/TxNodeAuth.php`、`api/app/Http/Controllers/Txapi/NodeProtocolController.php`、`api/app/Domains/Network/NativeNodeReport.php`、`api/app/WebSocket/NativeNodeWebSocket.php`。补充：[Machine Runtime Update v1](./machine-runtime-update-v1.md)。
