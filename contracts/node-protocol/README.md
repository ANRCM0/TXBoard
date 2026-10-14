# TXNode 接口

TXNode 通过 HTTPS/WSS 主动连接 TXBoard，使用独立 Node/Machine 凭据；HTTP 提供基础通信，WebSocket 为可选实时通道。

**[TXNode 当前 HTTP / WebSocket 完整对接手册](txnode-integration-current.md)**

- HTTP：`/txapi/node/v1/handshake`、`config`、`users`、`report`、`machine/nodes`、`machine/status`。
- WebSocket：`/txapi/node/v1/ws`，须配置 Workerman、TLS Upgrade 与功能开关。
- 请求头：`Authorization: Bearer <token>`；单节点使用 `X-TX-Node-ID`，机器使用 `X-TX-Machine-ID`。
- 机器运行时升级补充：[Machine Runtime Update v1](machine-runtime-update-v1.md)。
- 通用Agent/MCP与旧`ops.*`已退役；安装器拥有的有限机器升级操作仍按其独立契约执行。

修改核心协议时同时更新 TXBoard 校验、测试和 TXNode 适配器，实际联调需验证认证、ETag、账本幂等、故障重试和 WSS 降级。

- Native authority denial and terminal tail boundary: [contract](native-authority-denial-v1.md) and [verification](../../node/docs/native-authority-denial-verification.json).

- Native full-snapshot and confirmed-application retry: [contract](native-snapshot-application-v1.md), [implementation](../../node/docs/native-snapshot-application.md) and [verification](../../node/docs/native-snapshot-application-verification.json).

- Kernel configuration and strong revocation: [contract](kernel-application-boundary-v1.md), [implementation](../../node/docs/kernel-application.md), [verification](../../node/docs/kernel-application-verification.json).

- Native shared Machine control: [contract](native-machine-control-v1.md), [implementation](../../node/docs/native-machine-control.md), [verification](../../node/docs/native-machine-control-verification.json).

- Native durable traffic, quota and observed reset periods: [traffic contract](native-traffic-quota-v1.md), [period contract](native-traffic-period-v1.md), [implementation](../../node/docs/native-traffic-quota.md), [verification](../../node/docs/native-traffic-quota-verification.json).
