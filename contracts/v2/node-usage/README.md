# Node Usage v2：当前 native 用量边界

状态：当前实现以 [Native C2 Runtime](../native-c2-runtime-v1.md) 和
[Terms/周期边界](terms-boundary-current.md) 为准。2026-10-16 清理后，仅保留已激活的
native 安装、真实 Provider 回执和同一份有限签名 Node 授权驱动的用量链路。
隔离开关、手工 source/tariff/grant/capability、`core:usage-v2` CLI 及其原型运行器已退役。
历史隔离协议原文从 Git 查询，不是当前启用步骤或旧版本兼容承诺。

## 准入和领域归属

Core 的安装权威、当前配置摘要与 worker epoch 必须通过 native runtime admission。
`UsagePolicy::requireNative` 在接收、查询、领取、结算和恢复前核对准入；每个 SQL
写事务重新持有安装锁，并校验 Subscription 的安装归属。环境开关不能绕过激活。

`NativeUsageLedger` 在签名授权时分配当前 source、不可变 tariff 和 grant。
每个 grant 绑定真实 Subscription、Subject、Terms、UsagePeriod、Provider 发行回执与
完整授权覆盖窗口；签名 envelope 的摘要写入独立 native proof。Node 只引用这些身份，
不能自行定义费率或账务归属。旧 User UUID 映射和隔离 Registry 不参与当前计量。

`UsageEvidence` 仅提供稳定证据哈希、半开区间覆盖与 source instance/generation 校验，
不注册来源、不发行授权、不承载数据库写入。JSON 对象键重排不改变证据哈希，
内容变更仍然拒绝。整数倍率与累计运算由 `UsageMath` 完成，不使用浮点记账。

## 当前 HTTP 契约

| 入口 | 行为 |
| --- | --- |
| `POST /txapi/node/v2/usage/submit` | 提交 `{payload,payload_digest}`，SQL 接收事务提交后返回持久回执 |
| `POST /txapi/node/v2/usage/batch/get` | 按 node、source epoch、batch ID 和 digest 查询原回执 |

路由使用 Node/Machine 自身凭据、节点分配与启用状态；body 的 node 不得扩大认证范围。
成功响应仍为 `{data,request_id}`，错误为 `{error:{code,message},request_id}`。
当前计量身份来自签名授权中的 `usage_v2`，不再从隔离 capability 拼接。

payload 使用 `protocol_version=2`，ID、版本和原始字节为规范十进制字符串，时间为
UTC 安全整数，区间为 `[started_at,ended_at)`。entries 引用 Subject、grant、tariff、
Terms 和 period；按固定身份顺序排列，禁止重复。请求限 1 MiB、10,000 entries，
每方向 raw bytes 限 1 PiB；未知字段、重复 JSON 键、非法数字与越界均拒绝。
[共享 golden vector](golden-vector.json) 固定当前 canonical payload 和 SHA-256。

## 持久回执、预留与原周期结算

批次身份为 `(node_id,source_epoch,batch_id)`。同身份同摘要返回原记录；同身份不同摘要
返回 `BATCH_PAYLOAD_CONFLICT` 并保存去重冲突审计。历史回执重放先于当前 source
instance/generation 和授权检查，保留原 receipt、payload、frozen evidence 与计费归属。
这一规则不会绕过安装准入，也不提供手工修改 generation 的恢复 CLI。

首次接收验证完整 native proof、原始授权覆盖、归属、条款历史、倍率和整数算术。
同一事务保存 canonical payload、digest、冻结证据、稳定 receipt ID、原周期配额预留及
`usage.accepted` SQL outbox。Redis 和队列调度不构成持久 ACK 条件。

合法历史区间按原授权接收。已证明的后续截止可以生成 quarantined 持久回执，并为每个
原周期保留配额；独立的无效 scope、proof、倍率或算术仍拒绝，不能被截止掩盖。
缺失或矛盾的历史证据不能回退到当前订阅、周期、费率或旧 User 计数。

worker 扫描 SQL outbox，使用有限 lease 与递增 fencing token。以稳定次序锁安装、账户、
订阅历史、Subject、source、batch 与 period，验证冻结证据和原 proof；在同一事务写
不可变 entries、原周期计数、Node 原始统计、settled 状态与 outbox，再释放对应预留。
进程中断回滚全部投影，过期 lease 可由 SQL 扫描接管，旧 fence 不能提交。

溢出、证据损坏和无法证明的截止进入隔离，保留原回执与预留。领域恢复必须保存原因审计，
不修改原 payload 或重新分配已接受区间。续期不重置当前周期；reset 后的迟到流量仍属于
旧周期。历史 migration、账本、DDL 重放及有历史时拒绝 rollback 的保护继续保留。

## 验收与尚未交付范围

当前相关回归使用真实 native 激活、Provider 回执和签名 grant；覆盖接收事务回滚、
lost ACK 与冲突、预留、冻结哈希、原周期 reset/renewal、SQL 故障恢复和 fencing。
MySQL 独立验收保留并发接收、并发 worker 及进程崩溃后恰好一次结算；不再通过隔离
Registry 生成测试授权。重型验证仅在 Actions 执行。

当前 native 支持范围遵循 C2 runtime；完整协议/平台矩阵、v2 WSS、管理员恢复 API 和
完整备份恢复编排不因删除原型而宣称完成。当前 HTTP 持久回执与真实 native 用量继续保留。

## 历史证据

隔离接收、TCP collector、Core interop、Service、协议和撤销矩阵的旧协议页面已移除。
[实施台账](../../../docs/architecture/v2/implementation-plan.md)保留结果、验证 JSON 和固定提交的原文入口。
历史报告中的源码路径及哈希不改写，也不代表旧 CLI 或隔离授权仍可使用。
