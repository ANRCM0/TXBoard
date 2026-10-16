# 当前开发进度与下一步

更新日期：2026-10-16。当前主仓负责 Core、Gateway、Node；安装器由独立 TXBoard-Deploy 承接。新管理前端和用户前端延期，后续统一放在 TX-F，仅本地提交、不推送。Agent/MCP 已退役；优惠券等新增商业扩展继续后移。

## C2 收尾与验收

`feat/core-c2-closeout` 在已通过远端验收的 C2-C2 `38c6912` 上集成原生持久启用、身份与正式目录 API、Provider 即时轮换和客户端直接渲染、同一签名中的授权/用量绑定，以及 SQL 恢复任务。新契约见 [Native C2 runtime v1](../../../contracts/v2/native-c2-runtime-v1.md)。精确提交的 CI、领域复审、依赖合并顺序和最终状态统一记录在 [PR #251](https://github.com/ANRCM0/TXBoard/pull/251)，实现范围及验收入口见[收尾记录](core-c2-runtime-verification.json)。

新增 `native-c2` workflow 集中验证 SQLite 与 MySQL 8.4、Go race，以及不替换安装准入的真实注册、零元订单、Redis 队列履约、Provider、VLESS、离线到期、重启旧周期重放、重置、续费、轮换与撤销。`7600693` 的真实流量与 Go race 任务已通过；首轮 Core/MySQL 夹具问题和后续审查修复须以 PR 精确 head 复验，不借用旧提交结果。按照用户要求，本地只做轻量静态检查；大型 Linux 环境已删除，不重建。

当前原生运行仅开放免费 Offer；已知付费 `offer_id` 在建单时拒绝，旧付费原生订单也不能发起支付。C3 支付回调与资金账本、C4 正式 Internal API/生产证书与长期订阅链接、C5 安装发布仍另行推进。后文各切片是当时的历史范围与验收记录，其中“保持 initializing”和未合并状态不覆盖本节的收尾契约及 PR 最终状态。

收尾复审补齐未决用量配额约束：Core 持久接收的 native 批次在原 Period 预留已验证字节，待结算及隔离状态都参与授权可用额度；真实结算同事务释放预留并计入原周期用量。轮换或撤销后重签不能恢复这部分额度，跨截止时间的完整有效批次保留真实隔离回执。相关回归与实流证据仍须以最终 head 的 Actions 为准。

## 已完成的基础切片

| 切片 | 当前结果 | 实现及证据 |
| --- | --- | --- |
| P0.1 基础账户与授权 | 修复封禁管理员旧凭据、严格到期边界、用户组变更及提交后同步 | `64a48f6`；[账户验收](basic-account-authority-verification.json) |
| P0.2 原生节点拒绝与尾量 | 实际拒绝后关闭连接/监听，保留原 pending 和终止尾量 | [拒绝与恢复验收](../../../node/docs/native-authority-denial-verification.json) |
| P0.3 完整快照与应用重试 | 200/304完整快照、desired/applied区分、失败重试及并发认证修复 | `0b86c79`；[快照验收](../../../node/docs/native-snapshot-application-verification.json) |
| P0.4 两内核配置与撤销 | sing-box完整构造重建；Xray实际停止/重建完成强撤销，保留累计量 | `8867948`；[内核验收](../../../node/docs/kernel-application-verification.json) |
| P0.5 Node/Machine真实控制 | 共享WebSocket作用域、单节点停用/恢复、整机凭据轮换及原批次恢复 | `52ae0ba`；[Machine验收](../../../node/docs/native-machine-control-verification.json) |
| P0.6 原生流量与配额 | 持久接收、原批次去重、队列恢复、超额撤销、reset观察周期及崩溃恢复 | `a235065`；[流量验收](../../../node/docs/native-traffic-quota-verification.json) |

这些切片沿用现有模型、业务服务、内核适配、原扣量事务和重置服务。新增的是持久接收、明确计量基线、观察周期分段和故障恢复等缺少的保障；不重新实现已有账户、订单或代理协议内核。详细历史及 CI 提交见[实施台账](implementation-plan.md)。

## 两个最新阶段的验收

P0.5 在真实 Core、Workerman、Redis、专用 SQL 和受信 TLS 下验证双节点：配置变化通过 WebSocket 应用；单节点退出保留兄弟连接；整机 Token 轮换停止全部子节点；恢复先补报再开放监听。37个触发 workflow 全部通过。

P0.6 用真实 VLESS/VMess 流量和 Redis worker 验证丢 ACK、丢队列、Node 实际进程重启、worker 提交前后 SIGKILL、超额停止及管理员 reset。旧周期延迟报告/延迟结算只计入历史周期，新周期当前额度不被旧流量消耗。最终本地 API 1526项通过，Node race 595主测试和508子测试通过；条件跳过分别154项和8项，独立数据库及真实协议门禁另有证据。Node在`b4dfdad`的8个workflow及相同API源码在`4c425b6`的29个Core workflow均通过，镜像已发布。

## P0.7 有限签名授权阶段

Core 可通过默认关闭的原生开关，为完整 Node 用户快照签发独立 Ed25519 授权。Node 在显式固定 Core/key 公钥身份后验证 Node 与 Machine 子节点绑定、完整用户集、单调 revision 和期限；签名快照版本持久化后才能用于准入。有限离线时间最多120秒，独立30秒刷新不依赖管理员拉取周期；WS用户/配置消息仅触发完整签名快照重取。授权到期的内核I/O关闭绕过 Service、reporting、observer 和内核结构锁，随后沿原终止 checkpoint 保存原批次尾量；重启必须在线取得新 revision，旧周期与批次正文保持不变。

专项证据：Core授权专项12项/101断言通过，Node全量race 613主测试/577子测试通过（13项条件跳过），完整API回归1692项/16347断言通过（154项条件跳过）；Node vet、构建通过。真实 Linux Core/Machine 联调的 sing-box 和 Xray 均通过，涵盖 VLESS/VMess 实流、一个共享 Machine WSS、子节点封禁后兄弟继续、离线至签名到期关闭现有流和监听、不同进程离线重启拒绝旧快照、在线恢复，以及两批原 traffic spool 的ID/正文/观察周期恢复结算。真实证据见[授权验收](../../../node/docs/native-authorization-verification.json)。

精确审阅分支 `b480f9f` 的 Core API、Node runtime/stability、Machine、traffic及authorization两内核真实workflow全部成功；Core镜像 workflow 验证了容器内 Ed25519、数据库恢复和Octane smoke，Node image workflow实际构建并检查了匹配 commit 的离线 enrollment capability。两个 workflow 的publish job均按审阅分支策略跳过。TXBoard-Deploy 配置切片精确提交 `6209c6b` 的12套 Linux安装/升级保护与 Node 配置消费者CI成功；验收文档精确提交 `2c42836` 的 Node 全量测试、vet 和 runtime stability CI亦通过。两仓PR创建权限当前不足，分支未合并/未发布；真实Docker安装器与授权状态全备份恢复尚待下一切片。

本阶段交付的是 opt-in 原生完整快照有限租约，不包含生产 Provider 凭据签发/秘密自动分发、自动密钥重叠轮换、受控Machine迁移重绑定、硬件时钟/磁盘回滚保护、HA或全部协议/平台矩阵；这些仍是生产启用前的独立门禁。多实例必须逐实例配置授权 pin，顶层授权随instances会明确报错，不静默降级。

## P1.1 安装器授权配置接入

TXBoard-Deploy 可显式接收完整 Core/key 公钥 pin，为 Node/Machine 或新增实例生成有限授权配置。安装前通过 Node 新增的只读 `--capabilities` 确认所选镜像支持 `node_authorization_snapshot_v1`；现有容器以实际 image ID 验证。能力缺失、部分pin、非规范公钥、非HTTPS origin、普通覆写删除已有授权、原地改变已授权目标会拒绝。私钥不进入 Node 安装器，状态目录由运行时按实例派生，防止多个Core共享版本。

隔离 Linux 中12套安装/维护/升级保护回归、生产Node配置加载器消费单/多实例配置、Node CLI race/构建和实际能力查询通过。该切片仅证明配置接入与能力门禁；固定发布镜像的实际Docker安装、更新、备份恢复尚需下一切片验证，不能记为P1整个单机交付完成。P0.7 的最终审阅提交 `edd7539` 和 Deploy 审阅提交 `aa47747` 的精确 CI 均已成功；默认分支尚未合入，镜像未发布。逐项完成审计见[验收收尾记录](p0-authorization-completion.json)。

## P1.2 Node 完整运行备份与恢复

Node 已增加只读布局探测与有限安全停机检查点；安装器已实现配置、Compose、完整 data 的私密归档，保留当前较新的授权高水位和原流量文件，冲突拒绝及中断恢复保护。恢复固定原 image ID 并保持停止，后续启动重新取得在线授权。安装器拥有生命周期和归档，Node 复用已有尾量持久化逻辑。

Linux 全包 race 31 个包、1,203 个主/子测试项通过，另有8项条件跳过。安装器16套 shell 回归、27项归档/故障恢复及19项 Docker 编排模拟通过；本地真实 sing-box/Xray 容器的 VLESS、进程回执、备份恢复、中断保护和原batch/周期重放已通过。该 Docker 夹具使用合成 HTTP 控制端，仍需真实 Core/TLS/有限授权与 VMess 链路及完整安装/更新/回滚验收。详见 [P1 验收记录](p1-runtime-backup-verification.json)。Node运行时代码 `91fb762`、Installer `a7d4817` 已提交推送；Node全量/镜像、授权、Machine、流量与安装器CI全部通过。流量下载抖动通过仅CI修复 `ad74757` 复验；精确运行号见 [远端验收记录](p1-runtime-backup-ci.json)。P1整体仍在进行。

## 实现范围与剩余验收

| 范围 | 当前状态 |
| --- | --- |
| 双内核 | sing-box、Xray均保留；[协议支持范围](../../../node/README.md#协议接入与验收范围)以Node适配器为准 |
| 真实协议证据 | 两内核×VLESS/VMess TCP计量/撤销；完整Core/队列/周期链为sing-box VLESS/VMess |
| UDP | 通用packet计量边界有测试；不等于Hysteria/TUIC等全部UDP认证链完成 |
| 观察周期 | 绑定Node已观察到的Core reset epoch；未观察到的离线时钟切换仍需有限租约处理 |
| 旧无周期报告 | 保持接收周期语义，原文件不重贴当前签名周期 |
| 生产切换 | Production PKI、密钥恢复、完整协议矩阵、HA和全局writer/queue排空仍待验收 |

## Core C1：订单购买权益快照

Core C1 候选已新增正式 `tx_order_purchase_terms`，用户建单与管理员手工分配均在同一事务内冻结订单身份、应付金额、币种、购买额度/分组/限速、周期与开通事件。`purchase_terms_version` 区分新旧订单；读取时验证完整性和归属，快照缺失/损坏拒绝履约，Plan后续修改或删除不会改变已有购买额度。手工报价显式标明来源，不要求对应目录价格。重置策略仅记录为后续订阅权威输入，当前持续重置仍由原生Plan/system策略负责。

C1已完成实现与本地批次验收，通过PR #247提交审阅，不代表Core完成或已发布。收尾修复MySQL百并发测试的快照清理顺序，保留正式外键删除保护，并验证100个独立会话只生成一笔订单和一份快照（108条断言），清理后无残留。独立MariaDB执行生产迁移及原88项相关回归（86通过、2项既有独立连接条件跳过、654条断言）；新增快照失败回滚、重置失败重试、一次性套餐与换套餐4项SQLite回归通过（39条断言）。

2026-10-15已核实最终代码提交`ea5c4c4`的54个远端检查：52成功，`release-gate`与`publish`两项按审阅分支策略跳过，无失败或未完成项。MySQL 8.4专项、API回归和Core镜像验证/恢复均通过，精确运行号见[收尾验收记录](core-c1-closeout-verification.json)；这里的MySQL证据来自远端CI，不是把本地MariaDB等同MySQL。收尾文档不改变已验证源码，不重复启动全量应用测试。非作者领域批准、维护者合并及镜像发布仍分别待完成；PR最终head的检查以远端为准，不把跳过的发布任务记为已发布。接口见[Core Commerce v1](../../../contracts/v2/core-commerce-v1.md)。

C1开发与批次验收已收尾；C2已按[Core完成计划](core-completion-plan.md)开始开发。C2分支`feat/core-native-subscriptions`基于C1最终提交`5e03dbc`，明确依赖尚未合入的PR #247，不将审阅分支当作已交付的默认分支。

## Core C2-A：安装权威与套餐发布准备

首批实现持久安装权威及套餐发布基础，复用`tx_authority_writer_fence`单例和现有Revision/Offer/Availability规范表。安装身份、模式、版本、epoch与初始化业务键持久校验；native初始化逐表拒绝业务数据和历史演练证据，仅允许明确列举的配置元数据及无业务资产的引导管理员。HTTP、队列、业务CLI和已有命名写入者执行准入校验，关闭旧演练fence或将环境配置改回legacy都不能绕过已持久化的native记录。

新增`/txapi/readiness`，失败返回有界非秘密503；`/api/health`和`/txapi/health`保持不依赖数据库的存活检查。镜像准备与Octane进程可以启动，但业务仍逐项拒绝；entrypoint不对native准备实例运行旧更新器。套餐发布冻结精确价格、额度、日历、重置与开通策略，重复业务键不重置销售状态；独立Availability使用版本CAS，并在重放/变更前验证完整发布证据。

独立MariaDB生产迁移、安装/发布运行时及C1履约30项/194断言通过；12个独立进程初始化通过77条断言，仅生成一个不可变安装记录。初次API全量扫描的夹具缺失和旧断言冲突已逐项定位，补齐契约、现有入口HTML及私有Redis后执行330项相关复测：294通过、35条件跳过；剩余一处SQLite元数据断言修正后单项复测通过15条断言。未重复运行已通过用例，不把分批修复记录称为一次最终全绿的本地全量运行。真实路由255个HTTP方法/10类及14项路由脚本测试通过，MySQL 8.4与镜像门禁保留远端CI验收。详见[C2-A验收记录](core-c2a-verification.json)和[Core Subscription v1](../../../contracts/v2/core-subscription-v1.md)。

**C2整体仍在开发，native始终为`initializing`，本批没有激活操作。** C2-A尚未合并或发布，先依赖C1合入并完成非作者审阅和相关CI；后续领域实现与生产启用分别记录，不把影子模型或合成Provider视为已交付。

## Core C2-B：原生订阅领域与应用适配

2026-10-16候选实现接入已发布Offer建单、`tx-core.purchase-terms/v2`不可变购买证据、真实Subscription/Terms履约，以及新购、续费、换套餐和生命周期操作。持久Slot约束账户容量，履约、订单完成和操作回执在同一事务提交；暂停、恢复、撤销、到期和重置使用预期版本与业务幂等键。重置保留旧UsagePeriod，续费与换套餐按冻结策略保留或重置用量，不读取后来编辑的Plan作为已购权益。

共同权益读取器依据账户封禁、原生安装归属、Terms时间线和UsagePeriod计算非秘密权益。账户接口不再以旧User权益字段或UUID描述原生授权；用户建单和管理员订阅操作委托领域服务，原生定时推进只处理存储的到期/重置边界。Usage v2接收、登记和结算重新验证安装及聚合归属；接受后的旧周期流量仍归原周期，跨已接受流量引入的新边界拒绝。安装权威及历史证据强制读主库，滞后副本不能回退到legacy运行。

本批开发与本地验收已完成：最终SQLite原生专项90项中88通过、2项独立MySQL条件跳过，共949断言；独立MariaDB生产迁移及最终容量并发、恢复、权益和用量专项20项/285断言通过。此前54项MariaDB领域测试通过734断言；699项相关回归中的Redis夹具缺失已通过独立Redis复测解决，不将分批修复称为一次全量全绿。45个PHP文件语法、工作流YAML、256个HTTP方法路由边界及10项路由脚本测试通过，详见[C2-B验收记录](core-c2b-verification.json)。

收尾修复了套餐锁前建立RR快照导致容量判断滞后、订阅查询全表扫描锁住其他账户、原生调度遗漏订单恢复三个问题。新增账户有序索引，恢复任务仅扫描v2订单并有界轮转，队列派发失败可补派、超时未付订单释放预留，失败低ID订单不阻塞后续任务。远端百并发又定位到幂等回放的普通订单读取使用旧快照，已改为锁定当前读取。2026-10-16核实PR #248精确提交`0adb0dd`的61项检查：57成功、4项发布相关任务按分支策略跳过，无失败或未完成项；包含MySQL 8.4回归、原生订阅专项和安装初始化。该证据不等于已合并或发布。

领域测试替换原生准入边界以验证事务，**这种夹具不代表生产已经就绪**。真实安装仍只接受`native/initializing`；即使手动写入并重新计算`native/open`记录哈希，也拒绝运行。C2-B不签发Provider凭据，不开放原生Node完整/增量授权或订阅分发，也不包含C3账本、余额抵扣或退款。当前未合并、未发布，C2整体仍未完成。

C2-C按真实Provider控制操作、材料消费与完整授权链继续集成；首批C2-C1结果见下节。生产启用仍要求旧任务拒绝、写入者覆盖和必要的队列排空证据。接口及边界见[Core Subscription v1](../../../contracts/v2/core-subscription-v1.md)。

## Core C2-C1：真实 Provider 签发与故障恢复

本批新增独立Go Provider进程，在私有bbolt存储中生成VLESS/VMess UUID凭据。控制通道使用TLS 1.3、双向证书和Core客户端叶证书指纹限制；issue/query/revoke只返回非秘密引用及回执。Provider身份与epoch持久绑定，重启和幂等回放不重新生成材料；撤销墓碑阻止未知签发在稍后恢复时重新生效。没有材料导出接口，现有Node主程序不会自动启动该Provider。

Core新增原生Provider注册、持久操作与AccessSubject绑定，先提交意图再发网络请求；未知结果先查询原操作，仅明确缺失时重发相同逻辑请求。最终提交重验账户封禁、订阅版本和条款，晚到签发生成持久补偿撤销。用户签发/撤销/只读查询/恢复四个适配器均从认证身份确定归属。登记命令只属于安装准备，不开启业务准入；没有旧UUID兜底，也不提升影子Subject。

本地批次验收完成：Core初次100项中99通过、1项独立MySQL条件跳过，共712断言；修复后受影响的codec、真实准备门禁与领域60项通过474断言。独立MariaDB生产迁移及领域/API最终19项通过444断言，修复了空库回滚时InnoDB替代原外键索引造成的依赖问题。真实独立Provider/mTLS联调4项通过75断言，包含响应丢失恢复、封禁后的补偿和先撤销再恢复未知签发；Go专项race的17个主测试及其子测试通过。260个HTTP方法、17项路由/原生表名脚本测试、PHP语法和工作流解析通过。完整证据见[C2-C1验收记录](core-c2c1-verification.json)。

本阶段基于未合入的C2-B PR #248，继续依赖C1 PR #247；远端本批CI、非作者审阅、合并和发布分别记录。**C2整体尚未完成，真实native安装仍保持`initializing`。** 下一阶段接通Provider材料到Node及订阅渲染的受控消费、凭据轮换与撤销同步、共同权益的REST/WS完整及增量授权，并验证这些授权产生的流量结算。上述链路完成后才能引入明确的就绪转换；C2-C1真实签发成功不能作为生产激活证据。

## 下一步顺序

2026-10-16新增C2-C2候选（`feat/core-provider-node`，依赖PR #249）：Core以共同订阅权益和真实签发回执签署非秘密凭据摘要，Provider通过独立Node mTLS身份交付材料，Node校验摘要及实际内核协议后使用已有有限授权机制。空撤销快照不依赖Provider在线，签发后续检查当前Machine/Token身份；native旧流量计费路径明确拒绝。实现、已完成的早期本地检查与尚待远端验收分别见[C2-C2记录](core-c2c2-verification.json)。不能把候选推送或测试夹具视为生产就绪。

用户同日要求降低本地资源占用，后续数据库、真实服务/流量、race、完整构建和大范围回归统一优先放到GitHub Actions；本地只做轻量检查。本阶段最终真实Node链路及新增协议绑定检查交由`native-provider-node` workflow验收，精确提交结果记入PR。

1. **C2 收尾合并**。按 PR #251 的精确提交完成远端验收与领域复审，并依次合入 #247、#248、#249、#250、#251；原生生产启用遵循持久 preflight/activate，不通过环境变量绕过。
2. **Core：支付事实、账务与退款**。复用已有支付验证和交易入口，补齐payment attempts、账本及退款状态闭环。
3. **Core：正式Internal API与凭据生命周期**。在当前权限与服务边界内逐批开放，保留未完成生产秘密交付的状态。
4. **Core集中交付后收口安装器**。基础安装、启动、正常更新及关联恢复验收；Node/安装器边缘测试不阻塞Core主线。新前端和P2商业/HA扩展继续后移。

完整顺序和完成条件见[基础功能优先级](development-priorities.md)。P0.7属隔离合成环境验收，不代表生产密钥自动配置、PKI/HA或完整迁移门禁通过。

2026-10-15 用户确认采用[批次开发与集中验收](../../development/development-workflow.md)：连续实现一个或多个相关阶段，集中测试修复后提交推送。当前文档调整不增加功能完成度，也不把尚未执行的发布验收记为通过。

2026-10-15 已定义[多团队PR协作方案](../../development/multi-team-development.md)，Core/Node/Gateway/Deploy按边界分工，统一接口与数据库协调；后续贡献经PR审阅合并，沿用批次集中验收。团队账号、CODEOWNERS及远端保护由维护者后续配置；文档更新不代表这些设置已生效。
