# TXBoard Operations — 持续运维与发布验收

取代旧 Phase 2/4 的阶段记录。现行协议与目标 TXAPI 不能混为一谈。

当前对外适配以 [外部 HTTP / WS CURRENT 接入手册](../../contracts/http/external-adapter-current.md) 为准；真实环境逐步验收见 [预发与恢复流程](release-staging-acceptance.md)。完整 HTTP 目录由 [route:list CI](../architecture/http-route-inventory.md) 提供，而非人工猜测。

## 当前镜像通道

以 [image-release-channels.md](image-release-channels.md) 为准：main 触发 dev，PR 只跑验证，正式 tag 可更新 latest。纯文档合入 main 也可能触发 CI/开发镜像构建。

## 运行与流量可靠性

- 当前应用轻量探针是 `GET /api/health`；在新 `/txapi/health` 运行并完成 Docker、代理和 TXBoard-Deploy 调整之前不得删除它。
- 当前计划每 5 分钟执行 `php artisan traffic:health`；`php artisan traffic:health --json` 用于机器可读统计，检查持久化 `v2_traffic_batch` ledger、Redis `traffic_fetch` 队列 backlog。
- 关键日志告警：`traffic_queue_unavailable`、`traffic_queue_backlog_high`；空 ledger 在没有流量时可能正常。
- 故障演练：重复 batch 不能二次入账，同 ID 不同计数应拒绝，反序上报应正确，SQL 溢出整批回滚；Redis 故障有可观察异常但不得部分结算。

## 测试与生产验收

- SQLite in-memory CI 供快速反馈，生产语义必须在 MySQL 8.x 验证索引、DDL、事务/行锁、订单恢复和流量批次。
- API、Web、MCP、Docker/Octane healthcheck、生产 SPA、跨架构镜像通过质量门禁后再发布；CI 不能替代真实数据库恢复、支付沙箱与节点互通测试。
- 上线前固定 immutable 镜像 digest，备份并验证恢复 MySQL、存储、插件/主题、APP_KEY/.env 和必要 Redis 数据；留存上一可用 digest。
- 逐项验证登录、secure_path 轮换、套餐/订单/支付、Node、流量、工单、扩展和 Agent 审批。
- 监控 p95/error、锁争用、队列滞后、支付/佣金/余额与流量 ledger 对账、Node 连接和重复 batch。
- 新旧 API 双轨时记录匿名化 consumer 使用量；旧支付通知可达直到未完订单窗口结束；TX-Node、Gateway、AccessAudit、MCP、Deploy 需独立确认迁移。
- 回退镜像不能自动回退已发生的财务/DDL 操作，必须有对账/补偿/数据恢复 Runbook。

详见 [Native Development Plan](../architecture/txboard-native-development-plan.md)。
