# TX-Node Protocol Contract

TXBoard 是控制面，独立的 [TX-Node](https://github.com/PaiMonCai/TX-Node) 是节点 Agent / Data Plane。

TX-Node 主动向 TXBoard 发起 HTTPS / WSS 连接；TXBoard 不编译、不 vendor、也不部署 TX-Node。

## V2 core protocol

```http
POST /api/v2/server/handshake
POST /api/v2/server/report
GET  /api/v2/server/config
GET  /api/v2/server/user
POST /api/v2/server/machine/nodes
POST /api/v2/server/machine/status
```

V2 用于 Agent 握手、配置同步、用户同步、Machine 节点清单与状态上报。

## Machine runtime update

Machine-level TX-Node runtime upgrades are defined separately from per-node Agent Ops:

- [Machine Runtime Update Protocol v1](./machine-runtime-update-v1.md)

The update path delegates deployment mechanics to TX-Node Installer. It does not add SSH, generic shell, Docker socket access from TXBoard, or arbitrary image selection.

## UniProxy V1 compatibility

```http
GET  /api/v1/server/UniProxy/config
GET  /api/v1/server/UniProxy/user
POST /api/v1/server/UniProxy/push
POST /api/v1/server/UniProxy/alive
POST /api/v1/server/UniProxy/status
```

这些接口用于现有 UniProxy/Xboard 兼容，不应在没有迁移计划的情况下删除。

## WebSocket

WebSocket 是实时控制加速通道，HTTP 是基础协议。Agent 应能够在 WebSocket 暂时不可用时继续依赖 HTTP 完成核心同步。

## AccessAudit extension

AccessAudit 是可选插件，不属于核心 TX-Node 协议：

```http
GET  /api/v1/plugin/access-audit/rules
POST /api/v1/plugin/access-audit/report
```

TX-Node 可以启用可选 audit reporter 使用这些接口；未安装 AccessAudit 时，不影响核心节点功能。

## Change policy

核心 endpoint、认证方式、payload 或语义发生变化时，需要：

1. 更新 TXBoard 端契约与测试；
2. 在 TX-Node 仓库验证对应 adapter；
3. 保留必要的兼容窗口；
4. 避免将可选插件协议提升为核心硬依赖。
