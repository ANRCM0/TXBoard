# TXBoard 文档中心

这里仅维护正在使用的对接协议、架构规范和运维操作手册。HTTP、WebSocket 的具体路由以代码和对应契约为准。

## 外部开发者

- [第三方主题 TXAPI 接口、字段与示例](../contracts/http/theme-integration-current.md)
- [TXNode HTTP / WebSocket、流量批次与机器状态](../contracts/node-protocol/txnode-integration-current.md)
- [对外接口鉴权边界](../contracts/http/external-adapter-current.md)
- [插件和主题 Package / Module 契约](../contracts/README.md)

## 架构与开发

- [架构文档入口](architecture/README.md)
- [Laravel HTTP 路由清单与导出](architecture/http-route-inventory.md)
- [Module Platform](architecture/module-platform-v1.md) · [Module 开发指南](architecture/module-platform-development-guide.md)
- [Agent Ops / MCP 架构](architecture/agent-ops.md) · [Agent 开发指南](architecture/agent-ops-development-guide.md)
- [扩展运行时安全规范](architecture/extension-runtime-policy.md)

## 运维与安全

- [运维总览](operations/README.md)
- [镜像发布通道](operations/image-release-channels.md)
- [数据库切换操作手册](operations/native-mysql-table-cutover.md)
- [预发、备份恢复与真实环境验收](operations/release-staging-acceptance.md)
- [安全基线](security/README.md)

涉及真实支付、数据库修改、节点密钥的操作必须遵循维护窗口、备份恢复验证与独立部署验收；CI 通过不等于生产环境已执行。
