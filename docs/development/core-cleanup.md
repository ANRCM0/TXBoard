# Core 清理与领域边界

更新：2026-10-16。前四批已通过各自 CI 并合并至 main；第五批基于当前 native 开发基线退役隔离 Internal API，运行验证由候选 PR 的 CI 提供，不因代码清理宣称新增业务已交付。

## 第一批：订阅与原型

- 移除旧订阅身份、订阅链接的旁观中间件、请求属性和专属回填/核对命令。实际 Client 鉴权与当前 native delivery 保留。
- 移除隔离副本专用的订阅 backfill/reconcile、影子 Slot 预留、目录影子、commerce 影子、money 投影及生命周期核对工具。删除仅依赖这些退役工具的历史测试、孤立模型及专属 CI，当前 native 事务、权限与用量测试继续执行。
- 移除合成 CredentialProviderFixture 进程、签名/租约原型及 PluginManager 中的专用生命周期分支。保留通用插件身份、依赖、文件包和生命周期保护；真实独立 Provider 不受此原型删除影响。
- 当前 12 个订阅服务统一归入 `api/app/Domains/Subscription/Services`，所有真实消费者同步更改命名空间；旧路径不保留别名或转发类。
- 规范模型基类改名为 `SubscriptionRecord`。共用 Schema 校验器归入 Installation 域并改名为 `SchemaReplayValidator`，现存 migrations 仍调用同一校验逻辑。

数据库表、迁移历史和已有记录不在本批删除范围内。删除应用工具不会在启动或升级时自动执行 DROP、回填或重写；旧契约标记退役，既有验收 JSON 保留当时证据。

## 第二批：账户写入与捕获链

候选代码移除运行入口上的 Legacy capture、创建/删除证据包装、Observer 捕获分支及对应配置、命令、测试和 5 条手动工作流。注册、管理员操作、订单、支付、流量、重置和佣金继续执行真实写入与已有 admission，不再为影子事件双写。

- 身份 SQL 阶段移入 `Identity/AccountSqlPhase`，原消费者同步改名，不保留旧类转发。注册及安装管理员的实际持久化由 `AccountCreationService` 负责。
- 创建存储异常使用固定 `ACCOUNT_CREATION_FAILED` 内部错误，不再返回已退役捕获异常的 409；底层异常内容不传入响应，writer admission 拒绝继续使用原有安全错误。
- 批量封禁由 `Identity/AdminAccountModeration` 执行，保留 500 个账户的现有接口上限、完整目标锁定、管理员/员工/自身保护及同事务会话撤销。
- 套餐账户投影由 `Subscription/Services/PlanAccountPropagation` 执行。先锁定固定目标集合，再分批更新，同一事务任一批失败则全部回滚；删除仅服务于影子采集的 1000 账户上限。已购买的不可变 Terms 不受此投影修改。
- Observer 保留冻结事件快照、提交后通知、当前行锁定和调度重算。第二批当时保留的 isolated fenced postcommit/outbox 已在第四批连同迁移原型退役。
- 账户删除被 Eloquent 事件拒绝时，回滚已进行的会话撤销，不调用删除后的 Hook。订单退款的正整数金额约束、流量溢出检查、幂等和已提交结果语义继续保留。

第二批当时的三个定向回归覆盖删除事件拒绝、1001 账户第二批更新失败的全量回滚及重试、提交时调度失败的账户/intents/delivery 回滚。最后一项仅针对隔离迁移原型，第四批随原型删除，不作为普通 afterCommit 回调的保证。

## 第三批：条款影子写入与回执格式

候选代码删除 `SubscriptionTermsShadowService` 及两份专属影子测试。当前发布购买和续订仍由 `SubscriptionFulfillmentService` 执行，重置等状态转换由 `SubscriptionLifecycleService` 执行；只读 `SubscriptionTermsTimeline` 继续供双方和 UsageV2 共用。旧 `App\Services\Subscription` 目录不再保留任何实现或转发入口。

删除 `UsageTermsBoundary::assertFrozen` 对 B3.1 缺字段回执的推断。结算要求当前生产者已保存的完整 Terms 边界版本、周期创建 Terms、有效区间和结束 transition 字段；缺少证据时隔离，不能补猜后记账。合法的空结束时间和空 transition 仍可使用。隔离不删除原回执，也不释放原周期的配额预留。

