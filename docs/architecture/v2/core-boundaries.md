# Core First 边界决策

> ADR-CF-001 · 初始决策：2026-10-12 · 更新：2026-10-16。
>
> Core First 方向继续生效；开发期兼容策略由 2026-10-16 用户决定更新。C1/C2 已合并，后续能力按 C3—C5 实现，不以架构声明代替交付。

## 唯一业务权威

Core 基于现有 Laravel 构建模块化控制平面。账户身份与订阅权益分离；权益、用量和资金事实具有明确版本、归属及持久记录。API、Worker、Scheduler 共用领域规则；Gateway、Provider、Node 是访问或执行角色，不另建订单、权限或财务真相。

具体归属见 [Core 内核与插件扩展边界](core-extension-boundaries.md)。支付渠道是可替换插件；付款尝试、收款事实、钱包账本、退款规则和履约一致性仍由 Core 掌握。“支付属于 Core”不表示把具体支付商协议写进内核。

| 领域 | Core 权威 | 其他角色 |
| --- | --- | --- |
| Identity / RBAC | 账户、会话、管理员权限、资源归属、审计 | Gateway 适配入口并传递可验证调用者身份 |
| Plan / Subscription | 不可变 Revision/Offer、购买证据、订阅、Terms、有效期及配额 | 外部销售适配不能直接开通权益 |
| Order / Billing | 订单、履约；C3 完成支付尝试、收款事实、资金账户/分录及退款 | Payment 插件提供具体渠道协议、验签、查询和结果映射，不直接落账或开通权益 |
| Network / Usage | Node/Machine/Group/Route、Subject、授权政策、持久接收与周期结算 | Node 执行有限授权、记录及重报；Provider 管理代理凭据 |
| Subscription delivery | Subject 归属、权益过滤、有限渲染授权；C4 完成长链接生命周期 | Provider 渲染并直接交付给受权接收方 |
| Module / Plugin | 库存、权限授予、生命周期委派与审计 | 当前 PHP 插件为可信进程内代码；独立 Provider 有自己的运行边界 |
| Operations | 持久安装模式、schema/epoch、readiness | TXBoard-Deploy 负责安装/更新/恢复编排 |

新安装的订阅权益由 `Subscription/Terms` 决定，用量由 `UsagePeriod`、接收预留与结算记录决定。旧 User 套餐、流量和 UUID 字段不作为 native 业务权威。C2 只开放免费 Offer；资金账本尚未交付，不能把旧余额字段当作新的 native 资金实现。

## 开发期变更策略

项目尚未接入正式测试部署，不维护旧版本过渡窗口。允许破坏性调整 Schema、接口、DTO 和包契约，条件是同批更新真实消费者、契约及必要验证。无需为旧版本引入新适配器、shadow 模型、双写或降级路径。

可丢弃开发数据库可以按[数据处理规则](core-evolution.md)重建；旧站数据迁移另立需求。现存 legacy/shadow 路径、旧协议适配器与 PHP 插件并未因本决策自动消失，须在关联实现中核对依赖后清理。当前已有 UI/Theme 是否替换仍由前端范围决定，不能把“取消兼容”写成“新前端已完成”。

不依赖特定 Provider 的 Core 业务继续可用；缺失凭据能力时明确返回不可用，禁止 Core 生成代理 UUID 兜底。身份隔离、真实事务和失败恢复不因开发期破坏性变更而放宽。

## 信任与权限

1. Gateway 不直接读写 Core MySQL、不发布 Core Redis、不保存订阅/订单/余额权威。Core 逐次校验调用者、权限、归属及前置状态；mTLS 只证明服务身份。
2. User、Admin、Node/Machine 和支付回调保持独立凭据与授权链。管理员当前仍使用动态路径、Admin Bearer、RBAC 与审计；Gateway 管理员入口尚未交付。
3. Access Subject 是非秘密归属和计量身份，不是 Bearer 或代理密码。Provider 能力声明不能授予管理员权限。
4. Core 仅保存代理凭据的非秘密引用、承诺、版本、回执和归属。代理 UUID、私钥、可直接使用的配置只在 Provider 与授权接收方之间交付，不能进入 Core 任意 DTO、事件、日志或管理清单。Core 自身会话及服务身份材料按各自边界保护。
5. Provider 网络调用不占用业务 SQL 事务；意图、最终重验及补偿由 Core 持久协调。未知结果查询原操作，不以新业务键重复发行或履约。
6. Node 验证签名目标、revision 和期限；持久记录原 Session/批次后重报，不能自行更换结算周期或将隔离回执当作已结算。
7. PHP 插件仍能在可信宿主进程内执行。通用插件隔离是未来能力，不能宣称现有插件已被禁止读取宿主数据库或秘密。

## 当前实现与后续交付

| 边界 | 当前实现 | 后续条件 |
| --- | --- | --- |
| 订阅与授权 | C1/C2 已合并；native 持久激活、共同权益、Provider/Node/渲染及周期结算闭环 | C3 接入真实资金，不能提前开放付费 |
| Gateway | 可选用户 BFF；native 适配已存在 | C4 正式 Internal API、服务身份及 User/Admin 授权，完成后更新入口 |
| 凭据隔离 | 独立 Provider，Core/Node 客户端身份分离，材料受签名授权约束 | C4 生产 PKI、密钥/信任轮换及长期链接 |
| 安装拓扑 | 当前制品包含 API、Worker、调度和 Workerman 等角色 | C5 单机交付；Standard/Cluster 仍是目标，见[运行拓扑](runtime-topology.md) |
| 前端与 Agent | 新前端延期至独立 TX-F；Agent/MCP 已退役，旧凭据拒绝 | 不恢复 Agent/MCP；前端工作不代替 Core 交付 |

当前入口以[外部适配契约](../../../contracts/http/external-adapter-current.md)、[Node 契约](../../../contracts/node-protocol/txnode-integration-current.md)和 [C2 运行契约](../../../contracts/v2/native-c2-runtime-v1.md)为准。后续按[完整交付计划](core-completion-plan.md)推进，历史 B0—B8 迁移设计不再作为开发期旧版本兼容要求。

## 代码边界收敛

当前订阅业务统一位于 `api/app/Domains/Subscription/Services`，HTTP、命令、Node 授权与 UsageV2 直接使用这些领域服务，不保留旧 Services 路径别名。共用 Schema 校验归入 Installation，规范模型基类为 SubscriptionRecord。已删除的隔离影子工具及仍需处理的运行时依赖见 [Core 清理](../../development/core-cleanup.md)。
