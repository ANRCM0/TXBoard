# B0 静态基线盘点

由 `node TXBoard/scripts/export-core-baseline.mjs --workspace <四仓父目录>` 生成；`--check` 比较保存快照与当前源码及文件列表，并验证快照基准提交仍为当前 HEAD 的祖先。完整清单见 [baseline-inventory.json](baseline-inventory.json)，兼容结论见 [baseline-compatibility-matrix.md](baseline-compatibility-matrix.md)。

此清单是词法静态扫描，**不是完整动态调用图、Laravel 运行时 route:list、权限验证或生产验收**。HEAD 仅标识提交基准；脚本每次在控制台输出当前工作区 dirty 状态，不冻结该易变状态，也不宣称工作区干净。源码哈希按 LF 规范化，无时间戳、绝对路径或凭据。

## 基准与覆盖

| 仓库 | HEAD | 工作区 |
| --- | --- | --- |
| TX-Node | `925012ef6422fc1f56b813584e242eb304bb31f6` | 每次运行以控制台 porcelain 为准 |
| TXBoard | `0c70d5a60503f6291cc079ce5002bd07f8d5e90a` | 每次运行以控制台 porcelain 为准 |
| TXBoard-Deploy | `ae607bbb9b0ccf6343b8a9c3cf0c2edeacbebdfc` | 每次运行以控制台 porcelain 为准 |
| TXBoard-Gateway | `1b2f7a08645b9f0c8ad25a1c17c71f14d9c41cbc` | 每次运行以控制台 porcelain 为准 |

覆盖 563 个源码文件；36 个 Models、54 个 migrations、101 个 Services/Domains/Jobs 文件；255 条 HTTP 声明候选、15 个 Gateway 固定上游操作、46 处 Node 类型/协议事件引用、26 组 Deploy 命令。文件增删或源码修改均进入 `--check`。

JSON 的 `sourceManifest` 是完整覆盖文件列表及 SHA-256。`core.dependencies` 按文件列出全部直接 App import/symbol、DB 调用表名、SQL 表词候选、其他原生表字面值候选、Redis/Cache/Queue/Bus/DB 调用及 Plugin hooks。词法引用不等于执行调用，容器字符串、继承、反射、动态变量及插件发现仍需追踪。

## Model 表与关系

