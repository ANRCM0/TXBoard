# TXBoard 开发者文档

## 接口对接

- [外部主题：TXAPI HTTP 对接](../../contracts/http/theme-integration-current.md)
- [TXNode：HTTP / WebSocket 对接](../../contracts/node-protocol/txnode-integration-current.md)
- [对外路由总览与身份边界](../../contracts/http/external-adapter-current.md)
- [HTTP 路由清单与导出](http-route-inventory.md)

## 扩展与部署

- [Theme Package v1](../../contracts/theme-package/README.md)、[插件开发指南](../../api/docs/en/development/plugin-development-guide.md)
- [Module Platform 开发指南](module-platform-development-guide.md)、[Agent Ops 开发指南](agent-ops-development-guide.md)
- [部署验收](../operations/release-staging-acceptance.md)、[数据库切换操作](../operations/native-mysql-table-cutover.md)、[安全规范](../security/README.md)
- [项目开发规则](../../AGENTS.md)

TXBoard 为控制面，TXNode 为独立 Agent/Data Plane。浏览器主题仅使用面向用户的 TXAPI，不得携带 Node、Machine、Admin 或 Agent 私密凭据。
