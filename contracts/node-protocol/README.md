# Node 控制与用量协议

Core HTTP 路由、Workerman WSS 与 Node/Machine 身份彼此明确；HTTP 注册表不证明 WSS 在线。完整授权由当前 Core/Provider 签发，WebSocket 只触发刷新，不提供 unsigned 用户权威。

## 当前接口

- [TXNode HTTP/WSS 对接](txnode-integration-current.md)：认证、配置、用户授权、ETag 与上报边界。
- [有限签名授权](native-authorization-lease-v1.md)：当前信任、高水位、有限截止及过期关闭。
- [权限拒绝](native-authority-denial-v1.md)、[Machine 控制](native-machine-control-v1.md)：限定终止拒绝与子节点收敛。
- [完整快照应用](native-snapshot-application-v1.md)、[内核应用](kernel-application-boundary-v1.md)：期望/已应用分离、重试与强撤销。
- [流量与配额](native-traffic-quota-v1.md)、[期间归属](native-traffic-period-v1.md)：原批次/Session/Period 持久重报，不重贴周期。
- [原生运行](../v2/native-c2-runtime-v1.md)、[用量结算](../v2/node-usage/README.md)：接收、隔离、预留和结算不是同一状态。
- [机器运行时更新](machine-runtime-update-v1.md)：安装器拥有的有限更新操作，与退役 Agent/MCP 无关。

## 运行说明

[ControlPlane 与共享安全边界](../../node/docs/controlplane.md)、[Provider Node 运行](../../node/docs/native-provider-node.md)、[订阅用量](../../node/docs/native-subscription-usage.md)说明当前消费者。Node 不直接访问 SQL；Xboard 是独立适配器，不改变 Core native 的签名、归属或结算要求。

当前 native 实流支持限于 Linux sing-box VLESS/VMess direct TCP，不从已有协议列表推断全部受验收。协议变更同时更新 Core、真实 Node 消费者和直接相关负向检查。
