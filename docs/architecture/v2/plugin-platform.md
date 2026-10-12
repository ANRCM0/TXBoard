# Plugin Platform v2

> 状态：Target / Draft · 更新：2026-10-12 · Core First 方向已接受；运行隔离、能力授予、事件总线与 CredentialProvider 尚未实现。

## 当前基线与兼容边界

当前 [PluginManager](../../../api/app/Services/Plugin/PluginManager.php) 管理 `tx_plugins`、加载 PHP 服务提供者/路由/命令和 hooks；[ModuleLifecycle](../../../api/app/Services/Module/ModuleLifecycle.php) 委托专用适配器，不重新实现插件逻辑。[Module Platform v1](../module-platform-v1.md)、[Plugin Package v1](../../../contracts/plugin-package/README.md)、[Module Lifecycle v1](../../../contracts/module-lifecycle/README.md) 继续是当前契约。

PHP 插件是可信进程内代码，可访问 Laravel/Octane 运行环境，没有沙箱。当前 uninstall 删除注册/发布资源并保留数据库迁移及业务表；不具备 v2 Credential 撤销和租约排空。v2 规则不能直接套在 v1 包上宣称卸载安全，必须通过新契约与专门 adapter 迁移。

不强迫现有插件重命名 code、马上补新 manifest、重编译 TXBoard React、移动 `admin/dist` 或重写生命周期；Theme 的 `frontend_theme` 唯一活跃状态及 Admin Bridge v1 保持。可信 legacy 运行时必须显式标识 `legacy_in_process`，其兼容存在不证明隔离目标达成。

## v2 组件与职责

| 组件 | 目标职责 | 权威与限制 |
| --- | --- | --- |
| Registry | 统一 inventory、版本、capabilities、依赖、有限健康与导航 | 读取无副作用，enabled/healthy 来自运行时；系统 ID 冲突优先；错误有界且不含秘密 |
| Lifecycle coordinator | 校验权限/依赖，启动可恢复生命周期 operation，管理 drain/revoke/checkpoints | 通过 specialized runtime adapter；不由 Registry 直接 mutation |
| Capability registry | 绑定 type + contract version + provider ID + operation allow-list | 能力声明不是授权；选择策略由 Core 管，不由插件隐式抢占 |
| Core extension API | 受限领域 DTO、权限、target scope、幂等、审计 | 插件不写 Core 表、不发布 Redis/Node 命令、不通用 shell；Core 再校验授权 |
| Event delivery | 事务 outbox、持久 delivery 状态、重试和隔离失败 | at-least-once；按 event ID 幂等；不承诺网络 exactly-once |
| Plugin runtime | 拥有私有配置/存储/秘密，执行特定 Provider/Payment/通知/AgentOps/MCP 契约 | 不替代订阅、权限、账本及 Node 控制权威 |

## 隔离运行目标

v2 优先使用独立进程/容器及版本化 IPC/HTTP 适配器，禁止把新 Provider 通过 `require` 注入 Core 再叫隔离。运行时策略至少包括独立服务身份、私有存储、无 Core DB/Redis 凭据、无宿主通用文件系统/容器控制权限、网络 allow-list、超时/资源限制和日志脱敏。隔离技术与 Compact 部署资源预算仍待 B4 验证；只能通过进程生命周期、越权和秘密泄漏测试证明。

manifest 声明 identity/version/type/contracts/dependencies/capabilities 和 Admin 资源，不声明 installed/enabled/health。实际授权由 Core 管理员按插件身份、操作和目标授予，依赖安装不会自动授予上游权限。Admin UI 继续由 shell 持有安全上下文，Bridge 消息需校验 origin/version/path；插件应用不能通过导航声明提升 RBAC。

Core 可选模块故障只影响对应能力，不拖垮账户/套餐/订单读取或 Core health。健康检查不任意访问 HTTP、文件系统或运行脚本。超时返回稳定错误和 request_id，恢复重试使用原 operation id，不盲目再次执行可能已成功的非幂等动作。

## 事务事件与授权

领域事务提交业务状态及 outbox event：`event_id/event_type/schema_version/aggregate_id/aggregate_version/occurred_at/request_id`，payload 只含 allow-list 非秘密字段。消费者通过受限 API 获取必要资源，无数据库旁路。授权在投递和实际执行阶段均检查；停用/撤权后阻止新任务，但保留待排空/补偿记录。

消费确认不代表核心财务已结算。对影响订阅或账本的结果，Core 验证 operation identity/输入 hash/当前版本后通过领域服务落账；插件自行发布 `paid/fulfilled` 不能成为业务真相。重复或乱序事件按 aggregate version 与事件幂等记录处理；毒消息进入 quarantine 并有安全诊断，不静默丢弃。

## CredentialProvider 首个验证项目

[CredentialProvider Contract v1](../../../contracts/v2/credential-provider/README.md) 包括 issue/parse/render/rotate/revoke/query/import，秘密留在 Provider，Core 拥有非秘密 Access Subject、订阅授权和撤销政策。官方安装器可推荐第一方 Provider，但无 Provider 的 Core 必须可启动。

Disable 停止新发行和新授权租约，保留 Provider 数据；Uninstall 先撤销、验证租约过期/节点回执、排空待结算，再移除活动 Credential 数据与运行时；Purge 最后删除其余配置/秘密/私有存储，服从备份保留策略。Subject tombstone、Usage/Money 账本、审计和非秘密历史关联始终保留。

离线节点未收撤销消息时不能宣称立即失效；节点须执行有限租约和单调撤销版本。未实现租约的旧节点会阻断安全卸载，强制操作只能进入显式 `forced_pending` 并保留风险及后续对账，不能报告普通卸载成功。待结算历史不以 Provider enabled 为前提，缺少 Provider 也须能按历史 Subject 入账。

## B4 门禁

先实现新 adapter 与版本化 DTO/Schema，再连接生命周期和 Admin UI。门禁证据包括：v1 包/Bridge 回归、无 Provider 可启动、秘密不进入 Core DB/事件/日志、能力缺失和插件异常隔离、进程/网络越权拒绝、重复生命周期操作恢复、Node 离线租约到期、停用卸载后晚到批次仍可结算、待处理状态阻止 Purge、Octane 缓存失效/Worker 重启及失败升级恢复。

新实现通过后才更新 [AGENTS.md](../../../AGENTS.md) 和当前契约的适用边界。总体权限仍按 [Core First ADR](core-boundaries.md)，写入切换按 [Core 演进](core-evolution.md)；v2 包/事件 Schema 与最终 IPC 传输不在本轮 B0 声称完成。
