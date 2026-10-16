# CredentialProvider Contract v1

> 状态：Target / Draft · 更新：2026-10-12 · 契约版本：`credential-provider/1`
>
> 本契约位于 v2 架构目录，其能力协议版本为 v1。当前 PHP Plugin Package v1 不实现本契约；本文件不新增 HTTP 路由、不提供现有插件沙箱、不证明节点租约或凭据卸载已可用。

2026-10-16 的 C2-C1 实现子集见[真实发行与恢复契约](native-issuance-v1.md)：
独立 Provider 进程、mTLS、私有 UUID 存储、issue/query/subject-revoke 和 Core
持久回执已接入。它没有凭据材料导出、Node 消费、订阅渲染或轮换能力，
不等于下述完整 Target 契约完成，也不解除 native 安装就绪门禁。

## 范围与权威

Core 拥有 Subscription、Terms、Access Subject、授权政策和 Usage/Money 账本。Provider 拥有代理凭据的秘密、发行/解析算法、协议配置渲染和私有持久存储。注册、授权、生命周期由 [Plugin Platform v2](../../../docs/architecture/v2/plugin-platform.md) 协调；数据关联见 [Core Data Model](../../../docs/architecture/v2/core-domain-model.md)。

`access_subject_id` 是不可复用的非秘密归属标识，不能直接用于代理认证或 API 登录。Core 预先创建 Subject，再请求 Provider 为其发行；Provider 不能通过解析结果把流量重新归给不同订阅。无 Provider 时 Core 仍可启动，相关能力返回 `CAPABILITY_UNAVAILABLE`。

以下是逻辑 operation/DTO；最终 transport、JSON Schema、服务身份/签名与 Node payload envelope 待 B4 互操作定稿，不得把本文 operation 名解释成当前注册路由。

## 统一 Envelope 与数据单位

每次 Core → Provider 请求包含：

| 字段 | 类型及要求 |
| --- | --- |
| `contract_version` | 固定 `credential-provider/1`；不支持则失败 |
| `operation` / `request_id` | allow-list operation；全链路诊断关联，不是幂等键 |
| `operation_id` / `idempotency_key` | 变更与 render delivery 必填；同逻辑动作重试使用原值 |
| `provider_id` / `provider_instance_epoch` | Core 注册的实例身份和存储代次；请求不得选任意进程或 URL |
| `actor_ref` / `authorization_ref` | Core 已验证的非秘密 actor/审计授权引用；用户传来的同名字段不能直接信任 |
| `access_subject_id` / `expected_subject_version` | 按操作必要性填写；Core 必须验证 provider/subscription 归属及版本 |
| `deadline_at` / `input_hash` | UTC 秒绝对期限；规范化 allow-list 输入的摘要，排除 request_id 等传输变化；摘要规范待 Schema 固定 |

结果包含 `request_id/operation_id/provider_id/status/result/error`。status 为 `succeeded/pending/rejected/failed`；pending 必须带可查询的 operation ref，不能被当作发行/撤销完成。`error` 仅有稳定 `code/retryable` 和安全描述，不含 credential、payload、堆栈、文件路径或配置。

数据库 ID、版本计数、金额和 bytes 在 JSON 中用十进制字符串；时间为 UTC Unix seconds 整数，`*_at=null` 的语义逐字段定义。版本单调递增，不用时间戳代替版本；系统需拒绝负数/溢出和非整数输入。租约时长和时钟偏差预算必须显式配置及验证，禁止默认无限租约。

## 秘密交付边界

Provider 秘密包括可认证 UUID、password、private key、bearer、可逆凭据配置。Core DTO、表、日志、事件、health/Registry/Admin 响应禁止携带这些内容；“opaque JSON”“自定义 metadata”“fingerprint”不能成为例外。`credential_ref/provider_subject_ref/delivery_ref/material_ref` 必须是真正非秘密引用，知道引用不能取出秘密或获得代理访问。

Core 控制目的地 `delivery_target_ref`，它映射到已注册并通过身份认证的 Node 或已授权的用户配置交付组件。Provider 使用专用通道直接交付，Core 只接受受限 receipt。禁止用户指定任意 URL、路径、脚本、协议参数或回调主机。若实现必须经 Node Control 转送，只允许 Provider→目标接收方加密且绑定 audience/快照版本的不可解密 envelope；Core 不持有其解密密钥，也不记录 envelope 内容。具体加密协议属于 B4 transport 待验证项，不复用浏览器 [SC-v2](../secure-channel/README.md) 作为已完成证明。

