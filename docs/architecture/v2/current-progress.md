# 当前开发进度与下一步

更新日期：2026-10-14。当前主仓负责 Core、Gateway、Node；安装器由独立 TXBoard-Deploy 承接。新管理前端和用户前端延期，后续统一放在 TX-F，仅本地提交、不推送。Agent/MCP 已退役；优惠券等新增商业扩展继续后移。

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

1. **P0：凭据生命周期与有限离线授权**。复用已完成的Provider候选和Node停止/恢复能力，补齐实际发行交付、有限租约、到期/封禁/撤销、离线收敛和恢复验收。
2. **P1：独立安装器单机交付**。由TXBoard-Deploy完成三组件安装、配置、固定版本更新、备份恢复和失败恢复。
3. **P1：必要Gateway/Internal API**。围绕基础业务补齐真实调用与当前权限；管理员Gateway启用仍须对应契约和门禁。
4. **P1：生产安全、恢复及基础购买支付**。继续整合已有业务，逐项验证生产证书/密钥、恢复保护与支付账务。
5. **P2：商业扩展和高级拓扑**。优惠券、礼品卡、复杂佣金、Cluster/HA等按后续独立切片推进。

完整顺序和完成条件见[基础功能优先级](development-priorities.md)。当前摘要不替代每个阶段的验收报告，也不把隔离测试视为生产站点验收。
