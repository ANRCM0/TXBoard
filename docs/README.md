# TXBoard 文档中心

本目录仅保存当前架构、安全和运维文档。接口字段、鉴权、错误语义以 [对外协议总览](../contracts/README.md) 和实际注册路由为准。

## 外部集成

- [第三方主题 TXAPI HTTP 对接](../contracts/http/theme-integration-current.md)
- [TXNode HTTP / WebSocket 对接](../contracts/node-protocol/txnode-integration-current.md)
- [接口与身份边界](../contracts/http/external-adapter-current.md)
- [主题、插件与 Module 包契约](../contracts/README.md)

## 架构

- [Module Platform：Registry、Lifecycle 和扩展开发规则](architecture/module-platform-v1.md)
- [Agent Ops / MCP：权限、审批、Node 操作及开发约束](architecture/agent-ops.md)

## 运维与安全

- [镜像发布通道](operations/image-release-channels.md)
- [MySQL 原生数据库结构](operations/native-schema.md)
- [发布前验收、备份恢复和运行监控](operations/release-staging-acceptance.md)
- [安全基线与插件/主题运行时边界](security/README.md)

开发者应遵循 [AGENTS.md](../AGENTS.md)；其中包含 Laravel 路由导出命令和新增 Module / Agent 能力的实现检查要求。

原生数据库部署、支付商、第三方 TXNode、插件等真实环境行为须单独验收；CI 通过不代表生产部署或跨仓联调已通过。
