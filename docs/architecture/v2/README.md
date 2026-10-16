# Core First v2：当前架构与开发基线

> 更新：2026-10-16 · 代码基线：`main` 的 C2 合并提交 `ccf0b8c`。
>
> C1、C2 已合并，本分支整合 C3/C4 候选，尚未据此宣布完整内核成熟或生产可用。本目录保留早期 B0—B8 与 P0/P1 证据；历史切片状态不能覆盖本页和[当前进度](current-progress.md)。

## 当前开发决策

项目仍处于开发阶段，尚未接入正式测试部署，不承担旧版本过渡兼容承诺。开发主线面向全新 native 安装；允许同步修改契约及真实消费者、进行破坏性变更，并在明确为可丢弃的开发环境中重建数据库。无需为旧部署增加回填、双写、shadow 比较、兼容兜底或回退窗口。

这个决定不等于仓库中的 legacy/shadow 代码已经删除。现存实现按相关业务批次清理，删除时同步更新调用者和契约；不再为它们扩展新功能。不支持的模式明确拒绝，不能因取消兼容而绕过身份、事务、持久授权或凭据隔离。

真实资金、不可丢弃数据不属于开发数据库重建范围；未来如确有旧站迁移需求，另立项目及验收条件，不列入当前 C3—C5 门禁。规则见[Core 演进与数据处理](core-evolution.md)。

## 当前架构

Core 是账户、订阅、权限、订单和用量结算的唯一持久业务权威。Laravel API、Worker 与 Scheduler 共享领域规则和 SQL 事务；Gateway 是可选 BFF；独立 Credential Provider 保管代理秘密；TX-Node 执行有限授权并持久记录用量。代码合仓不合并身份和运行职责。

| 组件 | 已有行为 | 当前缺口 |
| --- | --- | --- |
| Core | C1/C2 已合并；C3/C4 候选增加支付事实、账本/钱包/退款、正式 Internal API | 候选需集成 CI；付费升级折抵与部分精准全退未实现，C4 操作覆盖未全 |
| Credential Provider | 独立进程、mTLS 签发/查询/轮换/撤销、Node 材料交付、客户端直接渲染；候选加入独立链接与显式信任转换 | 生产 PKI/代理通道和真实轮换验收，Node 新信任分发仍需运维协调 |
| TX-Node | 同一有限签名绑定访问和计量、持久批次与回放；C2 支持 sing-box VLESS/VMess direct TCP | 全协议/平台矩阵、HA 等不属已完成范围 |
| Gateway | 可选用户 BFF；候选加入真实 mTLS 服务客户端及服务端 CLI | 不自动开放管理员浏览器通道；旧购买 DTO 不冒充 native Offer 契约 |
| Plugin/Theme | 现有 Module 委派；PHP 插件仍是可信进程内代码 | 没有通用第三方插件沙箱，不宣称已隔离 |
| 前端与安装 | 现有入口仍存在；新增前端延期至 TX-F，安装器独立由 TXBoard-Deploy 承担 | C5 完成基础安装、正常更新和发布收口 |

C2 合并基线只开放零价 Offer；本分支候选加入 CNY、无手续费支付。候选行为与已合并范围必须分别阅读，不能把 C3/C4 代码存在等同于通过验收或 C3—C5 完成。

## 阅读入口

- [Core 边界决策](core-boundaries.md)：权威、身份、秘密与扩展职责。
- [Core 内核与插件扩展边界](core-extension-boundaries.md)：业务事实与可替换能力的责任矩阵，支付渠道、资金内核及停用恢复规则。
- [当前接口支持矩阵](compatibility-matrix.md)：当前组件支持与明确缺口。
- [运行拓扑](runtime-topology.md)：现有运行角色和 Compact/Standard/Cluster 目标。
- [完整交付计划](core-completion-plan.md)与[开发优先级](development-priorities.md)：C3 → C4 → C5。
- [当前进度](current-progress.md)与 [Native C2 runtime](../../../contracts/v2/native-c2-runtime-v1.md)：已合并范围及限制。
- [内核成熟度评审](core-maturity-review.md)：候选证据、阻塞项和下一轮完成门槛。
- [开发与批次验收流程](../../development/development-workflow.md)：本地轻量检查、CI 集中验收、不重复已通过测试。

跨组件行为以[当前 HTTP 契约](../../../contracts/http/external-adapter-current.md)、[Node 契约](../../../contracts/node-protocol/txnode-integration-current.md)及对应 [v2 契约](../../../contracts/v2/README.md)为准；目标文档不能使未注册接口自动可用。代码位置见[仓库布局](../../development/repository-layout.md)。

## 历史资料

[B0 清单](baseline-inventory.md)、[静态 JSON](baseline-inventory.json)、[实施台账](implementation-plan.md)、影子回填及早期验收报告保留当时路径、提交和结论，仅供历史追溯。它们不构成当前版本必须保留旧入口、兼容旧数据库或重跑历史矩阵的要求。新的架构总览与接口支持矩阵由当前实现维护，不使用 B0 生成结果覆盖。