沿用已有 native 购买、续订、重置、历史结算及授权截止验证，不重建旧影子矩阵。新增一个真实 native 回归验证缺字段且摘要有效的批次隔离、零记账、预留保留和回执重放；已有跨界区间测试扩展至五种接收状态，并通过实际 reset 与续订履约验证完整拒绝。运行结果以第三批 PR 的 CI 为准。

本批不删除数据库迁移或历史行，也不新增影子服务的 native 适配器。第三批通过 CI 后，第四批进一步删除 AuthorityMigration 专属测试，PHP 默认配置只保留 InternalReadOnly 一个历史目录排除项。

## 第四批：安装权限与领域事务

删除整个 `AuthorityMigration` 迁移原型，包括隔离副本的 writer/inventory/upgrade、事务对象 proof、v4-v6 postcommit intents/handoff、两条 CLI、配置、专属历史测试和 6 条手动 CI。没有把原型整体改名搬进其他领域，也不引入新的兼容入口。

- 订单、支付、钱包、管理员和流量的实际旧写入在原事务位置直接调用 `InstallationAuthority::assertLegacyWriter()`，安装权威检查仍先于业务行锁。native 不能绕过此拒绝逻辑。
- `AccountSqlPhase` 使用当前身份操作集合与受管理事务；认证、密码、偏好和会话锁定当前账户。删除 worker scope/version/epoch 矩阵及 enabled 分支。设置、统计、模板和安装默认设置不再借用身份域的迁移清单。
- Sanctum 会话元数据由 `Identity/AccountSessionGuard` 按账户、token 顺序锁定与重验证；保留失效及退役 Agent token 拒绝、Sanctum 认证生命周期。
- 工单实际服务归入 `Support/TicketService`，创建、回复和关闭统一受管理事务及当前安装权限。创建仍按账户锁串行化“最多一个未关闭工单”；子事务失败不能回滚调用方父事务。
- `UserObserver` 只保留当前冻结事件与 connection afterCommit 路径；外事务或保存点回滚不会发送对应同步。调度重算仍在提交后的独立事务执行，不声称调度失败能回滚已提交账户。正式 native runtime outbox 继续服务于当前订阅和凭据同步。
- Installation 自身保留六组历史 scope/version 标识、历史证据表拒绝、fresh database、schema/hash、authority/epoch 和 readiness 检查。历史记录与数据库迁移保持原样，不能被当作全新 native 安装重写或接纳。

现有普通账户冻结回调、管理员删除 veto、套餐批量回滚、native 权限与真实 C2 回归继续由各 CI 所有者执行；不再执行退役版本矩阵。第四批结果以候选提交的 CI 为准。

## 第五批：隔离 Internal API

移除 Core 的 `InternalReadOnly` 域、专属 controller/middleware、配置及 `/txapi/internal/v1/operations/{id}` 路由。旧 Bearer 或 Service Proof 不能重新启用退役路由；正式服务身份和操作分发仍属 C4 待交付。

- Gateway 同步移除 Internal user/admin 客户端、适配器、环境配置和仅依赖它们的 session、密码重置、订单取消入口。对应 SDK 方法、策略和 schema 一并删除，不保留失去服务端消费者的方法。
- 当前 native TXAPI 上游、账户工作流和 HPKE 认证继续保留。严格 JSON 解析归入 Gateway 通用安全层，供现存登录、注册和验证码解密使用，拒绝重复解码键和过深输入。
- 删除隔离副本夹具、启动器、专属测试和 11 条手动 CI。默认 PHP 配置不再排除历史目录，语义完全相同的第二份配置一并移除；其余手动工具通过测试路径或 filter 选择必要范围。
- 当前路由审计明确禁止恢复 `/txapi/internal`，领域检查禁止退役运行时引用。历史协议标记退役，既有验证 JSON、数据库迁移及历史表保持原样。

Core API、MySQL、native C2 与镜像继续验证当前行为；Gateway/SDK、Redis、容器及浏览器由现有 Gateway CI 集中验证。本地只做静态检查，不重建原型环境。

## 第六批：订阅与 Node 单一交付链

删除旧 token 订阅下载、User UUID 渲染器、协议注册门面、旧节点列表与凭据轮换入口，以及生成这些链接的 helper、管理端、用户页和知识库替换。旧订阅模板的设置、页面和规则资源一并删除；历史表及迁移保留。当前客户端通过短期 PoP 渲染授权直接向 Provider 获取材料。

