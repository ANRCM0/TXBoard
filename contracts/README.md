# TXBoard Contracts — CURRENT 与 TARGET

`contracts/` 记录 TXBoard 与前端、TX-Node、插件、主题、模块、Agent/MCP 的跨仓库边界。

## CURRENT（已实现/生效）

- **[对外 HTTP / WS 接入手册（CURRENT）](http/external-adapter-current.md)**：按实际注册表区分已实现路由、独立运行时与跨仓目标，覆盖 Node/Agent/Gateway/支付/订阅；已移除 V1/V2 HTTP 入口。
- **[外部主题对接路由与示例](http/theme-integration-current.md)**：公开配置、登录、全部用户 HTTP 路由、充值幂等、订单与订阅。
- **[TXNode HTTP/WSS 原生对接指南](node-protocol/txnode-integration-current.md)**：身份头、握手、ETag、流量批次、机器/WS、反代与联调。
- [HTTP 路由全量自动快照](../docs/architecture/http-route-inventory.md)：CI 从 Laravel `route:list --json` 输出 Artifact。

- [Module Registry v1](http/module-registry-v1.md)、[Module Management v1](http/module-management-v1.md)
- [TX-Node Protocol](node-protocol/README.md)、[Agent Ops v1](http/agent-ops-v1.md)、[Agent Support](http/agent-support-v1.md)
- [Plugin Package v1](plugin-package/README.md)、[Theme Package v1](theme-package/README.md)
- [Module Package v1](module-package/README.md)、[Module Lifecycle v1](module-lifecycle/README.md)
- [Admin Navigation](admin-navigation/README.md)、[Admin Bridge](admin-bridge/README.md)
- [Agent Ops Module](agent-ops-module/README.md)、[Agent Self-Connect](agent-self-connect/README.md)

## TARGET（计划，尚未实现）

- [TXAPI BFF Target v1](http/txapi-bff-target-v1.md)：独立 Hono Gateway 目标 /txapi/bff/v1/*，非当前 Laravel 已注册路径；网关运行时是否有 /gateway/v1/* 须以独立部署核验。
- [Gateway 双仓 ADR](../docs/architecture/gateway-integration.md)：职责/分流/G0–G5。
- [TXAPI v1 Target](http/txapi-target-v1.md)：历史方案与当前已交付功能混合存档，不能作为 CURRENT 路由证明；当前仅 `/txapi/*`，没有旧版 `/api/v1`、`/api/v2`。
- [Native 详细开发方案](../docs/architecture/txboard-native-development-plan.md)、[遗留依赖矩阵](../docs/architecture/legacy-inventory-and-work-packages.md)。

## 变更规定

当前事实以 Laravel route:list、运行时代码和 CURRENT contracts 为准。修改跨仓库契约必须同步 schema/DTO、实现、适配器、消费者、测试和升级窗口。Module/Plugin/Theme 的兼容边界不得凭“目标文档”暗中改变。
