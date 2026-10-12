# Core First v2 实施台账

> 状态：B0、B1.1 订阅影子、B1.2 商品快照/对账、B1.3 财务证据、B1.4 Terms 转换、B2.1/B2.2 单机影子升级、B3.1/B3.2 隔离用量回执与边界切片已实现；后续计费、Provider 和服务身份任务推进中。各整阶段仍按下表门禁验收。

## 1. 批次与关键路径

| 批次 | 交付 | 前置条件 | 完成证据 |
| --- | --- | --- | --- |
| B0 | 依赖/数据/接口盘点、ADR、六项规范、兼容矩阵、基线验证 | 当前四仓提交可定位 | 盘点可重跑；真实 HTTP registry 和现有测试证据齐备；关键未决项有明确门禁 |
| B1 | 订阅、不可变权益、用量周期、Access Subject 增量模型与回填 | B0 数据规范及现状映射；先验证旧单位/周期 | 隔离真实数据回填及逐行/聚合对账；旧行为兼容；并发单有效订阅约束 |
| B2 | Upgrade Orchestrator、Manifest、检查点和恢复 | 升级契约；可验证备份和隔离恢复路径 | 每个迁移中断点可识别并继续/恢复；禁止盲目启动旧镜像 |
| B3 | 持久接收、订阅结算、Node Usage v2 与 v1 映射 | B1 计费身份；B2 重大切换恢复能力 | 持久 ACK、入队/结算故障注入、原批次重放不重复计费 |
| B4 | Plugin v2、Credential Provider、租约及撤销 | B1 Access Subject；B3 授权与结算归属 | 无 Provider Core 可启动；停用/卸载含离线节点和待结算验收 |
| B5 | Internal API、服务身份、mTLS 与 Operation | B0 契约可先设计；领域服务逐项可调用 | Core 最终授权，User/Admin 交叉拒绝；真实私网联调 |
| B6 | SC-v2、统一 Gateway 与 SDK | 字节规范和审查；B5 业务接口 | 各端互操作、轮换/吊销、防重放、无明文回退和真实 Core 联调 |
| B7 | 独立前端、AgentOps/MCP 插件迁移 | B4/B5/B6 和对应权限契约 | 两端完整业务回归；卸载 Agent/MCP 不影响基础业务 |
| B8 | 整合、对账、性能、灰度和旧实现退役 | 所有对应新路径通过迁移门禁 | Compact/Standard/Cluster 验收；旧调用无消费者；回退演练 |

B1 → B3 → B4 是业务身份主线；B2 必须在生产数据库权威切换前完成。B5 可在 B1 期间开发读取 Operation；B6 的协议字节规范、测试向量可以提前开展，业务放量等待 B5。B7 不提前删除现有前端、Theme、Agent 或 MCP。

## 2. B0 状态

| 任务 | 本地进展 | 仍需退出证据 |
| --- | --- | --- |
| CORE-001 | 静态盘点、真实 HTTP registry、API 全量与数据库并发、Linux Gateway/Redis/Node 验证 | 匿名化真实站点数据在 B1 演练；生产镜像在对应阶段验证 |
| CORE-002 | Core First ADR、原生白名单、插件候选和角色边界 | 运行时迁移 PR 必须逐项遵守，文档不改变现行权限 |
| CORE-003 | 逻辑模型、约束、单位、回填与对账规范 | B1 物理 Schema 定稿及真实周期/历史订单样本验证 |
| CORE-004 | Provider 方法、非秘密归属、租约、撤销与卸载规范 | 授权租约时长/时钟偏差参数和隔离 Runtime 实现 |
| CORE-005 | Manifest、兼容矩阵、检查点与恢复规范；Installer 已获取并通过 5 套隔离测试 | B2 发布组件与升级中断/恢复演练 |
| CORE-006 | Internal Operation、服务身份和业务授权规范 | Operation Schema、密码字节规范、mTLS 运行时和消费者测试 |

可执行的结果与限制见 [基线验证记录](baseline-verification.md)。上表不宣称六项均已生产验收。

B0 补充退出证据见 [Linux 运行验证](baseline-linux-verification.md)。B0 的草案约束用于后续实现；SC-v2 字节参数和 Provider 运行时尚未实施，不因本地基线完成而标为当前能力。

