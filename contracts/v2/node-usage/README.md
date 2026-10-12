# Node Usage v2：订阅计费与可靠批次

状态：总体 **Target / Draft**。B3.1 已实现 [隔离接收切片](isolated-receipt-current.md) 和两条受隔离开关保护的 TXAPI HTTP 路由；生产权威、默认 Node Service 与 WSS 仍以 [Node v1 对接契约](../../node-protocol/txnode-integration-current.md) 为准。本文件其余目标不能视为已启用。

## 1. 当前实现与目标

当前 `TX-Node/internal/controlplane/types.go` 的 `ReportPayload.Traffic` 按整数用户 ID 上报。`internal/reporting/controller.go` 为新批次分配 ID，`spool.go` 将一个 pending 批次原子写入并 fsync，成功调用 Sink 后删除。Core 的 `NativeNodeReport` 接收后入队；`TrafficBatchJob` 按 `(server_id,batch_id)` 去重，将账本、用户计数和统计写入同一事务。现有 hash 包含入队时倍率，重放冲突仅记日志；HTTP 202 与 WSS ACK 均不能证明持久接收或结算。

v2 保留已有适配器和事务边界，补齐订阅身份、持久接收、可查询回执、费率快照和故障恢复。HTTP 与 WSS 使用同一命令处理器与数据库，不允许各自形成接收真相。具体传输绑定在 B3 另行注册并验证。

## 2. 身份、授权与旧协议

| 对象 | 约束 |
| --- | --- |
| `node_id` | 从通过认证的 Node 或 Machine 分配关系取得；body 不能自行扩大范围 |
| `source_epoch` | Core 分配并绑定安装实例和节点记录的上报身份代次；持久保存在 Node，独立于 schema epoch、进程启动及 receipt generation |
| `access_subject_id` | Core 的非秘密稳定身份，永久指向一个订阅；不得因换凭据、换 Provider 或续期而转移到另一订阅 |
| `subscription_id` | 服务端根据 subject 解析；不信任节点提交的用户或订阅归属 |
| `auth_snapshot_id` | Core 发布的授权快照，绑定节点、subject、`terms_id`、`usage_period_id`、`auth_version`、授权有效区间、撤销版本及费率版本；秘密配置来自 Provider |
| `revocation_version` | Core 在固定 `(node_id,source_epoch)` 授权域内单调递增，Node 持久保存已应用版本；回退版本或漏掉增量必须全量同步，跨域不比较 |
| `tariff_revision_id` | Core 发布的不可变计费倍率版本，绑定节点和有效区间；由 Node 引用，Node 不能定义价格 |

授权快照包含 `issued_at`、`lease_expires_at`、`revocation_version`，不得通过 304、重连或本地重启延长租约。Node 按 [Credential Provider 契约](../credential-provider/README.md) 使用授权与协议配置。租约过期、无法验证签名、时钟偏差超过发布策略时，拒绝新接入并终止受该失效授权控制的连接；刷新失败不允许无限使用旧快照。收到更高撤销版本须立即撤销对应准入并 ACK 已应用版本。Core 记录每节点撤销覆盖情况；离线节点只有确认撤销或租约到期后才能标记覆盖完成。

Core 保留 Subject/Provider 的撤销事实，再映射为各节点授权域的撤销序列；Subject 事实版本、Provider 操作版本和 Node 应用序号不能互相比较。Node 回执绑定域与序号，Provider 的 revoke receipt 仅证明 Provider 私有状态，不能代表 Node 已应用。

撤销、过期和卸载 Provider 不删除历史计费身份。已采集合法时段流量仍可上报，接收根据历史授权及费率验证，不根据“现在还有效”丢弃历史数据。跨租约、撤销、费率或 usage period 边界必须切分采集段；边界不明的报告进入隔离，不能猜测归属或计费。授权撤销不等同于 Node 的上报认证撤销；Node 退役需先清空 pending 或给受限的历史上报身份。

