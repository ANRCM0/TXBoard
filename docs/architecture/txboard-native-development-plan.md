# TXBoard Native — 独立化重构与优化开发方案

> 日期：2026-10-09 · 方案 1.0 · 状态：**P0–P2 核心代码已落地，P3–P7 仍待开发/验证**
>
> **唯一正式 API 根入口：`/txapi`。** 原有 `/api/v1`、`/api/v2` 只作为迁移兼容入口，确认所有支持的消费端升级后退役。
>
> 本方案持续记录已合并代码与目标施工路线；当前具体实现以 main 分支源码及已验证的 CURRENT contracts 为准。P2 交付与明确保留的旧业务路径见 [P2 验收记录](p2-completion.md)。

## 1. 改造目标、原则与非目标

目标是将 TXBoard 从依赖 Xboard 历史接口和源码结构的衍生实现，发展为独立维护的模块化网络服务控制面，同时优化安全、性能、可测试性与维护成本。

- **架构**：继续 Laravel 12 + Octane/Swoole + MySQL + Redis/Horizon，React Admin + Vue User；采用模块化单体，暂不拆为微服务。
- **业务**：用户、订阅、套餐、资金、订单、支付、节点、流量、工单、通知、插件、主题、Agent Ops 保持完整。
- **边界**：TXBoard 是 Control Plane；TX-Node 是独立 Data Plane；Gateway/MCP 是受限 API 适配器，不成为第二控制面。
- **价值优先**：优先消除旧契约依赖、重复逻辑、不安全行为和可测量性能瓶颈，而非以重命名目录、相似率下降或行数减少衡量成功。
- **渐进迁移**：contract → baseline/tests → domain implementation → legacy adapter → caller migration → observation → retirement。每个业务域独立 PR、回滚和验收。
- **严禁**：一次性删除旧支付回调、Node/Agent/插件协议、全部老路由；同批重命名所有 v2_* 表；将新业务继续接到旧 V1/V2 控制器上。
- **许可证**：保留 api/LICENSE 与 THIRD_PARTY_NOTICES.md 的来源和版权要求，去技术依赖不等于消除第三方许可。

最终完成定义：官方调用者全部迁往 `/txapi/*`、无需 Xboard 运行时接口和旧响应适配、资产及资金/流量账本对账一致、跨仓库协议/生产回滚/CI 验证齐全。

## 2. 已核查源码与 Xboard 耦合点

