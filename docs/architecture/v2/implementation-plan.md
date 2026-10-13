# Core First v2 实施台账

> 状态：B0、B1.1–B1.27 影子证据、Terms、身份旁观、固定 writer 捕获、套餐批量同步、批量封禁、管理员创建/删除、注册初始记录与余额端点投影、B2.1–B2.6 单机升级、B3.1/B3.2 隔离用量、B3.3–B3.6 本地观察与持久事件桶、B4.1–B4.7 合成 Provider、插件停用接入、租约候选、离线签名快照与Go公钥诊断、B5.1–B5.24 本地 mTLS 读取、隔离建单、登录、内存邮件验证码、隔离注册、密码恢复、当前会话与管理读取、B6.1–B6.4 实验字节、离线加密、共享 owner 与独立响应随机值候选及 B7.1 Agent/MCP 退役已实现；两仓迁移与三镜像发布已完成，继续推进业务迁移切片。各整阶段仍按下表门禁验收。

2026-10-13，按仓库整合任务，将 Gateway/Node 的完整 Git 历史导入本仓 `gateway/`、`node/`，安装器集中到 TXBoard-Deploy。当前目录与三镜像 CI 见 [双仓布局](../../development/repository-layout.md)；旧基线和验证报告保持当时提交与路径。本次仓库整理独立于下列业务迁移门禁。

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
| B7 | 新管理/用户前端均延期，后续由独立 TX-F 项目开发，仅本地提交；清理 Agent/MCP | 未来前端依赖 B5/B6 及对应权限契约 | 当前验收既有前端兼容与 Agent/MCP 退役后基础业务；新前端不计入当前交付 |
| B8 | 整合、对账、性能、灰度和旧实现退役 | 所有对应新路径通过迁移门禁 | Compact/Standard/Cluster 验收；旧调用无消费者；回退演练 |

B1 → B3 → B4 是业务身份主线；B2 必须在生产数据库权威切换前完成。B5 可在 B1 期间开发读取 Operation；B6 的协议字节规范、测试向量可以提前开展，业务放量等待 B5。2026-10-13 用户最新调整 B7：新管理前端和用户前端均延期，后续由独立 TX-F 项目承接，只本地提交、不推送；当前主仓只开发 Core、Gateway、Node，安装器维持独立仓库。直接清理 Agent/MCP 并取消插件迁移；现有前端和 Theme 兼容保留。[当前范围](admin-only-scope.md)优先于早期方案中的 Agent/MCP 保留约束。

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

B1.5 的 [旧订阅身份旁观读取](../../../contracts/v2/subscription-identity-compatibility-v1.md) 默认关闭，仅在隔离副本请求中核验已认证 User 对应的稳定 Subscription/Subject IDs，永远 `authorizes=false`。原链接认证、query token 优先、11 类原生配置渲染、SS2022、凭据 rotate 和 Node UUID 输出回归均通过；影子缺失或冲突不改变原响应。SQLite 20 通过、2 跳过、418 断言；MariaDB 22 通过、429 断言。固定 Core `846a181` 加本片全 API 765 通过、21 跳过、5927 断言，无失败或警告，详见 [验证报告](subscription-identity-verification.json)。历史链接归属、Provider import 与 live writer fence 仍待实施。

B1.6 的 [Admin User 变更捕获](../../../contracts/v2/legacy-user-mutation-capture-v1.md) 默认关闭，仅在隔离副本的现有编辑事务记录精确 before/after 和 hash chain，并在同事务标记原 mapping `source_changed`。SQLite 21 通过、4 跳过、230 断言；MariaDB 25 通过、271 断言；固定 `12985477` 加九文件全 API 786 通过、25 跳过、6157 断言。真实并发 CAS 只生成一条事件，SIGKILL 提交前回滚全部、提交后保留编辑/事件/map 并可只读验证，详见 [报告](legacy-user-capture-verification.json)。事件未应用到 Terms/周期/资金，其他 writer 未覆盖，整体 migration gate 永远关闭。

