# 当前开发进度与下一步

更新日期：2026-10-15。当前主仓负责 Core、Gateway、Node；安装器由独立 TXBoard-Deploy 承接。新管理前端和用户前端延期，后续统一放在 TX-F，仅本地提交、不推送。Agent/MCP 已退役；优惠券等新增商业扩展继续后移。

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

## 下一步顺序

1. **P1：独立安装器单机交付**。配置pin、完整运行备份恢复与合成Docker冒烟批次已收尾。下一批优先基础安装、启动与正常更新，完成相关交付功能后统一测试修复。VMess恢复不另开独立测试批次；真实Core/TLS/有限授权、VMess恢复及安装更新失败回滚的剩余证据安排在关联批次或发布验收。
2. **P1：必要Gateway/Internal API**。围绕基础业务补齐真实调用与当前权限；管理员Gateway启用仍须对应契约和门禁。
3. **P1：生产安全、恢复及基础购买支付**。继续整合已有业务，逐项验证生产证书/密钥、恢复保护与支付账务。
4. **P2：商业扩展和高级拓扑**。优惠券、礼品卡、复杂佣金、Cluster/HA等按后续独立切片推进。

完整顺序和完成条件见[基础功能优先级](development-priorities.md)。P0.7属隔离合成环境验收，不代表生产密钥自动配置、PKI/HA或完整迁移门禁通过。

2026-10-15 用户确认采用[批次开发与集中验收](../../development/development-workflow.md)：连续实现一个或多个相关阶段，集中测试修复后提交推送。当前文档调整不增加功能完成度，也不把尚未执行的发布验收记为通过。

2026-10-15 已定义[多团队PR协作方案](../../development/multi-team-development.md)，Core/Node/Gateway/Deploy按边界分工，统一接口与数据库协调；后续贡献经PR审阅合并，沿用批次集中验收。团队账号、CODEOWNERS及远端保护由维护者后续配置；文档更新不代表这些设置已生效。
