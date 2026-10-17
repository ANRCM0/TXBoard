# Core Module Ports v1

> 状态：Target / Draft；日期：2026-10-17。
>
> 本文是[小内核与业务模块设计](../../docs/architecture/v2/core-kernel-modular-design.md)的进程内能力契约草案，不是已实现 PHP SDK，不注册路由，不扩大当前权限、支付、退款、Provider 或 Node 支持范围。
>
> 当前源码参考本地 `main` 的 `85b0eac62f73f636efe736548e968535c561ced3`。现有 native 协议与真实消费者仍是当前行为依据；不支持旧开发版本的兼容 adapter。

K1 候选实现的精确接口见 [Core Entitlement Queries v1](core-entitlement-queries-v1.md)。该子集采用 `forAccount(EntitlementQuery)`，沿用入口鉴权，尚未引入本文示意的 `InvocationContext`；后文完整接口示例不代表已提供的 SDK。

K3 的实际履约/付款证明子集见 [Core Order Fulfillment Ports v1](core-order-fulfillment-ports-v1.md)：保留现有标量结果和内部锁定模型，尚未引入后文示意的统一上下文或通用事务包装。

K4 的账户读取子集见 [Core Account Read Ports v1](core-account-read-ports-v1.md)：HTTP 与三个固定 Internal API 操作共用查询，入口继续拥有实际 actor 认证及协议映射，不引入通用动态业务调用。

K4 的订单读取子集见 [Core Order Read Ports v1](core-order-read-ports-v1.md)：Billing 查询保留当前分页、归属及列表/详情投影，三个 HTTP 视图与两个固定 Internal API 操作复用，不改变订单写入、资金或履约权威。

## 1. 契约目标

业务调用者依赖明确的输入、输出与行为保证，运行时通过显式装配使用当前实现。每个端口必须说明：

| 项目 | 必须回答 |
| --- | --- |
| 所有者 | 哪个领域拥有业务规则和接口；哪些真实消费者调用 |
| 身份 | 调用者类型、当前权限、资源归属、是否允许系统任务 |
| 输入 | 闭集字段、类型、范围、版本、服务端决定的字段 |
| 输出 | 完成程度、数据来源、是否可重放、是否含敏感数据 |
| 事务 | 自有事务、事务内参与或持久多阶段工作流；谁提交 |
| 幂等 | 真实键、作用域、输入 hash、唯一约束、保留与回执 |
| 一致性 | 当前读/快照、锁前置条件、版本检查与原子副作用 |
| 故障 | 可确定失败、unknown、重试/查询方式及证据损坏处理 |
| 装配 | 哪个实现满足契约、版本/能力前置条件、缺失时行为 |

不存在“只要实现同名方法就能替换”的契约。能力声明不代替行为验收，也不授予 actor 权限。

## 2. 公共类型

这些是目标类型，不要求第一批全部建立。字段可以用不可变类和枚举表达，不强制抽象父类。

### 2.1 InvocationContext

| 字段 | 来源与用途 |
| --- | --- |
| `execution_id` | Core 生成的可信执行关联 ID |
| `request_id` | Core 响应关联；客户端 correlation 单列，不作身份 |
| `origin` | 编译枚举：direct_http/internal_api/cli/worker/scheduler/node/payment_callback 等 |
| `actor` | 认证边界生成的 actor 引用及身份类型，不接收客户端角色声明 |
| `service_ref` | Internal API 等经过验证的调用服务引用，可空 |
| `deadline_at` | 服务端按照该操作上限夹紧；不能延长已发行凭据或租约 |

Context 不存 bearer、代理秘密或任意 HTTP headers；不接受通用 JSON 反序列化。身份是否仍有效、操作是否允许、安装是否可写，要由对应边界按当前事实检查。

安装 ID/epoch 与事务准入由 Installation 在实际事务中确认，不把 Context 内的历史观察当作全局授权。

