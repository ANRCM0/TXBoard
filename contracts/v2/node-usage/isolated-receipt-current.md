# Node Usage v2：B3.1 隔离接收切片

> 已退役（2026-10-16）：本文保留隔离原型的历史设计和当时验收记录。对应开关、Registry/CLI、运行器和专属 CI 已删除；正文 Current/isolated 状态不描述现行运行时。当前用量边界见 [当前 native 契约](README.md)。

状态：**Current / isolated-copy only**。本切片实现可运行的 HTTP 持久接收、SQL 结算、查询与本地恢复工具。生产流量权威和 TX-Node 默认 Service 仍使用 v1；[完整 Node Usage v2](README.md) 的其余要求继续是 Target。

## 入口与启用条件

Core 只允许 `APP_ENV=local|testing` 且 `CORE_USAGE_V2_ISOLATED_COPY=true`。必须显式配置稳定 `CORE_USAGE_V2_INSTANCE_ID` 和正整数 `CORE_USAGE_V2_RECEIPT_GENERATION`，并具备 PHP bcmath。默认关闭；生产环境即使设置开关也拒绝执行。

| 已注册入口 | 行为 |
| --- | --- |
| `POST /txapi/node/v2/usage/submit` | `{payload,payload_digest}`，完整 SQL 接收事务提交后返回 HTTP 200 |
| `POST /txapi/node/v2/usage/batch/get` | `{protocol_version:2,node_id,source_epoch,batch_id,payload_digest}`，只查询本认证节点 |
| `POST /txapi/node/v1/handshake` | 原有字段保持；仅有隔离 source 时附加非秘密 `usage_v2` capability |

两条 v2 路由复用 `TxNodeAuth`、Node/Machine Bearer 和 `X-TX-Node-ID`，可选 `X-TX-Machine-ID`。body node 必须等于认证 node。Machine 仍只能访问当前分配且启用的 node；本切片没有发行退役或重新分配节点的历史认证凭据。关闭隔离开关时，已认证 v2 请求返回 `404 USAGE_V2_ISOLATED_ONLY`。

成功沿用 `{data,request_id}`，错误沿用 `{error:{code,message},request_id}`。未知批次返回 `404 BATCH_NOT_FOUND`；同身份不同摘要返回 `409 BATCH_PAYLOAD_CONFLICT` 并保存去重冲突审计事件，原批次不变。v1 的 202 queued 不能充当 v2 持久回执。本切片没有 v2 WSS 绑定。

## 字节与证据

payload 字段、半开区间和 entries 排序遵循 [目标规范](README.md)。Core 和 Node 都拒绝重复 JSON 键、未知字段、null/数值 ID、非规范十进制、重复或未排序 entry；请求最多 1 MiB、10,000 entries，每方向 raw bytes 最多 1 PiB。[共享 golden vector](golden-vector.json) 固定 canonical payload 与 SHA-256。实现只处理此 ASCII / safe-integer schema，其输出与该 schema 的 JCS 相同，不提供通用 RFC 8785 编码器。

Core 隔离实现的 ID、epoch、版本与会计累计上限是 `2^63-1`，超出返回明确错误；Node 解码器接受 uint64，但不会产生超出 Core 分配范围的身份。时间为 `0..2^53-1` 的 UTC 秒。倍率用 bcmath 整数乘除，按每 entry 每方向 `floor(raw × numerator / denominator)`；乘积可用任意精度计算，结果和累计禁止溢出。

Core 为安装实例分配递增 source epoch，保留旧 epoch。SQL 接收锁 source 行并由唯一 `(node_id,source_epoch,batch_id)` 约束兜底；同 source 的已接收半开区间不可重叠。首次接收验证 subject、subscription、user、terms、period、tariff 的归属和覆盖；拒绝未知 legacy 周期边界、跨 lease 或撤销时间的区间。非 active subscription 若缺少有效截止证据，返回 `USAGE_SUBSCRIPTION_CUTOFF_UNKNOWN`；expired 且有明确 expires_at 时允许此前合法区间。当前 subject 已撤销时，撤销前合法采集区间仍可接收；已有持久批次的重放先返回原记录，再考虑当前策略。一个 runtime user 若有多个不同计费主体的活跃 grant，capability 明确拒绝歧义，不选择最近主体。

