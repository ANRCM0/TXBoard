# TXBoard 运维与发布

## 当前运行入口

- `GET /txapi/health`、`GET /api/health` 是**应用存活检查**，不是数据库、队列、支付商或 TXNode 可用性证明。
- API 请求、后台、TXNode 身份与流量报告按照 [对外接口总览](../../contracts/http/external-adapter-current.md) 与 [TXNode 对接](../../contracts/node-protocol/txnode-integration-current.md) 实施。
- 开发镜像、预览镜像与正式镜像的推送条件以 [镜像发布通道](image-release-channels.md) 为准；选择不可变 digest 便于追溯。

## 日常检查

- Laravel/Octane、Horizon/queue、Redis、MySQL 的健康状态与错误率；确保 TrafficBatchJob 不持续堆积或反复失败。
- 使用 `php artisan traffic:health --json` 获取机器可读的流量队列/账本概况（需可用的运行时环境），并结合告警 `traffic_queue_unavailable`、`traffic_queue_backlog_high` 分析。
- 同批次流量重试必须幂等；HTTP 202 / WS traffic.ack 的 `queued` 不表示 SQL 已提交。对照 MySQL 批次账本、用户与节点流量和失败队列判断最终状态。
- 观察 API p95、5xx、数据库死锁、支付对账、余额与佣金、Node/Machine 连接、Agent 审批和插件健康情况。

## 备份、变更与恢复

- 生产变更前备份并**在隔离环境验证恢复** MySQL、APP_KEY / 环境配置、用户上传、插件、主题及必要的外部依赖状态。
- 数据库表名切换必须单独执行 [Native MySQL 表切换手册](native-mysql-table-cutover.md)，不可把一般启动/镜像更新视作 DDL 授权。
- API、管理端和资金业务在 MySQL 8.x 环境验证；支付回调必须使用沙箱和有权限的测试用户。
- 版本回退不会自动撤销财务交易或已执行的 DDL；需保留上一可用镜像 digest、恢复方案以及流量与资金账本核对步骤。
- 按 [真实环境发布验收](release-staging-acceptance.md) 验证 HTTP/WSS、管理端、支付、TXNode/Agent、扩展、备份恢复及 Go/No-Go 证据。
