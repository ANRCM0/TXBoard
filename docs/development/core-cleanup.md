# Core 清理与领域边界

更新：2026-10-16。本批基于已合并的 C2 和当前 native 开发基线；运行验证由候选 PR 的 CI 提供，不因文件移动宣称新增业务已交付。

## 本批清理

- 移除旧订阅身份、订阅链接的旁观中间件、请求属性和专属回填/核对命令。实际 Client 鉴权与当前 native delivery 保留。
- 移除隔离副本专用的订阅 backfill/reconcile、影子 Slot 预留、目录影子、commerce 影子、money 投影及生命周期核对工具。删除仅依赖这些退役工具的历史测试、孤立模型及专属 CI，当前 native 事务、权限与用量测试继续执行。
- 移除合成 CredentialProviderFixture 进程、签名/租约原型及 PluginManager 中的专用生命周期分支。保留通用插件身份、依赖、文件包和生命周期保护；真实独立 Provider 不受此原型删除影响。
- 当前 12 个订阅服务统一归入 `api/app/Domains/Subscription/Services`，所有真实消费者同步更改命名空间；旧路径不保留别名或转发类。
- 规范模型基类改名为 `SubscriptionRecord`。共用 Schema 校验器归入 Installation 域并改名为 `SchemaReplayValidator`，现存 migrations 仍调用同一校验逻辑。

数据库表、迁移历史和已有记录不在本批删除范围内。删除应用工具不会在启动或升级时自动执行 DROP、回填或重写；旧契约标记退役，既有验收 JSON 保留当时证据。

## 当前领域入口

订阅服务的具体职责、事务要求和跨域边界见 [Subscription domain](../../api/app/Domains/Subscription/README.md)。

| 所有者 | 当前职责 |
| --- | --- |
| Subscription | 不可变目录、发布 Offer、购买协调、订阅履约及生命周期、共同权益、非秘密 Provider 协调、有限分发授权 |
| Billing | 订单购买证据、幂等及已有支付入口；C3 真实资金账本与退款仍待实现 |
| CredentialProvider | mTLS 传输、Provider 协议及非秘密回执；实际代理凭据留在独立 Provider |
| Network | Node/Machine 身份、目标绑定、授权签名及下发 |
| UsageV2 | 原始 Session/Period 归属、持久接收、配额预留及结算 |
| Installation | 安装权威、admission/epoch、readiness 与共用 Schema 校验 |

## 仍需后续处理

`AuthorityMigration` 中的身份事务、writer fence、postcommit/outbox 等实现仍被当前消费者使用，不能按历史名称整目录删除。后续应先区分当前事务基础设施与旧切换工具，再迁入实际所属域。

`UserObserver`、旧写入口及其 capture hooks 尚存；去除时必须同时处理当前注册、管理员操作和提交后副作用，不能简单关闭事件或删除校验。旧 Client/User 渲染入口及隔离 Internal API 也尚未整体替换。

`SubscriptionTermsShadowService` 仍被现有 UsageV2 条款边界测试使用。本批保留这一依赖，后续将测试迁至当前生命周期实现，再移除旧隔离 mutation 工具。

这些剩余实现不构成旧版本支持承诺，也不要求为它们继续添加兼容功能。C3—C5 的业务交付范围保持原计划。

## 验证

- `python scripts/check-core-boundaries.py` 检查当前订阅域位置、旧命名空间和已退役运行时引用，由 API CI 执行。
- API 默认测试集检查当前行为与插件生命周期；MySQL CI 检查迁移、初始化和 native 事务；native C2 CI 检查真实 Provider/Node/渲染及原周期结算链路。
- 删除的专属工作流不保留同名空任务；其余工作流继续遵守 [CI 分工](native-development-baseline.md)。
- Core 的 API、MySQL 和镜像验收支持叠加 PR 的任意目标分支，按实际增量触发；默认分支推送与发布条件保持原有约束，未修改的 Node/Gateway/Web 不为此批重复验收。
- 本地只执行轻量静态和文档检查，不启动数据库、Linux 环境或真实流量。