支付回调没有用户/管理员 actor bearer。其专用 `VerifiedPaymentIngress` 由冻结渠道、商户和 dispatch 的协议验证建立，不能靠请求自报“已付款”构造。该来源证明只允许记录/核对对应金融事实，不授权一般用户操作。

### 2.2 规范值

- 新模块公开 JSON 的 bigint 数据库 ID、金额 minor、bytes、版本计数默认使用规范十进制字符串，拒绝浮点、科学计数法和歧义表示；安装 UUID、业务键等不是十进制 ID。
- 已有跨进程协议严格保留其 Current Schema，例如签名 Node 契约中的正整数 `user_id` 不能直接改为字符串。序列化适配必须按实际协议作有界、无损转换；需要改 wire 类型时同步修改生产者、签名规范和全部真实消费者，而不是隐式标准化。
- 进程内可使用现有经过范围验证的整数/值对象；变换不能丢失精度。
- Money 保留在 Billing，复用 `MoneyValue`；当前可执行币种不因 DTO 可表示多币种而扩大。
- 时间为契约规定的 UTC 秒，区间 `[start, end)`；空到期值不等于零。
- `expected_version` 用于并发前置检查；不替代业务键。
- 客户端不能指定钱包实际余额、已支付事实、资源所有者或内部授权快照。

### 2.3 结果类型

同步成功、持久受理和未知结果不能混成 `success=true`：

| 类型 | 承诺 |
| --- | --- |
| 完成结果 | 对应业务事务已提交，有可核验回执/状态 |
| 受理结果 | 持久意图或接收已提交，后续工作可能未完成 |
| 查询视图 | 截至观察时刻的有界只读投影，不代表下一次操作已授权 |
| unknown | 外部结果或响应状态不确定；保留原操作引用并按契约查询 |

不是每个方法都返回相同万能 `Result<array>`。各领域保留确切返回类型和明确状态；应用入口负责将它们映射到当前 HTTP envelope。

## 3. 公开能力目录

名称是拟议的 PHP 契约名，不是新 HTTP operation。

| 能力 | 所有者/当前实现 | 关键语义 |
| --- | --- | --- |
| `EntitlementQueries` | Subscription / `SubscriptionEntitlementResolver` | 当前账户与订阅归属、Terms/Period、预留配额；不输出秘密、不做修复 |
| `CatalogQueries` / `CatalogPublication` | Subscription / `NativePlanCatalog`、`PlanPublicationService` | 已验证 Offer、不可变发布；写入受管理员当前授权 |
| `PublishedPurchaseCommands` | Subscription / `PublishedOrderService` | 已发布 Offer、永久建单键和冻结购买；不信任客户端报价 |
| `SubscriptionLifecycleCommands` | Subscription / `SubscriptionLifecycleService` | 显式 suspend/resume/revoke/expire/reset，版本前置与持久回执 |
| `BillingCheckoutCommands` | Billing / native checkout 服务 | 明确免费/钱包/渠道/混合方式，冻结分配；unknown 原尝试查询 |
| `WalletRefundCommands` | Billing / `NativeRefundService` | 原现金预算、只退钱包、永久键、权益效果固定 none |
| `BillingReconciliationQueries` | Billing / `NativeBillingReconciliation` | 只读、有界、截断即 incomplete，不自动修账 |
| 当前 `NativePaymentProviderInterface` | Payment 协议扩展 / 当前 Registry 与插件 | 冻结、checkout、通知解析/验证、query、ACK；不写账 |
| `CredentialCommands` | Subscription Access / `NativeCredentialProviderService` | Core 持久意图与非秘密回执，秘密留在独立 Provider |
| Provider transport/metadata port | CredentialProvider / `ProviderTransport` | 事务外有界网络、严格 codec、固定对端与原操作 |
| `UsageReception` / `UsageSettlementWork` | Usage / 当前接收与结算服务 | 原批次及周期、持久预留、接收不等于结算 |
| `NodeAuthorizationCommands` | Network / `NativeProviderNodeAuthorization` | 发行会写授权版本，不是无副作用的普通查询；最终重验后签名 |
| Installation admission | Installation / `InstallationAuthority` | 保留当前事务/读取前提，检查实际持久身份与就绪 |