## 3. B1 的第一条可验收切片

当前实现及双引擎证据见 [B1.1 订阅影子回填与对账](subscription-shadow-implementation.md)。B1.1 已交付 Schema、单槽约束、有界回填、只读核对和显式影子 DTO；整个 B1 仍需商品/订单/财务快照、真实样本、Terms 转换和链接兼容门禁。没有发布 v2 业务写入权威。

B1.2 的 [商品目录影子契约](../../../contracts/v2/plan-catalog-shadow-v1.md) 已实现不可变 revision/offer、独立销售状态、有界游标与异常重试；SQLite 23 项、108 断言，MariaDB 25 项、119 断言通过。真实独立会话覆盖陈旧事务快照后的 revision/offer 和版本号读取；GiB、十进制价格、真实旧报价与 Carbon 日历周期一致性均校验。现有 User Terms 不引用当前 Plan 猜测历史。

B1.2 后续只读对账支持保存上下文、有界分页及已删除源/映射证据核对；实际列和 JSON hash 同时验证，价格/销售状态变化不会自动修复。SQLite 31 项、184 断言，MariaDB 33 项、195 断言通过，查询日志确认只执行 SELECT。报告范围为当前 revision/offers，不证明 superseded history、跨页实时一致性或生产 writer fence。

B1.3 的 [财务与订单影子证据](../../../contracts/v2/commerce-shadow-v1.md) 使用独立 `tx_commerce_shadow_*` 表，保存整数期初余额、平衡分录和当前订单证据。SQLite 25 项、184 断言，MariaDB 26 项、200 断言通过；8 个预先建立陈旧快照的真实会话重放只产生一次期初账。预扣余额、折抵、免费订单和旧支付标记有显式分类，历史 Terms/收款事实不推造。支付履约、退款、余额投影及 live ledger 权威切换仍未启用。

B1.3 后续对账增加双对象分页、源增长/全局异常/上下文门禁及 exception-only 证据，CLI 核对期望 currency/snapshot。SQLite 37 项、364 断言，MariaDB 38 项、380 断言通过；完整 user/order 分页查询仅执行 SELECT，测试所有表前后保持一致。每页使用独立事务，要求暂停 writer 的隔离副本；不以 high-water 检查证明已读行无后续漂移。

B1.4 的 [Terms 转换与周期契约](../../../contracts/v2/subscription-terms-shadow-v1.md) 实现不可变半开时间线、同订阅续期/限额/报价证据、签名 quota adjustment 和显式 reset；正常 Terms 变化保留既有用量。SQLite 23 项、143 断言通过，4 项 MariaDB 专测跳过；MariaDB 27 项、192 断言全部通过。独立会话验证版本竞争、同键重放、旧 MVCC 当前读与锁等待后 accepted usage 阻断；失败完整回滚。仅限隔离服务，未接入旧 writer 或生产业务路由。

