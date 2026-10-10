# TXNode 流量批次与幂等

正式上报接口：`POST /txapi/node/v1/report`；WebSocket：`traffic.report`。两者使用相同的服务端校验与队列结算逻辑。

- 每个有实际流量的上报必须携带 `protocol_version:1`、`traffic_batch_id`、`traffic`。
- `traffic_batch_id` 符合 `[A-Za-z0-9:_-]{8,80}`，同节点的新样本必须唯一。
- `traffic` 为 `{"<user_id>":[upload_bytes,download_bytes]}`，非负整数，最多 10,000 个用户，每方向不超过 1 PiB，HTTP body 不超过 1 MiB。
- 网络超时、丢 ACK 或重连时，用**相同的 batch ID 和相同的流量样本**重试；不要给原样本创建新 ID。
- HTTP `202` 或 WebSocket `traffic.ack` 的 `settlement:"queued"` 仅表示入队，不是持久化完成。
- 服务端按 `(server_id,batch_id)` 做账本幂等，流量/统计变更在数据库事务中完成；客户端仍需有监控与对账路径。

[完整请求示例、鉴权、错误码与机器模式](txnode-integration-current.md)。