v1 兼容适配器必须维护有版本、有效区间且不可变的 `legacy user_id → subscription_id → access_subject_id` 映射。B1 初期每用户最多一个有效订阅，但数据库仍支持 1:N。只有映射唯一、节点授权允许且采集区间明确时，旧用户 ID 才可转换；映射在首次持久接收时冻结，重试沿用。没有映射返回 `LEGACY_MAPPING_MISSING`；两个可归属订阅返回 `LEGACY_MAPPING_AMBIGUOUS`，整批不产生接收 ACK。

开放多订阅之前，预检必须证明该用户已不依赖只能识别 user_id 的旧节点；否则阻断开放或停止这些旧节点的该用户授权。不得默认选择主订阅、最近订阅或平摊。超过换订阅/换费率边界且无法定位采集区间的旧 pending 必须先排空或隔离对账，不能重新映射至当前订阅。v1 的 `queued` 回执永远不能被宣称为 v2 持久 ACK。

## 3. 不可变批次与摘要

幂等身份为 `(node_id,source_epoch,batch_id)`；`batch_id` 为持久生成的全局随机 ASCII 标识，建议 128 bit 随机值，限制 `[A-Za-z0-9:_-]{8,80}`。Node 在发送前原子写入完整 payload、摘要、身份、协议和 Core 实例标识并 fsync 文件及目录；重启、认证轮换和传输切换不能改变批次身份。节点身份重建可发行新 source_epoch，但旧 pending 仍使用旧 epoch，不能给同一批字节改 epoch 或换 batch ID。Core 保存历史 epoch 接收权限和去重记录；节点 ID 不回收使用，不以 Machine ID 或配置 code 替代。现行 v1 的整数 user_id 按独立 legacy 解码规则处理，不强迫现行客户端改成 v2 格式。新流量不能追加到旧批次，磁盘失败不能退化为内存成功。

提交对象为 `{payload,payload_digest}`。`payload_digest` 为 `sha256:` 加 64 位小写十六进制；摘要是 payload 的 RFC 8785 JCS UTF-8 字节 SHA-256，不含外层摘要、请求 ID、签名、发送时间或当前认证 Token。计费整数与 ID 用规范十进制字符串（无符号、无前导零，0 例外），避免 JS 大整数和浮点差异。时间为 UTC Unix 秒安全整数（范围 0 到 2^53−1），采集范围采用 `[started_at,ended_at)`。entries 按 `(access_subject_id,auth_snapshot_id,tariff_revision_id,usage_period_id)` 字典序排列，同键重复拒绝，不在重试时隐式合并。

| payload 字段 | 校验 |
| --- | --- |
| `protocol_version` / `batch_id` / `node_id` / `source_epoch` | 协议固定为 2；节点和 epoch 必须匹配认证范围及已登记上报来源 |
| `started_at` / `ended_at` | 非空有界区间，起点小于终点；迟到/未来偏差受握手发布策略限制 |
| `entries` | 非空且最多 10,000 条；整批最大 1 MiB，实际传输必须在解析前执行字节限制 |
| entry 的 subject / snapshot / tariff / terms / period ID | 均引用 Core 历史对象，subject 属于此节点授权；terms 和 period 必须属于该 subject 的订阅且覆盖区间，并与 snapshot 一致 |
| `raw_up_bytes` / `raw_down_bytes` | 非负整数，每方向最多 1 PiB；累加和存储溢出拒绝，不能截断 |

以下为非秘密、可重放的 payload 示例；实际提交的 `payload_digest` 为 `sha256:d7a022a63a0937d08be73abb133abf633350e187b128d8dc40485c74619c5eee`：

```json
{
  "batch_id": "node42_20261012_000001",
  "ended_at": 1791770460,
  "entries": [
    {
      "access_subject_id": "9001",
      "auth_snapshot_id": "7001",
      "raw_down_bytes": "2048",
      "raw_up_bytes": "1024",
      "tariff_revision_id": "301",
      "terms_id": "6001",
      "usage_period_id": "8001"
    }
  ],
  "node_id": "42",
  "protocol_version": 2,
  "source_epoch": "1",
  "started_at": 1791770400
}
```

