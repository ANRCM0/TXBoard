# TXBoard Native Node Protocol v1

**Scope: TXBoard server only.** TX-Node repository remains unchanged. These endpoints are for future adapter integration; do not redirect production nodes yet.

Base: HTTPS `/txapi/node/v1`. Native polling is authoritative; native WebSocket is **not yet implemented** and handshake deliberately declares `websocket.enabled=false`.

## Authentication and tenancy

- Send `Authorization: Bearer <credential>` on every request. For individual nodes use existing TXBoard server token; for a machine use that machine's current token. Never pass token in query/body.
- Send `X-TX-Node-ID` with the database primary key `v2_server.id` (not the legacy `code` alias). For machine credentials also send `X-TX-Machine-ID`; selected node must be enabled and assigned to that enabled machine. Machine-only endpoints need just machine ID. `POST /handshake` also accepts a machine identity without Node ID for machine-mode bootstrap; its response carries `mode:"machine"` and `node_id:null`.
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

Handshake `data` includes `protocol_version:1`, `mode:"node"|"machine"`, `node_id`, `capabilities`, `websocket:{enabled:false}`, `settings:{push_interval,pull_interval}`. Normal successful responses use `{data,request_id}` and header `X-Request-Id`; errors use `{error:{code,message},request_id}`. ETags are scoped to the node and its selected configuration or user snapshot; never reuse cached snapshots across node identity.

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

## Migration and compatibility

Old `/api/v1/server/UniProxy/*`, `/api/v2/server/*`, and legacy WS remain unchanged. **TX-Node is not modified in this stage.** Before any agent redirect, separately validate Go adapter, TLS, ETag handling, batched durable replay, queue outage behavior, machine polling, old route rollback and production canary. AccessAudit remains an optional extension outside this core protocol.
