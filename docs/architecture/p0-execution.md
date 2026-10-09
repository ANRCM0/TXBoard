# TXBoard Native P0：执行记录与验收

> 2026-10-09 | P0 已启动、部分落地；**尚未达到 P0-A/P0-B 完成条件**。本阶段只改 TXBoard。Gateway 将在 TXBoard 契约稳定后自行适配。

## 本次实现

- 静态扫描 Laravel、用户 Vue、管理 React、MCP、插件、部署配置与数据库迁移中旧 API、Xboard 鉴权缓存、历史数据表的调用位置。
- CI 根据 Laravel route:list --json 记录实际 method、URI、middleware 和 action；生成 inventory.json 和 summary.md，可在 p0-native-baseline 工作流下载。
- 对登录、用户订单、支付回调、Node、Agent、管理动态路径、健康检查建立关键兼容性断言。
- 利用现有测试作为订单并发、支付重复回调、节点协议与流量去重回归基线；保留 api-ci 全套测试、api-mysql-ci MySQL 集成测试。
- 不修改运行时业务、数据库、路由、客户端、支付/节点通信，也不改变 Xboard 许可证要求。

## 复现步骤

1. 仓库根目录运行 node --test scripts/tests/p0-api-audit.test.mjs
2. 完成 api/.env 和 composer install，生成 APP_KEY。
3. 仓库根目录运行 php api/artisan route:list --json > /tmp/txboard-routes.json
4. 执行 node scripts/p0-api-audit.mjs --routes /tmp/txboard-routes.json --output-dir /tmp/txboard-p0
5. 进入 api 目录执行 php artisan test tests/Feature/Contract/LegacyApiBoundaryTest.php

审计报告仅包含源码相对路径、行号、匹配类别/次数、业务域、路由与已声明的中间件，不存源码内容、Authorization、Secret、Token 或业务请求体。CI 证据产物保留 14 天。

## 已存在的自动测试矩阵

| 环节 | 现有验证 |
| --- | --- |
| 用户登录、注册和后台动态路径 | LoginServiceTest、RegisterServiceTest、AdminSecurePathTest |
| 下单、余额与履约 | OrderServiceConcurrencyTest、CheckOrderRecoveryTest |
| 支付回调签名、金额与重复事件 | PaymentWebhookTest |
| 节点配置、授权与协议 | NodeProtocolContractTest、ServerHandshakeTest |
| 批次流量去重、乱序与碰撞 | TrafficBatchSettlementTest |
| 实际 MySQL 表和事务行为 | api-mysql-ci 工作流 |

## P0-A 第二批：可复现的路由审核队列与数据库证据

- P0 API 工作流同时输出 route-review.json。每条路由有初步业务归属、风险分级、控制器文件候选位置、未验证的消费者列表及审批/回滚字段。所有路由默认 unverified，且 removal_allowed=false；这些只是人工核验任务，不代表外部调用者已经查清。
- MySQL CI 运行 php scripts/p0-schema-inventory.php --output=artifacts/p0-schema.json，并上传 txboard-p0-mysql-schema 工件（保留 14 天）。仅输出表名、字段名/类型/可空性、索引唯一性及列顺序、外键关系和约束校验。
- MySQL P0 校验保护 v2_user.email 唯一、v2_order.trade_no 唯一、v2_traffic_batch(server_id,batch_id) 组合唯一；失败时阻断 CI。索引契约保护结算安全，不意味着生产资金或流量对账已经通过。
- 导出不读取用户/订单/支付记录，不包含数据库名称、连接参数、默认值、表行数或业务数据。生产 schema 只能在经授权的受控环境采集。
- 两份工件都只是 P0-A 自动化辅助证据，不是来源授权、真实消费者清单或迁移批准；不得以候选 owner 替代人工核验。

## P0 还没有完成的事项

- **P0-A：** 对每个路由逐条确认真实消费者、Owner、风险、第三方代码 provenance、迁移窗口和业务回滚负责人；整理 Controller/Service/Model/Migration 双向调用关系及数据库字段字典。
- **P0-B：** 在隔离真实 Laravel/MySQL/Redis/节点/支付沙箱环境中，执行注册→登录→下单→支付→生效→节点同步→流量入账的完整 E2E。
- **性能证据：** 在同一基准负载下记录 p50/p95/p99、SQL 次数与 EXPLAIN、Redis QPS、MySQL 锁与事务、队列积压、Node 上报、CPU/内存及错误率。
- **生产门槛：** 验证备份恢复、资金/流量对账、真实支付回调窗口、外部消费者无损升级。不得把静态代码扫描解释为上线调用量或代码来源占比。

只有这些验收完成并由维护者审查后，才能将 P0 标为 DONE。P1 才建立新的 /txapi 路由与原生客户端迁移；旧 API 不应提前移除。