接口仅在真实消费者迁移时建立。现有类直接实现窄接口通常优于再添加同参同返的转发 Service。

## 4. 三个代表性用例

### 4.1 查询权益

建议 PHP 形状，类型声明仅示意：

```php
interface EntitlementQueries
{
    public function forAccount(
        InvocationContext $context,
        EntitlementQuery $query,
    ): EntitlementView;
}
```

`EntitlementQuery` 只含明确账户/订阅目标及允许的展示需求，不接受外部 `now`、配额覆盖、角色或 arbitrary filter。公开用户入口从当前 actor 限定目标；管理员和系统调用需各自的受控范围。

`EntitlementView` 包含非秘密的 user/subscription/terms/period ID、状态/拒绝原因、到期、配额/已用/已预留、速度/设备限制及观察版本。确切嵌套字段在接入时与当前 DTO 同批冻结，不宣称当前接口已全量闭合。

计量字段必须定义关系：当前 `consumed_bytes` 已是本周期已结算 billable 与持久预留之和，不是单独“已结算量”。若新 DTO 分列 `settled_billable_bytes`、`reserved_billable_bytes` 和 `admission_consumed_bytes`，三者须来自同一次锁定读取，准入总占用只相加一次；展示剩余为 `max(0, quota - admission_consumed)`。不得将原 `consumed_bytes` 当成 settled 后再次加 reserved；溢出按当前拒绝/保守关闭策略处理，不能回绕恢复额度。该拆分需要实现和消费者同步，不是适配器凭现有输出猜测两个分量。

结果不包含可认证 UUID、订阅链接 bearer、支付配置或节点 Token。展示结果不是新的授权票据，Node 不能拿旧结果直接续签。

公开 resolver 保留既有 fresh transaction 约束。Node 最终签名等内部流程使用独立的 locked 查询能力并在自己的完整锁范围内重验，不能在其外层事务中调用公开 fresh-transaction 方法。

### 4.2 创建购买

```php
interface PublishedPurchaseCommands
{
    public function create(
        InvocationContext $context,
        PurchaseCommand $command,
    ): PurchaseReceipt;
}
```

输入是经过校验的 Offer、当前契约允许的购买目标和原业务键；不接受总价、余额、已付款或“强制开通”字段。业务服务决定价格、购买类型、来源报价、归属和可售状态。

一个原子事务保存订单、购买证据、原幂等键，以及该购买类型要求的升级来源/预算等事实。结果只承诺“订单已建立”，不自动承诺付款、履约或 Provider 凭据可用。

同键重放不得重新报价或延长原报价有效期。旧报价过期、目标状态变化和新建订单是明确不同结果；接口不能自动取消再建单。

### 4.3 钱包退款

```php
interface WalletRefundCommands
{
    public function refund(
        InvocationContext $context,
        WalletRefundCommand $command,
    ): WalletRefundReceipt;
}
```

输入为原 receipt 或 wallet allocation 的互斥来源、正整数金额、永久键和有界原因。actor 必须拥有当前退款权限；目标用户与货币从已验证来源决定，不能传任意收款账户。

一个事务验证原现金、累计退款和升级 claim 共享预算，写退款及账本，更新原用户钱包。纯钱包付款不能伪造外部 receipt，非现金 carry 不能退款。

回执明确 `destination=wallet`、`entitlement_effect=none`，不取消订单或任何 work。该能力不实现原路退款、不隐式调用 revoke。管理员另行撤销必须独立授权及独立业务键。

当前准确行为见 [Native Wallet / Refunds](native-wallet-refunds-v1.md)；settlement 文档同时遵守该退款边界。

## 5. SQL-only 事务参与端口

公开用例自管事务与内部参与接口严格分开：