| 当前实现 | 核查发现 | 工作 |
|---|---|---|
| `api/app/Providers/RouteServiceProvider.php` | 注册 /api/v1、/api/v2 | 新路由注册、旧路由隔离 |
| `api/app/Http/Routes/V1/*`、`V2/*` | 多数还按 Xboard 层级组织，V2 复用 V1 Controller | 领域 API、控制器解耦 |
| `web/user/src/api/client.ts` | /api/v1、xboard_auth_data 与旧 envelope | 新 API client、登录态安全迁移 |
| `web/admin/src/api/client.ts` | /api/v2/{secure_path} | /txapi/admin/{secure_path}、动态轮换 |
| `api/app/Services/PaymentService.php` | 支付通知 URL 硬编码 /api/v1 | 命名路由、老单通知兼容 |
| `api/app/Http/Controllers/V1/User/OrderController.php` | 全量查询订单列表 | 服务端分页、筛选、稳定排序 |
| `web/user/src/api/order.ts` | 浏览器端过滤/分页 | 标准分页与类型化 DTO |
| `api/app/Models/User.php`、`Plan.php`、`Order.php` | v2_* 表名和部分历史字段 | 隔离领域规则，DB 最后迁移 |
| `api/app/Services/OrderService.php` | 订单、计价、返佣及结算逻辑集中 | 业务服务拆分、并发/幂等 |
| `contracts/node-protocol/README.md` | /api/v2/server/* 是当前正式 Node 线协议 | 双边版本化协议 |
| `mcp/README.md`、Agent contracts | 仍使用 /api/v2/agent | 独立 Agent API，维持授权/审批 |
| Plugin Package v1 | 旧 host API base 和插件端点 | 扩展路由版本化，保留迁移窗口 |

抽样对照上游 cedar2025/Xboard master：V1 UserRoute、V1 PassportRoute、V2 ServerRoute、User Model 与对应上游内容一致，OrderService 存在差异。**这不能推算整个项目的 Xboard 代码占比**；完整 provenance/依赖盘点属于 P0。

## 3. TXAPI 根入口与目标接口

| 领域 | 目标路径 | 安全要求 |
|---|---|---|
| 轻量健康检查 | GET `/txapi/health` | 不依赖数据库/Redis 的 liveness，readiness 独立 |
| 公共配置 | GET `/txapi/public/config` | 匿名，公开字段白名单 |
| 注册/登录 | POST `/txapi/auth/register`、`/txapi/auth/login` | CAPTCHA、速率限制、反枚举 |
| 用户资料 | GET/PATCH `/txapi/me` | User token |
| 套餐 | GET `/txapi/plans` | 可售/可见策略 |
| 订单列表 | GET `/txapi/orders?page=1&per_page=20` | 服务端分页和用户隔离 |
| 订单详情 | GET `/txapi/orders/{tradeNo}` | 强制所有权验证 |
| 取消订单 | POST `/txapi/orders/{tradeNo}/cancel` | 状态/幂等检查 |
| 管理后台 | `/txapi/admin/{secure_path}/*` | Admin token + RBAC + 路径轮换 + 审计 |
| TX-Node | `/txapi/node/v1/*` | Node 身份和版本化协议 |
| Agent Ops | `/txapi/agent/v1/*` | Agent 专用 abilities、target scope、审批 |
| 插件/扩展 | `/txapi/extensions/{code}/v1/*` | host 权限 + 启停防护 |
| 支付回调 | `/txapi/payment/webhooks/*` | 验签、重放检测和唯一结算 |
 
用户/Admin REST 不强制路径版本号；跨仓库 Node、Agent、Extension 协议必须有版本。任何新 HTTP 功能禁止注册在 /api/v1 或 /api/v2。不能把 secure_path 当成管理员鉴权；用户/管理员/节点/Agent token 不可互相提权。

### 3.1 响应契约（目标建议，待 P1 冻结）

成功示例：

```json
{"data":[{"id":1}],"meta":{"page":1,"per_page":20,"total":42,"last_page":3},"request_id":"trace-id"}
```

错误示例：

```json
{"error":{"code":"ORDER_NOT_FOUND","message":"订单不存在"},"request_id":"trace-id"}
```

- HTTP 2xx 代表成功；错误使用正确 4xx/5xx，不再返回 Xboard 风格的 status success/fail 兼容 envelope。
- 分页参数为正整数、设上限（建议 per_page<=100）、排序/过滤字段白名单且使用稳定次排序键。
- 金额使用整数最小货币单位与明确 currency；流量使用非负 64 位整数字节；时间使用 UTC RFC3339；敏感字段显式输出白名单。
- Mutation 校验、所有权、限流、request_id、审计与错误码有统一规则；支付可重试动作有幂等键和数据库唯一性保障。
- 详细候选规范见 [TXAPI Target](../../contracts/http/txapi-target-v1.md)。目标文档不是当前已上线协议。

### Gateway 双仓联合方案（ADR-006）

`/txapi` 是统一对外 API 根路径，**并不意味着所有请求都通过 Gateway**。独立 Hono Gateway 只处理未来 `/txapi/bff/v1/*` 的可选主题/用户 BFF；其余 native API、Admin、Node、Agent、Webhook、插件直接进入 Laravel。Edge 要先分流 BFF 子树，Gateway 只通过私有 TXBoard origin 的固定 named operations 调用 native API。BFF v1 保留现有 SDK `{ok,data,meta}` envelope，与 Native `{data,meta,request_id}` 不同。现有 `/gateway/v1/*` 和固定 `/api/v1/*` 仍有效。联合 G0–G5 工作包、可信代理风险和双轨/回退请见 [Gateway Integration ADR](gateway-integration.md) 与 [BFF Target Contract](../../contracts/http/txapi-bff-target-v1.md)。

## 4. 建议的模块化单体代码结构

```text
api/app/
├── Core/
│   ├── Auth/             # 认证、授权、能力/资源策略
│   ├── Http/             # 请求验证、响应与错误、Trace
│   └── Contracts/        # 值对象与共享接口
├── Domains/
│   ├── Identity/         # User/Session
│   ├── Subscription/     # Plan/Subscription/Pricing
│   ├── Billing/          # Order/Payment/Balance/Coupon/Commission
│   ├── Network/          # Node/Machine/Group/Route
│   ├── Traffic/          # Usage/Settlement/Reset/Stats
│   ├── Support/          # Ticket/Knowledge/Notice
│   └── Notification/     # Mail/Telegram/Provider
├── Services/             # 旧服务迁移缓冲区
└── Http/                 # 领域路由、请求/资源、legacy adapters
```

Controller 不承担复杂交易，只完成鉴权、验证和调用服务。域服务承担业务规则和事务；Repository/DTO 按复杂度使用，不制造为了“漂亮架构”而产生的层层转发。legacy adapter 只转换旧请求/响应，新原生领域绝不输出 Xboard 专用字段。

保留现有 Module Platform v1、PluginManager、ThemeService、Agent Ops 权威行为；Module Registry 只做库存/健康/能力投影，Lifecycle 继续委托既有适配器；MCP 不直接连 MySQL/Redis/Node。Octane 服务必须定义 request scope 和缓存失效；避免常驻单例泄漏用户状态。

## 5. 业务领域优化方案

### 5.1 Identity / Auth
- 账户、管理员 RBAC、Agent/Node tokens 与会话分别建立边界；后端负责撤销令牌。
- xboard_auth_data → txboard_auth_data 只做一次安全迁移，过渡时允许既有登录态正常使用，失败时明确重新认证。
- 登录、邮件链接、密码重置、邀请码与验证码有测试、限流和敏感值脱敏。
- secure_path 轮换立即生效，旧动态管理路径不可被缓存穿透。

### 5.2 Subscription / Plans
- 套餐、周期、价格、按量计费、流量重置和订阅升级补差统一定义与测试。
- 不在新 API 返回 month_price、quarter_price 等老字段；旧字段映射仅留适配器。
- 计划/价格缓存必须按权限和配置变动失效，绝不能错用跨用户缓存。

### 5.3 Billing / Orders / Payments
- 分拆订单生成、报价/优惠、账户余额、支付、返佣、订单状态和订阅生效；状态变化合法性集中验证。
- 金额全部以整数计价；余额变更和订单状态在事务中原子执行，必要时行锁/唯一约束防双写。
- Webhook 验证签名和来源，按 provider event ID / trade_no 幂等持久化；重复/乱序/并发回调至多一次结算。
- 长耗时通知、邮件与事件在 DB commit 后排队；提供失败对账和补偿机制。
- **旧通知地址必须覆盖尚未完成订单的最大回调窗口**，新旧入口最终调用同一个结算服务，不能直接 404。
- 列表改 DB 分页，减少全量拉取、N+1 查询与大响应；以真实 SQL EXPLAIN 和采样负载确认收益。

### 5.4 Network / Traffic
- TX-Node 通过版本化 handshake、节点/机器鉴权、capability negotiation、配置同步、批次上报和 WSS 加速；HTTP 保底。
- 持久化 traffic batch ledger 是权威；同一批次重复上报不得重复扣费，ID 碰撞、乱序、溢出要正确拒绝或补偿。
- Redis 用于队列/缓存，不是财务或流量结算唯一真相；MySQL 故障与重试必须避免局部结算。
- 优化统计索引、批量聚合、归档、Redis 命中、队列积压与节点心跳，先测 p95/CPU/内存/锁等待再决定重写。

### 5.5 Frontend / Plugin / Theme / Agent
- Vue/React 使用标准 API 客户端管理 Token、异常、分页、请求取消和类型；只有幂等读自动重试。
- 不在前端猜测后端端点或用假成功掩盖缺失接口；动态功能开关以服务器契约为准。
- Plugin Package v1、Theme Package v1、Admin Bridge 和模块生命周期保持兼容窗口，新增插件显式声明版本化 route/capability。
- PHP 插件不具有隔离沙箱；安装升级需校验包完整性、权限和 Worker 状态；停用后 stale route 不能执行。
- Agent Ops 的 abilities、target scope、审批、审计不得因为改 API prefix 失效。

## 6. 数据库演进：Expand → Backfill → Verify → Contract

1. P0 全面调查 v2_* 表、migration、索引、唯一性、字段语义、真实 MySQL 数据量和事务/统计 SQL。
2. 新字段/索引优先 additive migration；大表分批回填，有 checkpoint、限速、错误恢复、对账。
3. 数据源单一权威；能用 mapping/adapter 则避免长期双写。不得未经设计引入新旧表双向同步。
4. 通过影子读取/灰度验证新旧数据对齐，核对用户、订单、余额、佣金、套餐、结算 ledger 与历史流量统计。
5. 有经过验证的 MySQL + 文件/插件/主题/APP_KEY 备份恢复、增量迁移回滚和业务补偿，才进入破坏性 schema 清理。
6. v2_* 表名改不改应看维护成本和风险，**不把重命名当成去 Xboard 的 KPI**。历史迁移脚本不得为了整洁擅自删除。

## 7. 旧路由迁移流程与停用门槛

- **发现**：Laravel route:list 与代码搜索列出每个 path/method/middleware，找出 React/Vue、TX-Node、TXBoard-Gateway、AccessAudit、Deploy、MCP、支付平台和第三方订阅客户端消费者。
- **冻结**：旧 V1/V2 接口仅保留适配职责；新功能和原生响应只进 TXAPI。
- **双轨**：按领域开关分批开启新协议；新旧接口共享真正的授权、业务服务与幂等账本。
- **迁移**：先低风险公共/用户/管理端，再资金支付和 Node/Agent/插件，具体顺序依依赖图调整。
- **观测**：按匿名化 consumer 类型统计旧 API 请求量、成功率、延迟和异常，日志不保存 Bearer、订阅 token、签名或支付密钥。
- **退役**：所有受支持调用方已完成升级，交易回调有效窗口结束，Node/Agent 双端验证成功，最长调用周期内旧请求为 0，回滚预案完整且 Owner 批准。

API 兼容策略不是永久双轨；但在证据不足时宁可保留短期 adapter 也不要丢单/中断节点。

## P3-A3 统一 Checkout 状态转移

- `Domains/Billing/OrderCheckout` 为新旧接口共用交易发起服务：在用户归属+订单行锁下读取待支付订单、拒绝负金额、零元订单复用幂等 paid 转换、正金额统一获取启用的支付方式与计算手续费（分）。
- Provider 外部调用在订单锁事务提交后进行，防止外部网关慢响应长期占用 MySQL 行锁；支付方法 ID、手续费与订单绑定时同一事务持久化。原 V1 仍保持 `{type,data}` 响应。
- `POST /txapi/orders/{tradeNo}/checkout` 返回 native `data:{type,data}`，Vue 支付操作切到新入口；完整回调依旧通过 P3-B 的共享验签路径，默认向旧 URL 通知，外部提供方切流未自动打开。
- SQLite/MySQL 验证免费订单只能触发一次履约、跨用户拒绝、负价/无支付方式拒绝；既有 P0 合成支付、真实 provider 签名回归持续运行。

## P3-B 双路径 webhook（代码合约完成，第三方沙箱保留后置）

- `GET/POST /txapi/payment/webhook/{method}/{uuid}` 与原 `/api/v1/guest/payment/notify/{method}/{uuid}` 同时支持，两者委托同一 `Domains/Billing/PaymentNotificationProcessor`，同一支付插件签名校验、商户绑定、金额检查和订单入账；**provider 原始 ACK 不是普通 JSON envelope**，旧接口响应格式保持不变。
- 订单锁内同时复核付款 provider ID、已验证的签名金额和 callback_no，避免收款时切换支付方式与回调交错造成越权入账；相同 callback 只确认一次，不同 provider trade ID 失败，已取消订单不能回生。
- `api/config/billing.php` 新增默认关闭的 `TXBOARD_NATIVE_PAYMENT_WEBHOOK` 开关。默认**所有出站支付请求继续通知旧路径**，只在提供方沙箱与反向代理已验收后手动打开开关切换新回调 URL。旧 webhook 在实证无消费者前保留。
- SQLite/MySQL 测试新旧路径重放、签名错误、金额不符、跨商户、GET/POST 回调、仅一次履约；真实第三方支付沙箱、提供方兼容、线上核对仍为 deferred，本批不宣布 P3-B 实际上线验收完成。

## P3-A2 原生交易 API 与用户操作

- 原生 `GET /txapi/billing/wallet` 返回余额/返佣余额（分）；`GET /txapi/billing/commissions` 按当前用户 ID 过滤已产生的返佣记录并使用数据库分页，不暴露其他人的佣金。
- 原生 `GET /txapi/billing/payment-methods` 只列出启用的方式，严格白名单排除 provider config/key；固定手续费使用最小货币单位；`POST /txapi/billing/coupons/check` 复用 CouponService 权限检查，固定金额用 value_minor，百分比用 percent。
- 原生 `POST /txapi/orders`、`POST /txapi/orders/{tradeNo}/cancel` 分别委托 OrderService::createFromRequest 与 cancel；交易仍由原服务负责原子扣余额、优惠券限额、订单类型/升级/返佣定价与恢复。
- Vue 的下单、取消、支付方式、优惠券展示、返佣分页读路径切换到 /txapi；**线上支付发起/收款回调依旧走历史有签名验收的 provider 流程**，不允许新入口绕过服务端费率/付款确认。
- 补充 SQLite/MySQL 套餐新购余额抵扣、取消只返还一次、券占用/归还、跨用户查看与金融配置泄露测试。

## P3-A1 交易一致性第一批（可回滚，无 Schema 迁移）

- 订单创建已在持有用户行锁的事务中重新读取套餐并核验购买资格与价格，不再单纯依赖事务开始前的校验；交易金额一律折算为整数分，并将券折扣与会员折扣限制在订单小计之内，避免负价。
- 订单创建时有限使用次数的优惠券被占用；仅第一次从 PENDING 成功取消时，在相同事务中恢复一次剩余次数，并返还曾扣划的用户余额；多次取消、迟到支付通知不能再次返还或反向扣款。
- 支付的 PENDING→PROCESSING 状态转换在行锁内复核 provider callback_no：重放**相同**提供方交易可幂等确认，**不同**交易号不能被认为已成功结算，且不会再次触发履约队列。
- 复用原支付/订单业务，不改真实 webhook URL；SQLite/MySQL 并发/余额/优惠券/重复回调及负价边界回归必须通过后合并。实际第三方环境尚未联调。

## P2-E 原生认证及会话安全收尾

- 用户登录与注册通过 `POST /txapi/auth/login`、`POST /txapi/auth/register`，分别复用现有 LoginService、RegisterService、CaptchaService 和 Sanctum AuthService；保留密码错误限流、机器人校验、注册关闭、邀请码、邮箱验证码与已有用户登录/注册 Hook 逻辑。
- 原生认证响应只提供 `auth_data`，不暴露订阅私有 Token 和管理 secure_path；认证失败映射稳定 401/409/422/429 错误及统一 request_id。
- 新 `/txapi/auth/logout`、`GET /txapi/auth/sessions`、`DELETE /txapi/auth/sessions/{sessionId}`、`POST /txapi/auth/password` 复用 Sanctum 用户 token、只返回白名单会话字段，密码更新撤销其他会话。
- Vue 的普通登录、注册、会话列表/撤销和密码修改使用原生接口；邮箱登录/重置、临时 token 与 Telegram 登录、订阅安全重置等特殊操作继续兼容原 V1，保持独立验证。
- 不涉及支付/节点或管理员动态地址迁移。新增 SQLite/MySQL 登录、注册、越权、密码撤销与敏感字段测试。

## P2-D 工单业务域原生化

- 原生 `GET /txapi/tickets` / `GET /txapi/tickets/{ticketId}`：仅当前登录用户的数据，数据库稳定分页，详情按消息 ID 顺序，固定 DTO，拒绝越权。
- 新建、回复与关闭分别由 `POST /txapi/tickets`、`POST /txapi/tickets/{id}/messages`、`POST /txapi/tickets/{id}/close` 提供；创建复用既有 TicketService 的事务与“一人一个未关闭工单”约束，回复复用 TicketService 与原 hook，行级锁检查关闭状态和连续回复限制；关闭幂等。
- Vue 工单列表/详情及提交/回复/关闭迁移到原生路由；前端只进行时间与字段适配，管理工单接口、提现工单业务继续在旧实现中保持兼容。
- 跨数据库测试覆盖创建、回复、关闭、规则冲突、身份隔离、分页参数与敏感字段白名单。

## P2-C 原生账户/公告/知识库

- 原生账户 `GET /txapi/me` 扩展最小展示字段：uuid、余额分、返佣余额分、到期时间、Telegram 标识；严格白名单，不暴露明文订阅 Token/密码信息。Vue User 用户资料由 native adapter 转换为原有视图字段，安全敏感的认证/token 管理仍通过已有认证业务端点。
- `GET /txapi/notices` 按数据库分页和显示状态过滤，Native 返回受限字段及 ISO UTC 日期；Vue 公告调用原生接口，取消旧接口无效响应时的静默空列表。
- `GET /txapi/knowledge`、`/knowledge/categories`、`/knowledge/{articleId}` 尊重原有 show/language、当前用户的订阅有效性与私有订阅链接模板替换；对无效订阅用户隐藏 access 内容；HTML 仍由前端 DOMPurify 清理，保留原 HookManager filter 兼容路径。
- 本批不涉及支付、订阅密钥重置、邮件登录、验证码或者工单写路径。新增 SQLite/MySQL 访问控制、知识库权限与列表边界测试。

## P2-B 套餐前端只读迁移

- `web/user/src/api/plan.ts` 的套餐列表及详情都使用 `GET /txapi/plans`、`GET /txapi/plans/{planId}`；通过唯一的页面适配器将原生 period/amount_minor/traffic_limit_bytes 映射为历史页面视图字段，不使新后端重新输出 Xboard DTO。
- 前端对响应结构、价格整数与套餐 ID 做显式验证；返回异常时抛错并显示失败，不回落全量旧接口掩盖原生接口缺失。
- 原 `saveOrder`、`checkCoupon`、`checkoutOrder` 与支付回调不改；订单仍按服务端权限和价格二次验证，本次仅迁移展示和可购套餐详情读路径。
- 独立单测验证无旧套餐列表调用、保留历史页面的周期/价格单位和认证 Token，回滚可恢复原业务 adapter；旧 V1 套餐路由仍有效。

## P2-A Subscription Native Catalog：查询域与购买权限边界

- 新增 `Domains/Subscription/PlanCatalog`：汇总可显示/可售套餐，并以一次分组查询核对所有有限容量套餐的有效订阅人数，避免逐套餐 COUNT 引发 N+1。
- 原生 `GET /txapi/plans` 使用固定 DTO（canonical period、amount_minor、traffic_limit_bytes、tags、content、设备/速度/容量/重置策略）；`GET /txapi/plans/{planId}` 必须具备 Sanctum 用户身份且复用 PlanService 现有续费/新购资格规则，隐藏的续费专用套餐仅本人可访问，不向陌生账户公开。
- **唯一授权来源仍是既有 OrderService + PlanService**；价格、容量、订阅资格在 checkout/create 时仍须重新验算，不将页面展示价格视为预授权报价。禁止新增请求绕过旧结算或支付幂等。
- P2-A 只改原生只读 catalog，保留 `/api/v1` 套餐端点及原业务。跨 SQLite/MySQL 测试验证用户隔离、隐藏套餐、到期会员和批量查询，外部 TX-Node/Gateway 后置。

## P1-B 订单列表读路径迁移（首个业务页面）

- Vue User 订单列表与“待处理订单”检查改为调用 `GET /txapi/orders`，从 MySQL 根据用户、状态、分页参数读取，**不再拉取全部订单并在浏览器筛选分页**。
- 新订单 DTO 补充最小展示字段 type、plan.id/name、paid_at；前端专用 adapter 将 ISO 时间转为现有 UI 使用的秒级 timestamp，将整型 amount_minor 映射到旧页面的 total_amount，仅在页面适配层映射旧套餐周期显示。Native HTTP 层绝不输出 Xboard 风格字段。
- 详情、创建、支付、取消依旧调用旧 API，因为付款页面需要旧字段、业务写操作仍以旧接口为权威；不能把它们指向只有只读能力的新入口。
- 服务器端订单按 created_at DESC、id DESC 稳定排序、每页最多 100，并强制用户所有权；跨 SQLite、MySQL 和 Vue HTTP adapter 做契约测试。
- 此 PR 是只读页面链路切换，没有 schema/billing/webhook/node 或 Gateway 变更；回退可恢复原有列表 adapter，原旧 endpoint 保持可用。

## P1-B 客户端适配第一批（不切换业务接口）

- Vue User 的登录态 canonical key 为 `txboard_auth_data`。首次读取自动迁移 `xboard_auth_data` 到新键，优先使用新值，并且成功迁移后移除旧键。登录写入、退出、令牌失效清理同时移除旧存储，避免旧会话被恢复。
- Vue 的 legacy `api` 和 native `nativeApi` 并行。Native 客户端使用 `/txapi`、标准 `{data,meta?,request_id}` envelope 和共用 Bearer；401 仅在带 `UNAUTHENTICATED` 错误代码时注销，普通 403 禁止误注销。
- React Admin 的老 `/api/v2/{secure_path}` 原封不动；新增独立 native client，其 404 不能清除或重置后台安全路径；它通过原 Sanctum Bearer 请求已实现的 TXAPI 用户只读接口。
- Vue/React Vite 本地开发代理加入 /txapi；客户端合约单测验证令牌迁移、不会复活旧 session、不同 API base、错误 schema 与动态 admin path 保留。
- **本批没有将订单、套餐、资料业务页强制改用 TXAPI**。因为新 DTO 尚不完整且存在与旧 UI 不兼容的字段，后续按页面/领域落实 adapter 后再单独切换，禁止“新服务返回空白但前端假装成功”。

## 当前执行决议（2026-10-09）：内核优先，外部适配后置

维护者明确要求先完成 TXBoard 本体：**真实 TX-Node 版本互通、独立 Gateway/Hono 联调、支付提供方沙箱、第三方插件主题端到端升级和线上负载/恢复演练不作为 P1–P6 代码开发的前置阻塞**。TXBoard 只承诺已测试的 Laravel 内部合同与向后兼容；这些外部工程应在后续独立适配 TXBoard 已冻结的协议。

此安排**不代表**这些验收已通过：P0-A 外部消费者所有权和 provenance 仍待人工核对；P0-B 真实环境的性能、备份恢复、第三方回调及跨仓库联调仍记为 deferred。P4-B、P5、P7 里相应的真实联调/安全/发布退出条件依然存在，不能据此删除旧 /api/v1、/api/v2 和真实 webhook，也不能对上线稳定性作已验证的承诺。

执行顺序转为：P0 合成业务/数据基线已入库 → P1-A 原生 HTTP（首批纯只读）→ P1-B 客户端无损迁移 → P2–P6 分域实现与测试 → 外部消费者分别适配 → P7 经外部证据完成后再退役旧路由。每一阶段可以在不破坏旧接口的前提下并行推进。

## 8. 实施阶段与退出条件

| 阶段 | 具体工作包 | 退出条件 |
|---|---|---|
| P0-A | 完整 provenance、依赖矩阵、路由/消费者/数据库字典 | 每条旧接口有 Owner/消费者/风险 |
| P0-B | 登录→下单→支付→生效→节点→流量 E2E 与性能基线 | 基准可重现，关键回归可执行 |
| P1-A | TXAPI 注册、响应/error/pagination、权限审计 | 新旧路径隔离，旧业务不受影响 |
| P1-B | User/Admin API client、登录态迁移与 secure_path | 前后端和页面刷新完整验证 |
| P2 | 账户、认证、套餐、知识库、公告、工单 | 逐域功能与数据一致 |
| P3-A | 订单/钱包/返佣/优惠与支付状态机 | 幂等/并发/金额对账通过 |
| P3-B | 支付 webhook 双路径迁移 | 支付沙箱/真实回调无漏单 |
| P4-A | 流量 ledger、统计、Redis/MySQL 效率 | 重放/故障测试、负载不退化 |
| P4-B | TX-Node HTTP/WSS 版本化互通 | 双端协议测试/升级/降级成功 |
| P5 | Agent Ops、插件、主题、Gateway 接入 | 权限边界及生态兼容验证 |
| P6 | DB 选择性 schema 优化、历史实现清理 | 数据恢复/对账，删除候选确认 |
| P7 | 旧 API 退役、发布、观测与最终验收 | 无受支持旧消费者，完整回归 |

每个 PR 只解决一个架构主题；Billing、Traffic、DB 切换不得混在一次发布中。P0 完成前不对性能提升做无依据的百分比承诺。

## 9. 可量化验收与测试矩阵

- **Unit**：金额和流量单位、订阅周期、权限、订单状态流转、优惠、价格、响应错误码。
- **Integration**：SQLite 快速测试与 MySQL 8.x、Redis、Horizon、真实锁/唯一约束语义测试。
- **Contract**：OpenAPI/DTO/客户端、Node、Agent/MCP、插件、Admin Bridge、跨仓库消费者。
- **Adversarial**：两次同时下单/取消/回调、重复与乱序支付、重放流量、Token 跨角色、secure_path 轮换、恶意 ZIP、Octane 共享状态污染。
- **E2E**：注册、套餐、购买、支付、订阅生效、节点拉取、流量入账、到期/重置、工单与回滚。
- **性能**：记录 p50/p95/p99、SQL 次数/EXPLAIN、Redis ops、队列 lag、DB lock、Node batch QPS、CPU/内存及 error rate；在同样环境/负载下验证无明显回退。
- **财务与流量**：回调幂等、账户余额、真实订单状态、流量 batch 累计与历史总量对账差额必须为 0。
- **发布**：npm ci + npm run verify:web；cd api 后 composer install + php artisan test；按 CI 执行 MySQL、Docker/Octane 与 MCP smoke 和生产恢复演练。

CI 通过不等于实际支付回调联调或真实生产备份已验证。

## 10. 发布/灰度/回退与合规

每个工作包有 Feature Flag、指标看板、回滚操作者与命令、数据变更清单、失败补偿和正式发布说明。PR 经过审查和自动测试后以不可变 SHA 镜像部署，先内部/小流量、后放量。保留上一版本镜像与可靠备份。

路由/前端可回退，但**已支付订单和破坏性 DDL 无法仅靠 git revert 恢复**：必须有数据库快照/对账/补偿方案。生产变更时监控支付对账、流量去重、DB/Redis/队列、Node 连接与错误率。

旧 Phase N 临时文档已汇总成 [Operations](../operations/README.md)、[Security](../security/README.md)、[Extension Runtime](extension-runtime-policy.md)；完整代码/来源变迁仍可通过 Git history 查找。当前有效协议见 [contracts](../../contracts/README.md)，初始审计见 [legacy inventory](legacy-inventory-and-work-packages.md)。

## 11. P0 立即执行清单

1. 自动导出 route:list、API/client 代码引用图及所有 /api/v1、/api/v2 硬编码 URL。
2. 查明支付回调、健康探针、部署脚本、Node、Gateway、AccessAudit、MCP 与主题/插件真实路径依赖。
3. 为 Controller/Service/Model/Migration 标注：来源、领域、所有调用者、保留优化/重构/暂存适配/删除、Owner。
4. 补齐金额/重复回调/跨角色、批量流量去重和 DB 迁移的回归与失败注入。
5. 测定当前关键 API/SQL/负载基线；明确逐模块性能改进目标、上线指标及回滚窗口。
6. 冻结 TXAPI v1 细节，先实现非破坏性新入口和低风险领域，再按 P1–P7 拆分开发。