倍率由 Core tariff revision 保存为非负有理数 `numerator/denominator`（分母非零）。按每 entry 每方向 `floor(raw_bytes × numerator / denominator)` 计费，使用整数运算并检查乘法及累计溢出；明细保留原始字节、倍率与计费字节。同一采集段只出现一次，跨段切分的舍入规则在 revision 中固定。Node 不预乘倍率。

首次接收冻结 payload、subject→subscription、period、授权证据、tariff revision 及 rounding revision。先以唯一批次查找已有记录再评估当前策略：同身份同摘要返回原记录；同身份不同摘要返回 `BATCH_PAYLOAD_CONFLICT` 并审计，原记录不变。worker 和重放从持久记录结算，不能再次查询当前节点倍率重新定价；费率变更只影响随后采集段。修正计费使用独立、引用原明细的审计调整流水，不能编辑原 payload。

## 4. 两种 ACK 与接收事务

| 回执 | 必须已完成 | Node 行为 |
| --- | --- | --- |
| `receipt_ack`，`receipt_status=persisted` | MySQL 事务提交完整 payload、冻结快照、摘要及待处理 outbox | 可移出发送热队列，仍按保留规则保存本地回执与恢复材料 |
| `settlement_ack`，`settlement_status=settled` | 订阅 usage ledger、周期计数、节点原始统计及批次 settled 状态在同一事务提交 | 记录结算回执，按协商保留期回收 payload |
| v1 `queued` 或无类型的 2xx | 只能说明该传输现有语义 | 不视为 v2 receipt_ack |

接收 ACK 示例：

```json
{
  "protocol_version": 2,
  "node_id": "42",
  "source_epoch": "1",
  "batch_id": "node42_20261012_000001",
  "payload_digest": "sha256:d7a022a63a0937d08be73abb133abf633350e187b128d8dc40485c74619c5eee",
  "receipt_status": "persisted",
  "settlement_status": "pending",
  "accepted_at": 1791770462,
  "receipt_id": "receipt_42_000001"
}
```

回执的 `payload_digest` 必须与本地一致；回执通过已认证 TLS 通道验证。回执绑定 Core 实例、节点、批次与摘要，不凭 HTTP 状态码删除文件。MySQL 不可用、配额不足或事务失败时不得返回 persisted；返回可重试错误和有界退避。队列仅是唤醒通道，持久接收不以 Redis 成功为前提。

接收事务在 `tx_usage_batch` 插入唯一 `(node_id,source_epoch,batch_id)` 和完整有效载荷，在同事务插入 settlement outbox。并发插入采用唯一约束处理竞争，冲突读取已提交记录比对摘要；不能用“先查再插”代替唯一约束。逐条验证失败时整批拒绝，禁止部分 ACK 后让 Node 猜测需要重放的条目。

## 5. 批次状态机与结算事务

```text
未接收 --校验和持久事务--> pending --worker 领取--> processing --事务提交--> settled
pending / processing --可恢复失败--> retry_wait --到期或恢复扫描--> processing
processing --租约过期--> retry_wait
pending / processing / retry_wait --不可自动处理--> quarantined
quarantined --审计修复后重新验证原记录--> pending
```

输入校验拒绝属于 `rejected` 响应而非已持久批次终态。`quarantined` 是已接收且仍未完成的责任，必须可查询、告警并保留；不能以最大重试次数到达为由删除。每次状态变更保存版本、attempt、原因码与时间。Worker 领取设置有限 `processing_lease_until` 和单调 fencing token；旧 Worker 不得在新 token 出现后提交。

结算事务锁定批次、按稳定 ID 顺序锁 subscription/period/统计行，验证 fencing token，并执行以下原子写入：不可变 `tx_usage_entry`，唯一 `(batch_id,entry_no)`（此处 batch_id 是 tx_usage_batch 主键，不是 Node 字符串标识）；对应订阅周期计数；节点原始字节统计；批次 `settled` 与回执摘要；配额变更通知 outbox。明细唯一约束及外键防止并发重复和历史引用丢失。崩溃要么整笔回滚要么可查询 settled；失败重试不能将已提交明细再加一次。统计复合键也需唯一约束，不能仅依赖应用判断。发生死锁可有限重试同一事务。