B2.1 的 [隔离升级执行器](https://github.com/ANRCM0/TXBoard-Deploy/blob/main/docs/durable-upgrade.md) 已通过 Windows/Linux 33 项 Python 测试、Linux 管理互锁，以及真实 Docker/MariaDB 提交后 SIGKILL 恢复。此阶段不执行 production writer switch；Cluster fencing、完整角色矩阵、生产副本和恢复外部增量仍待验收。

B2.2 商品目录 profile 已推送 Deploy `617cb939589def56e7560db639dd7aa6df3094f5`，Windows/Linux 40 项升级测试通过。真实固定 Core 镜像与 MariaDB 验证回填提交后、journal result 前 SIGKILL，恢复无重复映射、逐页只读对账通过且旧 binary guard 保留。演练为合成隔离副本，未切换生产权威。

B3.1 的 [隔离用量回执契约](../../../contracts/v2/node-usage/isolated-receipt-current.md) 与 [验证报告](usage-receipt-slice-verification.json) 已交付 Core SQL 持久接收、有限 worker lease/fence、整数原子结算和 TX-Node 显式 v2 spool/replay。全量 API 633 项、4585 断言通过；7 项引擎专测在 SQLite 跳过，Usage v2 MariaDB 31 项和真实并发/MVCC 2 项另行通过。真实 Node↔Core HTTPS、spool 重启及相同回执结算已验证。默认业务仍使用 v1；Terms 转换、Node runtime 边界切分、Provider 租约、v2 WSS、通知消费者和完整恢复门禁尚未完成。

B3.2 的 [Terms/周期边界对接](../../../contracts/v2/node-usage/terms-boundary-current.md) 保留历史 Terms、周期与费率，正常变更不重置用量，跨边界区间在首次 ACK 前拒绝；合法迟到/待结算批次仍写入原周期。基于已提交 B1.4 的固定快照，全量 SQLite 678 项、4892 断言通过，14 项引擎专测跳过；新边界 MariaDB 17 项、176 断言和既有用量 MariaDB 33 项、254 断言另行通过。真实 HTTPS 与 spool 重放通过，代码及依赖 hash 见 [验证报告](usage-terms-boundary-verification.json)。默认 Node runtime 采集、Provider 租约和生产切换仍待实施。

B3.3 的 [Node 连接管理前置切片](https://github.com/ANRCM0/TX-Node/blob/main/docs/connection-lifecycle-current.md) 已推送 `13fb04d87c0a06409aea76fe488e8834028ea2b0`，兑现 sing-box TCP/UDP 用户会话关闭；身份移除后拒绝新准入，末尾累计用量仍由 v1 Tracker 处理。Linux race/vet 的 103 个主用例、18 个子用例通过。未启用 v2 collector 或 lease 策略。

B4.1 的 [本地 Provider 合成进程切片](../../../contracts/v2/credential-provider/local-fixture-runtime-v1.md) 默认关闭，Core 先持久 operation，未知结果查询原 ID，私有秘密不进入 Core DTO/SQL。最终 SQLite 20 项、120 断言及 MariaDB 22 项、147 断言通过，固定提交快照全量 API 698 项、5012 断言通过，16 项引擎专测跳过。发行前重新校验当前授权、Subject 和 Terms 边界；此固定可信脚本是同 OS 身份进程，不能视为生产隔离 Provider，尚无 Node 秘密交付、撤销或卸载完成门禁。

首先建立只增不删的订阅/迁移映射骨架，保持现有 User 写入为唯一权威。在隔离数据库副本中，把旧用户映射到稳定 Subscription、Terms、Usage Period 与 Access Subject，再通过只读核对证明额度、有效期、用量和归属保持一致。

| 顺序 | 工作单元 | 验收 |
| --- | --- | --- |
| 1 | 定稿物理字段、关系、唯一键和迁移映射版本 | 旧周期、试用、无套餐、无限流量/期限等样本都有显式规则；未知值不得猜测 |
| 2 | expand Migration + Laravel Model + 单有效订阅写入约束 | 重复执行安全；已有表不删除；并发激活不能产生两个有效订阅 |
| 3 | 有界 backfill、游标、源快照/版本与幂等映射 | 中断后原映射不变；源数据变化能被检测；失败记录可单独重试 |
| 4 | 只读对账命令与隔离副本报告 | 用户/套餐/余额/订单/到期/流量逐项核对，整数单位不丢精度 |
| 5 | 兼容读取适配器和旧业务回归 | 现行 User/Admin/Node/支付行为不因影子数据存在而改变 |

这条切片不切换生产写入、不开放多订阅、不删除 User 字段。写入切换等待 B2/B3、实时数据追平、恢复演练和对应契约消费者完成。详细迁移阶段以 [Core 演进](core-evolution.md) 与 [Upgrade Manifest](../../../contracts/v2/upgrade-manifest/README.md) 为准。

## 4. 四条工程线的交接

- Core：数据模型、领域服务、账本、Node 接收和 Plugin 平台；输出版本化契约及迁移 Manifest。
- Deploy：消费 Manifest，执行预检、备份、互斥升级、检查点与恢复，不自行推导业务 Schema。
- Node：消费授权/计费身份与协议，保持不可变批次和本地持久化；旧协议的退役由兼容矩阵决定。
- Gateway/Frontend：按 Operation 逐项适配 Core，区分 Current/Target，先解决现有路径与 envelope 不匹配；不能同时建设另一套 Theme 安装业务权威。

Gateway 的旧 BFF/Theme 路线作为历史资料保留；影响统一 Admin/User Gateway、独立 Frontend 和 SC-v2 的旧目标，以本台账及对应版本化契约为后续实施依据。
