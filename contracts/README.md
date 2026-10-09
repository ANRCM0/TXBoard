# TXBoard Contracts — CURRENT 与 TARGET

§contracts/§ 记录 TXBoard 与前端、TX-Node、插件、主题、模块、Agent/MCP 的跨仓库边界。

## CURRENT（已实现/生效）

- [Module Registry v1](http/module-registry-v1.md)、[Module Management v1](http/module-management-v1.md)
- [TX-Node Protocol](node-protocol/README.md)、[Agent Ops v1](http/agent-ops-v1.md)、[Agent Support](http/agent-support-v1.md)
- [Plugin Package v1](plugin-package/README.md)、[Theme Package v1](theme-package/README.md)
- [Module Package v1](module-package/README.md)、[Module Lifecycle v1](module-lifecycle/README.md)
- [Admin Navigation](admin-navigation/README.md)、[Admin Bridge](admin-bridge/README.md)
- [Agent Ops Module](agent-ops-module/README.md)、[Agent Self-Connect](agent-self-connect/README.md)

## TARGET（计划，尚未实现）

- [TXAPI v1 Target](http/txapi-target-v1.md)：最终官方 API 以 §/txapi/*§ 为统一入口；现在仍有生效的 /api/v1、/api/v2 协议。
- [Native 详细开发方案](../docs/architecture/txboard-native-development-plan.md)、[遗留依赖矩阵](../docs/architecture/legacy-inventory-and-work-packages.md)。

## 变更规定

当前事实以 Laravel route:list、运行时代码和 CURRENT contracts 为准。修改跨仓库契约必须同步 schema/DTO、实现、适配器、消费者、测试和升级窗口。Module/Plugin/Theme 的兼容边界不得凭“目标文档”暗中改变。