```php
interface SubscriptionFulfillmentParticipant
{
    public function apply(
        CommitScope $scope,
        LockedFulfillmentInput $input,
    ): FulfillmentReceipt;
}
```

此接口只表示未来对当前 `applyLocked()` 的窄化封装，不新增一套履约算法。

`CommitScope` 必须由当前事务 owner 创建，并绑定同一连接、活跃事务、安装/epoch、账户范围和对应锁计划。提交/回滚后失效，不能序列化到队列或由 HTTP 反构造。它是程序正确性边界，不是对同进程恶意 PHP 的安全隔离。

`LockedFulfillmentInput` 是进程内受限类型，可持必要锁定对象，不是公开 DTO，也不能由插件直接创建以请求任意写库。

前置条件：

1. 完成当前安装 admission，持有规定 User/Slot/Subscription/history/order 及完整资金锁并集。
2. 购买证据、付款/免费接受、来源归属与版本经过同事务验证。接受证明是互斥类型：`FreeAcceptanceProof` 引用永久免费 checkout 记录，不创建 allocation/receipt/journal；`PaidAllocationProof` 引用真实 allocation，纯钱包仅需其真实 capture journal，渠道/混合付款再绑定相应真实 receipt。升级两种路径均验证原报价/预算，不接受单独 `paid=true`。
3. 所有参与者使用原业务键及真实唯一约束，不提交第二份“Kernel 成功记录”代替领域回执。
4. SQL-only：不调用 Provider/支付网络，不独立 commit，不调自管事务的 public 方法。
5. 任一参与者失败回滚整个拥有者事务；重试重新获取锁与验证，不重用失效 scope。
6. 真实当前读要求不能因输入类型为“已锁定”就省略原有完整性检查。

周期参与能力采用相同约束。Subscription 决定何时切换周期，Usage 维护预留/累计的真实性；它们不能分别提交后再靠异步消息修补中间状态。

若第一批不改变事务 owner，不需要立刻实现 `CommitScope`；用明确文档、现有检查和针对性测试约束当前方法，不添加无效证明类型。

## 6. 外部协议端口

### 6.1 Payment

复用 [Native Payment Provider](native-payment-provider-v1.md) 与 `App\Contracts\NativePaymentProviderInterface`。不建立旧插件兼容 façade，不把同一接口再包装成另一套动态支付框架。

适配器只能返回协议核验后的候选事实。Core 再验证冻结 attempt、商户、货币、金额、交易身份及当前允许的转换，才提交 receipt、ledger 和 allocation。

验证成功不是“Core 已收款提交”。渠道 ACK 只在对应持久结算完成后返回。超时/unknown 不能被映射为余额可释放或可以创建新 attempt 的确定失败。

金融事实记录不复用用户购买的封禁拒绝策略。原账户已封禁、原订单已取消或当前渠道已停用时，冻结 dispatch 对应的合法通知/query 仍按限定恢复路径重验并持久处理；不能用于履约的真实收款按原规则记入负债，不能静默丢弃。安装准入、来源验证及交易唯一性仍需成立。

停止新 checkout 与继续历史 query/verification 是不同能力。包金融 pin、冻结配置及已加载版本检查不能被抽象掉。

### 6.2 Credential Provider

发行/轮换/撤销流程：持久意图 -> 事务外请求 -> 独立事务核验结果与目标当前状态 -> 持久完成或补偿意图。

Core 的协议 DTO 只含非秘密 subject/reference、版本、承诺与回执。代理秘密仍由 Provider 直接交付授权 Node/终端；不提供 `getRawCredentials()` 给一般业务。

未知结果沿原 operation 查询。取消、封禁、到期后晚到的发行结果不得复活权限。不存在为了“兼容”在 Core 临时生成 UUID 的 fallback。

### 6.3 Node 和用量

API/WSS 传输适配器不能改变 node/source epoch/batch 身份。`accepted`、`settled`、`quarantined` 语义按当前契约保持独立。

用量接收端口返回 durable receipt 前必须提交冻结身份、原周期预留与恢复证据；结算端口只消费持久 payload，不重新猜当前订阅。