Core 自身登录/服务身份安全材料和 Core 管理的订阅链接验证摘要有独立契约；它们不能被 Provider 用于代理凭据私有存储。旧 User UUID 仅在受限导入桥内处理；持久业务权威切换后不得继续把 Provider 秘密写回 User。

## Operations

### `issue`：发行

输入：Envelope、Core 预建的 `access_subject_id`、`subscription_id/terms_id`、`credential_profile_id`（manifest 声明且管理员授予的 allow-list）、`not_before_at/credential_expires_at`、可选非秘密 `label`。不得发送 User 密码或选择任意秘密算法参数。

输出：`credential_ref/provider_subject_ref/credential_generation/status/issued_at/credential_expires_at`，可选独立 `delivery_ref`；不返回秘密原文。Core 在验证 metadata 并提交 Subject 绑定前不授予节点租约。Provider 成功而 Core 未提交时，原 operation 重试/查询取回同一结果；Core 可幂等吊销孤儿发行，不能再发行一次“补偿”。

Provider 对同一 Subject 可有受限多代 credential，generation 必须单调且历史保留；默认一个当前代，临时双代仅用于有界轮换窗口。credential_expires_at 为凭据自身寿命，不代替更短的授权租约。

### `parse`：解析

输入：Provider 私有入口收到的 `credential_carrier_ref` 或端到端密封 carrier、`protocol_profile_id`、`node_id`、Core/Node 关联的 `auth_snapshot_id` 与安全解析上下文。秘密不得经 Core 的普通 JSON 解析 endpoint 转发；Carrier 原文由 Provider 边界处理，引用本身不能授权取秘密。

输出：`matched/access_subject_id/credential_ref/credential_generation/provider_subject_ref/evidence_ref`，仅在匹配时返回归属。解析不是授权：Core/Node 还要验证 Subject 状态、Terms、配额、Core grant、租约和撤销版本。跨 Provider ref、未知 Subject 或归属冲突均拒绝；失败结果避免区分“存在但被禁用”和“不存在”造成枚举，入口须限流。

### `render`：节点或用户配置渲染

输入：`access_subject_id/credential_ref/credential_generation/format_id`、受限 `delivery_target_ref`、Core 签署的授权快照 metadata 和非秘密 allow-list 节点配置。用户配置交付需要独立用户/订阅链接归属校验；订阅链接不能作为 Node 服务身份。

节点快照 metadata 至少绑定 `auth_snapshot_id/node_id/access_subject_id/subscription_id/terms_id/usage_period_id/tariff_revision_id/auth_version/revocation_version/issued_at/lease_expires_at`，以及 Core policy digest、目的地身份和 Provider generation。Provider 不可改变 Core 配额、费率、有效期或计费归属。授权租约由 Core 签发，不由 Provider 自行延长。

输出：`delivery_ref/delivery_status/rendered_generation/auth_snapshot_id/lease_expires_at/content_digest`；接收方通过独立通道获得秘密配置，Core 只看到 receipt。相同 operation 重试复用同一个逻辑交付；交付已过期则返回 `LEASE_EXPIRED`，Core 如需新快照创建新的 operation，而非悄悄延长旧租约。

### `rotate`：轮换

输入：`access_subject_id/credential_ref/expected_credential_generation/rotation_mode`、`overlap_ends_at`、受限 `delivery_target_ref`；mode 仅 `immediate/bounded_overlap`。Core 校验 `overlap_ends_at` 不超政策窗口；有安全泄漏时禁止 overlap。

输出：`new_credential_ref/new_generation/previous_generation/previous_valid_until/rotation_status` 和非秘密交付状态。旧代失效必须产生撤销版本及投递，不只是从 Provider UI 隐藏。轮换不创建新 Subscription、不重置 Usage、不修改历史 Subject 归属。原 operation 重试不生成第三代；未知结果先 query，不将超时等同失败。

### `revoke`：吊销

输入：`scope`（credential/subject/provider）、受限对应 refs、`expected_generation`（credential scope）、`reason_code`（allow-list）、Core 确定的 `revocation_fact_version/effective_at`。此版本作用于声明的撤销事实域；Provider scope 仅有生命周期授权的 Core operation 能调用。

输出：`accepted_revocation_fact_version/provider_revoked_at/affected_subject_count/status`；必要时分页引用非秘密 affected Subject 清单。此输出只说明 Provider 私有状态，不证明所有 Node 已执行。Core 持久化 tombstone、禁止新授权、把撤销事实映射为各 `(node_id,source_epoch)` 授权域递增的 revocation_version 并投递，单独跟踪 convergence。重复撤销返回已有结果，同一事实域低版本不得回滚高版本，跨域版本不比较；被吊销 generation 永久不重新启用。

### `query`：状态与恢复查询

