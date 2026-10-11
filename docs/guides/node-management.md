# 节点与机器管理

TXBoard 是 Control Plane；[TX-Node](https://github.com/ANRCM0/TX-Node) 是独立的 Go Agent / Data Plane。部署节点请使用 [TX-Node-Installer](https://github.com/ANRCM0/TX-Node-Installer) 的当前说明，不要把节点部署职责放到 TXBoard 应用容器。

## 节点接入检查

1. 在管理端创建并核实节点/机器对象，确认对象启用状态与归属关系。
2. 根据选择的 Provider 和模式配置 TX-Node：兼容 `xboard` 协议或 TXBoard 原生 `txboard` 协议；两者凭据和行为不要混用。
3. 原生单节点模式使用服务端 Node 凭据与节点 **数据库主键 ID**，不要把配置 `code` 错当成 Node ID。
4. Machine 模式使用独立 Machine Token 与 Machine ID；对子节点的配置、用户、流量请求还需校验实际归属。
5. 在受信任 HTTPS/反代环境完成握手、配置与用户快照、流量上报及失败重试验证。

原生接口的基本路径为 `/txapi/node/v1`，关键请求包括握手、配置、用户及流量报告。**流量报告 HTTP 202 只表示已入队**，不能当作最终结算成功。WebSocket 升级路径由单独的 Workerman 运行时管理，默认能力开关和反向代理需要额外验证。

## 上线验收

- [ ] Node / Machine Token 未进入 URL、日志或浏览器主题包。
- [ ] 不同节点、机器间资源隔离正确，禁用对象不能继续上报。
- [ ] 流量批次重试和服务端幂等去重在真实环境通过。
- [ ] 已验证 HTTP 轮询与可选 WSS 路径的功能开关、反代和异常恢复。
- [ ] AccessAudit 若启用，已评估日志隐私与存储成本，不把审计记录用于流量计费真相。

**权威协议**：[TX-Node 原生 HTTP / WebSocket 对接](../../contracts/node-protocol/txnode-integration-current.md) · [TX-Node Runtime](https://github.com/ANRCM0/TX-Node) · [发布验收](../operations/release-staging-acceptance.md)。