Node 授权需要事务外 Provider 元数据及最终事务内重验，不可转换成“从缓存读取 EntitlementView 并签名”。空撤销授权、有效期和当前支持协议仍由原实现决定。

访问授权与计量归属继续使用同一签名快照：最终 account-first 事务冻结 Usage Session 的 source/tariff/auth-snapshot，与主体、有效 Terms/Period 和租约一起绑定精确 payload digest。Node 不得从另一次 current handshake 获取独立计量归属，也不能将授权与计量拆成可混配的两个“兼容能力”。精确要求见 [Native C2 Runtime](native-c2-runtime-v1.md) 的 One signed authorization for access and billing。

## 7. 错误、重放与访问控制

Kernel 可以提供有限分类，但不能吞掉领域错误码或改变现有协议：

| 分类 | 调用者行为 |
| --- | --- |
| InvalidInput | 修正请求；不能重放错误输入期待副作用 |
| Unauthorized / Forbidden | 重新获得合法身份/权限；不绕到其他入口 |
| NotFound / HiddenResource | 按当前资源隐藏规则返回，不泄露另一用户数据 |
| StateConflict / VersionConflict | 查询当前状态，按业务规则重新决策 |
| IdempotencyConflict | 保留原键记录；不能为掩盖已提交状态自动换键 |
| CapabilityUnavailable | 返回真实不支持/不可用；不降级到旧实现 |
| DependencyUnknown | 原操作查询/恢复，不承诺未发生 |
| EvidenceInvalid | 拒绝继续生效并保留诊断；不能自动重建历史事实 |

每个实现必须声明重试是否安全及原操作定位方法，不能只靠 HTTP 状态判断。

用户/管理员入口在返回旧回执之前检查当前访问权限；Node、系统恢复和支付回调分别验证自己的当前来源与契约范围，不要求它们借用用户会话。领域业务键仍按原作用域唯一，不因为增加 actor/context 就缩窄原永久退款键、支付交易键或批次身份。

## 8. 能力装配与描述

能力 ID/版本、支持的操作和前置条件由编译代码与已验证当前包声明提供；installed/enabled/healthy 来自各自运行时。

能力选择不得接受用户提供的类名、脚本路径、任意 URL 或未经验证的包。缺失实现启动/调用失败应明确，不自动选第一个、默认渠道或旧实现。

必选业务能力随 Core 版本装配，不能被 Module Lifecycle 热卸载。可选渠道继续通过专有 Registry 管理，发现只读，不产生配置或安装副作用。

无需新建 `tx_core_modules` 或全局能力数据库。已有运行时能够派生的状态不另存第二份。能力发现结果只做描述，最终每次调用仍验证身份、业务和当前状态。

## 9. 契约验证与退出条件

每个实际新增端口必须具备：

- 当前实现与真实调用者映射；没有消费者的预留接口不作为交付。
- 输入输出闭集和边界值检查，跨进程大整数不丢精度。
- 未授权、跨资源、封禁/撤销、失效安装/epoch 的拒绝路径。
- 原键同内容重放、异内容冲突、提交后丢响应与持久证据损坏检查。
- 自管事务拒绝外层事务；SQL-only 参与者拒绝无事务/错误 scope，失败全部回滚。
- 网络不在 SQL 事务中；unknown 原操作查询、禁用后的有限恢复。
- Octane/Worker 不复用 actor 或失效 scope；新版本装配不绕过实际已加载代码检查。
- 业务行为与相应 Current 契约一致，不用 DTO 映射掩盖能力缺失。

文档阶段只验证引用和设计一致性。接口实施后的数据库并发、真实 Provider/Node、金融恢复及长驻进程行为按组件 CI 集中验收，不在本地启动重型环境。

开发期可同步调整本草案与真实消费者；不承诺对历史未发布接口提供并行适配。只有对应实现、消费者与验证完成后，才逐项提升为 Current，而不是整体改一个状态标签。