| Model | 显式表 | Eloquent 关系声明数 | 来源 |
| --- | --- | --- | --- |
| AdminAuditLog | `tx_admin_audit_log` | 1 | [TXBoard/api/app/Models/AdminAuditLog.php](../../../api/app/Models/AdminAuditLog.php) |
| AgentAction | `tx_agent_action` | 3 | [TXBoard/api/app/Models/AgentAction.php](https://github.com/ANRCM0/TXBoard/blob/0c70d5a60503f6291cc079ce5002bd07f8d5e90a/api/app/Models/AgentAction.php) |
| AgentAuditLog | `tx_agent_audit_log` | 1 | [TXBoard/api/app/Models/AgentAuditLog.php](https://github.com/ANRCM0/TXBoard/blob/0c70d5a60503f6291cc079ce5002bd07f8d5e90a/api/app/Models/AgentAuditLog.php) |
| AgentInspection | `tx_agent_inspection` | 0 | [TXBoard/api/app/Models/AgentInspection.php](https://github.com/ANRCM0/TXBoard/blob/0c70d5a60503f6291cc079ce5002bd07f8d5e90a/api/app/Models/AgentInspection.php) |
| AgentSupportReplyRequest | `tx_agent_support_reply_request` | 0 | [TXBoard/api/app/Models/AgentSupportReplyRequest.php](https://github.com/ANRCM0/TXBoard/blob/0c70d5a60503f6291cc079ce5002bd07f8d5e90a/api/app/Models/AgentSupportReplyRequest.php) |
| CommissionLog | `tx_commission_log` | 0 | [TXBoard/api/app/Models/CommissionLog.php](../../../api/app/Models/CommissionLog.php) |
| Coupon | `tx_coupon` | 0 | [TXBoard/api/app/Models/Coupon.php](../../../api/app/Models/Coupon.php) |
| GiftCardCode | `tx_gift_card_code` | 3 | [TXBoard/api/app/Models/GiftCardCode.php](../../../api/app/Models/GiftCardCode.php) |
| GiftCardTemplate | `tx_gift_card_template` | 3 | [TXBoard/api/app/Models/GiftCardTemplate.php](../../../api/app/Models/GiftCardTemplate.php) |
| GiftCardUsage | `tx_gift_card_usage` | 4 | [TXBoard/api/app/Models/GiftCardUsage.php](../../../api/app/Models/GiftCardUsage.php) |
| InviteCode | `tx_invite_code` | 0 | [TXBoard/api/app/Models/InviteCode.php](../../../api/app/Models/InviteCode.php) |
| Knowledge | `tx_knowledge` | 0 | [TXBoard/api/app/Models/Knowledge.php](../../../api/app/Models/Knowledge.php) |
| MailLog | `tx_mail_log` | 0 | [TXBoard/api/app/Models/MailLog.php](../../../api/app/Models/MailLog.php) |
| MailTemplate | `tx_mail_templates` | 0 | [TXBoard/api/app/Models/MailTemplate.php](../../../api/app/Models/MailTemplate.php) |
| Notice | `tx_notice` | 0 | [TXBoard/api/app/Models/Notice.php](../../../api/app/Models/Notice.php) |
| Order | `tx_order` | 5 | [TXBoard/api/app/Models/Order.php](../../../api/app/Models/Order.php) |
| Payment | `tx_payment` | 0 | [TXBoard/api/app/Models/Payment.php](../../../api/app/Models/Payment.php) |
| Plan | `tx_plan` | 4 | [TXBoard/api/app/Models/Plan.php](../../../api/app/Models/Plan.php) |
| Plugin | `tx_plugins` | 0 | [TXBoard/api/app/Models/Plugin.php](../../../api/app/Models/Plugin.php) |
| Server | `tx_server` | 3 | [TXBoard/api/app/Models/Server.php](../../../api/app/Models/Server.php) |
| ServerGroup | `tx_server_group` | 2 | [TXBoard/api/app/Models/ServerGroup.php](../../../api/app/Models/ServerGroup.php) |
| ServerLog | `tx_server_log` | 0 | [TXBoard/api/app/Models/ServerLog.php](../../../api/app/Models/ServerLog.php) |
| ServerMachine | `tx_server_machine` | 2 | [TXBoard/api/app/Models/ServerMachine.php](../../../api/app/Models/ServerMachine.php) |
| ServerMachineLoadHistory | `tx_server_machine_load_history` | 1 | [TXBoard/api/app/Models/ServerMachineLoadHistory.php](../../../api/app/Models/ServerMachineLoadHistory.php) |
| ServerRoute | `tx_server_route` | 0 | [TXBoard/api/app/Models/ServerRoute.php](../../../api/app/Models/ServerRoute.php) |
| ServerStat | `tx_server_stat` | 0 | [TXBoard/api/app/Models/ServerStat.php](../../../api/app/Models/ServerStat.php) |
| Setting | `tx_settings` | 0 | [TXBoard/api/app/Models/Setting.php](../../../api/app/Models/Setting.php) |
| Stat | `tx_stat` | 0 | [TXBoard/api/app/Models/Stat.php](../../../api/app/Models/Stat.php) |
| StatServer | `tx_stat_server` | 1 | [TXBoard/api/app/Models/StatServer.php](../../../api/app/Models/StatServer.php) |
| StatUser | `tx_stat_user` | 0 | [TXBoard/api/app/Models/StatUser.php](../../../api/app/Models/StatUser.php) |
| SubscribeTemplate | `tx_subscribe_templates` | 0 | [TXBoard/api/app/Models/SubscribeTemplate.php](../../../api/app/Models/SubscribeTemplate.php) |
| Ticket | `tx_ticket` | 3 | [TXBoard/api/app/Models/Ticket.php](../../../api/app/Models/Ticket.php) |
| TicketMessage | `tx_ticket_message` | 1 | [TXBoard/api/app/Models/TicketMessage.php](../../../api/app/Models/TicketMessage.php) |
| TrafficResetLog | `tx_traffic_reset_logs` | 1 | [TXBoard/api/app/Models/TrafficResetLog.php](../../../api/app/Models/TrafficResetLog.php) |
| User | `tx_user` | 9 | [TXBoard/api/app/Models/User.php](../../../api/app/Models/User.php) |
| WalletRecharge | `tx_wallet_recharge` | 0 | [TXBoard/api/app/Models/WalletRecharge.php](../../../api/app/Models/WalletRecharge.php) |

完整关系方法、目标类、显式键及行号在 `core.models[].relationships`。User 仍拥有 plan/group 与流量、订阅凭据等字段；Plan/Order 直接关联 User。未发现新版 Subscription、Access Subject 或独立资金账本模型，这仅适用于当前 Models 目录。

## Migration 表操作

`core.migrations` 覆盖全部 migration 文件及 Schema 操作，按 `up/down` 等方法标记，保留 create/table/rename/drop/hasTable 等操作和行号。局部变量赋值仅作为静态候选，无法解析的表保留 `dynamic-unresolved`。历史 migration 中旧 server 协议表或 tx_log 的 create/drop 不能被解读为实际数据库仍存在这些表。

## HTTP 注册入口与分类

- [TXBoard/api/app/Providers/RouteServiceProvider.php](../../../api/app/Providers/RouteServiceProvider.php)：Laravel map; /api/health and /txapi/health; /txapi -> routes/txapi.php; web -> routes/web.php
- [TXBoard/api/routes/txapi.php](../../../api/routes/txapi.php)：Only registered TXBoard application HTTP API declarations; group/auth candidates in routeDeclarations
- [TXBoard/api/routes/web.php](../../../api/routes/web.php)：Web rendering, private subscription URL and dynamic Admin SPA
- [TXBoard/api/app/Http/Kernel.php](../../../api/app/Http/Kernel.php)：Middleware aliases; actual authorization also requires controllers/services/config
- [TXBoard/api/app/WebSocket/NativeNodeWebSocket.php](../../../api/app/WebSocket/NativeNodeWebSocket.php)：Native Workerman WebSocket protocol; not a Laravel route:list entry
- [TXBoard/api/app/Console/Commands/NodeWebSocketServer.php](../../../api/app/Console/Commands/NodeWebSocketServer.php)：Workerman listener bootstrap and feature/configuration boundary
- [TXBoard/api/app/Services/Plugin/PluginManager.php](../../../api/app/Services/Plugin/PluginManager.php)：Trusted in-process plugin dynamic registration; not resolved by this static inventory

| 静态分类 | 声明数 |
| --- | --- |
| admin | 157 |
| admin-spa | 1 |
| agent | 21 |
| agent-pairing | 1 |
| health | 2 |
| node-machine | 8 |
| payment-callback | 1 |
| public-or-auth-controller-policy | 11 |
| subscription-url | 1 |
| telegram-callback | 1 |
| user | 49 |
| web | 2 |

分类根据声明 middleware 与特定协议回调生成，匿名/auth 入口仍由控制器政策决定；不能仅凭路径证明鉴权。真正的 HTTP 注册表应在可启动环境执行 `php artisan route:list --json`，再按现有 `scripts/export-route-catalog.mjs` 校验。插件动态路由、Workerman WSS 和反向代理分别验证。

## Gateway 上游对应候选

| Gateway operation | 当前固定上游 | Core 静态候选 |
| --- | --- | --- |
| guestConfig | `GET /api/v1/guest/comm/config` | `GET /txapi/public/site-config` (源码存在) |
| guestPaymentMethods | `GET /api/v1/guest/paymentMethods` | 无公开等价声明；需独立决策 |
| guestPlans | `GET /api/v1/guest/plan/fetch` | `GET /txapi/plans` (源码存在) |
| login | `POST /api/v1/passport/auth/login` | `POST /txapi/auth/login` (源码存在) |
| register | `POST /api/v1/passport/auth/register` | `POST /txapi/auth/register` (源码存在) |
| sendEmailCode | `POST /api/v1/passport/comm/sendEmailVerify` | `POST /txapi/auth/email-code` (源码存在) |
| userNotices | `GET /api/v1/user/notice/fetch` | `GET /txapi/notices` (源码存在) |
| userOrderCreate | `POST /api/v1/user/order/save` | `POST /txapi/orders` (源码存在) |
| userOrderDetail | `GET /api/v1/user/order/detail` | `GET /txapi/orders/{tradeNo}/detail` (源码存在) |
| userOrders | `GET /api/v1/user/order/fetch` | `GET /txapi/orders` (源码存在) |
| userOrderStatus | `GET /api/v1/user/order/check` | `GET /txapi/orders/{tradeNo}` (源码存在) |
| userPaymentMethods | `GET /api/v1/user/order/getPaymentMethod` | `GET /txapi/billing/payment-methods` (源码存在) |
| userProfile | `GET /api/v1/user/info` | `GET /txapi/me` (源码存在) |
| userStats | `GET /api/v1/user/getStat` | `GET /txapi/me/dashboard-stats` (源码存在) |
| userSubscription | `GET /api/v1/user/getSubscribe` | `GET /txapi/me/subscription` (源码存在) |

**全部 15 个固定上游仍是 /api/v1 路径，当前 Core 应用 API 仅注册 /txapi，因此两份当前源码直接对接不兼容。** 以上对应关系仅表示迁移候选，不证明语义、字段、凭据或权限等价。公开支付方法无已注册匿名等价接口，不能把用户接口简单改成匿名。

Gateway 仅解码 legacy `status=success` 或 `{data,total}` 分页，当前 Core 的 `{data,request_id,meta?}` 会被拒绝；错误码也需从顶层 legacy `code` 迁移到 `error.code`。当前登录仍返回 `auth_data: Bearer ...`，其值来自 Sanctum；字段名相同不能证明历史值有效。登录/撤销/session DTO 需验收。

## Core 耦合与 Node / Plugin / Agent

`core.couplingPoints` 完整列出全部 Core app 文件中 App\Protocols、Plugin、Module、AgentOps、WebSocket、Node/ServerService、NodeUserSyncJob、NativeNodeReport 的直接引用和 Services/Domains/Jobs 中的 hooks。例如 OrderService 的 order.create/open/cancel hooks 进入可信进程内 PHP 插件，UserService 的流量过滤 hook 在入队前执行。该清单不能证明事务边界、隔离或授权；后续切片应逐一验证。

Native Node 使用独立 `internal/txboard` 适配器、专用 Bearer 身份头与 `/txapi/node/v1`。旧 `internal/panel` Xboard 适配器另存，不能凭其中 legacy 路径判断原生协议缺失。`node.nativeHttpOperations`、`node.typedEventOccurrences` 列出 HTTP 操作及全部指定协议事件源码位置，包括固定 ops 类型与业务同步。

Node 发送前将一个不可变批次以稳定 ID 写入 spool 并 fsync，重启重放；成功 ACK 清本地 spool。原生 ACK 是 **202 / queued**，不是 SQL 结算确认。Core Worker 在一个 SQL 事务中写批次去重记录、用户/节点流量与统计，但入队后的持久接收、故障恢复与对账仍是 B3 缺口。流量主体当前仍是 user ID。spool 损坏/不可写会拒绝启动；未刷盘内存流量及 ACK 后永久队列丢失不能靠 Node 重放解决。详见 JSON `node.semantics.evidence`。

## Deploy 与恢复限制

`deploy.commands` 覆盖 txboard 顶层全部命令分支和 aliases，`deploy.sourceFiles` 覆盖 shell/Compose 文件。更新器有预检、备份及 migration/健康检查；未尝试迁移时可启动旧镜像，尝试迁移后要求停止服务并验证兼容备份/人工恢复，不能承诺通用数据库回滚。自动交互恢复限内置 local MySQL，host/external 数据库需管理员工具。当前 phase/attempted_migrate 是进程变量，没有持久化跨组件断点恢复日志。

保留 Core `/api/health` 的容器存活契约；`/txapi/health` 是独立 TXAPI 存活入口。TX-Node-Installer 不在本地，未盘点其实现，不对其升级/恢复能力作结论。

## 再生成与漂移检查

`node TXBoard/scripts/export-core-baseline.mjs --workspace <四仓父目录>` 写入 JSON、本文及历史兼容矩阵（baseline-compatibility-matrix.md）；不覆盖当前 compatibility-matrix.md。`node TXBoard/scripts/export-core-baseline.mjs --workspace <四仓父目录> --check` 只比较且漂移时返回非零。工作区状态实时输出并不进入生成物，文档/脚本本身改动不会制造永久 dirty 漂移；当前代码仍按实际工作区扫描，源码变化不能被 HEAD 掩盖。检查使用快照中固定的基准 HEAD，文档提交不会改写基准或导致误报；需要建立新基线时显式重新生成。
