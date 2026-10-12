# B0 跨仓兼容矩阵

由 `scripts/export-core-baseline.mjs` 与 [静态库存](baseline-inventory.json) 同步生成。此矩阵描述当前源码与 v2 目标间的缺口，**不代表运行时联调或发布认证**。原始路径、类、事件、来源行号及基准 HEAD 见 [基线说明](baseline-inventory.md)。

| 边界 | 当前生产者 / 消费者 | 当前结论 | v2 切片要求 |
| --- | --- | --- | --- |
| Gateway → Core HTTP | Gateway 15 个固定 `/api/v1/*`；Core `/txapi/*` | **实质不兼容**：旧应用入口未注册；详见下表 | 单独原生适配器及 User 契约；保留闭合 allowlist，验证鉴权/参数/响应 |
| 用户凭据 | Gateway SDK/DTO 使用 `auth_data` 并转发 Bearer；Core 登录同样返回 `auth_data`，TxapiUser 使用 Sanctum | Bearer 形状通过不能证明旧值可用；旧凭据/DTO 未验证迁移 | 明确 token 签发/会话/注销/撤销；验收同名字段的 Sanctum 值，不借用 Admin/Agent/Node Token |
| JSON 与错误 | Gateway upstream 仅接受 legacy `status=success` 或 `{data,total}`，取顶层 `code`；Core `{data,request_id,meta?}` / `error.code` | **实质不兼容**：仅替换 URL 仍会返回 Gateway 502 或丢业务码 | 显式 Native envelope codec 与错误映射，DTO/分页/价格/订阅字段逐项验收 |
| Gateway 订单写入 | 源码有默认关闭 `userOrderCreate` | 代码存在不等于启用或真实 Core 通过；上游仍 legacy | Core 持久幂等、沙箱支付/回调、负向重放测试先验收再开 flag |
| Admin Web API | Core 动态路径 Admin Bearer/RBAC/audit；Gateway 当前用户 BFF | 当前没有统一 Admin Gateway；v1 AGENTS 边界继续有效 | v2 ADR/SC-v2 建立新边界和兼容窗口后再实施 |
| Node HTTP/WSS | TX-Node native adapter ↔ Core node/v1，protocol_version=1，专用 Bearer/身份头，Workerman WSS | 有双方原生实现；静态对应，运行时未验收；legacy panel 是独立适配器 | 保持 v1 与 capability 协商；逐事件验证，不将 Laravel route:list 当成 WSS 可用证明 |
| 流量/ACK | Node 稳定 batch/spool；Core 202 queued 后 Worker SQL 去重事务 | 重试基础存在；ACK 后本地 spool 清除，未形成 Core 持久接收闭环；计费 user ID | B3 持久接收/恢复/对账；B1 主体模型是前置，B2 恢复能力覆盖重大切换；需协议版本及双阶段兼容 |
| Plugin/Theme 包 | Plugin Package v1、Theme Package v1、Admin Bridge；PHP Plugin 可信进程内 | 保持既有包/生命周期兼容；没有 PHP 沙箱隔离 | 新隔离协议另起显式版本/adapter；不暗改 v1 或让第三方宣称 runtime 状态 |
| Module 生命周期 | Registry 库存 → ModuleLifecycle → Plugin/Theme runtime | 已有 adapter 边界；不是新版完全隔离平台 | 延续权威状态与运行时委派，不复制业务源头 |
| Agent/MCP | MCP adapter → Agent Ops ability/scope/approval/audit → domain → typed Node ops | 已有受限调用链；ops.result 是报告，独立 verify 才能证明恢复 | 保持审批/范围/审计，禁直连 DB/Redis/Node/任意 shell；v2 不绕过 v1 控制 |
| Deploy / health | Core `/api/health` + image healthcheck；Deploy 安装/更新/备份 | **必须保留 /api/health**；健康通过不证明账务/Node/插件业务正确 | 跨组件 release manifest / 兼容矩阵 / 持久 checkpoint，业务独立验收 |
| Deploy / rollback | 无迁移尝试时旧镜像恢复；迁移后停服等待兼容备份；restore 限 local MySQL | 不保证数据库逆迁移；host/external restore 人工 | 离线副本迁移与恢复演练，版本兼容才启动，不自动降级未知 schema |
| TX-Node-Installer | 本地缺失 | **未核查**，不从远端链接推断实现 | 单独取得仓库/版本再进入兼容矩阵，当前不阻塞本地 B0 文档 |

## 已确认的字段差异

| 操作 | Gateway 当前读取/校验 | Core 当前输出 |
| --- | --- | --- |
| 订阅概览 | txboard-v1 adapter 读取 `u/d/transfer_enable/speed_limit` 等 legacy 字段 | `upload_bytes/download_bytes/traffic_limit_bytes/speed_limit_mbps`，到期时间为 ISO8601 |
| Dashboard | `statsSchema` 校验 3 个非负整数 tuple | `unpaid_orders/open_tickets/invited_users` 对象 |
| 订单状态 | `orderStatusSchema` 校验 0..3 的单个整数 | AccountController.order 返回含 `trade_no/status/amount_minor` 的对象 |
| 订单/通知分页 | legacy 上游 `current/pageSize` 及顶层 `total` | Native 参数/分页 `meta` 需独立适配，不能复用旧包解码 |

来源：[TXBoard-Gateway/apps/gateway/src/contracts/schemas.ts](https://github.com/ANRCM0/TXBoard-Gateway/blob/1b2f7a08645b9f0c8ad25a1c17c71f14d9c41cbc/apps/gateway/src/contracts/schemas.ts)、[TXBoard-Gateway/apps/gateway/src/adapters/txboard-v1.ts](https://github.com/ANRCM0/TXBoard-Gateway/blob/1b2f7a08645b9f0c8ad25a1c17c71f14d9c41cbc/apps/gateway/src/adapters/txboard-v1.ts)、[TXBoard/api/app/Http/Controllers/Txapi/AccountController.php](../../../api/app/Http/Controllers/Txapi/AccountController.php)。这些是静态确定的差异，不是所有 DTO 的完整等价审计。

## 固定上游操作与 Core 源码候选

| Gateway operation | 当前上游 | Core 候选（语义未验证） |
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

来源：[TXBoard-Gateway/apps/gateway/src/services/upstream.ts](https://github.com/ANRCM0/TXBoard-Gateway/blob/1b2f7a08645b9f0c8ad25a1c17c71f14d9c41cbc/apps/gateway/src/services/upstream.ts)、[TXBoard/api/app/Providers/RouteServiceProvider.php](../../../api/app/Providers/RouteServiceProvider.php)、[TXBoard/api/routes/txapi.php](../../../api/routes/txapi.php)、[TXBoard/api/app/Http/Middleware/TxapiUser.php](../../../api/app/Http/Middleware/TxapiUser.php)。没有匿名支付方法对应候选，不能把现有用户支付方法接口降级成公开入口。

所有“源码存在”仅通过词法注册声明匹配；HTTP route:list、配置/feature flags、实际 Token、DTO 及 Core/Node/Deploy 联调在各实现阶段验证。
