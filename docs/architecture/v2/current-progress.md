# 当前实现基线

更新：2026-10-17。本文只记录当前能力及限制，不安排下一轮开发任务。

## 源码基线

C1/C2 及支付、资金、升级折抵、统一插件框架和正式服务接口子集已合入 `main`。K0-K3 与 K4 账户/订单/会话入站子集由 PR #263-#268 依次合入，当前源码基线为 `f59f1675932fa9e52181613cf9e1792bec04729d`；PR #263-#268 的触发开发检查通过，主线集成检查另行核对。合并、CI 通过和生产发布是不同状态。

| 范围 | 当前实现 | 限制与权威契约 |
| --- | --- | --- |
| 订阅 | 不可变 Revision/Offer、购买证据、持久安装激活、履约及生命周期、共同权益 | [订阅契约](../../../contracts/v2/core-subscription-v1.md)、[有界运行契约](../../../contracts/v2/native-c2-runtime-v1.md) |
| 支付结算 | 当前渠道插件、冻结 dispatch、CNY 无手续费验签/查询、唯一获胜分配、迟到负债及 SQL 恢复 | 不支持非 CNY、手续费和网络建单；[结算契约](../../../contracts/v2/native-payment-settlement-v1.md) |
| 钱包与退款 | 双分录、充值、钱包/混合付款、取消释放、累计退款至钱包、有界只读对账 | 不做自动修账、原路或精准权益退款；退款不修改权益或履约 work；[钱包契约](../../../contracts/v2/native-wallet-refunds-v1.md) |
| 升级折抵 | 不可变来源报价、现金 claim、非现金 carry、共享退款来源预算、接受与 SQL 升级原子提交 | 升级非现金 credit 不可退款；[折抵契约](../../../contracts/v2/native-upgrade-credit-v1.md) |
| 扩展 | 统一当前 package/payment 契约、Registry/Lifecycle、开发 starter、停用后历史验证与查询 | 不兼容旧插件；金融 pin 阻止有 dispatch 的包破坏性升级/卸载；PHP 插件不是沙箱；[支付插件契约](../../../contracts/v2/native-payment-provider-v1.md) |
| 服务接口 | 独立服务身份与实际 actor、mTLS、固定 operation、永久 workflow 回执及审计；Gateway 客户端和服务端 CLI | 只支持已列操作，非完整管理 API；无管理员浏览器通道，完整嵌套输出 Schema 未覆盖；[接口契约](../../../contracts/v2/native-internal-api-v1.md) |
| Core 模块边界 | K0-K3 与 K4 账户、订单、会话子集已合入；HTTP/Internal API 共用相应领域 owner | Usage 入站查询仍在候选实施；K4 其他实际消费者盘点与 K5 集成收尾未完成；[能力清单](core-capability-map.md) |
| Provider | 独立秘密存储、发行/查询/轮换/撤销、Node 直交付与客户端直渲染、显式信任转换及独立可撤销链接 | 生产 PKI、实际证书轮换及 Node 新信任分发未验收；[生命周期契约](../../../contracts/v2/native-provider-lifecycle-v1.md) |
| Node 与用量 | 有限签名授权、持久原批次/Session/Period、配额预留及原周期结算 | 有界 native 支持 Linux sing-box VLESS/VMess direct TCP；接收/隔离不等于结算，全协议/平台和 HA 未验收 |

## 验证与发布边界

PR #263-#268 精确提交触发的 API、MySQL 初始化/账务/订阅、native C2、文档和镜像 verify 检查通过；release/recovery/publish 与 mysql-regression 等未触发或跳过项不计通过。合并主线 `f59f167` 的 API、native C2、Core image、文档以及最终 native-subscription-domain、native-billing-domain 和 installation-bootstrap 均已通过，mysql-regression 跳过。Gateway PR #269 的最新提交 `c9f0424` 已通过 verify/container/browser/native-commerce-http/docs/API；API 1214 passed、33 skipped，真实 HTTP 38 checks。旧提交联调失败已在新提交中修复并复验；PR 仍为 Draft、未合并。完整结果依精确 SHA 区分，不覆盖后续改动。

Usage 周期查询 K4 候选的真实 API/MySQL 验收待运行；Gateway #269 待领域审阅与合入。仍未完成生产 PKI/反向代理与真实支付商户验收、固定版本的新安装/正常更新/恢复与正式发布收口。Gateway 创建订单仍默认关闭；本批只统一已发布 `offer_id` DTO，不代表支付或履约完成。现存 Xboard Node 适配器仍是独立产品能力，不是 native 全矩阵验收证明。

本仓维护 Core/Gateway/Node；TXBoard-Deploy 是唯一安装器。新增管理/用户前端延期至 TX-F，仅本地提交、不推送；Agent/MCP 已退役。开发及数据规则见[Native 开发基线](../../development/native-development-baseline.md)。历史验证 JSON 是当时快照，保留原字节，不覆盖本页当前状态。