接收 outbox 在 settled 或经审计的终态处置前不得过期；派发失败和 Redis 清空后由数据库扫描重建。结算后通知 outbox 不得因结算已成功而静默丢弃：重试到消费者确认，按事件 ID 去重；无法送达转入可见异常队列，由状态扫描重新同步配额。归档不撤销唯一身份；批次去重墓碑与不可变账本在可能重放期间必须保留。历史归档后不得将未知旧 batch 当作新 batch，须返回 `BATCH_ARCHIVED` 并提供对账引用。

## 6. 查询、重放与恢复

B3 必须实现 `usage.submit`、`usage.batch.get`、受审计的 `usage.batch.recover` 操作；名称是能力设计，不代表当前已实现的路由。查询返回批次身份、摘要、接收/结算状态、更新时间、可恢复原因与回执 ID；Node 只能查询自己，Machine 只可查询当下或历史明确授权的节点，管理员查询与恢复经 RBAC/审计，不能借查询泄露别的订阅。

超时先查询再以相同 payload 重放；“查无记录”不等于成功或失败，必须保持原身份。ACK 丢失时重放返回原 persisted 或 settled。Redis 丢数据由扫描恢复 pending，不要求 Node 重报已确认接收的数据。数据库备份恢复须改变并发布 `receipt_generation`，列出受影响 ACK 时间区间并启动账本恢复；禁止把恢复后丢失的 persisted 记录解释为“从没接收”。

握手发布且双方持久记录 `receipt_generation`、`max_late_report_seconds`、`node_replay_retention_seconds` 和 `receipt_retention_seconds`。Node 在 persisted 前不按年龄清理 payload；之后至少保留到 settled/人工处置和协商恢复窗口均满足，收据索引保留到服务端去重保留窗口。Core 的可恢复备份时间窗不得超过 Node 完整 payload 恢复覆盖，除非另有独立、验证过的接收归档。超过覆盖范围禁止宣称无损恢复，须隔离恢复并对账。空间满时告警并背压/停止产生无法持久保存的新流量，不能静默覆盖 pending。损坏文件隔离并报告，不能给相同字节换 batch ID。

## 7. B3 故障注入验收

| 注入点 | 必须观察到 |
| --- | --- |
| Node 写文件、fsync 或删除失败 | 未成功持久化不发送；删除失败保持原 batch，重放不重复结算 |
| 接收事务提交前中断 | 无 persisted ACK；原身份重放只产生一条批次 |
| 接收提交后、ACK 前断网 | 查询/重放返回原摘要及状态 |
| 两个入口同时提交、或同 ID 改字节 | 同摘要只有一条批次；不同摘要明确冲突，原记录不变 |
| Redis 清空、outbox 派发后崩溃 | SQL pending 被扫描恢复；重复派发只结算一次 |
| Worker 提交前后崩溃、租约竞争 | 事务全回滚或完整 settled；过期 token 无法提交 |
| 接收后改变倍率或周期 | 重试使用原 snapshot/period/revision，明细与计费字节不变 |
| subject/Provider 停用或删除 | 历史可结算，秘密不进入流量表；新准入撤销 |
| 离线租约到期、撤销版本倒退 | Node 停止授权；Core 不把未覆盖撤销标记完成 |
| 旧用户有两条订阅、旧 pending 跨迁移 | 明确拒绝歧义或隔离对账，无默认归属 |
| 恢复到 ACK 之前的备份、磁盘满 | generation 改变并重放恢复/明确覆盖不足；无静默丢流量 |

验收以真实 MySQL、Redis、Node 磁盘重启和 HTTP/WSS 双传输完成；单元测试或 202 不能代替上述证据。跨仓门槛见 [升级 Manifest](../upgrade-manifest/README.md)。