B2.1 的 [隔离升级执行器](https://github.com/ANRCM0/TXBoard-Deploy/blob/main/docs/durable-upgrade.md) 已通过 Windows/Linux 33 项 Python 测试、Linux 管理互锁，以及真实 Docker/MariaDB 提交后 SIGKILL 恢复。此阶段不执行 production writer switch；Cluster fencing、完整角色矩阵、生产副本和恢复外部增量仍待验收。

B2.2 商品目录 profile 已推送 Deploy `617cb939589def56e7560db639dd7aa6df3094f5`，Windows/Linux 40 项升级测试通过。真实固定 Core 镜像与 MariaDB 验证回填提交后、journal result 前 SIGKILL，恢复无重复映射、逐页只读对账通过且旧 binary guard 保留。演练为合成隔离副本，未切换生产权威。

B2.3 财务影子 profile 已推送 Deploy `ac9b811afb540206080fe4b6ae08d9feb1423d85`，Windows/Linux 48 项测试和真实固定 Core/MariaDB 中断恢复通过。期初分录提交后丢失 journal result，恢复继续订单游标并完整扫描 user/order；重复 opening、重复 journal 和不平衡 journal 均为零，保留旧 binary guard。仍仅合成隔离副本。

B3.1 的 [隔离用量回执契约](../../../contracts/v2/node-usage/isolated-receipt-current.md) 与 [验证报告](usage-receipt-slice-verification.json) 已交付 Core SQL 持久接收、有限 worker lease/fence、整数原子结算和 TX-Node 显式 v2 spool/replay。全量 API 633 项、4585 断言通过；7 项引擎专测在 SQLite 跳过，Usage v2 MariaDB 31 项和真实并发/MVCC 2 项另行通过。真实 Node↔Core HTTPS、spool 重启及相同回执结算已验证。默认业务仍使用 v1；Terms 转换、Node runtime 边界切分、Provider 租约、v2 WSS、通知消费者和完整恢复门禁尚未完成。

B3.2 的 [Terms/周期边界对接](../../../contracts/v2/node-usage/terms-boundary-current.md) 保留历史 Terms、周期与费率，正常变更不重置用量，跨边界区间在首次 ACK 前拒绝；合法迟到/待结算批次仍写入原周期。基于已提交 B1.4 的固定快照，全量 SQLite 678 项、4892 断言通过，14 项引擎专测跳过；新边界 MariaDB 17 项、176 断言和既有用量 MariaDB 33 项、254 断言另行通过。真实 HTTPS 与 spool 重放通过，代码及依赖 hash 见 [验证报告](usage-terms-boundary-verification.json)。默认 Node runtime 采集、Provider 租约和生产切换仍待实施。

B3.3 的 [Node 连接管理前置切片](https://github.com/ANRCM0/TX-Node/blob/main/docs/connection-lifecycle-current.md) 已推送 `13fb04d87c0a06409aea76fe488e8834028ea2b0`，兑现 sing-box TCP/UDP 用户会话关闭；身份移除后拒绝新准入，末尾累计用量仍由 v1 Tracker 处理。Linux race/vet 的 103 个主用例、18 个子用例通过。未启用 v2 collector 或 lease 策略。

B3.3 的 [Service 本地观察器](https://github.com/ANRCM0/TX-Node/blob/main/docs/runtime-usage-observer-current.md) 已推送 `7eef49229442ef25458a9715caf056fcbdf2bb6f`。默认关闭，复用 v1 同一次采样，持久记录不可归属累计量与生命周期；容量或存储失败停止观察而保持 v1。Linux 全仓 race 376 个主测试、147 个子测试通过，无测试跳过。缓存边界、未采样尾部和恢复旧副本仍未知，未开启 v2 报送。

B3.4 的 [本地原子事件桶基础](https://github.com/ANRCM0/TX-Node/blob/main/docs/local-event-buckets-current.md) 已推送 `974dd1f1d1da0dbb176b073724f6249531f16f07`。同一计数点记录不可归属事件，短锁串行封存、关闭与晚到 callback 保留原连接绑定；默认 Service 不启用。Linux 全仓 race 399 个主测试、159 个子测试通过。仅内存证据、无持久 sink；真实 sing UDP writer 绕过旧计数的覆盖缺口已实证并列为下一步修复前置。

B3.4 的 [UDP writer 漏计数修复](https://github.com/ANRCM0/TX-Node/blob/main/docs/udp-writer-accounting-current.md) 已推送 `22fc0c933d92f509c7f60629a66cbef688005e73`。普通 pinned sing CopyPacket 现在对成功写包恰好调用一个原计数器，保留原 legacy upload 映射，观察器另记 physical download。真实 UDP/cached/失败写与 Close 取消验证通过；完整 internal race 403 个主测试、167 个子测试通过，无跳过。回调限速仍在写入之后，不构成租约门禁；事件桶观察默认关闭。

B3.4 的 [本地事件持久存储基础](https://github.com/ANRCM0/TX-Node/blob/4094fe0f8faee58bf253c342d6d285deedc2aab0/docs/local-event-store-current.md) 已推送 Node `4094fe0f8faee58bf253c342d6d285deedc2aab0`。原 sealed bucket 保留至文件/目录 fsync、不可覆盖发布和独立 head 提交后才释放；单 worker/八 meter 和磁盘容量有界。全 internal race 418 个主测试、194 个子测试通过，五个真实 SIGKILL 点和十三个同步故障点通过。仅显式 consumer，未接入 Service；记录不可归属、无授权、恢复与未落盘尾部未知，不是 Core ACK，也未启用 v2 报送。

B4.1 的 [本地 Provider 合成进程切片](../../../contracts/v2/credential-provider/local-fixture-runtime-v1.md) 默认关闭，Core 先持久 operation，未知结果查询原 ID，私有秘密不进入 Core DTO/SQL。最终 SQLite 20 项、120 断言及 MariaDB 22 项、147 断言通过，固定提交快照全量 API 698 项、5012 断言通过，16 项引擎专测跳过。发行前重新校验当前授权、Subject 和 Terms 边界；此固定可信脚本是同 OS 身份进程，不能视为生产隔离 Provider，尚无 Node 秘密交付、撤销或卸载完成门禁。

B4.2 的 [合成 Provider 撤销与清理](../../../contracts/v2/credential-provider/local-fixture-lifecycle-v1.md) 先提交永久 shadow fact，再执行私有清理；未知结果只查询原 operation。Disable 先阻止发行，有界游标和独立历史核验决定 fixture 清理完成，秘密删除后保留 receipts/tombstones。SQLite Provider 43 项通过、5 项跳过，MariaDB 48 项全部通过；固定 Core `8543668` 加本片全量 API 744 项、5484 断言通过，19 项引擎专测跳过且无警告。Node 收敛、生产 OS 隔离与 PluginManager 卸载仍未实现。

B5.1 的 [本地只读 Internal API](../../../contracts/v2/core-internal-api/current-readonly-slice-v1.md) 由真实 mTLS 边界签发服务证明，Core 重新校验当前 Sanctum actor、User/Admin 角色、Admin path 和订单归属；SQL nonce 防重放、有限服务速率及无秘密审计均已实现。三个只读 operation 默认关闭且仅 local/testing 可启用。真实 Core/MariaDB/TLS 34 项和对抗上游超时 6 项通过；固定 Core `7622cab` 加本切片全量 API 共 737 项（721 通过、16 项专测跳过）、5295 断言通过，详见 [验证报告](internal-readonly-verification.json)。完整 operation 和生产 PKI/私网拓扑门禁仍待实施。

B5.2 的 [Internal User DTO v2](../../../contracts/v2/core-internal-api/current-user-dto-v2.md) 和 Gateway 本机 mTLS 客户端迁移两个现有用户 GET 的可选上游，沿用 Core 当前认证/归属与现有公网 DTO。默认 Native；只有 local/testing 显式启用才调用，错误配置启动失败，TLS/授权/DTO 错误无 fallback。固定 Core `17fbc9a` 加本片组合 API 共 764 项（745 通过、19 项引擎专测跳过）、5509 断言通过；Gateway 467 项、SDK 33 项及 typecheck/build 通过，实际 Core/Gateway mTLS 24 项通过，代码与基线见 [验证报告](internal-user-v2-verification.json)。管理员 Gateway、生产 PKI 和正式 SC-v2 仍未启用。

B5.3 的 [有界 Internal 订单列表](../../../contracts/v2/core-internal-api/current-order-list-v2.md) 新增 `user.order.list` v2，复用 Core 原 owner/filter/order/pagination，并在现有本地 opt-in 下适配 Gateway list/page DTO。固定 Core `e1ed462` 加 Internal 文件全 API 767 通过、21 跳过、5973 断言；固定 Gateway `2a5d2ee` 加本片 492 项、SDK 68 项与 typecheck/build 通过。真实 Core/MariaDB/mTLS 27 项通过，包含分页/过滤/已删除套餐与用户隔离，见 [报告](internal-order-list-verification.json)。分页为实时读取，生产 PKI、其他 Operation 和 Admin Gateway 仍待实施。

B6.1 的 [实验 metadata codec](../../../contracts/v2/secure-channel/metadata-codec-prototype-v1.md) 已推送 Gateway `6adea76c5259a43556dee611e7b932a72d008a3c`，提供独立 SDK 实验 subpath 与 Python/Node/Chromium 固定字节向量。固定 Linux 快照 Gateway 425 项、SDK 40 项和 typecheck/build 通过；实际 Chromium 6 项字节/hash检查、5 项拒绝检查通过。没有加密实现或业务路由，正式响应 key/nonce 构造、独立审查、共享 replay 与业务互操作仍待实施。

B6.2 的 [离线加密实验](../../../contracts/v2/secure-channel/offline-crypto-experiment-v1.md) 已推送 Gateway `2a5d2ee2dd01a7568a0b121236b158fa5339fd8e`。独立实验 subpath 实现 HPKE 请求、域分离 Exporter 响应和先占用后执行的一次性闭包。固定 Linux 源码 Gateway 467 项、SDK 68 项及 typecheck/build 通过，实际 Chromium/Node 四组双向互通及固定向量通过。仅每个闭包有一次性保证；重复打开同一请求仍可派生相同响应材料。共享 owner、防重放、密钥信任/吊销、业务授权与正式密码审查未完成，尚无网络消费者。

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

### B1.7 隔离副本充值结算捕获

`WalletRechargeService::settleVerified` 在原 Recharge → User 事务锁序中记录已验签充值的余额变化，保持本金、手续费、重复回调及原 ACK 语义。仅 local/testing 显式启用；固定 payment actor domain，不记录回调、商户密钥或请求正文。同事务使影子映射失效，证据尚未应用到 Subscription/钱包投影，完整写入覆盖仍待补齐。

固定基线 `a51d572`：充值 SQLite 13 通过 / 4 跳过 / 177 断言，MariaDB 17 / 0 / 222；原 Admin 捕获 SQLite 21 / 4 / 230，MariaDB 25 / 0 / 271；全 API 799 通过 / 29 跳过 / 6334 断言。独立并发、提交前后 SIGKILL、开启异常参数后的日志保密检查通过。[源码及验收证据](legacy-recharge-capture-verification.json)。

### B5.4 用户统计与支付方式只读接入

新增 `user.dashboard.get` / `user.payment-methods.list`，委托原 Account/Billing 查询；Gateway 保留公开 DTO，支付方式仍要求当前非 Admin User，不初始化 Provider 或重算收费。local/testing 显式 mTLS opt-in，生产入口仍关闭。

最终组合固定基线 Core `79a3854`、Gateway `6ea649b`：Core API 803 通过 / 29 跳过 / 6422 断言，Gateway 512、SDK 68，typecheck/build 及 29 项实际本机 mTLS 联调通过。[契约](../../../contracts/v2/core-internal-api/current-user-display-v2.md)及[验收](internal-user-display-verification.json)。其余 Operation、生产 PKI、Admin 接入仍待完成。

### B6.3 共享响应所有权隔离实验

Gateway `6ea649b` 仅新增实验测试目录：19 单测、14 真实 Redis、1 个实际 TTL 检查通过，64 客户端/4 worker 下观察到一次响应加密。证据只适用于连续主节点且不丢失 claim 的假设；Redis 检查与 WebCrypto 非原子，未知丢失/恢复/HA 仍阻断所有真实网络或业务消费方。[合同与范围](../../../contracts/v2/secure-channel/shared-owner-experiment-v1.md)。

### B5.5 订阅摘要与公告读取

新增 `user.subscription-summary.get` / `user.notice.list`，委托原 Account subscription 与 ContentReader；闭合 DTO 排除私有 URL/凭据，缺失套餐仍为 409，公告原可见性/排序/内容/分页保持。Gateway 本地 mTLS opt-in 保留现有公开 DTO；当前非 Admin User 授权不变。

固定 Core `60e6eca` / Gateway `31bde17`：Core API 806 通过 / 29 跳过 / 6482 断言，Gateway 526、SDK 68，typecheck/build 及 35 项真实 mTLS 联调通过。[契约](../../../contracts/v2/core-internal-api/current-user-content-v2.md)及[证据](internal-user-content-verification.json)。未迁移匿名认证/写入、Admin 或生产 PKI。

### B5.6 订单状态只读接入

新增 `user.order-status.get`，委托原 Account order 查询，保留订单归属、0–4 状态及套餐已删除时的状态读取。Gateway 本地 mTLS opt-in 映射为现有 `{tradeNo,status}`，闭合 DTO 核对请求 trade_no，失败拒绝且无 Native 回退。至此现有 Gateway v1 受保护只读操作均可选择本地 Internal 上游；默认仍为 Native。

固定 Core `dd3b4fd` / Gateway `257ff7e`：全 API 808 通过 / 29 跳过 / 6544 断言，Gateway 527、SDK 68、typecheck/build 和 35 项实际 Core/MariaDB/TLS1.3 联调通过。[契约](../../../contracts/v2/core-internal-api/current-order-status-v2.md)及[证据](internal-order-status-verification.json)。完整 Internal API、写入、Admin、生产 PKI 和 SC-v2 仍待验收。

### B1.8 隔离副本订单履约事务捕获

`OrderService::open` 在原 PROCESSING Order → User 锁序内记录 User 变化，并与 COMPLETED 状态、事件和 mapping 失效原子提交。原手续费、预扣/折抵、日历、流量重置、支付 ACK 和队列重试保持；捕获异常仅用固定无上下文日志。Order ID 是 system work-item reference，不推定 Admin 或支付身份。

证据明确限定为 `transaction-only`：提交后 Observer/openEvent/hooks 的变化仍在原时序执行，会被核对识别为来源断链；未捕获的建单预扣同样阻断后续 append。已完成订单重试不恢复丢失的提交后 callback，不声称完整 writer 覆盖或影子投影。

固定 Core `79a3854` 加五文件：履约 SQLite 14 通过 / 5 跳过 / 306 断言，MariaDB 19 / 0 / 355；原路径 SQLite 45 / 8 / 453，原 Admin/充值 MariaDB 42 / 0 / 493；全 API 815 / 34 / 6686。独立并发、旧 MVCC、提交前后 SIGKILL、真实 Redis 三次重试与日志保密通过。[契约](../../../contracts/v2/legacy-user-mutation-capture-v1.md)及[源码和日志证据](legacy-order-fulfillment-capture-verification.json)。

### B4.3 合成 Provider 的插件停用接入

固定可信 `credential_provider_fixture` 插件显式绑定一份 registration/epoch；PluginManager 按 plugin → binding → registration → lifecycle 锁序，在同一事务提交 plugin disabled 和停止发行。私有清理在事务外有界执行并恢复原 operation；停用后不允许重启、替换或丢失 binding 后重新注册，普通 v1 插件不查询 fixture SQL。

SQLite 18 通过 / 2 跳过 / 167 断言，专用 MariaDB 20 / 0 / 182；固定 Core `60e6eca` 加切片全 API 821 / 31 / 6589。实际 Module adapter、双状态 SQL 回滚、发行竞争、丢 ACK 与提交后 SIGKILL 通过。已加载 PHP 的跨 worker 中断、Node 收敛、租约和待交付/结算尚未实现，因此即使 fixture 清理完成，卸载/删除仍拒绝并保留资产与历史。[契约](../../../contracts/v2/credential-provider/local-fixture-plugin-boundary-v1.md)及[精确源码和验收证据](../../../api/tests/Fixtures/CredentialProviderPlugin/verification.json)。

### 原订单幂等过期键修复

建单预扣回归发现原 mutable SQL builder 在删除旧过期记录后保留其 ID 条件，导致替换键新建单成功而 claim 未标记 succeeded。各 lookup/delete/update 现在从干净 base clone，原 User 事务锁、金额算法、TTL、错误和 prune 不变；立即同键重放返回新原订单，不再次扣款。

固定 Core `1d27469` 加两文件：SQLite/MariaDB 各 8 通过 / 270 断言，相关资金/优惠回归 24 / 441，100 个真实独立会话检查 1 / 106；全 API 840 通过 / 36 跳过 / 7030 断言。[精确源码与日志证据](native-idempotency-fix-verification.json)。

### B5.7 隔离订单创建 mTLS 接入

新增固定 `user.order.create` version1，额外要求严格写入开关和隔离副本标记。原 BillingController/OrderService 继续决定身份、预检、价格、优惠、余额和 SQL 幂等；Gateway 只绑定原业务键和购买参数，接受 HTTP201 的闭合写入回执。默认关闭、无自动重试和 Native 回退；原生公开建单行为不变。

提交后审计失败、超时或丢响应可能返回错误而订单已经提交，恢复必须使用新 transport request 和原业务键/参数。原 TTL、当前 actor、Plan 和 owned Order 可访问性仍约束重放。实际 TLS 并发同键只预扣一次；过期键替换后立即重放不再扣款；故意审计失败503后独立 SQL 检查确认一份已提交订单及 succeeded claim，再用原键恢复同一 trade。

固定 Core `422e85c` / Gateway `ee1cec6` 加十四文件：全 API 845 通过 / 36 跳过 / 7085 断言，Gateway 529、SDK 68、typecheck/build 和40项真实 TLS1.3/MariaDB 联调通过。[契约](../../../contracts/v2/core-internal-api/local-user-order-create-v1.md)及[精确源码和日志证据](internal-order-write-verification.json)。未加入 checkout、履约或影子账本投影；Admin、生产 PKI 与 SC-v2 消费路径仍待验收。

### B1.9 隔离副本建单预扣事务捕获

在原 User/SQL幂等创建闭包内，购买预检之后、before hook之前开始捕获，两个after hook之后append；余额、优惠码使用次数、Order、幂等claim、事件和mapping失效一同提交或回滚。固定 `order.create` policy只记录实际locked owner User ID；原Native角色行为、金额算法、锁序和成功重放保持。成功同键重放不执行captureSQL，即使后来capture配置或表不可用也返回原Order。

建单预扣现在能与Admin、充值和稳定履约组成连续User证据链。B1.8中未捕获建单的历史负例已改为本批覆盖检查；取消退款、提交后Observer/hooks等未覆盖writer仍显式阻断，未伪造Order/Commerce、Terms、用量或资金投影。免费/无余额且User allowlist未变的建单不会制造User事件。

专项固定Core `422e85c` 加五文件：SQLite13通过/4跳过/440断言，MariaDB17/0/488；旧路径SQLite68/13/1035，MariaDB62/0/854。最终新快照基于已提交的 `342c12c`，包括Internal建单接入：全API859通过/40跳过/7531断言。真实独立session竞争、旧MVCC、提交前后SIGKILL、原键恢复和args0保密回滚均通过。[契约](../../../contracts/v2/legacy-user-mutation-capture-v1.md)及[源码与六份原始日志核验](legacy-order-creation-capture-verification.json)。capture继续默认关闭，完整writer覆盖及生产切换仍未验收。

### B5.8 公共站点与套餐展示 mTLS 读取

新增固定 `public.site-config.get` / `public.plan.list` version1，仅允许没有 Authorization 的匿名请求，仍要求 user-gateway 服务证书和 proof。Core/Gateway 各有严格默认关闭开关，限 local/testing；原站点公开字段、可信主题扩展、套餐可见性/容量/排序和价格展示保持。匿名请求清理缓存身份，审计使用 actor_id=0；保护接口、支付方式与 User/Admin 认证没有扩大。

固定 Core `a7e73b1` / Gateway `8c03a78` 加十六文件：专项13通过/166断言，全API872通过/40引擎专测跳过/7697断言，Gateway596、SDK68、typecheck/build及48项真实MariaDB/TLS1.3联调通过。边界检查先清公共缓存，另验证一次MISS/HIT只生成一条审计；缓存仍遵守原TTL，不作为当前授权事实。数值溢出、超大响应、证书拒绝和断线均在cache miss时失败而无Native回退。[契约](../../../contracts/v2/core-internal-api/local-public-display-v1.md)及[精确源码和日志证据](internal-public-read-verification.json)。匿名登录/注册/邮件、Admin、生产PKI与SC-v2仍待实施。

### 邮件诊断保密前置

原MailService发送try/catch的caught Exception现在只记录固定 `MAIL_SEND_FAILED` 字符串，并把同一code写入原returned/SQL error字段；不附原message、previous或args trace。原成功模板、私有收件人与subject/config metadata、SendEmailJob release/tries/timeout和Exception捕获范围保持。try外、SQL日志保存、非Exception Error及可信插件自己的日志仍在原边界，未承诺全邮件路径保密或送达。

固定Core `d3ae864` 加三文件：真实LaravelMailer+本地Array/throwing transport专项6通过/89断言，全API925通过/50引擎专测跳过/8620断言。测试先证明args0异常及previous/trace确有合成秘密，再核对三个诊断目的地固定code；成功Blade/DB渲染与原job release决定保持。[当前契约](../../../contracts/http/mail-diagnostics-current.md)及[源码和日志](mail-diagnostics-verification.json)。这是下一条隔离邮件Internal消费者的前置，尚未新增邮件Operation或外部SMTP投递。

### B4.4 合成 Provider 的本地租约候选

[当前契约](../../../contracts/v2/credential-provider/local-fixture-lease-candidate-v1.md)默认关闭，只把真实发行回执、ProviderBinding、Subject、Terms、周期、tariff 和 source 固定为非秘密证据；持有当前行锁后校验禁用、撤销、恢复代际与已结算配额，候选最长 60 秒并受各已知结束边界约束。相同 key 的历史重放保持原证据，当前校验的拒绝或过期状态提交保存。尚未生成授权快照、交付 Node 或授予准入。

固定 Core `1cc6bf9` 加九文件：SQLite 专项 11 通过、14 项引擎条件跳过，MariaDB 专项 25 全通过（402 断言），含独立会话竞争、提交后 SIGKILL 重放及双向写入屏障；全 API 936 通过、64 跳过、8894 断言。实际迁移外键长度和测试异常分类问题已修正，失败尝试保留于[验证报告](provider-lease-candidate-verification.json)。仅验收本地可信 fixture，生产 Provider 隔离及 Node 收敛门禁仍待完成。

### B3.4 Service 自动本地事件消费者

Node [当前契约](https://github.com/ANRCM0/TX-Node/blob/main/docs/local-event-runtime-current.md)已推送 `b6bd28d`。默认关闭，仅一个Service取得永久进程owner、一个异步worker和有界meter/存储；旧kernel Close及cancel后开始5秒单tracker monotonic退休，迟到callback保留原绑定，2秒shutdown超时保留原FD/flock并拒绝替代worker。原v1累计用量及限速保持，记录仍不可归属、恢复/尾部未知，无v2上报或租约准入。

固定Node `4094fe0` 加16个Go文件：四受影响包Linux race173主测试/68子测试通过，无失败/跳过，同四包vet通过。实际Service.Run、原ConnTracker、内存TCP/loopbackUDP、同步停滞/immutable fsync/退休/容量和原进程死亡恢复回归均覆盖，不宣称live VLESS或面板互通。[冻结源码与日志证据](https://github.com/ANRCM0/TX-Node/blob/main/docs/local-event-runtime-verification.json)。首次两次审批未执行，用户授权正常重申请后专项与必要回归获批通过。

### B1.11 隔离副本佣金转余额事务捕获

在原CommissionController单次User事务内，原最低金额/资金拒绝之后、余额变化之前开始捕获，save后append；两账户变化、事件和mapping失效共同提交或回滚。固定 `commission.transfer` policy只引用实际owner User，原金额、最低额继承/rounding、角色和响应保持。原请求没有幂等键，足够资金时相同POST可合法划转两次；未知提交不能被当作安全重试，事件未应用到资金/Terms/用量投影。

专项Core `ceea19b` 加四文件：SQLite14通过/4跳过/300断言，MariaDB18/0/348；旧五类writer和原路径SQLite107/23/1837、MariaDB100/0/1727。最终新快照基于已推送 `d2c3026`：全API920通过/50引擎专测跳过/8537断言。真实one/two winner、旧MVCC当前充值tail、SIGKILL提交前后、bearer args0保密回滚及旧hash/context通过。[契约](../../../contracts/v2/legacy-user-mutation-capture-v1.md)及[两个冻结来源、源码和六份原始日志](legacy-commission-transfer-capture-verification.json)。捕获默认关闭，完整writer覆盖与生产切换仍未验收。

### B5.9 隔离原生账户登录 mTLS 接入

新增固定 `auth.login` version1，Core/Gateway 各有额外严格默认关闭开关，要求local/testing和隔离副本。委托真实AuthLogin、AuthController、LoginService及Sanctum，保留密码空格、旧hash算法、封禁、原密码预算和10次domain/IP限流；技术审计actor为0。只接受确认关闭的CAPTCHA策略，缓存/已加载策略漂移或读取失败拒绝，不访问外部验证码服务。原生Admin账户仍可按原登录语义发行会话，但受保护User操作拒绝Admin，未开放Admin Gateway。

固定Core `ceea19b` / Gateway `19cf371` 加二十文件：专项19通过/237断言，全API906通过/46引擎专测跳过/8237断言，Gateway671、SDK68、typecheck/build及57项真实MariaDB/TLS1.3联调通过。首次测试定位并修复内层Laravel路由提前处理异常的问题：本地执行器先返回安全策略/异常结果，参数trace不进入全局报告，原生处理不变。真实TLS保留原伪造代理头403，提交后审计失败503可留下已提交的未知会话，现有会话撤销已实证；无自动重试、token receipt或原token恢复。[契约](../../../contracts/v2/core-internal-api/local-isolated-login-v1.md)及[源码、两快照和原始日志证据](internal-isolated-login-verification.json)。注册/邮件/恢复、真实CAPTCHA、生产PKI与SC-v2仍待验收。

### B1.10 隔离副本取消退款事务捕获

在原PENDING Order → User → Coupon事务内记录实际 `balance_amount` 退款，状态、退款、优惠码名额、事件和mapping失效共同提交或回滚。取消采用exclusive current User读取，旧四类writer保持shared current读取；固定Order工作项actor不推定发起User/Admin或资金来源。原取消false/409、支付状态竞争、两小时任务和零退款路径保持，capture继续默认关闭。

专项基线 `a7e73b1` 加六文件：SQLite15通过/6跳过/304断言，MariaDB21/0/386；既有SQLite87/17/1543、原四类MariaDB79/0/1341。新建最终快照基于已提交 `e29db554`：全API887通过/46引擎专测跳过/8000断言。独立session竞争、旧MVCC、提交前后SIGKILL、args0日志保密和原生重试均通过。[契约](../../../contracts/v2/legacy-user-mutation-capture-v1.md)及[精确源码、两快照和五份原始日志](legacy-order-cancellation-capture-verification.json)。pre-fence/提交后hooks和Observer仍能造成显式来源断链，不宣称全局无死锁或完整writer覆盖；未应用任何影子投影。

### B4.5 合成 Provider 初始绑定与离线签名快照

[当前契约](../../../contracts/v2/credential-provider/local-fixture-authorization-snapshot-v1.md)要求真实合成发行回执和未过期候选，在同一事务内把精确 pending Subject 初始绑定到该发行结果，并保存不可变 RSA3072/RS256 签名证据。签名失败、晚到过期、配置变化或 SQL 失败会同时回滚绑定与快照。当前校验锁读安装身份、业务版本、配额和撤销状态，终态拒绝持久保存；同键历史重放始终返回原始签名字节。独立公钥 CLI 不启动 Laravel，也不连接数据库或读取私钥。

固定 Core `e33ba63` 加十三文件：SQLite 专项 41 通过、29 项引擎条件跳过、703 断言；MariaDB 70 项全部通过、965 断言，含原 B4.4 的 25 项和新增 45 项；全 API 966 通过、79 跳过、9323 断言。独立进程竞争、双向变更屏障、SIGKILL、安装身份损坏和原始历史重放通过，早期 fixture 失败与环境修正保留于[验证报告](provider-authorization-snapshot-verification.json)。默认关闭，仅 local/testing 合成数据；`admission_granted=false`，未产生 Node 授权、秘密交付或生产 PKI 验收。

### B1.12 隔离副本独立定时流量重置捕获

仅在方法入口没有外层事务、来源为 cron 且要求重新检查到期条件时，原 User 独占锁后开始捕获，原计数重置、日志和 after hook 之后追加事件；SQL 状态、事件和 mapping 失效共同提交或回滚。固定工作项引用实际 User，保留原计划读取、调度、手动/强制重置与嵌套订单行为。捕获继续默认关闭，不应用 Terms、用量或财务投影；缓存、hook 和提交后 Observer 的外部影响仍在原边界。

专项基于 Core `d3ae864` 加四文件：SQLite 19 通过、5 跳过、357 断言，MariaDB 24 全通过、414 断言；旧六类 writer 和原生路径 SQLite 127 通过、27 跳过、2185 断言，MariaDB 118 全通过、2075 断言。最终固定 `e33ba63` 加同四文件全 API 955 通过、69 跳过、9251 断言；独立竞争、陈旧快照、提交前后 SIGKILL 及旧链验证通过。[契约](../../../contracts/v2/legacy-user-mutation-capture-v1.md)和[冻结来源、日志与验收范围](legacy-cron-traffic-reset-capture-verification.json)保留实际证据；完整 writer 覆盖和生产切换门禁仍未完成。

### B5.10 隔离内存邮件验证码 mTLS 接入

固定 `auth.email-code.send` version1 默认关闭，仅 local/testing 隔离副本可启用。Core 复用原输入校验、匿名限流、收件策略、冷却、验证码生成与邮件 Job，另外要求真实同步队列和 ArrayTransport；只允许 `@example.test` 收件地址，无外部 SMTP。Gateway 邮件开关独立于登录/注册，要求 HPKE 和 Redis，以 mTLS 调用 Core；没有访客 Authorization、自动重试或 Native 回退。原成功只说明执行已返回，审计或缓存阶段失败可留下已发送的未知结果。

固定 Core `e33ba63` 加十文件：专项 19 项通过、336 断言，全 API 955 通过、64 项引擎专测跳过、9230 断言。固定 Gateway `dfd6122` 加邮件切片：Gateway 713 项、SDK 68 项、typecheck/build 通过；真实 SDK/HPKE/Redis/mTLS/原邮件链路 15 项和原有 57 项联调均通过。邮件正文与缓存六位验证码精确一致、300 秒有效期和 60 秒冷却、匿名审计、共享限流、重放拒绝、未知结果不重试均已实证。[契约](../../../contracts/v2/core-internal-api/local-isolated-email-code-v1.md)与[源码及分批验收报告](internal-isolated-email-code-verification.json)保留 fixture 修正前的失败证据；生产邮件、真实 CAPTCHA、正式 SC-v2 与完整账户流程仍待验收。

### 2026-10-13 两仓迁移前收尾

当前三条切片已分别提交并推送：B4.5 Core `15a5feb`、B1.12 Core `40a2733`、B5.10 Core `b8d58bd` / Gateway `4d36e3b`。随后以已提交 Core `b8d58bd` 的完整 Git archive 新建快照，不叠加工作区文件；组合 API 1004 项通过、84 项 MariaDB/独立进程专测跳过、10016 断言，无失败、警告或 risky。适用的双引擎专项和真实跨仓联调证据保留在各切片报告中，[收尾报告](pre-monorepo-closure-verification.json)另记录组合源码、依赖和日志哈希。

本轮收尾完成后，按用户决定准备两个仓库：运行代码包含 Core、Node、Gateway 与现有前端/SDK/契约，部署代码包含 Deploy 和 Node Installer。此次收尾未移动仓库、改写历史或合并运行职责；迁移时保留组件边界、构建产物、版本与旧安装入口兼容。B1–B8 的未完成生产门禁继续生效，不因仓库收尾完成而关闭。

### B1.13 隔离副本标识流量批次结算捕获

原 `TrafficBatchJob` 增加固定 `traffic.batch.settle` policy，在 Server → 有序 User 原锁序内记录精确计费后端点；批次去重、全体用户及节点统计、事件和 mapping 失效共同提交或回滚。原倍率/floor/溢出及 queued 语义保持，成功批次重放在捕获配置/表失效时仍跳过。显式捕获路径的统计读取使用当前共享锁，修复已实证的陈旧 MVCC 唯一键冲突；关闭路径保留原批量读取。死锁重试的 Redis 用户列表仅取成功事务，提交后丢失的通知仍未知。

固定合仓基线 `3ca3940` 加三个精确 PHP 文件：最终全 API 1017 通过 / 88 引擎专测跳过 / 10179 断言；其中专项 SQLite 13 通过 / 4 跳过 / 163 断言。MariaDB 专项 17 全通过 / 205 断言，包含真实独立会话、陈旧快照和提交前后 SIGKILL。早期数据库长度问题与 fixture 失败保留在[源码及验收报告](legacy-traffic-batch-capture-verification.json)，复现方式见[测试 fixture](../../../api/tests/Fixtures/LegacyTrafficBatchCapture/README.md)，行为见[契约](../../../contracts/v2/legacy-traffic-batch-capture-v1.md)。默认关闭，不应用 Subscription/Terms/用量/资金投影；无标识旧流量与其他未覆盖 writer 仍阻断完整迁移门禁。

### B1.14 隔离副本无标识旧流量写入捕获

原 `TrafficFetchJob` 默认路径保留单次 SQL 更新；显式开启捕获时，每个实际 User 的独占当前读、原倍率/数据库赋值、事件和 mapping 失效共同提交。固定 `traffic.fetch.apply` policy 只引用 queued Server 工作项，不推定 Node 认证。相同输入仍累计两次；后一个 User 失败保留前一个提交，原统计 Job/Redis、单次尝试/20秒超时和未知结果不可安全重放语义均保持。MariaDB 原 INT 舍入逐行记录，SQLite 非整数证据拒绝而不重算价格或用量。

固定已推送 B1.13 `dc33ec1` 加四个精确 PHP 文件；专项 runtime/test 与 v1 验收 hash 相同。SQLite 专项 8 通过 / 2 跳过 / 78 断言；MariaDB 10 全通过 / 98 断言，包含独立会话和 User 提交前后 SIGKILL。另将既有邮件 deadline 用例改为在真实发送回调中跨过原签名期限并断言进入，保留前次失败日志。全 API 1025 通过 / 90 引擎专测跳过 / 10258 断言。[契约](../../../contracts/v2/legacy-traffic-fetch-capture-v1.md)、[fixture](../../../api/tests/Fixtures/LegacyTrafficFetchCapture/README.md)和[源码/日志报告](legacy-traffic-fetch-capture-verification.json)保留精确复现信息。两类旧流量 User 端点现有固定捕获策略；完整 writer 覆盖、历史周期归属、影子投影、无标识 pending 排空与生产权威切换仍待完成。

### B1.15 隔离副本返佣与礼品卡奖励捕获

原 `CheckCommission` 每 Order 事务增加固定 `commission.distribute` 捕获，启用时独占读取实际 inviter；原百分比、三级遍历、余额/佣金模式、原日志、Order 统计及候选重放语义保留。`GiftCardService` 原兑换事务分别捕获 primary/inviter 奖励，覆盖余额、流量、有效期、嵌套重置和套餐赋值；同 User self-invite 生成连续端点。原 code/template/User 锁和使用次数维持，SQL/捕获失败共同回滚。

固定 B1.14 `9cc8c0c` 加四个 PHP 文件，SQLite 15 通过 / 3 专测跳过 / 162 断言；MariaDB 18 全通过 / 193 断言，包括真实 stale 返佣候选再次发放、single-use gift 并发仲裁及提交前后 SIGKILL。全 API 1040 通过 / 93 引擎专测跳过 / 10420 断言。最终审阅补充跨 policy 票据拒绝和原 code 保存 false 被忽略的真实行为，前次失败及成功版本均保留在[报告](legacy-reward-capture-verification.json)，[契约](../../../contracts/v2/legacy-reward-capture-v1.md)及[fixture](../../../api/tests/Fixtures/LegacyRewardCapture/README.md)说明边界。

未引入 live ledger 或幂等性：原返佣缺少 Order 当前锁/状态重查，stale work 可以再次发放；原 `markAsUsed` false 不阻止奖励提交，可能再次兑换；expiry/plan 后的 Observer 仍独立写 next_reset_at 并产生明确 source gap。这些证据不能证明整阶段完成，后续继续覆盖 Observer/其他 writer、投影和权威切换门禁。

### B1.16 隔离副本 Observer 重置时间跟进捕获

独立 `LEGACY_OBSERVER_CAPTURE_ENABLED=false` 开关在现有 local/testing 隔离捕获之上，允许 `UserObserver` 原 after-commit 重算使用单独 User 独占事务记录 `user.schedule.observer`。不移动原业务提交点；礼品卡/履约端点后可追加连续 schedule 事件，原日历/普通 Plan 读取、withoutEvents 与 Node dispatch 仍保留。新事务失败只回滚 schedule，不取消此前业务提交；未知响应与 Node 通知缺口仍待恢复机制。

固定 B1.15 `cb16180` 加四个 PHP 文件；SQLite 10 通过 / 2 专测跳过 / 100 断言，MariaDB 12 全通过 / 125 断言，包含 stale model 等锁后当前读和真实跟进提交前后 SIGKILL。全 API 1050 通过 / 95 引擎专测跳过 / 10520 断言。查看[契约](../../../contracts/v2/legacy-observer-schedule-capture-v1.md)、[fixture](../../../api/tests/Fixtures/LegacyObserverCapture/README.md)和[验证报告](legacy-observer-capture-verification.json)。旧捕获单独启用仍保持历史 source gap；原始 User insert、其他独立 writer、整个迁移与投影门禁未因此完成。

### B1.17 隔离副本独立流量重置捕获

独立 `LEGACY_STANDALONE_RESET_CAPTURE_ENABLED=false` 开关只覆盖原 `performReset` 在 SQL level zero 且非 due-cron policy 的调用。固定 `traffic.reset.standalone` 记录当前 User 原重置端点；manual/auto/api/user_access/order/gift_card/forced-cron 标签是可信 PHP 来源，不能推定调用身份。原 Admin 鉴权/路径、locked eligibility、日历/日志/metadata/cache/hook 和 false/409 语义保持；nested Order/gift/hook reset 仍由外层 ticket 捕获，未增加内部冲突事件。

固定 B1.16 `5a8f06d` 加四个 PHP 文件；SQLite 10 通过 / 2 专测跳过 / 202 断言，MariaDB 12 全通过 / 229 断言，包含 stale User 等锁后当前读、重复手动重置和真实提交前后 SIGKILL。全 API 1060 通过 / 97 引擎专测跳过 / 10722 断言。查看[契约](../../../contracts/v2/legacy-standalone-reset-capture-v1.md)、[fixture](../../../api/tests/Fixtures/LegacyStandaloneResetCapture/README.md)和[验证报告](legacy-standalone-reset-verification.json)。原 post-commit order reset 可独立接入连续链；未知结果重放仍是另一重置，force/fix-null/initial schedule、其他 writer、v2 周期投影与通知恢复仍待完成。

### B1.18 隔离副本显式重置时间修正捕获

原 `reset:traffic --fix-null/--force` 只在新增 `--capture-schedule` marker 下使用每 User 独占事务、当前资格重查和固定 `user.schedule.repair` 证据；原候选遍历、日历、save false 和部分成功 exit 行为保持。只修正 next_reset_at，不能推定 counter reset、原始创建、CLI 授权或全局停写。无 marker 路径零捕获 SQL；失败 User 回滚，已完成 User 保留，固定日志不带秘密上下文。

固定 B1.17 `e47a775` 加四个 PHP 文件；SQLite 7 通过 / 2 专测跳过 / 73 断言，MariaDB 9 全通过 / 94 断言，包含等锁后 fix-null 已不符合条件跳过及真实修正提交前后 SIGKILL。全 API 1067 通过 / 99 引擎专测跳过 / 10795 断言。查看[契约](../../../contracts/v2/legacy-schedule-repair-capture-v1.md)、[fixture](../../../api/tests/Fixtures/LegacyScheduleRepairCapture/README.md)和[验证报告](legacy-schedule-repair-verification.json)。其余 creation/deletion/plugin/initial schedule writer、影子投影和生产权威切换仍未完成。

### B5.11 隔离注册 mTLS 接入

[当前契约](../../../contracts/v2/core-internal-api/local-isolated-registration-v1.md)加入固定 `auth.register`、独立默认关闭的 Core/Gateway 开关与匿名技术 actor。Core 复用原 `AuthRegister`、共享原生限流、`RegisterService`、`UserService`、`AuthService`；请求内 CAPTCHA 适配器验证已知停用及 SQL/cache/loaded 注册策略一致，保留邀请码、验证码、白名单、试用、钩子和原生会话签发。Guest Operation 拒绝任何 Authorization，包括空头；只有合成 `@example.test` 账号可进入本切片。

Gateway 只开放已存在的 HPKE 注册路由，必须有 nonce store 与 Redis 限流；不开放邮件码或完整 account workflows。消费者验证 HTTP201 和封闭 DTO；选定 Internal 后无重试或 Native 回退。邀请码先消费后保存失败、用户先创建后钩子失败、审计/期限隐藏成功等原生部分提交行为已验证，不能视为全事务或注册幂等。

固定快照 API 全量 **1080 通过、99 引擎/独立进程专测跳过、10983 断言**，Gateway **755**、SDK **68** 项以及类型检查/构建通过。真实 MariaDB、Redis22、TLS1.3、SDK/HPKE 完整链路 **9** 项通过，Native 回退调用为零；测试布局 4 项和文档路径检查通过。[证据报告](internal-isolated-registration-verification.json)保留先前夹具/断言失败和最终源码、依赖、日志 hash。无生产注册或全 B5/B6 放量。

### B5.12 隔离密码恢复 mTLS 接入

[当前契约](../../../contracts/v2/core-internal-api/local-isolated-password-reset-v1.md)加入固定 `auth.password.reset`、独立默认关闭的 Core/Gateway 开关与匿名 actor。Core 复用原 `AuthForget`、共享原生 10/min 限流、15 秒邮件锁、三次验证码错误预算、密码更新与全部 Sanctum 会话撤销；原代码删除返回值、钩子和事务边界保持。仅合成 `@example.test` 账户允许进入，已启用或未知 CAPTCHA 不调用外部 Provider。

Gateway/SDK 增加显式 HPKE 密码恢复 Operation、独立 Redis 限流和封闭 `{reset:true}` DTO，不发送访客凭据。重复密文与其他 Operation AAD 均拒绝，选定 Internal 后无重试或 Native 回退。密码已提交后 token 删除、钩子、code 删除、审计或响应失败可能形成未知结果；租约锁不构成 SQL fence，本片没有补偿或恢复会话。

固定快照 API 全量 **1096 通过、99 引擎/独立进程专测跳过、11206 断言**，专项 **16/16、223 断言**。Gateway **799**、SDK **68** 项、类型检查和构建通过；真实 MariaDB、Redis22、TLS1.3 与 SDK/HPKE 链路 **9** 项通过，Native 回退调用为零。快照布局 4 项、原生表名和文档路径检查通过。[证据报告](internal-isolated-password-reset-verification.json)保存源码、依赖、日志 hash 以及失败夹具记录。生产账户流程与完整 B5/B6 仍待验收。

### B5.13 隔离 User 会话 mTLS Operation

[当前契约](../../../contracts/v2/core-internal-api/local-isolated-sessions-v1.md)增加固定 `user.session.list/revoke/logout`、默认关闭的独立 Core 开关与隔离副本门禁。只接受当前 Sanctum User Bearer 和合成账户；管理员、封禁、过期/撤销凭据、其他 token principal、错误服务和 caller owner 均拒绝。列表委托原生 owner query 并限制为 101 行，超过 100 拒绝；指定撤销保留 owner-scoped delete 与缺失/重复 404，登出只删除当前凭据。

原生列表保持现有行为，Internal 返回 decimal session ID 与现有非秘密元数据，不包含 token/hash 或其他用户 session。原生 false-delete、已准入请求与事后撤销竞争保持；审计/期限/丢失响应可以隐藏已提交撤销，不添加重试、补偿或新会话。专用适配器在路由异常报告前安全渲染 SQL/领域错误。Gateway/SDK 对外会话路由尚未启用。

固定 Core 全量 **1112 通过、99 引擎/独立进程专测跳过、11324 断言**；专项 **16/16、118 断言**。真实 TLS1.3/MariaDB **9** 项验证 owner 列表、服务/角色/期限拒绝、跨用户/重复撤销、SQL 删除失败与审计未知结果；最终当前用户零 session，其他用户保持一枚。运行时代码在全量与联调快照 hash 一致，PHP lint、原生表名和文档检查通过，见[证据报告](internal-isolated-sessions-verification.json)。

### B6.4 独立响应随机值离线候选

现有 B6.3 在 Redis 记录丢失后可能重新分配响应 owner，旧确定性响应材料不能因此进入业务通道。本片[候选契约](../../../contracts/v2/secure-channel/response-salt-experiment-v1.md)采用 RFC9458 的随机响应 nonce/HKDF 派生方式，并用独立 HPKE info/exporter/AAD 标签区分原实验；没有自行开启网络消费者。每枚响应由 recipient CSPRNG 生成 32 字节 nonce，不接受 caller override，仍遵守单次 closure 和失败后不重建上下文。

固定快照 Gateway **799**、SDK **105** 项、类型检查和构建通过，新专项 **37** 项；同一请求的 32 个 recipient 产生 32 组实测不同响应 salt/key/nonce。旧协议向量与主 SDK 字节不变，独立 Python 验证摘要/HKDF/AAD 五项，真实 Chromium142/Node 旧新向量与双方向各四次互通通过。CI 已加入 Python 与新浏览器验收，[证据报告](../../../gateway/docs/sc-v2-response-salt-verification.json)保留失败统计与源码/依赖/日志 hash。该片不证明 RNG 健康、共享业务重放、恢复安全或正式密码审查；完整 B6 与网络消费者仍待实施。

### B5.14 Gateway/SDK 当前会话消费者

[当前契约](../../../contracts/v2/core-internal-api/local-isolated-session-consumer-v1.md)在独立 local/testing 开关下，接通 owner 会话列表、加密指定撤销和当前登出。Gateway/SDK 保持 decimal-string ID，只转发实际当前 User Bearer；Core 决定角色、归属和原生删除。闭合信封与有界 DTO 排除凭据，三个入口共享 Redis 凭据/IP 频控，存储失败拒绝，所有新路由默认关闭。SDK 不保存或清除应用 token，不重试 POST，也没有 Native 回退。

固定 Gateway **832**、SDK **123** 项、类型检查和构建通过，新增专项分别 **33** 与 **18** 项。真实 SDK→Gateway→TLS1.3→Core/MariaDB/Redis **12** 项通过，涵盖 100/101 列表边界、跨用户/角色拒绝、密文重放与错误 AAD、原生重复 404、删除 SQL 失败和审计503隐藏已提交登出；另一枚凭据确认后可撤销最终会话，其他用户保持一枚。固定全量与联调 runtime/test hash 相同，Core API tree 与已验收 B5.13 完全相同，未把旧全量结果称为重跑。新 CI 使用合成 MySQL8.4/Redis 服务，远端结果另行核验；见[证据报告](internal-session-consumer-verification.json)。这是现有 v1 请求 HPKE，SC-v2 和生产门禁仍未通过。

B5.14 的 CI Redis 测试库改为显式12，当前源码本地12项真实链路重跑通过；提交 `651bdbc` 的 [Oracle MySQL8.4/Redis7.4 真实链路](https://github.com/ANRCM0/TXBoard/actions/runs/38329369187) 12项全部通过。Gateway运行时提交 `18c7d1a` 的镜像构建、浏览器验收、固定标签发布和dev提升均成功；后续纯夹具/CI修改按规则跳过镜像发布。先前失败与本地快照修正保留在报告，未覆盖原证据。

### B1.19 隔离余额端点影子投影

[当前契约](../../../contracts/v2/legacy-money-shadow-v1.md)在独立默认关闭开关下，将已验证捕获事件的余额/佣金端点写入三张独立证据表。首事件before作为观察锚点，不补造历史收款；每个非零整数差额生成两条平衡分录，另一侧明确为未分类捕获过渡账户。非财务事件只推进端点。首次冻结高水位，分页原子保存事件/分录/游标，完成后显式extend再接新事件；不会修复旧map或切换任何业务权威。

固定 Core `651bdbc` 加本批六个PHP文件：SQLite专项 **13通过、2项引擎条件跳过、182断言**，MariaDB **15全部通过、203断言**，全API **1125通过、101项引擎条件跳过、11506断言**。真实独立会话验证User锁等待，提交前后SIGKILL分别留下零/一页，重启无重复分录；逐页对账仅执行SELECT，损坏证据、上下文漂移、源缺口及SQL失败拒绝或整页回滚。源码、依赖和日志hash见[验证报告](legacy-money-shadow-verification.json)，重现范围见[测试夹具](../../../api/tests/Fixtures/LegacyMoneyShadow/README.md)。提交 `ddbc7e3` 的独立Oracle MySQL8.4 CI **15项、203断言**通过，API、MySQL原回归、真实会话链路和Core镜像发布流程均成功。完整writer覆盖、订单/支付事实、Terms/周期投影与生产切换仍待完成。

### B4.6 Node独立公钥签名诊断

[当前契约](../../../contracts/v2/credential-provider/node-offline-signature-proof-v1.md)增加独立Go诊断包，只接受显式受信任公钥和原Core合成签名域。固定RSA3072/RS256、SPKI/epoch/时间元数据、闭合外层与有界严格JSON均校验；未知key、重复字段、算法混淆、损坏或非规范编码返回固定错误。诊断始终不授予接入，嵌套业务语义、有效窗口和当前Core资格明确未评估，不返回credential或usage grant；默认Node Service没有导入。

固定 `ddbc7e3` 加本批源码，Go1.26.3 **7个主测试、30个子测试**及race/vet通过，纯Go轮次明确跳过一项需真实Core的用例。另以实际Core初始绑定和完整PHP校验后的原始签名、公钥运行Go互操作，**1项、6断言**通过，原始payload/签名摘要和时间精确一致。全API **1125通过、102项引擎/跨工具链跳过、11509断言**。源码/依赖/日志hash、早期工具链及缓存环境失败见[验证报告](provider-offline-proof-verification.json)；已接入单独CI。该片未实现Node授权、秘密交付、可信时钟、租约执行或撤销ACK。

### B2.4 捕获余额影子升级恢复

Deploy `71cb340` 增加封闭 `captured-money-shadow/v1`，签名绑定单User、捕获run/stream/高水位/尾hash及billing。原来源逐次检查，变更拒绝；内置三表migration、每页100事件及从0完整只读扫描，禁止自动extend和业务权威切换。Windows/Linux各 **58项** 编排测试及原管理互锁通过。

真实固定Core `63f99cf` 工程镜像、Docker28.3.3/MariaDB11.4合成演练包含 **205** 事件；首100已提交后SIGKILL，持久journal缺失result，恢复到205并全页核对，重复步骤和不平衡步骤均 **0**，旧binary guard保留。原数据库dump/restore字节相同；[制品/源码和范围证据](https://github.com/ANRCM0/TXBoard-Deploy/blob/main/docs/captured-money-upgrade-verification.json)区分本地OCI工程镜像和正式发布验收。生产writer fence、全站迁移、集群租约和外部增量恢复仍未完成。

Deploy `34fd467` 修正host MySQL临时socket服务就绪竞争，[完整部署CI](https://github.com/ANRCM0/TXBoard-Deploy/actions/runs/38338365530)通过58项编排、真实MySQL8.4.11渲染/历史转换和固定Gateway安装回退。该CI未重跑实际Core205事件强杀演练，各验收范围独立记录。

### B1.20 隔离套餐批量同步捕获

[当前契约](../../../contracts/v2/legacy-plan-propagation-capture-v1.md)在独立默认关闭开关下，接入原Admin套餐force-update。原Plan事务先锁Plan，再选定有序且最多1000个当前User；原批量SQL只更新冻结集合，不触发User Observer/钩子。固定Admin身份policy记录精确before/after，链、mapping失效、全体User及Plan同事务；later gap或storage失败整批回滚。晚到新订阅者明确不属于选定集合，不能推定全站停写。原Plan saving返回false被调用方忽略的行为保持，捕获仅记录真实User端点。

固定Core `77f819c` 加本批源码：SQLite **12通过、2项引擎条件跳过、128断言**；MariaDB **14全部通过、149断言**，包括真实Plan锁等待后成员重查和提交前后SIGKILL。全API **1137通过、104项引擎/工具链条件跳过、11637断言**。原生表名及文档检查通过；前次夹具主键/外键问题及最终源码/依赖/日志hash见[报告](legacy-plan-propagation-verification.json)。远端Core镜像和原MySQL回归通过；新增MySQL8.4专项发现夹具误将JSON对象成员顺序视为值，`cd36471`仅修正比较，SQLite12/128及MariaDB14/149复验通过，[修正后的MySQL8.4 CI](https://github.com/ANRCM0/TXBoard/actions/runs/38342592660)已通过。User创建/删除、其他writer、Terms/周期投影与权威切换仍未完成。

### B1.21 隔离批量封禁捕获

[当前契约](../../../contracts/v2/legacy-bulk-ban-capture-v1.md)接入原Admin批量封禁事务，默认关闭。原最多500个有序User锁、当前角色保护、批量SQL及逐User会话删除继续生效；在隔离模式以固定Admin policy记录真实端点。全体封禁、token删除、事件链和mapping失效同事务，来源缺口或存储失败全部回滚。已封禁User不补造事件，重放仍删除新会话；过滤集合保持原事务前选定语义，不宣称Node撤销已收敛。

固定Core `bddaf7e` 加本批源码和JSON夹具修正：SQLite **12通过、2项引擎条件跳过、113断言**；MariaDB **14全部通过、140断言**，包括独立锁等待后staff保护和提交前后SIGKILL。全API **1149通过、106项引擎/工具链条件跳过、11750断言**。源码/依赖和日志hash见[报告](legacy-bulk-ban-verification.json)。提交 `0ba06e0` 的[独立MySQL8.4 CI](https://github.com/ANRCM0/TXBoard/actions/runs/38343061660)、API、Core镜像、会话联调、公钥诊断和文档均通过。创建/删除及其他writer、Terms/周期、全局覆盖和生产迁移门禁仍未完成。

### B7.1 前端范围调整与 Agent/MCP 退役

[最新范围决定](admin-only-scope.md)延期两个新前端，未来另建仓库；已完成的 Agent/MCP 清理继续有效，取消插件拆分。删除 Agent API、配对、巡检、内置模块、管理页面、Node AI executor、MCP 进程和构建/CI；旧 Agent 名称或作用域的 Sanctum 凭据拒绝，历史迁移/审计行保留。正常 Node 同步、上报和安装器 Machine runtime update 继续运行。Compact/Split 边缘显式拒绝 `/mcp` 及子路径；安装器移除启用与管理选项，旧 CLI 参数在 Docker 调用前失败。

固定 `0ba06e0` 加清理源码：全 API **1114通过、106项引擎/跨工具链跳过、11735断言**；退役与基础边界专项 **28项、719断言**；Node 全仓 race **660测试、28包**及相关 vet 通过。Web 共享 **8**、Admin **287**、既有 User 兼容 **63**项及类型/构建/体积门禁通过；实际注册 **254** HTTP methods，外部契约和退役路由门禁通过。安装器12套相关回归及旧参数拒绝通过；[源码与日志报告](agent-retirement-verification.json)保留修正前夹具失败和固定快照。提交 `3417670` 的 API/MySQL、前端、会话、公钥及文档 CI 全部通过；[Core 镜像](https://github.com/ANRCM0/TXBoard/actions/runs/38349216051)验证 MCP/Node.js/旧 Supervisor 程序缺失、PDO/Octane smoke 和发布，[Node 镜像](https://github.com/ANRCM0/TXBoard/actions/runs/38349216127)构建/配置兼容、发布和 dev 提升通过。Deploy `aaacd54` 的[主 CI](https://github.com/ANRCM0/TXBoard-Deploy/actions/runs/38349237512)通过；仅 PR/dispatch 的历史镜像安装 smoke 本轮未运行。独立管理前端、完整 Admin Operation 与正式 SC-v2 尚未完成。

### B5.15 隔离管理身份读取

[当前契约](../../../contracts/v2/core-internal-api/local-admin-identity-v1.md)增加固定 `admin.identity.get` version1，要求专用 `admin-gateway` proof、当前 Sanctum Bearer、原生封禁/管理路径/管理员角色检查；独立严格默认关闭，限 local/testing 隔离副本。输出仅 decimal ID、当前邮箱和 `is_admin=true`，无秘密或路径；Core 保持最终授权，不把本次身份结果缓存为未来权限。普通 User、仅 staff、过期/撤销、退役 Agent 和 User service 均拒绝；没有开放 Admin Gateway。

固定 `3417670` 加本批源码：专项 **24项、462断言**，包含10项新管理测试和原服务/退役边界；全API **1124通过、106项引擎/工具链条件跳过、11820断言**。真实 Core/MariaDB11.4/TLS1.3 **12项**通过，覆盖服务/actor交叉拒绝、路径轮换、SQL防重放、真实审计INSERT失败503及当前凭据撤销。初版拒绝分支被内层路由渲染为500，已改为固定403并在报告保留失败源码/日志；修正后的固定快照通过全部验收，[证据报告](internal-admin-identity-verification.json)记录源码、依赖与日志hash。提交 `e9281b7` 的[独立MySQL8.4联调](https://github.com/ANRCM0/TXBoard/actions/runs/38351881362)及API/MySQL原回归、Core镜像、公钥、会话和文档均通过；其余管理操作、细粒度RBAC、SC-v2和生产私网仍待完成。

### B5.16 隔离管理登录

[当前契约](../../../contracts/v2/core-internal-api/local-admin-login-v1.md)增加固定 `auth.admin.login` version1，专用 `admin-gateway` 身份且拒绝任何Authorization头，独立默认关闭、local/testing隔离副本和合成邮箱门禁。复用原生FormRequest、TrimStrings、共享10/min限流、密码预算/旧hash、CAPTCHA已知停用检查、LoginService与管理角色检查；只有实际管理员才能收到真实Sanctum会话及引导管理路径。原native last_login_at/hook先于角色检查的副作用保持，审计503可隐藏已提交会话，未引入补偿或登录幂等。

固定 `e9281b7` 加本批源码：专项 **51项、514断言**，包含11项新管理登录测试及原User登录/身份/service边界；全API **1135通过、106项引擎/跨工具链跳过、11910断言**。实际Core/MariaDB11.4/TLS1.3 **12项**验证登录→当前身份、服务与User/staff隔离、guest头拒绝、封禁/降权、proof重放、CAPTCHA拒绝、真实审计失败隐藏会话和撤销；最终token为0。[报告](internal-admin-login-verification.json)保留首次缓存预加载夹具失败及最终源码/依赖/日志hash。提交 `6c5ed4c` 的[独立MySQL8.4管理登录联调](https://github.com/ANRCM0/TXBoard/actions/runs/38354883073)、管理身份、API/MySQL原回归、Core镜像、公钥、会话和文档CI全部通过；Admin Gateway/SDK消费者、新管理前端、正式SC-v2与生产PKI仍未启用。

### B5.17 隔离管理仪表盘

[当前契约](../../../contracts/v2/core-internal-api/local-admin-dashboard-v1.md)加入固定 `admin.dashboard.get` version1，独立默认关闭、local/testing和隔离副本门禁。与管理身份共用原生AdminPath/Admin授权适配器，当前Sanctum、封禁、角色和路径每次重查；Core委托原生统计服务及15秒缓存。闭集DTO将金额/流量转为十进制字符串，保留原生公式和时间窗口；缓存异常、SQL或审计故障拒绝，未知字段不透传，不新增业务权威或管理公网入口。

固定 `6c5ed4c` 加本批源码：专项 **37项、346断言**，全API **1147通过、106项引擎/工具链跳过、12029断言**。实际Core/MariaDB11.4/TLS1.3 **12项**通过，验证超过53位的字节、业务记录读取不变、服务/actor隔离、当前路径/封禁/降权、SQL防重放、审计故障隐藏结果及会话撤销。首轮夹具遗漏StatServer必填server_type，已修正并保留失败快照/数据库；初始化失败也清理测试私钥。[报告](internal-admin-dashboard-verification.json)记录最终源码、依赖、脱敏receipt和日志hash。独立MySQL8.4 CI已加入，远端结果另行核对。

管理页退役收尾 `4e169a9` 将健康摘要修正为实际工单/佣金两项，Web共享8、Admin287、既有User兼容63项及类型、构建和体积门禁通过；远端Web和Core镜像CI通过。完整管理前端、其余Operation、SC-v2、生产私网和迁移切换仍待完成。

提交 `a3ed2fc` 的[独立MySQL8.4管理仪表盘联调](https://github.com/ANRCM0/TXBoard/actions/runs/38358536502)、管理登录/身份、API/MySQL原回归、Core镜像、公钥、会话和文档CI全部通过，远端证据已写入上述报告。

### B5.18 Gateway 隔离管理客户端

[当前契约](../../../contracts/v2/core-internal-api/local-admin-consumer-v1.md)增加独立默认关闭的管理配置加载和固定Internal客户端，限local/testing及Admin服务证书，仅消费登录、身份、仪表盘三种Operation。闭集DTO、严格JSON、双向TLS1.3、Core SAN、CRL、5秒壁钟和响应大小门禁拒绝歧义或不完整结果；金额/流量字符串不转Number，错误不反射私有输入。没有公开Admin路由、原生回退、管理角色缓存或登录重试。

固定 `a3ed2fc` 加本批源码：新专项 **43项**，Gateway **875项**、SDK **123项**及类型/构建全部通过；实际Gateway客户端/Core/MariaDB11.4/mTLS **10项**验证登录→当前身份→精确大整数仪表盘，普通User/staff、额外身份输入、封禁/降权、错误路径和撤销均拒绝。真实审计503隐藏一次已提交会话，客户端不重试或补偿；最终token为0。[证据报告](internal-admin-consumer-verification.json)保留固定源码/依赖/日志hash和脱敏receipt；独立Oracle MySQL8.4 CI已加入。正式浏览器入口、SC-v2、管理前端、细粒度权限与生产私网仍待完成。

提交 `5042744` 的独立MySQL8.4管理消费者、管理登录/身份/仪表盘、Gateway镜像构建发布、会话和文档CI全部通过，远端结果已记入上述报告。

### B5.19 隔离管理订单列表

[当前契约](../../../contracts/v2/core-internal-api/local-admin-order-list-v1.md)增加默认关闭的 `admin.order.list` version1，委托原生管理订单查询、状态过滤、稳定排序和当前User/Plan关联。闭集分页DTO移除callback、返佣策略及历史折抵引用，金额和ID保留字符串。Gateway固定orders调用核对请求页码、状态、分页范围和关联ID；当前角色、封禁与管理路径仍由Core每次重查。

固定 `5042744` 加本批源码：Core专项 **37项、377断言**，全API **1159通过、106项引擎/工具链跳过、12163断言**；Gateway **884项**、SDK **123项**及类型/构建通过。最终客户端与实际Core/MariaDB/mTLS **12项**通过，包含过滤分页、孤儿关联、SQL/审计失败、角色变化和撤销；最终测试token为0。全量API与最终联调的API源码hash一致，后续只修正夹具断言并补充客户端验证。[报告](internal-admin-order-list-verification.json)保留源码、依赖、失败快照与最终日志hash。原MySQL金额32位字段未扩大；未开放公开Admin入口、正式SC-v2或生产运行。独立Oracle MySQL8.4 CI已加入，远端结果另行核验。

提交 `5f2e680` 的独立Oracle MySQL8.4订单列表、管理消费者/登录/身份/仪表盘、API/MySQL原回归、Core与Gateway镜像、公钥、会话及文档CI均通过，结果已写入上述报告。

### B5.20 隔离管理套餐列表

[当前契约](../../../contracts/v2/core-internal-api/local-admin-plan-list-v1.md)增加独立默认关闭的 `admin.plan.list` version1，委托原生管理目录及sort/id升序。保留隐藏/停售/不可续费套餐、当前权限组与用户计数；GiB整数和原生主要币值价格用字符串传输，不转换Offer或重算报价。闭集DTO、分页与关联核对、当前授权和失败拒绝由Core/Gateway共同验证。

固定 `5f2e680` 加本批源码：14项新Core专项进入全API，**1173通过、106项引擎/工具链跳过、12328断言**；Gateway **893项**、SDK **123项**及类型/构建通过。实际Core/MariaDB/Gateway/mTLS **14项**通过，包含隐藏状态、稳定排序、孤儿group、到期计数、超过53位的原生字符串价格、零/null价格及降权。首轮夹具未给旧套餐设置sort，SQL NULL正确排在负数前；已修正显式sort并换新合成库，失败记录保留。全量API和Gateway检查源码与最终联调hash一致，[证据报告](internal-admin-plan-list-verification.json)记录依赖、日志和脱敏收据。数字价格已经过PHP JSON解析，不能称为数据库词法精度保留；尚未开放公开Admin、正式SC-v2或生产运行。独立Oracle MySQL8.4 CI已加入。

提交 `6213318` 的独立Oracle MySQL8.4套餐列表、订单列表与其余管理联调、API/MySQL原回归、Core/Gateway镜像、公钥、会话和文档CI均通过，远端结果已记入上述报告。

### B5.21 隔离管理会话

[当前契约](../../../contracts/v2/core-internal-api/local-admin-sessions-v1.md)增加默认关闭的管理会话list/revoke/logout，使用独立管理服务与实际当前凭据。委托原生Sanctum owner查询和删除，保留100项上限、缺失/跨owner/重复撤销404与当前Token退出；每次核对当前管理路径、角色和封禁。Gateway消费闭集降序字符串ID及非秘密会话元数据，不自动重试或补偿未知提交。

固定 `6213318` 加本批源码：18项新Core专项与原User会话/身份共 **44项、337断言**，全API **1191通过、106项引擎/工具链跳过、12462断言**；Gateway **906项**、SDK **123项**及类型/构建通过。实际Core/Gateway/MariaDB/mTLS **17项**通过，包含owner隔离、当前路径/降权拒绝、真实审计失败隐藏已提交退出、刷新鉴权与成功退出；最终测试Token为0。[报告](internal-admin-sessions-verification.json)记录固定源码、依赖、日志hash与脱敏收据。原生并发进行中请求和模型拒绝delete语义保留，不称为SQL授权fence；独立Oracle MySQL8.4 CI已加入。公开Admin、新管理前端、正式SC-v2与生产门禁仍未启用。

提交 `5f51d34` 的独立Oracle MySQL8.4管理会话、其余管理联调、API/MySQL原回归、Core/Gateway镜像、公钥、既有会话和文档CI均通过，结果已写入上述报告。

### B5.22 隔离管理用户读取

[当前契约](../../../contracts/v2/core-internal-api/local-admin-users-v1.md)增加独立默认关闭的管理User目录/详情，抽取原查询到Core共享reader，保留既有HTTP的筛选、排序、分页和编辑器DTO。新Internal DTO直接读取原持久INT余额与字节，避免经主要币值浮点数逆算；只返回闭集当前字段与关联，不含密码、UUID、Token或订阅链接。Gateway核对请求id、分页/筛选、降序唯一ID及当前关联。

固定 `5f51d34` 加本批源码：14项新Core专项进入全API，**1205通过、106项引擎/工具链跳过、12599断言**；Gateway **915项**、SDK **123项**及类型/构建通过。实际Core/Gateway/MariaDB/mTLS **19项**通过，包含INT余额上界、超过53位的原生字节、业务行hash不变、当前关联/孤儿、缺失404、服务/角色拒绝与会话生命周期。首次测试辅助方法重名及无Plan关联的SQL失败夹具已修正，失败源码/日志保留。Gateway检查源码与最终联调hash一致，[报告](internal-admin-users-verification.json)记录最终源码、依赖和脱敏收据。原金额字段未扩容；公开Admin、新管理前端、SC-v2与生产门禁继续保留，独立Oracle MySQL8.4 CI已加入。

提交 `87ff745` 的独立Oracle MySQL8.4管理用户及其余管理联调、API/MySQL原回归、Core/Gateway镜像、公钥、既有会话和文档共15个触发CI全部通过，结果已写入上述报告。

### B1.22 隔离管理员创建初始证据

[当前契约](../../../contracts/v2/legacy-admin-creation-shadow-v1.md)接入原生Admin创建事务，在实际User INSERT后、提交前记录不可变初始状态与当前Admin引用。独立开关严格默认关闭，限local/testing隔离副本；失败时User和证据一起回滚。只记录原有15个非秘密字段，不补造before状态、余额流水、Terms、授权或mapping。原默认值、单位、重复邮箱和提交后Observer时机保留；提交后排期变化由独立捕获记录或明确报告来源变化。

固定 `87ff745` 加本批源码：新SQLite专项 **18通过、1项引擎条件跳过**，与原编辑器及捕获兼容回归共 **43通过、5项跳过、428断言**；MariaDB **19全部通过、156断言**，包含实际独立会话的提交前不可见、共同回滚和提交后同时可见。全API **1223通过、107项引擎/工具链条件跳过、12748断言**。有界CLI只读核对闭集字段、context/hash和至多首条事件，不认证完整链或开启迁移门禁；DDL重放保留历史，有数据时拒绝down，删除旧User不删证据。[源码与日志报告](legacy-admin-creation-verification.json)保留首轮漏填必填UUID的夹具失败。注册、Installer初始化、其他创建/删除writer和全局投影仍待完成，独立Oracle MySQL8.4 CI已加入。

提交 `d6fac98` 的独立Oracle MySQL8.4创建证据、其余管理联调、API/MySQL原回归、Core镜像、公钥、既有会话和文档共15个触发CI全部通过，结果已记入上述报告。

### B1.23 隔离管理员删除证据

[当前契约](../../../contracts/v2/legacy-admin-deletion-shadow-v1.md)接入原生未使用账户删除事务，记录删除准入时的15个非秘密字段和实际Admin引用，并在原生删除后确认User已不存在。User、会话删除与证据同事务提交；存储或context失败共同回滚。原模型删除回调取消时保持响应和会话行为，不补造删除证据；创建、变更及其他历史不删除。准入后的可信钩子可改变端点，因此记录不认证最终pre-delete状态，也不宣称Node/Provider撤销收敛。

固定 `f1b0291` 加本批源码：新SQLite专项 **18通过、1项引擎条件跳过**，与创建及原账户操作兼容回归共 **41通过、2项跳过、353断言**；MariaDB **19全部通过、179断言**，包含独立会话提交前可见旧User/会话且不可见证据、共同回滚与提交后共同变化。全API **1241通过、108项引擎/工具链条件跳过、12919断言**。真实MySQL专项暴露原删除路径查询不存在的User parent_id；已按实际Schema检查该可选关联，存在字段和子账户引用时仍拒绝删除，未添加新业务字段。首轮Token长度夹具及第二轮原生Schema失败均保留于[报告](legacy-admin-deletion-verification.json)。有界CLI只读核对历史/来源重现，DDL保留历史并拒绝 populated down；独立Oracle MySQL8.4 CI已加入，生产切换和全局writer覆盖仍未开启。

提交 `1edf030` 的独立Oracle MySQL8.4删除/创建证据、其余管理联调、API/MySQL原回归、Core镜像、公钥、既有会话和文档共16个触发CI全部通过，结果已写入上述报告。

### B1.24 隔离注册创建证据

[当前契约](../../../contracts/v2/legacy-registration-creation-shadow-v1.md)在独立默认关闭门禁下，接入原生RegisterService实际保存新User的路径。只将User INSERT与初始证据放入同一事务，验证成功后沿原顺序执行Observer、注册后钩子及会话发行；关闭时仍直接调用原save，不添加事务。共享初始证据表新增固定user.register策略，actor引用绑定实际新User ID；Admin创建版本、hash与ticket形状保留，两个开关/校验器互相拒绝错误策略。邀请码消费仍先于保存事务，失败时不补造回滚保证。

固定 `1edf030` 加本批源码：注册新SQLite专项 **14通过、1项引擎条件跳过**，与原Admin创建/删除及原认证兼容回归共 **54通过、3项跳过、477断言**；MariaDB **15全部通过、124断言**，包含实际注册INSERT提交前独立会话不可见、提交后User/证据共同可见。全API **1255通过、109项引擎/工具链条件跳过、13037断言**。覆盖试用套餐实际初始字节、排期后续捕获、取消saving、邀请码原顺序、存储/context失败回滚、提交后钩子隐藏已提交账户、跨策略拒绝及只读CLI；[源码与日志报告](legacy-registration-creation-verification.json)记录固定依赖和验收范围。现有Internal注册的本次capture开关仍按原默认关闭回归，尚未验收其opt-in mTLS组合；Installer初始化、可信直接writer、完整投影和生产切换仍待完成，独立Oracle MySQL8.4 CI已加入。

提交 `9f0c644` 的独立Oracle MySQL8.4注册/管理员创建/删除证据、其余管理联调、API/MySQL原回归、Core镜像、公钥、会话和文档共17个触发CI全部通过，结果已记入上述报告。

### B1.25 注册证据与现有通道组合验收

[注册证据契约](../../../contracts/v2/legacy-registration-creation-shadow-v1.md)新增仅测试使用的明确profile，在独占合成库启用现有两项capture门禁。保留原SDK/Gateway注册与真实Core mTLS测试，补充成功注册初始证据、真实审计INSERT失败隐藏已提交账户/会话/证据、实际证据INSERT触发器故障回滚User及零Native回退。测试seed/mutation/router使用明确库名，临时PKI及凭据清理；没有新增生产API或启用正式SC-v2。

固定 `9f0c644` 加本批测试源码：Gateway **915**、SDK **123**及类型/构建通过；真实Core/MariaDB/Redis/Gateway/TLS1.3 **12项**通过。最终快照全API **1255通过、109项引擎/工具链条件跳过、13037断言**。两条初始证据验证有效并保留原排期变更造成的source_changed，未覆盖改写初始状态；故障账户不存在。首轮HTTP test router仍拒绝新库名导致就绪失败，注册断言未执行；已修正固定profile库名检查并保留失败源码/日志和合成dump校验和。[验收报告](registration-shadow-transport-verification.json)记录最终源码、依赖和脱敏receipt。独立Oracle MySQL8.4组合CI已加入，生产PKI、完整writer投影、Node/Provider收敛及正式SC-v2继续待验收。

### B1.26 隔离Core初始化管理员证据

[当前契约](../../../contracts/v2/legacy-bootstrap-creation-shadow-v1.md)接入Core TxboardInstall::registerAdmin实际保存User的路径，不运行完整宿主安装器。独立严格默认关闭开关，关闭时沿用原save且不添加事务；启用时仅新User和初始证据同事务。固定install.admin.create策略、自引用实际新User ID、原生密码hash/角色/UUID/Token/默认值和取消saving语义保留，不给安装账户补造试用套餐、会话或其他权益。共享保存组件只允许两个固定自身创建来源，注册和Admin既有版本/hash/ticket形状不变。

固定 `5c55adb` 加本批源码：新SQLite专项 **11通过、1项引擎条件跳过**，与注册、Admin创建和原认证兼容回归共 **47通过、3项跳过、409断言**；MariaDB **12全部通过、109断言**，包含原初始化保存事务提交前独立会话不可见、提交后User/证据共同可见。全API **1266通过、110项引擎/工具链条件跳过、13140断言**。取消saving、短密码、context/storage失败、重复邮箱不重建凭据、跨policy拒绝、有界只读CLI和来源变化/缺失均验收；[源码与日志报告](legacy-bootstrap-creation-verification.json)记录固定依赖。原完整安装settings/plugin/文件系统/控制台输出仍在原边界，宿主安装生命周期归TXBoard-Deploy；可信直接writer、权威投影和生产迁移继续待完成，独立Oracle MySQL8.4 CI已加入。

B1.25 的初次Oracle MySQL8.4 CI使用原夹具Redis22，默认16库容器拒绝；`01a5f0a`将CI改为显式Redis12并保留原profile默认22，本地12项真实链路复验通过。提交 `0ab072e` 的[Oracle MySQL8.4/Redis7.4组合联调](https://github.com/ANRCM0/TXBoard/actions/runs/38423939013)现已通过；初始化管理员专项及API/MySQL、Core镜像、管理读取、会话、公钥和文档共19个触发CI全部通过，两份报告保留原失败和最终收尾证据。

### B4.7 Node离线时间窗口核对

[当前契约](../../../contracts/v2/credential-provider/node-offline-window-proof-v1.md)在已有公钥签名诊断上增加仅进程内的UTC/单调时间窗口。只能从原签名statement构造，处理前后检查时间，保留纳秒余量；UTC回退、单调时间回退、期限越界、同kid信任的epoch/SPKI/有效期变化、移除和撤销均永久终止实例。Check与Close同锁，无定时器、worker或网络请求；只返回独立诊断副本，admission_granted始终false。

固定 `dc47812` 加本批Node源码：Linux Go1.26.3 race **14主测试、56子测试通过**，其中窗口新增7主/26子，vet通过。纯Go的1项真实Core条件跳过由既有PHP初始绑定/签名夹具单独补跑，**1项通过、6断言**，覆盖原签名最后一纳秒、精确到期及之后恢复仍拒绝。源码/依赖和日志hash见[报告](../../../node/docs/provider-window-verification.json)。该API目前只有测试调用，未接入Service、计量绑定、凭据交付或v2准入；外部可信时间、恢复fence、完整业务证据与撤销收敛继续待验收。由开发代理实现，协调者核对冻结源码后分批提交；现有Node和Provider CI负责后续远端验收。

### B5.23 隔离管理网络权限组读取

[当前契约](../../../contracts/v2/core-internal-api/local-admin-network-groups-v1.md)增加默认关闭的admin.network-group.list v1，专用Admin服务、当前Sanctum/封禁/角色和管理路径由Core每次核对。提取原生查询为共享reader，原HTTP仍返回完整列表与整数ID；Internal按组分页并返回闭集字符串ID/计数/时间。节点成员仍沿原250条chunk扫描全表，混合整数/字符串去重、隐藏/停用节点与当前User/Plan计数保持；不声称全局扫描有界或查询快照一致。

固定 `dc47812` 加本批源码：Core新专项和原网络组回归 **17项、186断言**，Gateway **923**、SDK **123**及类型/构建通过；实际Core/Gateway/MariaDB/mTLS **14项**通过，业务表hash不变、最终Token为0。协调者补跑固定切片全API **1279通过、110项引擎/工具链跳过、13280断言**。[报告](internal-admin-network-groups-verification.json)记录精确源码/依赖、失败header夹具及修正后日志；冻结快照未混入其他代理未完成代码。开发代理交付后经协调者审查分批提交，独立Oracle MySQL8.4 CI已加入；未开放公开Admin、生产PKI、正式SC-v2或前端开发。

### B1.27 隔离账户生命周期核对

[当前契约](../../../contracts/v2/legacy-lifecycle-reconcile-v1.md)增加独立默认关闭的有界只读CLI及原subscription reconciliation附加观察。固定checkpoint高水位范围联合User/map/exception/初始创建/删除/capture stream，按有序唯一User ID逐条锁定当前来源并核对闭集policy/context/snapshot/hash、首条mutation关联及mapping ID/状态/归属。输出来源存在、已知Admin删除、未知缺失或重现；已知删除仍保留LEGACY_USER_MISSING阻断，不释放活跃订阅或slot，不改历史或投影。

代理最终专项SQLite及原backfill **31通过、2项MySQL条件跳过、246断言**；专用MariaDB **20全部通过、166断言**，包含JSON成员重排、范围快照后提交异常的独立会话current read、非法BIGINT/slot引用和重算hash但仍非法的证据拒绝。协调者固定 `851bb6c` 加七个冻结源码文件做组合全API **1297通过、112项引擎/工具链跳过、13445断言**。[报告](legacy-lifecycle-reconcile-verification.json)记录各次失败夹具、交叉review修正、源码/依赖和日志hash。默认关闭保留原查询/响应；current_matches_initial和首事件只说明本页观察，完整链/Terms/周期投影、全局覆盖和生产迁移gate均为false。独立Oracle MySQL8.4 CI已加入，后续远端验收另行记录。

本轮并行交付已分别审查提交：Node `6351957` 的3个触发CI全部通过；Gateway/Core `851bb6c` 的21个触发CI全部通过，含Oracle MySQL8.4网络组联调、Gateway与Core镜像发布。代理拥有独立文件/专用库，协调者检查冻结源码、分批提交推送和组合回归；TX-F保持只本地提交且前端开发暂缓。

### B2.5 持久SQL写入围栏基础

[当前契约](../../../contracts/v2/authority-writer-fence-v1.md)在独立严格默认关闭门禁下接入原Admin账户创建/更新事务第一步。共享authority当前锁先于User/capture锁，保留到业务commit/rollback/连接死亡；固定prepare取得同一单例排他锁，等待已接纳事务结束后原子提交prepared、epoch2与不可变回执。显式init不随DDL或writer自动执行；status/recover只读有界核对，同key输出丢失恢复原回执，precommit死亡保留inconclusive，不自动reopen或发布v2。

固定 `3156b0d` 加十个冻结候选文件：SQLite及原创建证据回归 **30通过、6项引擎条件跳过、274断言**；专用MariaDB **17全部通过、186断言**，包含实际原HTTP更新commit/rollback/SIGKILL与prepare顺序，prepare提交前/后强杀、同key重试和坏/缺回执存储拒绝。协调者全API **1309通过、117项引擎/工具链跳过、13570断言**。[报告](authority-writer-fence-verification.json)保留guest401预期与原403语义的夹具修正、独立进程和日志hash；独立Oracle MySQL8.4 CI已加入。仅两条已声明writer受控，其他业务/可信插件直写/提交后Node效果未排空；global coverage/drain、target authority和migration gate均false。后续需统一接入所有已知写入事务和升级编排，不能凭此foundation切生产权限。

B1.27 `3156b0d` 的[Oracle MySQL8.4生命周期专项](https://github.com/ANRCM0/TXBoard/actions/runs/38431743759)及其余Admin、创建/删除、注册通道、API/MySQL、Provider、公钥、Node/Core/Gateway镜像和文档共23个触发CI全部通过，结果已记入生命周期报告。当前仍为隔离迁移核对，不由绿色CI解除全局writer、权威切换或生产数据门禁。

### B3.5 实际TCP区间采集与持久v2归属桥

[当前契约](../../../contracts/v2/node-usage/bounded-tcp-collector-v1.md)增加显式sing-box tracker构造，以冻结Core Session/User绑定和实际Read/Write Begin/Complete构造非零v2批次。私有net.Conn视图阻止zero-copy/Count/Upstream绕过；Pinned sing Copy和extended writer沿实际计数入口。整数半开秒边界使未完成或跨界I/O留在原区间等待下一边界，partial error保留实际正字节；身份、lease、clock、容量、late/closed及UDP未知均fail-stop。journal先持久原Session、I/O evidence与随机batch，再导出既有spool，ConfirmCapture核对owner和原回执并重确认file/directory fsync，恢复不重建旧live timeline或未知tail。

固定 `3156b0d` 加本批冻结源码：Linux Go1.26.3五个完整受影响包race **185主测试、152子测试通过，无跳过/失败**，vet通过；六个真实SIGKILL点、八类journal同步故障和spool重确认故障通过。实际memory TCP验11 upload/7 download、partial7/2和Copy13/0及0/13；Reporter使用进程内synthetic持久回执模拟丢响应后查询原batch。[精确报告](../../../node/docs/bounded-usage-verification.json)保留失败快照、锁及源码hash。默认Service仍不用该构造，v1累计仍在原权威路径；未完成真实Core TLS接收/结算、准入/撤销或生产handoff，不能双份计费。旧不可归属事件bucket不被提升为确定区间；新source marker要求更高Core epoch才能新live collector，旧sealed记录保持原身份。

### B5.24 原生取消订单的隔离业务调用

[当前契约](../../../contracts/v2/core-internal-api/local-order-cancel-v1.md)新增独立默认关闭的user.order.cancel v1及Gateway/SDK固定消费者。Core委托原BillingController/OrderService当前owner查询和PENDING事务，退款、优惠券名额、可选capture和钩子时机保持；不存在新取消幂等或自动补偿。Gateway handler仅local/testing且Internal User mTLS配置有效时注册，闭集输入/输出、当前Bearer/角色/封禁/归属每次重查，无Native回退；SDK只尝试一次，将409、5xx、网络/坏回执标为需确认当前状态。取消Exception诊断改为固定code，保留false与Exception范围，不返回原args/previous。

Core新专项及原建单/Billing回归 **19项、198断言**；Gateway **939**、SDK **138**及类型/构建通过；实际SDK/Gateway/Core/TLS1.3/MariaDB **13项**验证真实预扣和名额、退款恢复一次、重复409、before异常回滚、after异常409已提交、审计503隐藏退款、native capture以及撤销拒绝，最终Token为0。协调者固定 `7737662` 加本批冻结源码组合全API **1319通过、117项引擎/工具链跳过、13669断言**。[报告](internal-order-cancel-verification.json)保留早期schema/夹具/路由/配置/响应ID/日志位置失败和最终同源验收。Core响应ID仍服务端生成，客户端核对响应头/body一致；默认父级writeban可能405，未启用生产或正式SC-v2。独立Oracle MySQL8.4真实组合CI已加入，后续远端结果单独核对。

### B3.6 实际Node与Core持久接收/结算互通

[当前契约](../../../contracts/v2/node-usage/bounded-tcp-core-interop-v1.md)以实际ConnTracker采集的11上传/7下载经journal/spool、原Node HTTPS握手与Reporter送入真实Laravel接收。正常TLS1.3/CA/SAN校验与Native Node Bearer/NodeID保持独立；不是Admin/Internal mTLS。客户端先读真实persisted响应再注入丢响应，Reporter查询原batch恢复pending回执；SQL独立确认batch/digest/outbox已经提交，再由原UsageSettlement按冻结3/2倍率分方向floor得到16/10、总26。重复原批次返回同回执，改相同batch的canonical载荷409，wrongBearer/Node拒绝。

固定 `7737662` 加专属测试源码，在专用MariaDB11.4/PHP8.4/Go1.26.3完成真实两阶段链路；Node helper race构建、vet和PHP syntax通过。关闭/重启journal/spool后真实HTTPS查询settled并重放原批次，最终SQL仍为1 batch/1 entry/26 billable bytes，后续scan为空。新实际Session、载荷、pending/settled receipt、journal/spool和SQL摘要的脱敏制品与hash见[报告](../../../node/docs/bounded-core-interop-verification.json)。为避免冷构建耗尽原有限grant，runner改为先编译再seed，保持原租约长度，第二份全新隔离夹具复验通过；原库和r1证据dump/hash保留。独立Oracle MySQL8.4/Redis7.4组合CI已加入，尚待远端结果；默认Service、生产handoff、准入/撤销/恢复gate未开启，不允许v1/v2双权威计费。

### B2.6 同一围栏的闭合写入清单扩展

[当前契约](../../../contracts/v2/authority-writer-inventory-expand-v2.md)使用同一authority单例，显式expand从旧legacy/open epoch1升级固定inventory的新legacy/open epoch2，再prepare为prepared epoch3；旧prepared epoch2/原回执保持原hash与恢复结果，拒绝expand和隐式reopen。新增nullable inventory_hash在v1哈希中排除，upgrade receipt绑定prior/expanded状态、版本和固定代码清单；新prepare还交叉核对原expanded-state hash，重算单个receipt hash不能跳过关联。旧进程scope/version/epoch声明拒绝新状态，只读status/recover可以核对历史。

七项inventory覆盖原Admin create/update、删除、凭据轮换、批量封禁、Plan save/force和TrafficBatch；authority admission均在原事务第一句，先于User/Plan/Server等业务锁。flags/sort/delete、Order/wallet/Gift、其他writer/可信插件和提交后Observer仍未覆盖，global coverage/drain和target authority/migration gate保持false。固定 `04482a7` 加本批十五个候选源码：SQLite及原围栏/删除/套餐/流量回归 **54通过、14项引擎条件跳过、575断言**；专用MariaDB **22全部通过、284断言**，含8个独立进程的锁等待、commit/rollback/SIGKILL与输出丢失恢复。协调者固定 `6fa00ee` 加冻结扩展源码全API **1333通过、125项引擎/工具链跳过、13872断言**。[报告](authority-inventory-expand-verification.json)记录精确来源、旧v1真实记录兼容和各次失败夹具；独立Oracle MySQL8.4 CI已加入，生产切换继续等待完整事务与副作用排空。
