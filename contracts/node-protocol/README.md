# TXNode 接口

TXNode 通过 HTTPS/WSS 主动连接 TXBoard，使用独立 Node/Machine 凭据；HTTP 提供基础通信，WebSocket 为可选实时通道。

**[TXNode 当前 HTTP / WebSocket 完整对接手册](txnode-integration-current.md)**

- HTTP：`/txapi/node/v1/handshake`、`config`、`users`、`report`、`machine/nodes`、`machine/status`。
- WebSocket：`/txapi/node/v1/ws`，须配置 Workerman、TLS Upgrade 与功能开关。
- 请求头：`Authorization: Bearer <token>`；单节点使用 `X-TX-Node-ID`，机器使用 `X-TX-Machine-ID`。
- 机器运行时升级补充：[Machine Runtime Update v1](machine-runtime-update-v1.md)。
- Agent 动作结果通过 WebSocket `ops.result` / `ops.ack` 回传。

修改核心协议时同时更新 TXBoard 校验、测试和 TXNode 适配器，实际联调需验证认证、ETag、账本幂等、故障重试和 WSS 降级。
