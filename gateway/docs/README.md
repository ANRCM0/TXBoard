# Gateway 文档入口

Gateway 当前是 `/gateway/v1` 用户 BFF/SDK；`/txapi/bff/v1`、统一管理员浏览器入口和网络 SC-v2 没有因文档存在而上线。Core 保留身份、权限、资金、订单和权益权威。

## 当前维护文档

- [架构与信任边界](architecture.md)：静态策略、上游归属、缓存、观测和故障。
- [当前实现与验证范围](development-status.md)：实际代码、历史证据及尚未覆盖的环境。
- [当前 HTTP 契约](../contracts/gateway-v1.md)、[Native 服务接入](native-core-integration.md) 与 [SDK README](../packages/theme-sdk/README.md)。
- [SDK/现有示例指南](developer-guide.md) 和 [Core 主题包契约](../../contracts/theme-package/README.md)。
- [加密、replay 与离线实验](app-crypto-preview.md)、[安全政策](../SECURITY.md)。
- [合仓运行/CI/镜像约定](monorepo-current.md)、[Docker 部署与恢复](docker-deployment.md)、[隔离 Staging](../staging/README.md)。
- [限流策略](rate-limiting.md)、[限流恢复](runbook-rate-limit-recovery.md)、[发布记录](release-checklist.md)。

## 维护规则

当前事实以源码与对应契约为准；[状态页](development-status.md)集中说明验证边界，不在各入口重复历史测试计数。实验不是已开放业务，历史结果只覆盖其记录的源码/环境。API/包格式同步更新真实生产者和消费者，不保留已退役入口、旧包共存或无消费者的过渡计划。

[主仓架构边界](../../docs/architecture/v2/README.md) · [主仓文档入口](../../docs/README.md)。
