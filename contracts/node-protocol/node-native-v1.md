# TXBoard Native Node Protocol v1

**CURRENT TXBoard server-side contract.** The independently developed TX-Node client and production deployment must still be verified before a real cutover. Start with the [TXNode Integration Guide](txnode-integration-current.md), which supersedes historical compatibility wording below.

Base: HTTPS `/txapi/node/v1`. HTTP polling and native WebSocket are implemented on TXBoard; WebSocket opt-in is disabled until the public TLS/Upgrade route is verified.

## Authentication and tenancy

- Send `Authorization: Bearer <credential>` on every request. For individual nodes use existing TXBoard server token; for a machine use that machine's current token. Never pass token in query/body.
- Send `X-TX-Node-ID` with the TXBoard Server model's database primary key `id` (not any legacy `code` alias; the internal `v2_*`/`tx_*` physical table prefix does not change the ID). For machine credentials also send `X-TX-Machine-ID`; selected node must be enabled and assigned to that enabled machine. Machine-only endpoints need just machine ID. `POST /handshake` also accepts a machine identity without Node ID for machine-mode bootstrap; its response carries `mode:"machine"` and `node_id:null`.
- Wrong/disabled credentials return HTTP 401, unknown/foreign node returns 404. No token is echoed or serialized.

## Endpoints

| Method | URL relative to base | Description |
|---|---|---|
| POST | `/handshake` | protocol version, negotiated HTTP capabilities and intervals |
| GET | `/config` | ProtocolRegistry node configuration; `ETag` and conditional 304 |
| GET | `/users` | available user snapshot; `ETag` and conditional 304 |
| POST | `/report` | validated usage batch, online/status and optional metrics; 202 accepted/queued |
| GET | `/machine/nodes` | nodes of authenticated machine only |
| POST | `/machine/status` | authenticated machine cpu/memory/disk snapshot |

Handshake `data` includes `protocol_version:1`, `mode:"node"|"machine"`, `node_id`, `capabilities`, `websocket:{enabled:boolean,path:"/txapi/node/v1/ws"}`, `settings:{push_interval,pull_interval}`. Normal successful responses use `{data,request_id}` and header `X-Request-Id`; errors use `{error:{code,message},request_id}`. ETags are scoped to the node and its selected configuration or user snapshot; never reuse cached snapshots across node identity.

## Example batch and semantics

```json
{"protocol_version":1,"traffic_batch_id":"node42-batch-0000001","traffic":{"1001":[1024,2048]},"online":{"1001":1},"status":{"cpu":12}}
```

- Per-user traffic is `[upload_bytes,download_bytes]` as unsigned whole integers; per-direction bound 1 PiB. The server applies the configured rate once, not the agent. Request body limit 1 MiB, max 10,000 users.
- Nonempty `traffic` **requires** an immutable `traffic_batch_id` with 8–80 ASCII `[A-Za-z0-9:_-]` characters. Retries of an uncertain outcome must reuse the original ID and exact payload. New increments must use a new ID per node.
- HTTP 202 returns `data:{protocol_version:1,accepted:true,traffic_batch_id,settlement:"queued"}`. This **does not confirm SQL settlement**. Settlement happens asynchronously in `TrafficBatchJob`, with a unique `(server_id,batch_id)` ledger row and user/server/stat changes in one SQL transaction. Same-ID different-payload retries must never create additional charges.
- On network failure or timeout retry the exact batch. The current protocol does not provide a durable server receipt for post-enqueue queue loss; operators must monitor failed queue jobs and verify ledger settlement. This limitation must be addressed before irreversible production cutover.
- Status-only reports may omit traffic/batch and return `settlement:"none"`. The optional `alive`, `online`, `status` and `metrics` use existing ServerService semantics.

## Machine status

`POST /machine/status` requires a valid machine token and `X-TX-Machine-ID`; body includes `protocol_version:1`, `cpu` (0–100), `mem:{total,used}` unsigned bytes and optional `swap` and `disk`. Also accepts `net:{in_speed,out_speed}` and bounded `runtime`/`runtime.update` metadata (secret-like messages redacted). Native and legacy endpoints now use the same SQL machine status/history writer. Does not allow a global node token to write machine status.

## Native WebSocket (P4-D TXBoard server)

The new WebSocket endpoint is `wss://<panel-host>/txapi/node/v1/ws` on the existing Workerman listener (port 8076). Single-container and split Caddy ingress route the Upgrade to Workerman, not to Laravel Octane. If an external Nginx/OpenResty proxy is used, set HTTP/1.1 Upgrade + Connection headers and forward Authorization and X-TX-Node-ID / X-TX-Machine-ID headers.

**Feature gate:** `TXBOARD_NATIVE_NODE_WS_ENABLED=false` by default. Enable only after WS service, HTTPS proxy, health check, and client handshake are confirmed. Native HTTP handshake exposes `websocket:{enabled:boolean,path:"/txapi/node/v1/ws",heartbeat_interval_seconds:55}`. HTTP polling remains available. Old `/ws` has been removed from current TXBoard routing; there is no legacy fallback.

**Security:** WebSocket Upgrade requires Bearer credentials in Authorization and scoped node/machine identity headers, checked by the same TxNodeAuth middleware as HTTP. Query credentials are rejected on the native path. A machine session multiplexes only the nodes assigned to that machine. Inbound operations recheck the DB ownership and token, so revoked machine credentials, disabled nodes and foreign-node traffic are rejected.

**Envelope:** Native WS messages are `{protocol_version:1,event:"...",data:{...},request_id:"client-id"}` with a 1 MiB max frame. The server replies with `session.ready`, real-time `sync.config`, `sync.users`, `sync.user.delta`, `sync.nodes`, `heartbeat.ping`, `heartbeat.ack`, `sync.ack`, `traffic.ack`, or `error`. Redis node:push uses native framing for native sockets, without guaranteeing any old WebSocket route.

**Heartbeat:** respond to server heartbeat.ping with heartbeat.pong; the server replies heartbeat.ack, and closes stale connections. Reconnect with headers, reload snapshots, and retry pending traffic with identical batch identifiers.

**Usage:** example outgoing event: `{"protocol_version":1,"event":"traffic.report","request_id":"req-1","data":{"protocol_version":1,"node_id":42,"traffic_batch_id":"batch-000042","traffic":{"1001":[1024,2048]}}}`. Machine sessions require node_id; single-node sessions infer their bound node_id and reject an override. `traffic.ack` includes accepted=true, settlement=queued, node_id, traffic_batch_id, and echoed request_id. It does NOT confirm SQL settlement. HTTP and WS use the same NativeNodeReport -> TrafficBatchJob validation and ledger; retries must keep the immutable batch ID and payload.

**Errors:** `event:error` with `{code,message}`, error reason restricted to safe protocol codes; malformed frames never dispatch traffic. Never put credentials in logs, query strings or reply data. To disable native WS without affecting HTTP/old WS, set the feature gate false and restart web/WS workers.

**Rollout:** TX-Node source and real dual-system integration are outside this P4-D server-only change. Validate production DNS, TLS, WS Upgrade, Redis, queue recovery and actual agent before enabling in service.
## Migration and compatibility

Old `/api/v1/server/UniProxy/*`, `/api/v2/server/*`, and legacy `/ws` are **not** registered in current TXBoard. The independent TX-Node client and actual cross-repository integration are **not** established by this server-side document. Before any agent redirect, separately validate Go adapter, TLS, ETag handling, batched durable replay, queue outage behavior, machine polling, old route rollback and production canary. AccessAudit remains an optional extension outside this core protocol.