一次接收事务同时保存完整 canonical payload、digest、冻结归属/授权/费率/周期证据、证据 hash、稳定 receipt ID、Core instance/generation 和 `usage.accepted` SQL outbox。Redis 不参与 ACK 条件。Node 保留原 batch ID、epoch、digest 与 payload，在 Linux fsync 文件和目录后才发送；query-before-replay、spool 重启和稳定回执均已验证。

回执 `data` 必含：`protocol_version`、`core_instance_id`、`receipt_generation`、`node_id`、`source_epoch`、`batch_id`、`payload_digest`、`receipt_status=persisted`、`settlement_status`、`accepted_at`、`receipt_id`；包含 `updated_at`，settled 时包含 `settled_at`，异常时包含安全大写 `reason_code`。状态为 `pending|processing|retry_wait|quarantined|settled`。

## 结算与恢复

worker 从未完成 SQL outbox 扫描 eligible 批次，领取有限 lease 和递增 fencing token。批次、subscription、period、node stat 以稳定顺序锁定；结算只使用接收时冻结的归属与倍率。同一事务写唯一不可变 usage entries、周期 billed counters、node raw counters、settled 状态和 `usage.settled` outbox，再完成 accepted 事件。旧 fence 不能提交；进程中断回滚投影，lease 过期后 SQL 扫描恢复。隔离头部批次不会阻塞后续 eligible 事件。

不可自动修复的累计溢出或证据损坏进入 quarantined；保留 payload、回执、outbox 与原因。显式 recover 保存原因审计，再重新运行原冻结批次。CLI 不会自动改证据、清除历史或回退账本。tariff 和 grant 没有更新 API，使用新 revision/grant 发行新证据。

所有新增表使用 `tx_*`。source / receipt / node stat 不外键约束旧 node 行，旧节点删除不删除历史；shadow 归属采用复合外键。DDL 重放验证既有表，rollback 遇到任何历史数据会拒绝删除。

隔离 CLI：

```bash
php artisan core:usage-v2 source --node=42 --installation=installation_test_0001
php artisan core:usage-v2 tariff --node=42 --numerator=3 --denominator=2 --from=1791770400 --until=1791774000
php artisan core:usage-v2 grant --source=1 --subject=1 --terms=1 --period=1 --tariff=1 --from=1791770400 --until=1791774000
php artisan core:usage-v2 settle --limit=100
php artisan core:usage-v2 recover --batch=1 --reason=ISOLATED_RECOVERY_VERIFIED
```

generation 不可仅改配置后继续接收。配置新 generation 后，由 `core:usage-v2 generation --node=42 --reason=DATABASE_RESTORE_RECONCILED` 保存前后代次与原因；旧回执不重写。Node 若收到变化的 generation，或已经确认的 receipt 再查却不存在，会停止普通重放并要求恢复。此工具不构成完整备份恢复编排或受影响 ACK 区间对账。

## 尚未启用的路径

- TX-Node 默认 Service、累计 tracker、HTTP/WSS v1 report 未切换；显式 v2 Reporter 要求调用方提供已按授权/周期/费率边界切分的 counters，并证明旧 v1 pending 与内存 counters 已排空。
- 有限 grant 是隔离 CLI fixture；Provider 发行、签名、Node 准入/连接到期终止、撤销版本同步与确认等待后续 B4 runtime。
- v1 历史映射、旧 pending 的迁移/隔离、v2 WSS、管理员 RBAC 查询/恢复、退役节点历史凭据未实现。
- `usage.settled` 通知 outbox 被持久保留；当前没有配额消费者及确认/派发器，不能宣称端到端配额同步已完成。
- Core 与 Node 不自动 GC payload / receipt / quarantine；generation CLI 没有自动导入丢失 payload 或完整灾备演练。

因此 B3.1 通过仅证明隔离接收/结算切片；不满足完整 B3 或生产权威切换退出条件。验证证据见 [B3.1 报告](../../../docs/architecture/v2/usage-receipt-slice-verification.json)。
