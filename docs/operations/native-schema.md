# TXBoard 原生 MySQL 数据库

TXBoard 仅使用 **`tx_*` 应用数据表**。Eloquent 模型、数据库迁移、原生 SQL 和参数校验均直接使用固定的 `tx_*` 名称。不存在旧版兼容开关、动态表名 Trait 或在线双向改名命令。

Laravel 自有的 `migrations`、`failed_jobs`、`personal_access_tokens` 等表名不受影响。

## 破坏性版本边界

此版本适用于**全新数据库**。不得将它直接部署到仍使用旧表命名的现有数据库上：自动迁移不会替你转移历史用户、订单、钱包余额或流量统计。先在离线备份的数据库副本中制定独立数据迁移方案，核对表结构、财务合计、身份、幂等流水、外部插件和数据库写入方。完成真实恢复演练后才允许生产切换。

禁止自动删除、清空或覆盖现有生产表。所有队列、定时任务、WebSocket、Octane 和外部写入方必须在数据迁移窗口冻结。CI 仅证明原生全新建库和代码契约，不是生产数据迁移验收。

## 启动保护

安装及升级前必须通过 `NativeSchemaPreflight` 检测。数据库已经存在非原生表或只存在旧 Migration 记录时，拒绝初始化，防止意外创建并行的 `tx_*` 空表。容器首次启动若数据库尚未连接可暂时继续启动，但后续的实际安装/升级不会跳过检查。

## 升级与恢复边界

- 安装、更新、互锁和持久检查点操作以 TXBoard-Deploy 对应版本为准。当前没有统一跨组件 Upgrade Manifest 或 Cluster 编排的交付承诺；历史演练格式不能作为现行命令。
- 修改前确认安装身份、实际镜像 digest、数据库形状、迁移历史及备份集合。DDL 可能隐式提交；存在 intent 而没有结果是未知状态，先只读探测，不盲目重跑或启动旧 Writer。
- 已迁移数据不因换回旧镜像而恢复。不能证明旧制品读写兼容时保持维护，优先使用固定目标制品前向修复。
- 恢复旧备份前隔离全部 Writer，在独立实例验证数据库、APP_KEY、Provider 私有存储及 Node 持久状态的对应关系。列明备份后的真实支付、钱包、用量回执和撤销增量；缺失时不得声称无损恢复。
- 保留原批次/Session/Period、去重记录及单调授权高水位；不能以新 epoch 重贴旧字节或使旧凭据复活。备份可解压、应用健康和聚合总数一致都不能单独证明这些不变量。
- 备份与检查点包含敏感状态，限制权限，不上传到公开日志或仓库；观察及恢复验证完成前保留当前恢复点。实际验收见[发布 Runbook](release-staging-acceptance.md)。

## Compose project identity

The root Compose project is named `txboard`. It no longer reuses Docker volumes from historical deployment project names. Operators with an existing installation must not assume that similarly named services or volumes contain the same data; back up the original installation, identify its database volumes explicitly, and perform a separately verified restore to the new project before deploying. No volume is automatically renamed or deleted.
