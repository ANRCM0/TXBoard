# 当前组件接口与支持矩阵

> 更新：2026-10-16 · 合并基线：C2 已合入 `main`（`ccf0b8c`）；本分支含未发布的 C3/C4 集成候选。
>
> 本页由当前实现手工维护，描述组件职责、接口支持与缺口，不是旧版本兼容承诺。B0 的旧字段/路径差异保留在[历史清单](baseline-inventory.md)及[JSON](baseline-inventory.json)，不能覆盖本矩阵。

项目处于开发阶段，允许同批破坏性更新接口及真实消费者，不要求旧站回填、旧 Token 迁移或双阶段兼容。历史适配器尚存在不代表必须继续支持；相关清理按业务批次完成。

| 边界 | 当前接口与行为 | 明确缺口或限制 | 当前依据 |
| --- | --- | --- | --- |
| User / Admin → Core | 注册的 `/txapi/*`；User Sanctum Bearer，Admin 动态路径/Bearer/RBAC/audit | native 不支持的旧写入明确拒绝；新 UI 延期，现有入口仍存在 | [HTTP 身份边界](../../../contracts/http/external-adapter-current.md) |
| Gateway → Core | 可选用户 BFF；候选加入独立 User/Admin 服务客户端与 server CLI，不自动开放管理员浏览器路由 | 候选尚需实际 CI/生产通道验证；旧订单 DTO 不等于 native 购买支持 | [Gateway native 对接](../../../gateway/docs/native-core-integration.md)、[正式服务契约](../../../contracts/v2/native-internal-api-v1.md) |
| 套餐 / 订单 → 订阅 | 不可变 Revision/Offer、v2 购买证据，原子履约与生命周期；C3 候选加入 CNY/无手续费支付、钱包、充值/退款、持久恢复 | C2 仅免费；候选未发布，升级折抵/部分精准全退未交付 | [支付结算](../../../contracts/v2/native-payment-settlement-v1.md)、[钱包退款](../../../contracts/v2/native-wallet-refunds-v1.md) |
| Core → Provider | 独立进程 mTLS；持久 issue/query/revoke/rotate；Core 仅保存非秘密绑定 | 当前即时轮换，不宣称重叠轮换、生产 PKI 或证书全生命周期完成 | [真实发行](../../../contracts/v2/credential-provider/native-issuance-v1.md)、[C2 runtime](../../../contracts/v2/native-c2-runtime-v1.md) |
| Core / Provider → Node | Core 有限签名绑定 Node、Subject、凭据承诺与 Usage Session；Provider 向授权 Node 交付秘密 | C2 支持 sing-box VLESS/VMess direct TCP；其他内核/传输及完整矩阵不属 C2 交付 | [Node 材料交付](../../../contracts/v2/credential-provider/native-node-delivery-v1.md)、[C2 runtime](../../../contracts/v2/native-c2-runtime-v1.md) |
| 客户端 → Provider 渲染 | Core 产生短期签名策略；候选加入独立可撤销链接及显式信任轮换，Core 不接收代理 UUID | 候选真实通道/信任分发未验收；已有有限授权仅在原到期时间前可用 | [C2 runtime](../../../contracts/v2/native-c2-runtime-v1.md)、[Provider 生命周期](../../../contracts/v2/native-provider-lifecycle-v1.md) |
| Node → Core 控制 | `/txapi/node/v1/*` HTTP 与独立 Workerman WSS；Node/Machine 专用身份 | HTTP 路由存在不证明 WSS 进程在线；WS 触发完整授权刷新 | [Node Current](../../../contracts/node-protocol/txnode-integration-current.md) |
| Node → Core 用量 | `/txapi/node/v2/usage/*`；持久原 Session/批次/Period、真实接收回执、预留与结算 | 接收/隔离不等于结算；native 不使用旧 User 计费，不能将原批次贴到新周期 | [C2 runtime](../../../contracts/v2/native-c2-runtime-v1.md)、[Node Usage](../../../contracts/v2/node-usage/README.md) |
| Worker / Redis / SQL | 领域事务与 SQL outbox/接收记录恢复；Redis 用作队列、缓存和控制分发 | 不保证 Redis 永不丢消息；运行恢复必须依持久事实 | [运行拓扑](runtime-topology.md) |
| Module / Plugin / Theme | Registry 提供库存，Lifecycle 委派专用运行时；候选 typed 支付插件停用后保留历史验证/查询 | PHP 可信进程内代码；无通用沙箱，支付历史永久 pin 尚无旧版本交接 | [Module 平台](../module-platform-v1.md)、[支付插件](../../../contracts/v2/native-payment-provider-v1.md) |
| Deploy / health | `/api/health`、`/txapi/health` 是存活；`/txapi/readiness` 校验持久安装权威；安装器独立 | C5 新安装/正常更新/发布仍待收口；旧站迁移不是本轮门禁 | [HTTP 身份边界](../../../contracts/http/external-adapter-current.md)、[交付计划](core-completion-plan.md) |
| Agent / MCP | 已退役；旧 Agent 凭据拒绝 | 不恢复 API、Token、嵌入进程、巡检或 CI | [范围与退役](admin-only-scope.md) |

## 如何使用

新增或修改行为时更新当前契约、真实调用者和本矩阵；不为旧版本增加兜底。权限、资金、事务及秘密边界继续验证。一次相关批次集中运行必要 CI；文档变更只做差异、引用和一致性检查，不重跑旧组件矩阵。

已合并、已验证与已发布分别记录。[当前进度](current-progress.md)提供主线状态；精确提交的验收以对应 PR/报告为准，历史失败及修复记录不能直接作为当前失败。
当前未完成项及关闭条件见[内核成熟度评审](core-maturity-review.md)。
