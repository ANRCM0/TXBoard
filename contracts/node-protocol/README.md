# TX-Node Protocol Contract — CURRENT (TXAPI-only)

TXBoard 是控制面，独立的 [TX-Node](https://github.com/ANRCM0/TX-Node) 是节点 Agent / Data Plane。TX-Node 主动连接 TXBoard 的 HTTPS/WSS，不由 TXBoard 编译、打包或部署。

**当前接入必读**：[TXNode 原生 HTTP/WS 路由及代码示例](txnode-integration-current.md)（2026-10-10，基于 TXBoard main）。完整服务端协议见 [Native Node v1](node-native-v1.md)、[WS v1](../../docs/architecture/native-node-websocket.md)。

## 当前核心入口

| Method | Path | 场景 |
| --- | --- | --- |
| POST | `/txapi/node/v1/handshake` | 握手、能力协商 |
| GET | `/txapi/node/v1/config` | ETag 配置快照 |
| GET | `/txapi/node/v1/users` | ETag 用户快照 |
| POST | `/txapi/node/v1/report` | 带批次 ID 的流量与状态 |
| GET | `/txapi/node/v1/machine/nodes` | Machine 节点发现 |
| POST | `/txapi/node/v1/machine/status` | Machine 资源与运行时上报 |
| WSS | `/txapi/node/v1/ws` | Feature-gated Workerman 升级路径 |

**HTTP 是基础协议。** WebSocket 默认关闭，启用前须验证反代与 TLS；节点/机器均需带 Bearer + scoped Header。202 queued 不代表账本已经落库。真正跨仓 TXNode 客户端兼容性必须单独验收。

## 已移除的旧协议（仅供历史参考，不可对接当前 TXBoard）

- `/api/v2/server/handshake`、`/api/v2/server/report`、`/api/v2/server/config`、`/api/v2/server/user`、`/api/v2/server/machine/*`
- `/api/v1/server/UniProxy/*`、旧 `/ws`
- 旧 `/api/v1/plugin/access-audit/*` 不能当作核心 Node API；扩展路由以实际已安装插件自身路由为准

[Machine Runtime Update v1](machine-runtime-update-v1.md)、[Agent Ops v1](agent-ops-v1.md)、[Traffic Batch v1](traffic-batch-v1.md) 是协议补充，若仍出现上述旧路径或兼容叙述，以本 CURRENT 路由指南及 `api/routes/txapi.php` 为准。

## 变更约定

TXBoard 更新 Node path、method、身份域、payload、事件名或重试语义时，必须同时更新对外指南、HTTP/WS 契约测试，并在独立 TX-Node 仓库验证真实客户端；不要以单仓模拟测试代替跨仓联调。
