# Core First v2 契约目录

> 更新：2026-10-17。C1、C2 及 C3/C4 本批实现已合入 main；C3-A 见 PR #260（`070a5df`），结算、钱包退款、升级折抵、统一插件框架及 C4 服务接口子集见 PR #261（`eba7ed8`）。合并不等于完整 C4、C5 或生产验收完成；各协议仍按自己的支持范围和限制阅读。

项目仍处开发阶段，尚未接入正式测试，以当前 native 为开发基线，不承担旧版本过渡兼容。允许生产者、真实消费者与契约同步变更；不为历史协议新增双轨适配。旧迁移/隔离切片的历史证据保留；已退役源码与命令不再可调用，当前领域入口见[架构总览](../../docs/architecture/v2/README.md)，开发规则见[开发基线](../../docs/development/native-development-baseline.md)。

TXBoard 是业务与数据权威。Gateway、Deploy、TX-Node 与可选插件按本目录的版本化契约协作，不能分别定义订单、授权、流量与升级的业务真相。`v2` 是平台演进目录；其中每项协议有自己的版本，不代表全部协议同时升到第二版。

2026-10-13 [当前范围](../../docs/architecture/v2/admin-only-scope.md)：管理前端与用户前端均暂缓，后续集中到独立 `TX-F` 项目，只本地提交、不推送；本仓推进 Core、Gateway、Node，安装器独立成库；Agent/MCP 已退役。现有 Admin/User UI/Theme 的真实消费者继续维护，不承担历史格式兼容；Admin 迁移仍要求独立服务身份、Core 当前授权与通道验收。

| 契约 | 负责边界 | 范围 |
| --- | --- | --- |
| [Core Commerce v1](core-commerce-v1.md) | 正式订单购买快照与履约输入；不等于订阅/账务权威切换 | Core C1 |
| [Core Subscription v1](core-subscription-v1.md) | 安装准备、正式目录及订阅聚合基础；生产启用条件由 Native C2 Runtime v1 补充 | Core C2 基础 |
| [Native C2 Runtime v1](native-c2-runtime-v1.md) | 原生就绪转换、免费购买、Provider 轮换与接收者渲染、签名授权及原周期结算；精确提交验收见 PR #251 | Core C2 收尾 |
| [Core Billing v1](core-billing-v1.md) | C3-A 真实双分录与支付尝试/收款事实基础；付费边界由以下 native 契约补充 | Core C3，已合并 |
| [Native Payment Provider v1](native-payment-provider-v1.md) | 单一显式当前支付插件契约、冻结 dispatch、停用恢复与永久金融 pin；不兼容旧插件，非 PHP 沙箱 | C3，已合并 |
| [Native Payment Settlement v1](native-payment-settlement-v1.md) | 真实 CNY 收款、唯一获胜分配、迟到负债与 SQL 履约恢复；免费永久幂等 | C3，已合并 |
| [Native Wallet / Refunds v1](native-wallet-refunds-v1.md) | 钱包/混合付款、充值、有界只读对账；全量/部分退款仅至钱包，不改变权益或任何履约 work | C3，已合并 |
| [Native Upgrade Credit v1](native-upgrade-credit-v1.md) | 不可变来源报价、现金 claim 与非现金 carry、按源折算、永久键及最长两小时有效期；接受与 SQL 升级原子提交 | C3，已合并 |
| [Native Internal API v1](native-internal-api-v1.md) | 正式服务凭据、实际 actor、mTLS/epoch、固定 operation、审计与原键恢复；不开放任意业务操作 | C4 已合并子集 |
| [Native Provider Lifecycle v1](native-provider-lifecycle-v1.md) | 显式信任轮换、独立链接与有限授权；生产 PKI/信任分发未验收 | C4 已合并子集 |
| [Credential Provider v1](credential-provider/README.md) | 秘密归属；发行、Node 交付、生命周期及有限离线诊断 | 当前入口 |
| [Node Usage v2](node-usage/README.md) | 当前 native 计费主体、持久接收、原周期结算与重放 | C2 native |
| [Secure Channel v2](secure-channel/README.md) | 隔离 SDK 实验与未开放业务通道的安全边界 | Experimental |

## 当前接口仍然有效

当前 HTTP 权威是 [TXAPI 接口](../http/external-adapter-current.md) 和 Laravel 注册路由；当前节点协议是 [Node Protocol v1](../node-protocol/README.md)。当前扩展继续遵守 [Plugin Package v1](../plugin-package/README.md)、[Module Lifecycle v1](../module-lifecycle/README.md) 和 Admin Bridge v1。

不得为了符合目标目录而重新注册已退役的 `/api/v1`、`/api/v2`。正式服务入口仅限上表当前契约的 `/txapi/internal/native/v1/...`，不能据此恢复旧 dispatcher；代码实现、CI 验证、合并及发布分别记录。协议落地需同时更新生产者、实际消费者、直接相关负向检查和能力声明；不要求维护历史协议兼容窗口。

## 共同规则

- 64 位数据库 ID、bytes 与金额最小单位在当前 JSON DTO 中用十进制字符串；时间为 UTC Unix 秒。格式变更同时更新真实消费者，不额外保留旧解码层。
- 权限、财务和流量归属由 Core 验证；声明 capability 或通过 mTLS 不等于最终用户授权。
- 修改已实现的对外行为时，更新对应当前契约和真实消费者；本目录不能作为绕过现有约束的依据。
- 示例是非秘密的目标格式；没有执行迁移或开放接口。

## 待评审的进程内契约

[Core Module Ports v1](core-module-ports-v1.md) 是小内核与业务模块的 Target / Draft 设计，明确公开用例、事务内参与者和外部协议能力的不同语义。它不属于上表已实现 wire API，不注册 HTTP 路由、不扩大能力范围，也不增加旧版本兼容。对应[详细设计](../../docs/architecture/v2/core-kernel-modular-design.md)以当前 native 源码为起点。

旧阶段计划和已退役协议从当前目录移除，原文从 Git 历史查询；历史验证 JSON 保留原字节，不作为当前调用面。安装与恢复操作以 TXBoard-Deploy 对应版本为准。

K1 候选代码的精确进程内接口见 [Core Entitlement Queries v1](core-entitlement-queries-v1.md)，尚待 CI 与评审，不作为已合并 wire API。

K2 候选适配边界见 [Core Provider Client v1](credential-provider/core-provider-client-v1.md)；支付扩展复用现有 Native Payment Provider，不新增通用渠道包装。

K3 履约与付款证明候选子集见 [Core Order Fulfillment Ports v1](core-order-fulfillment-ports-v1.md)，分别说明自有事务、SQL 参与及付款证据语义，不开放任意跨域事务或账本写入。
