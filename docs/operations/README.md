# 运维文档导航

| 场景 | 权威文档 |
| --- | --- |
| 安装、升级、备份和恢复命令 | [TXBoard-Deploy](https://github.com/ANRCM0/TXBoard-Deploy) |
| 镜像 dev/preview/stable 发布规则 | [镜像通道](image-release-channels.md) |
| 原生 `tx_*` 数据库及升级保护 | [Schema 边界](native-schema.md) |
| 上线前真实数据库、支付、Node 验收 | [Staging / Release Runbook](release-staging-acceptance.md) |
| 安全路径恢复及代理信任边界 | [安全基线](../security/README.md) |

## 运维原则

1. 先确认部署模式、镜像 digest、数据库连接和备份，再操作升级/恢复。
2. 生产数据迁移、支付回调和 Node 联调必须在独立预发环境验证；不可让 CI 合成数据的成功掩盖实际迁移风险。
3. 对管理工具命令或选项的更新，以 [TXBoard-Deploy](https://github.com/ANRCM0/TXBoard-Deploy) 对应版本为唯一来源，本仓库只提供导航。