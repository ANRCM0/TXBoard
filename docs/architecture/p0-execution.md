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

## P0-B 第一批：隔离业务链路与合成 SQL 读性能

- CorePurchaseTrafficJourneyTest 在本地/CI 应用内，使用真实 Laravel HTTP 入口、数据库写入与测试 EPay 插件，覆盖密码登录、下单、生成外部支付 URL、签名通知、重复通知、OrderHandleJob 履约、可见节点、节点握手、HTTP 流量批次上报以及 TrafficBatchJob 去重与账户/节点/统计账本核验。
- 支付商户和交易通知为**合成测试数据**；测试不会请求第三方支付域名。队列通过 Bus::fake 捕获，再显式执行任务，不代表真实 Redis Horizon/Worker 交付。节点交互是 Laravel HTTP 模拟客户端，不代表真正 TX-Node 互通。
- P0ReadPathBaselineTest 使用 1 名合成用户、1 个套餐、30 条订单；分别对公开套餐列表与用户订单列表执行 3 次热身、20 次测量，在 MySQL CI 导出 p50/p95/p99 响应时长和 SQL 查询数分布。文件只含预定义操作名、合成样本数量与指标，不导出 SQL 文本、数据库连接、令牌或业务记录。
- 新增 MySQL CI 附件 txboard-p0b-synthetic-mysql-read-path；这些是同一 CI 环境内的**合成应用内性能观测数据**，**不是生产负载/QPS、真实网关端到端延迟、SLA 或性能优化效果**；不同 Runner 的毫秒值不可直接比较。
- SQLite 快速回归与 MySQL 8.4 CI 双运行核心旅程；脚本没有修改线上交易服务、节点服务或数据库迁移。

**未完成的 P0-B 出口：** 隔离真实 TX-Node / 真实 Redis Horizon 与支付沙箱的联调；真正请求链路的 p95/p99 与 SQL EXPLAIN、Redis/队列延迟、MySQL 锁等待、CPU/内存、故障注入及恢复演练仍需额外环境和人工批准。

## P1 开发与 P0 外部验收分离

维护者决定先完成 TXBoard 原生架构。P0-B 已具有 MySQL 和 SQLite 合成登录→支付→订阅→流量账本回归，以及合成读 API 性能采样。真实 TX-Node 联调、独立 Gateway、支付沙箱、正式运行环境压力与恢复演练及逐项外部消费者批准仍未完成，但不阻断 P1–P6 的**非破坏性开发**。原 P0/P4/P7 的对外发布、删旧接口、资金与节点验收条件未豁免，不得标为完成。

P1-A 第一批只实现独立 /txapi/health、公开配置/套餐、Sanctum 用户信息与订单只读分页、详情；独立的 native JSON/Error/Trace/User Auth 规则和 SQLite/MySQL 合同测试随 PR 引入。没有新写操作、没有数据库 schema 修改，老 API 和旧支付回调保持有效。后续 P1-B 单独迁移 Vue/React 客户端。

## P0 还没有完成的事项

- **P0-A：** 对每个路由逐条确认真实消费者、Owner、风险、第三方代码 provenance、迁移窗口和业务回滚负责人；整理 Controller/Service/Model/Migration 双向调用关系及数据库字段字典。
- **P0-B：** 在隔离真实 Laravel/MySQL/Redis/节点/支付沙箱环境中，执行注册→登录→下单→支付→生效→节点同步→流量入账的完整 E2E。
- **性能证据：** 在同一基准负载下记录 p50/p95/p99、SQL 次数与 EXPLAIN、Redis QPS、MySQL 锁与事务、队列积压、Node 上报、CPU/内存及错误率。
- **生产门槛：** 验证备份恢复、资金/流量对账、真实支付回调窗口、外部消费者无损升级。不得把静态代码扫描解释为上线调用量或代码来源占比。

只有这些验收完成并由维护者审查后，才能将 P0 标为 DONE。P1 才建立新的 /txapi 路由与原生客户端迁移；旧 API 不应提前移除。
