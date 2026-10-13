# Core First 边界决策

> ADR-CF-001 · 决策日期：2026-10-12 · 方向：Accepted · 实现：Target
>
> 本 ADR 接受开发方案 v4.0 的演进方向；不表示新运行角色、隔离机制或接口已经交付。当前行为仍由代码、[AGENTS.md](../../../AGENTS.md) 和既有版本化契约决定。

## 背景与证据

TXBoard 已具备业务领域和 Module Platform v1，适合增量演进。当前 [User](../../../api/app/Models/User.php) 同时持有账户、套餐、到期、流量、余额和代理 UUID；[OrderService](../../../api/app/Services/OrderService.php) 直接修改这些字段。[ModuleRegistry](../../../api/app/Services/Module/ModuleRegistry.php) 汇总库存与健康，[ModuleLifecycle](../../../api/app/Services/Module/ModuleLifecycle.php) 委托 Plugin/Theme 专用运行时；它们不是独立插件安全边界。完整盘点由[基线清单](baseline-inventory.md)维护。

## 决策

Core 是唯一业务与持久数据权威，基于现有 Laravel 演进。User 与 Subscription 分离，权益、使用量和资金变更有版本及流水。统一 Gateway、Node Control、Core Worker、插件运行时是访问与执行角色，不产生第二套订单、授权或财务真相。

| 领域 | Core 权威 | 扩展/边缘职责 |
| --- | --- | --- |
| Identity / RBAC | 账户、会话、管理员权限、审计 | 前端展示；Gateway 执行入口策略并传递可验证身份 |
| Plan / Subscription | 商品、不可变权益版本、报价、订阅归属和有效期、配额 | 第三方导入/销售适配不能直接开通权益 |
| Order / Billing | 订单明细、支付状态、钱包、复式账本、退款/折抵规则 | Payment Provider 验签、渠道请求、渠道结果映射 |
| Network / Usage | Node/Machine/Group/Route、Access Subject、授权政策、持久批次、结算流水 | TX-Node 执行授权快照、采集与重发；Provider 管理代理凭据 |
| Subscription delivery | 链接归属、链接撤销、权益过滤 | Provider 渲染凭据；外部前端提供链接管理体验 |
| Plugin Platform | 包兼容、登记、权限授予、生命周期协调、事件投递与审计 | 独立 Provider、Payment、通知运行时 |
| Operations | Core 就绪条件、迁移状态与契约能力 | Deploy 管安装/升级/恢复编排，Node Installer 管节点运行包 |

核心依赖指向稳定契约和领域接口。插件/适配器依赖 Core 契约；Core 不依赖某个具体 Provider、支付商或 MCP。没有 Credential Provider 时，Core 仍可管理账户、套餐、订单和节点，发行代理凭据返回 `CAPABILITY_UNAVAILABLE`，不能生成临时原生 UUID 绕过能力边界。

## 信任与权限边界

1. Gateway 不直接读写 Core MySQL，不发布 Core Redis，不保管订阅/订单/余额业务状态。Core 对每次调用再次校验身份、权限、资源归属和状态前置条件。mTLS 只证明服务身份，不能替代 User/Admin 授权。
2. User、Admin、Node/Machine、Payment 回调使用独立凭据和授权链。User 的身份不能通过更改 operation 名变成 Admin；管理员动态路径保护在兼容期继续生效。
3. Access Subject 是非秘密计费身份，不是 Bearer，不是代理密码，也不授权调用 Core。Provider 的产品能力声明不授予 Admin permission 。
4. Core 仅保存 Provider 的非秘密引用和归属、状态、版本；禁止通过任意 JSON 配置/DTO/事件/日志回传代理密码、私钥、完整 UUID 凭据或可直接授权的凭据材料。Core 自身登录、服务身份和链接验证的必要安全材料不属于插件任意秘密，仍按对应身份边界保护。
5. 插件不能直接修改 Core 表、发布节点控制命令、读取其他插件秘密、执行通用 shell 或调用任意内网 URL。此项是 v2 隔离运行目标；现有可信 PHP 插件具有进程内执行能力，不能声称当前已强制禁止其访问。
6. 2026-10-13 用户取消 Agent/MCP 插件迁移并清理功能，历史审计保留、旧凭据拒绝。管理前端与用户前端均暂缓，后续作为独立项目单独成库；当前主仓仅开发 Core、Gateway、Node，详见[当前范围](admin-only-scope.md)。

## 与当前边界的衔接

当前接口见[对外路由与身份](../../../contracts/http/external-adapter-current.md)，Module/Plugin 规则见 [Module Platform v1](../module-platform-v1.md) 与 [Plugin Package v1](../../../contracts/plugin-package/README.md)。本 ADR 不覆盖这些 Current 契约。

| 演进项 | 当前必须保持 | 允许切换的门禁 |
| --- | --- | --- |
| Gateway 接管 User/Admin Web API | 目前可选 User BFF；不得接管 Admin、Agent、支付 Webhook 或订阅密钥；应用入口保持注册的 `/txapi/*` | B5 内部 API 授权/幂等/审计及真实联调通过；B6 Secure Channel 验收；版本化替代契约、客户端灰度及回退方案齐备 |
| Core/插件隔离 | PHP Plugin v1 可信进程内执行；包 code、生命周期、`admin/dist`、Admin Bridge 与现有主题保持兼容 | B4 独立运行时、能力授权、秘密隔离、撤销/排空与故障恢复实现并验收；破坏性包变更使用新版本 |
| User → Subscription | User 旧字段及当前订单开通服务仍是权威 | B1 回填、余额/配额/期限/归属对账、单有效订阅并发约束；写入切换与恢复演练通过 |
| User 流量 → Subject 流量 | 现有 Node 协议、旧批次去重及用户写入路径保持可用 | B3 持久 ACK、订阅级结算、旧节点映射、重放冲突和故障注入通过 |
| 独立前端（延期，后续另建仓库） | 既有 Admin/User UI/Theme 兼容；Agent/MCP 清理 | 未来前端依赖 B5 授权与 B6 通道门禁；当前主仓验收 Core/Gateway/Node |

目标部署是[运行拓扑](runtime-topology.md)中的 Compact/Standard/Cluster；每种拓扑都要满足同一契约和权限边界，合并进程不能成为放宽授权的理由。新接口见 [Core Internal API](../../../contracts/v2/core-internal-api/README.md)、[Secure Channel](../../../contracts/v2/secure-channel/README.md)，不得仅因文档存在启用未注册路径。

## 取舍与后果

选择增量迁移，避免一次重写同时改变业务真相、节点协议与插件执行。代价是兼容窗口内保留旧字段、旧包和适配器，并增加可追溯迁移及对账成本。没有选择让 Gateway 持有业务数据库、让 Credential Provider 持有计费账本，或把账户/订单拆成多个权威服务；这些方案会扩大一致性和恢复责任。

方向已决策，具体表类型、传输 Schema、运行时技术和容量参数仍为 Draft。关闭兼容入口前必须更新当前契约与消费者，不得把 Target 文档当作删除依据。实施顺序由[实施计划](implementation-plan.md)维护；数据及失败恢复见[数据模型](core-domain-model.md)和[Core 演进](core-evolution.md)。