输入：只接受当前授权的 `operation_ref/credential_ref/access_subject_id` 三种有限查询，带 provider identity 和资源 scope；分页有硬限制。不提供按秘密搜索、任意数据库查询或全部秘密导出。

输出：`operation_status/credential_status/current_generation/issued_at/expires_at/revoked_at/provider_subject_ref/last_error_code` 与 delivery receipt。Core 另行返回/审计 Node convergence，不能由 Provider 推测 offline 节点执行结果。query 无业务变更，不启用插件、不刷新租约。

### `import`：旧凭据导入

输入：`migration_run_id/legacy_source_ref/source_record_hash/access_subject_id/legacy_profile_id` 及仅由受限迁移桥交给 Provider 的密封导入材料。Core 请求仅有 ref 和摘要；原 `User.uuid` 由只读迁移工具直达 Provider 私有导入边界，不给普通插件 Core DB 权限。source ref/摘要不能是可认证旧 UUID 本身。

输出：`import_receipt_id/credential_ref/provider_subject_ref/credential_generation/compatibility_status`；receipt 不包含旧秘密。UNIQUE(provider_id, migration_run_id, legacy_source_ref)；同 key 同 hash 同结果，不同 hash 为 `IDEMPOTENCY_CONFLICT`。保留旧凭据原认证行为直到迁移切换；重复凭据映射多用户/订阅、缺失来源或不支持旧协议进入隔离清单，禁止自动重发新凭据冒充兼容成功。

## 幂等、并发与不确定结果

变更幂等范围为 `(provider_id, provider_instance_epoch, operation, idempotency_key)`，Provider 持久保存 input_hash、operation state 与非秘密结果。相同 key+hash 返回同一动作；不同 hash 拒绝；Provider 私有事务提交与 operation 状态原子完成。幂等记录保留覆盖最大重试、离线及迁移恢复窗口；有长期引用的状态保留 tombstone。

Core 对 Subject 使用 expected version 和固定锁顺序，生命周期 state/generation 与发行、rotate、grant 并发仲裁。Provider 不可依赖一次进程内锁；恢复/重装后 instance_epoch 与旧 operation 容易重复的关系必须检查，不能用换 epoch 再执行同一业务发行。

Core 的 operation 先持久登记，再调用 Provider，最后提交领域状态/outbox；超时或连接断开标记 `pending/unknown` 并 query 同 operation。部分交付保留 receipt 并补发同版本；如果取消则幂等 revoke，然后等待 Node convergence。Core 对不安全/不可知结果 fail closed，不随意重发凭据或授予无限租约。

建议标准错误：`CAPABILITY_UNAVAILABLE/CONTRACT_UNSUPPORTED/PROVIDER_DISABLED/UNAUTHORIZED_SCOPE/SUBJECT_NOT_FOUND/SUBJECT_BINDING_CONFLICT/VERSION_CONFLICT/IDEMPOTENCY_CONFLICT/LEASE_EXPIRED/REVOKED/UNSUPPORTED_PROFILE/DELIVERY_PENDING/DEADLINE_EXCEEDED/IMPORT_QUARANTINED/RUNTIME_UNAVAILABLE/UNINSTALL_BLOCKED`。冲突/权限/吊销错误不自动重试；暂时错误保持原幂等键，指数退避有界，达到 deadline 后进入可观测待恢复状态。

## 租约、撤销与离线节点

Core 的 auth_snapshot_id 唯一且不可被重试改写。`lease_expires_at <= min(policy_deadline, subscription_expiry, credential_expiry)`；无期限订阅仍有有限 policy_deadline。Node 验证 Core grant、audience、Subject 绑定、Provider generation 和时间，维护已接受的最高 revocation_version，拒绝回退。授权材料不能通过 Provider 可用性探测延长。

租约到期 fail closed，停止新准入并结束受该租约控制的已有代理会话；否则不能声称租约到期代表停止使用。撤销在在线 Node 生效时停止准入/对应会话；离线 Node 最迟在最后授予租约到期加已验证时钟预算后失效。节点时钟回拨/无法信任时间须拒绝续用，最终实现必须通过故障注入验证。

Core 保存每个目标 `(node_id,source_epoch)` 的 applied revocation_version、最后可能有效租约到期与观察证据，不能拿 Provider accepted_revocation_fact_version 代替 Node 回执。只有 Node 确认已应用撤销，或其所有已授予租约按可验证时钟政策到期，才可声明该目标收敛。离线/旧节点没有有限租约时无法自动证明全网撤销，应阻断正常卸载或通过独立经验证的停止节点流程处理。