TXBoard Node 消费者只接受 Provider 签名授权和完整刷新，删除 unsigned 用户快照、缓存、delta/device 事件及旧孤立用量客户端。Core 删除无人调用的旧授权签发入口，默认策略改为 Provider 授权。WebSocket 只推刷新提示，实际完整授权仍由当前身份和权益重新生成。独立 Xboard 外部适配器仍有实际消费者，不属于本次 Core 过渡兼容。

## 第七批：UsageV2 native 收敛

删除隔离模式配置、手工 Registry 发行命令与旧 Service/Node 夹具。`UsageEvidence` 只拥有共享摘要、覆盖区间和来源身份校验；接收与结算严格要求 native admission、Provider 证据与签名授权。保留原 Period 配额预留、不可变回执、重复提交、SQL 恢复、代际重放和结算锁定。

有价值的回执及 MySQL 并发/进程崩溃覆盖迁移到真实激活、订阅和签名 grant 夹具，删除纯原型矩阵。9 条失去消费者的手动工作流退出，保留 8 条自动组件/集成流程和 3 条显式手动 Provider 工具。Node/Web 自动 CI 同时支持叠加 PR，不通过未触发检查冒充验收。

## 第八批：包格式与订单 owner

Plugin 必须使用当前 `package.schema: 1`，明确声明菜单及 Admin app；删除旧菜单别名、字段修复、renderer 与资源合并入口。Theme 只保留系统 TXBoard SPA 和当前静态包 runtime，删除 Blade 包类与重复上传路由。保留 ZIP 路径/软链/大小限制、SRI、版本依赖、CSP、活动主题保护与回滚补偿；PHP Plugin 仍为可信进程内代码。

Billing 的 `NativeOrderLifecycle` 负责当前 native 订单付款、取消与履约入口，调用方从主库当前 marker 选择 owner。旧 `OrderService` 拒绝 native 订单，保持账户优先锁、付款金额/支付商校验、回调幂等、提交后队列故障的 SQL 恢复。

## 当前领域入口

订阅服务的具体职责、事务要求和跨域边界见 [Subscription domain](../../api/app/Domains/Subscription/README.md)。

| 所有者 | 当前职责 |
| --- | --- |
| Identity | 账户创建、身份 SQL 阶段、管理员账户保护和封禁、认证与会话 |
| Subscription | 不可变目录、发布 Offer、购买协调、订阅履约及生命周期、共同权益、非秘密 Provider 协调、有限分发授权 |
| Billing | native 订单状态机、订单购买证据、幂等及已有支付入口；C3 真实资金账本与退款仍待实现 |
| CredentialProvider | mTLS 传输、Provider 协议及非秘密回执；实际代理凭据留在独立 Provider |
| Network | Node/Machine 身份、目标绑定、授权签名及下发 |
| UsageV2 | 原始 Session/Period 归属、持久接收、配额预留及结算 |
| Installation | 安装权威、admission/epoch、readiness 与共用 Schema 校验 |
| Support | 工单创建、回复、关闭和实际事务；权限、消息与通知规则 |

## 仍需后续处理

本轮三批随 PR #258 合入 `main` 的 `0150107`；精确候选 `5f8cc4d` 的 13 项开发检查通过，10 项按条件跳过。数据库历史迁移、表和既有验证 JSON 未删除或改写；它们不代表仍有原型运行入口。后续分批见[开发路线](../architecture/v2/next-development-roadmap.md)。

旧付费购买、余额、佣金、退款及其账户投影仍有真实业务消费者，native admission 继续拒绝未交付能力；不能通过删除安全门禁或改名伪装成 C3 已完成。后续 C3 以正式资金模型接管这些业务并清理剩余旧业务服务。C4 正式服务接口、PKI 与长期订阅交付、C5 发布仍按原计划推进。

## 验证

- `python scripts/check-core-boundaries.py` 检查当前订阅/身份域位置、旧命名空间、配置和已退役运行时引用，由 API CI 执行。
- API 默认测试集检查当前行为与插件生命周期；MySQL CI 检查迁移、初始化和 native 事务；native C2 CI 检查真实 Provider/Node/渲染及原周期结算链路。
- 删除的专属工作流不保留同名空任务；其余工作流继续遵守 [CI 分工](native-development-baseline.md)。
- Core 的 API、MySQL 和镜像验收支持叠加 PR 的任意目标分支，按实际增量触发。第二批包含 Gateway 正常注册夹具的清理，按其现有 CI 验证；没有变化的 Node/Web 不重复执行组件测试。
- 本地只执行轻量静态和文档检查，不启动数据库、Linux 环境或真实流量。
