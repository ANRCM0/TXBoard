# Core 清理与领域边界

更新：2026-10-16。本批基于已合并的 C2 和当前 native 开发基线；运行验证由候选 PR 的 CI 提供，不因文件移动宣称新增业务已交付。

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
- Observer 保留冻结事件快照、提交后通知、当前行锁定和调度重算。仍使用的 fenced postcommit/outbox 保留原有哈希字段、提交时调度和失败回滚。
- 账户删除被 Eloquent 事件拒绝时，回滚已进行的会话撤销，不调用删除后的 Hook。订单退款的正整数金额约束、流量溢出检查、幂等和已提交结果语义继续保留。

三个定向回归覆盖删除事件拒绝、1001 账户第二批更新失败的全量回滚及重试、提交时调度失败的账户/intents/delivery 回滚。运行结果以本批候选 PR 的 CI 为准。

## 第三批：条款影子写入与回执格式

候选代码删除 `SubscriptionTermsShadowService` 及两份专属影子测试。当前发布购买和续订仍由 `SubscriptionFulfillmentService` 执行，重置等状态转换由 `SubscriptionLifecycleService` 执行；只读 `SubscriptionTermsTimeline` 继续供双方和 UsageV2 共用。旧 `App\Services\Subscription` 目录不再保留任何实现或转发入口。

删除 `UsageTermsBoundary::assertFrozen` 对 B3.1 缺字段回执的推断。结算要求当前生产者已保存的完整 Terms 边界版本、周期创建 Terms、有效区间和结束 transition 字段；缺少证据时隔离，不能补猜后记账。合法的空结束时间和空 transition 仍可使用。隔离不删除原回执，也不释放原周期的配额预留。

沿用已有 native 购买、续订、重置、历史结算及授权截止验证，不重建旧影子矩阵。新增一个真实 native 回归验证缺字段且摘要有效的批次隔离、零记账、预留保留和回执重放；已有跨界区间测试扩展至五种接收状态，并通过实际 reset 与续订履约验证完整拒绝。运行结果以第三批 PR 的 CI 为准。

本批不删除数据库迁移或历史行，也不新增影子服务的 native 适配器。PHP 默认测试配置只保留 AuthorityMigration 和 InternalReadOnly 两个历史目录排除项。

## 当前领域入口

订阅服务的具体职责、事务要求和跨域边界见 [Subscription domain](../../api/app/Domains/Subscription/README.md)。

| 所有者 | 当前职责 |
| --- | --- |
| Identity | 账户创建、身份 SQL 阶段、管理员账户保护和封禁、认证与会话 |
| Subscription | 不可变目录、发布 Offer、购买协调、订阅履约及生命周期、共同权益、非秘密 Provider 协调、有限分发授权 |
| Billing | 订单购买证据、幂等及已有支付入口；C3 真实资金账本与退款仍待实现 |
| CredentialProvider | mTLS 传输、Provider 协议及非秘密回执；实际代理凭据留在独立 Provider |
| Network | Node/Machine 身份、目标绑定、授权签名及下发 |
| UsageV2 | 原始 Session/Period 归属、持久接收、配额预留及结算 |
| Installation | 安装权威、admission/epoch、readiness 与共用 Schema 校验 |

## 仍需后续处理

`AuthorityMigration` 中的 writer fence、postcommit/outbox 等实现仍被当前消费者使用，不能按历史名称整目录删除。身份 SQL 阶段已迁入 Identity；后续继续区分当前事务基础设施与旧切换工具，再迁入实际所属域。

旧写入口的实际业务与 `UserObserver` 尚存，capture hooks 已移除。旧 Client/User 渲染入口及隔离 Internal API 尚未整体替换。

条款影子写入已在第三批候选中删除。旧 UsageV2 的隔离接收配置、手动 Registry 工具及其共享实现仍有测试或历史夹具消费者，不能把本批理解为所有旧 Node 链路均已清除。

这些剩余实现不构成旧版本支持承诺，也不要求为它们继续添加兼容功能。C3—C5 的业务交付范围保持原计划。

## 验证

- `python scripts/check-core-boundaries.py` 检查当前订阅/身份域位置、旧命名空间、配置和已退役运行时引用，由 API CI 执行。
- API 默认测试集检查当前行为与插件生命周期；MySQL CI 检查迁移、初始化和 native 事务；native C2 CI 检查真实 Provider/Node/渲染及原周期结算链路。
- 删除的专属工作流不保留同名空任务；其余工作流继续遵守 [CI 分工](native-development-baseline.md)。
- Core 的 API、MySQL 和镜像验收支持叠加 PR 的任意目标分支，按实际增量触发。第二批包含 Gateway 正常注册夹具的清理，按其现有 CI 验证；没有变化的 Node/Web 不重复执行组件测试。
- 本地只执行轻量静态和文档检查，不启动数据库、Linux 环境或真实流量。