授权撤销不拒绝合法历史流量：Node 继续持久化/重发已发生 usage，批次携带当时 `auth_snapshot_id/access_subject_id/usage_period_id/tariff_revision_id` 等不可变绑定，重试不改价、不改归属。Core 依据历史快照结算，不要求当前 Provider enabled 或秘密仍存在。可疑/跨期不足证据按 [Node Usage](../node-usage/README.md) 隔离处理，不自动丢弃。

## 停用、卸载、清理状态机

生命周期操作有独立 operation id、checkpoint、授权与审计；下列是 v2 Target，不覆盖现有 PluginManager.uninstall。

| 状态与转换 | Core/Provider 行为 | 完成条件 |
| --- | --- | --- |
| `installed_disabled → enabled` | 校验契约、私有存储、依赖/授权；开放发行和 grant | health/兼容门禁通过；被 revoke 的旧代不复活 |
| `enabled → disabling → disabled` | 先 fence 新 issue/import/rotate 与新租约；保留数据和 query/revoke/受限排空能力 | in-flight operation 逐个完成或撤销；已发租约在限定窗口到期；不得宣称立即全网失效 |
| `enabled/disabled → uninstalling` | 固定生命周期 generation，拒绝并发 enable/new grant；对全部活动 credential/Subject revoke，发布撤销版本 | 批量撤销有游标与回执；失败保持 uninstalling，不删存储 |
| `uninstalling → draining` | 跟踪每节点收敛；关闭秘密新下发，保留 query/revoke 和必要恢复入口 | 全部目标 ACK 或可证明租约失效；旧节点无租约阻断 |
| `draining → uninstalled` | 已接收 usage/operation 排空或移交给不依赖 Provider 的 Core 恢复；删除活动 credential 数据，移除运行时 | 无无法解释的待结算/秘密交付引用；历史 Subject、流水、审计仍完整 |
| `uninstalled → purged` | 清除剩余 Provider 配置/秘密/私有存储及相关密钥 | 备份保留/销毁策略记录；不删除 Core 历史账本和非秘密 tombstone |
| 任何未完成撤销 → `forced_pending` | 仅显式受权风险操作；记录不支持旧节点/未收敛目标，停止新下发并保留恢复材料 | 不是正常卸载成功；后续仍需撤销确认、停止节点或租约失效及对账 |

Disable 的目标是停止新发行与新授权，已存在的有限租约不会被悄悄续期；如需立即停用全部访问，使用 revoke 并检查收敛。Uninstall 必须能够从任意 checkpoint 续跑，不能反向开通 Subject；不可逆吊销后取消卸载也只恢复生命周期管理，重新访问需新代凭据/新授权。

迟到历史批次可能在运行时移除后到达；Core 必须保留无需 Provider 秘密的映射、历史 auth/费率快照、去重和结算恢复能力。已持久确认的待结算批次必须结算/明确隔离或可靠移交后才完成卸载，不能以“插件已经不在”删除它们。无限期等待离线节点不是实现办法：有限租约决定最大授权窗口，历史数据按既定 retention/quarantine 政策继续处理。

## 验收与待验证决定

B4.3 的[本地 Plugin 停用接入](local-fixture-plugin-boundary-v1.md)仅适用于固定可信测试插件：Plugin 状态与 Provider 发行 fence 原子提交，私有清理保留原 operation；卸载与删除继续等待 Node/租约/交付/结算门禁。它不改变下述 v2 Target 的完成要求。

B4.4 的[本地租约候选](local-fixture-lease-candidate-v1.md)和 B4.5 的[初始绑定与离线签名快照](local-fixture-authorization-snapshot-v1.md)默认关闭，只适用于 local/testing 的固定合成 Provider。后者原子保存初始 Subject 绑定和签名历史，公钥 CLI 只验证离线证据；两者均不授予 Node 准入，不完成秘密交付或全网撤销。

B4 契约测试覆盖七个 operation、超时后成功、同键冲突、重复导入/重复轮换、Provider 失联、Core 提交前后失败、停用与发行并发、卸载与 enable 并发、Node 离线/时间回拨/撤销回退、运行时移除后晚到 usage、跨 Provider 错绑和秘密泄漏。Node 控制与使用量故障测试独立证明 auth lease 和计费归属；只有 PHP 单元测试通过不能证明沙箱或全网撤销。

尚需定稿：JSON Schema、输入规范化摘要算法、独立 runtime transport/服务身份、Node 加密材料封装、有限租约/时钟预算、具体 retention、批量 revoke 分页与 checkpoint 存储。实现开始前将这些细节落入 Schema/DTO/Node 消费端及测试；当前 API/Plugin v1 权限边界在 [Core First ADR](../../../docs/architecture/v2/core-boundaries.md) 门禁前保持。
