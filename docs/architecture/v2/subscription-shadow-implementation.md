# B1.1 订阅影子回填与对账

> 2026-10-12；当前实现仅为隔离副本中的增量演练。现有 User、订单、节点和插件写入继续由旧路径负责。

## 实现与操作

物理表、不可变模型、复合归属外键、容量为 1 的 slot 以及 DDL 恢复规则见 [运行切片契约](../../../contracts/v2/subscription-shadow-v1.md)。没有对旧 User/Plan 添加外键，因此新影子数据不改变现有删除行为；对账能够发现被删除的来源。不可变模型拒绝常规模型 UPDATE/DELETE，数据库写入权限和完整运行时 fence 在权威切换前另行验收。

仅在已核实的隔离副本中设置 `APP_ENV=local` 或 `testing`、`CORE_SHADOW_ISOLATED_COPY=true`。回填同时要求显式 `--isolated-copy`，不能借此切换生产权威。先运行 expand migration，再使用从站点实际配置核实的货币与时区；以下 USD/Asia/Shanghai 只是演练参数。

```sh
php artisan migrate --force
php artisan core:subscriptions-backfill --run=shadow-rehearsal-001 --limit=100 \
  --billing-timezone=Asia/Shanghai --currency=USD --isolated-copy
php artisan core:subscriptions-reconcile --run=shadow-rehearsal-001 --limit=100 --after-id=0 --json
```

回填每次只处理一个有界块；重复同 run 继续原游标，固定高水位与上下文。不同 run 仍复用全局 legacy User 映射，不重新发放权益。失败对象写入持久 exception，修正来源后用 `--retry-user=<id>` 单独重试；已有 source hash 变化只标记 `source_changed`，不覆盖原 Terms/周期。生产写入变化的同步捕获与最终 quiesce 尚未启用。

对账每页返回 `next_cursor` 和 `complete`；调用者必须从 0 开始检查所有页且每页 `passed=true`，不能把空的末页当整次演练通过。报告只输出差异字段名和错误码，不输出 token、UUID、邮件、密码或原始值；无 source/map、source 变化、未决 exception、新增高水位和影子内容篡改均阻断。对账不修改 checkpoint 或业务数据。

用户当前实际 quota、限速、设备数、组、到期、重置锚点和原始 bytes 原样保留；0 配额保持 0，过用量保留，永久期限保持 null。缺少周期起点时使用 `legacy_boundary_unknown`，不使用创建日期猜测。初版 Terms 是导入时当前状态的证据，`plan_revision_id=null`，不伪造历史合同。Access Subject 是无授权的 pending 身份，不复制 Provider 秘密。

余额和佣金余额作为十进制字符串证据，已有订单以排序流式摘要和数量核对，不生成支付或资金分录。此切片尚未实现逐订单字段报告、PlanRevision/Offer 回填、资金期初账本、Terms 转换业务、旧订阅 token 摘要适配以及实际写入切换。因此 **B1.1 完成不等于整个 B1 完成**。内部 ShadowReader 提供十进制 DTO；当前公开 API 保持从 legacy User 读取。

## 验证

| 验证 | 实际结果 |
| --- | --- |
| Linux API 全量，SQLite | 554 passed、2 skipped，4166 assertions；两个 skipped 为真实数据库并发测试 |
| 回填/对账，SQLite | 13/13，81 assertions |
| 回填/对账，MariaDB 11.4.12 / MySQL 驱动 | 13/13，81 assertions；大于 JS safe integer 的流量原样保留 |
| Schema/slot，SQLite | 22 passed，50 assertions；并发 case 在该引擎 skipped |
| Schema/slot，MariaDB 11.4.12 / pcntl | 23/23，89 assertions；32 独立连接中 1 成功、31 冲突，最终 1 active 与 31 pending |

测试覆盖重复/部分 DDL、坏 schema 拒绝、外键归属、暂停占位、到期释放、事务回滚、断块恢复、独立重试、源变化、大字节、负数/溢出、无计划、永久/过期、未知重置边界、删除来源、JSON key 重排和公开 HTTP 兼容。全量测试采用冻结副本，避免并行开发污染结果；原始日志与 JUnit 位于本机任务工具目录。未获取匿名化生产副本，不宣称真实历史订单/支付数据迁移或 Oracle MySQL 8 已验收。

B0 静态盘点保留历史源码哈希；新增源码使旧 `--check` 产生预期漂移。新的跨仓静态快照等待本阶段相关代码均提交后另生成，不能把并行工作区中的未完成协议算作已发布基线。
