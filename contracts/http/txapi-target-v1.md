# TXAPI Contract v1（P1–P4 服务端代码已实施 + P5–P7 目标）

> **历史阶段性方案 / TARGET 混合文档**：本文第一段中的“Admin 仍为 TARGET”已过时；第一方 Admin TXAPI 与旧 V2 Admin 移除已在 2026-10-10 完成。Agent Native `/txapi/agent/v1/*`、Extensions Native `/txapi/extensions/{code}/v1/*`、独立 BFF `/txapi/bff/v1/*` **尚非本仓 CURRENT 路由**。已注册 HTTP 以 [实时路由快照](../../docs/architecture/http-route-inventory.md) 和 [CURRENT 外部适配手册](external-adapter-current.md) 为准。以下保留早期设计/迁移时点信息，不能当作现行部署保证。

**当前状态（代码，不代表生产已切流）：** P1–P4 TXBoard 服务端代码已合并 main；当前入口以 `api/routes/txapi.php` 和 [P2](../../docs/architecture/p2-completion.md)、[P3](../../docs/architecture/p3-completion.md)、[P4](../../docs/architecture/p4-completion.md) 交付记录为准。原生 Node HTTP/WS 与支付 Webhook 服务端已实现，但 TX-Node 真实联调尚未执行，原生 WS 与新支付通知 URL 默认均关闭。Admin、Agent、Extensions、Gateway BFF 仍是 **TARGET** 而非已上线接口。原 `/api/v1`、`/api/v2` 兼容入口必须保留。

## Root: /txapi

全部未来 TXBoard-owned HTTP API 使用 `/txapi/*`，不再新增 `/api/v1/*`、`/api/v2/*`。

| Scope | Target | Required boundary |
|---|---|---|
| Gateway BFF (optional) | `/txapi/bff/v1/*` | Hono 独立进程，Edge 分流；v1 SDK envelope，不是 Laravel handler |
| Health | `GET /txapi/health` | liveness，不依赖 DB/Redis |
| Public | `/txapi/public/*` | 匿名，速率限制，公开字段 |
| Authentication | `/txapi/auth/*` | 验证码/反枚举/限流 |
| User | `/txapi/me/*`、`/txapi/plans/*`、`/txapi/orders/*` | Sanctum user/ownership |
| Admin | `/txapi/admin/{secure_path}/*` | Admin + dynamic path + RBAC + audit |
| TX-Node (CURRENT server, no external interoperability signoff) | `/txapi/node/v1/*` (HTTP + native WS) | 独立 Node identity/protocol |
| Agent Ops | `/txapi/agent/v1/*` | Agent abilities/scope/approval |
| Extensions | `/txapi/extensions/{code}/v1/*` | Module enabled + permissions |
| Payment webhook (CURRENT handler, rollout off by default) | `GET/POST /txapi/payment/webhook/{method}/{uuid}` | 签名 + 重放防护 + 幂等 |

Hono Gateway 独立的 [TXAPI BFF Target](txapi-bff-target-v1.md) 保留 `{ok,data,meta}` 的 v1 SDK envelope，和 Laravel 原生响应不同。

## Native user/auth CURRENT after Legacy Batches 1–3

The following handlers are present in Laravel `api/routes/txapi.php` (code shipped, not a statement about production deployments):

| Endpoint | Scope and invariants |
|---|---|
| `GET /txapi/traffic/logs?page=&per_page=` | Sanctum user, current-month owner-scoped SQL, server pagination, whitelisted byte counters and multiplier |
| `GET /txapi/orders/{tradeNo}/detail` | Sanctum order owner; minor-unit balance/discount, payment ID lock, plan traffic, no provider secrets |
| `GET/PATCH /txapi/me/preferences` | Sanctum user, only expiration and traffic reminder preferences |
| `POST /txapi/auth/quick-login` | Sanctum user, rate limit, only local redirect and existing short-lived login code |
| `POST /txapi/me/subscription-credentials/rotate` | Sanctum user, row lock, rotate private subscription UUID/token, return subscription URL |
| `POST /txapi/auth/mail-link` | Anonymous, Captcha (when enabled) and existing mail-link policy, enumeration-safe success |
| `POST /txapi/auth/one-time-token` | Anonymous token redeem, shared one-time code store/lock, only `auth_data` in response |
| `POST /txapi/auth/email-code` | Anonymous, Captcha (when enabled), shared V1/TXAPI email issuer and cooldown |
| `POST /txapi/auth/password/forgot` | Anonymous, Captcha, single-use email code, revoke sessions on successful reset |

All legacy V1/V2 routes and existing subscription URL generators remain available until supported consumers and external providers complete migration. Credential-bearing URLs, checkout and payment callbacks require separate live integration validation. See [Batch 3](../../docs/architecture/legacy-retirement-batch-3.md).

## P3-A3 Native checkout & shared provider invocation

- `POST /txapi/orders/{tradeNo}/checkout` 使用 owner-scoped 行锁、统一手续费与网关配置，返回 native `{data:{type,data},request_id}`。免费单走已有 paid 状态机；付费单在锁提交后调支付提供方，并继续使用默认旧 webhook URL。
- 旧 `POST /api/v1/user/order/checkout` 保留原始 `{type,data}` 形态，但与 native 共享相同 OrderCheckout 服务。余额、券、账期已在订单创建时计算，不允许浏览器提交应付金额。
- 新路径只有 CI/模拟验证，不代表支付沙箱签名真实接入已经执行。

## P3-B Native provider callback path (code shipped, rollout disabled)

- `GET/POST /txapi/payment/webhook/{method}/{uuid}` 与旧 `/api/v1/guest/payment/notify/{method}/{uuid}` 调用同一验签/订单落库领域服务。provider 回调只返回原始 ACK 文本/自定义 ACK，不使用 JSON envelope。无签名、错误金额、错商户和冲突 callback 返回非 2xx。
- `TXBOARD_NATIVE_PAYMENT_WEBHOOK=false` 是默认值；支付 provider 的 notify_url 仍然指向旧路径。仅沙箱联调并确认公网反代可达后才可在具体部署打开此开关。旧路径继续保留。
- 新路径的自动化内部模拟/签名提供方回归不等于真实外部支付沙箱验收；正式 P3-B 真实支付验收留待部署测试。

## P3-A2 Native Billing / Orders

- `GET /txapi/billing/wallet` 只返回 balance_minor、commission_balance_minor；`GET /txapi/billing/commissions?page=&per_page=` 返佣入账记录的数据库分页和当前用户归属。
- `GET /txapi/billing/payment-methods` 仅返回可用方式及不含配置密钥的固定白名单；`POST /txapi/billing/coupons/check` 返回 type 和 value_minor/percent。
- `POST /txapi/orders`（plan_id、period、可选 coupon_code）创建并返回 trade_no；`POST /txapi/orders/{tradeNo}/cancel` 只允许所有者取消 pending 订单。订单核心状态、余额划转、券与返佣均委托同一 OrderService；新入口不会发明支付成功信号。
- P3-A3/P3-B 已实现原生 Checkout 与共用签名/幂等回调核心；旧 V1 仍可用，真实提供方切流和对账尚未验收。

## P2-E Native authentication

- `POST /txapi/auth/login`、`POST /txapi/auth/register` 复用原注册、Captcha、密码限制与 Sanctum；仅返回 auth_data，不包含 subscription token 或 admin secure_path。
- `POST /txapi/auth/logout` 注销当前 token；`GET /txapi/auth/sessions` 返回不含 token hash 的当前用户会话；`DELETE /txapi/auth/sessions/{sessionId}` 仅能操作当前用户 token；`POST /txapi/auth/password` 验证旧密码后撤销其他会话。
- **P2-E 历史阶段说明**：当时邮件链接、Telegram、验证码发送、忘记密码与订阅密钥重置仍使用 legacy；Legacy Batches 1–3 后除 Telegram 等外已陆续切换 Vue 官方调用方，现行接口见上方 CURRENT 清单。

## P2-D Tickets

- `GET /txapi/tickets?page=&per_page=`：Sanctum 用户隔离、稳定数据库分页、数据+meta；`GET /txapi/tickets/{id}`：仅所有者可见，messages 带 is_me、时间使用 ISO UTC。
- `POST /txapi/tickets`（subject/level/message）返回 201 和 id；`POST /txapi/tickets/{id}/messages` 回复；`POST /txapi/tickets/{id}/close` 关闭（幂等）。全部使用固定响应和严格 401/404/409/422 状态。
- 重用既有 TicketService 的状态规则和扩展事件；旧 V1 工单及提款功能仍保留，不涉及任何管理员功能调整。

## P2-C Account 与 Content

- `GET /txapi/me` 增加 uuid、balance_minor、commission_balance_minor、expired_at、telegram_id；所有字段为固定白名单，不返回私有订阅 token。
- `GET /txapi/notices?page=&per_page=` 仅认证用户、仅 show 的公告、数据库分页/总数；公告日期使用 ISO UTC。
- `GET /txapi/knowledge?language=&keyword=`、`/txapi/knowledge/categories`、`/txapi/knowledge/{articleId}` 认证用户专属，隐藏未公开文章；所有正文按当前用户的订阅有效性进行 gated content 替换和 subscribeUrl 插值。
- 用户前端针对正文保留 DOMPurify；后续 P2-D/P2-E 已实现原生工单写入和普通登录/注册。

## P2-B 用户套餐页面适配

Vue 套餐列表/详情现使用原生 /txapi/plans 和认证详情 /txapi/plans/{planId}；前端业务页 adapter 将 native 周期、整数价格、流量字节映射到现有 Vue 页面视图字段。订单创建、优惠券验证、checkout 在后续 P3 已改为原生 TXAPI，并在结算阶段重复验证资格与金额。

## P2-A 已实施的套餐只读协议

- `GET /txapi/plans`：匿名可用、仅展示 show+sell 并有容量的套餐；输出 id/name/content/tags/traffic_limit_bytes/speed_limit_mbps/device_limit/capacity_limit/reset_traffic_method/prices[{period,amount_minor}]/renewable。
- `GET /txapi/plans/{planId}`：仅已登录用户可用，复用现存 PlanService 资格检查；本人可读取有续费权的隐藏套餐，其他用户返回 404。禁用/不存在的套餐不泄露是否真实存在。
- 查询/展示不等于预定价格或完成购买资格审核；创建订单时仍以当前权限、容量和实时计价为准。旧 PlanResource 的 `month_price` 等字段仅旧接口继续输出。
- 有容量限制的公开套餐使用一次 group-by 统计有效用户而不是逐计划 count；不缓存跨用户的详情响应。

## P1-B 首个页面已迁移

Vue 用户订单列表使用 `GET /txapi/orders` 的服务端分页/状态过滤与原生响应；LR-02 后状态轮询、LR-05 后订单详情均切到原生。订单创建、支付、取消已于 P3 转向原生；Admin 仍使用旧动态路由。早期 UI 金额、时间、周期 adapter 只是历史展示兼容，不是后端协议。

## P1-B 前端适配准备

Vue User 与 React Admin 已引入隔离的 native HTTP 客户端和类型化 envelope；Vue 登录态优先读 `txboard_auth_data`、兼容迁移旧存储。业务 UI 仍通过 legacy adapters 请求它们尚未迁移的接口。新 /txapi DTO 尚未支持的字段不得伪造或从老 UI 偷偷兜底为假成功。

## P1-A 历史基线（以下为 P1 时点快照，非当前功能清单）

- **健康检查**：`GET /txapi/health` 无鉴权，不加载插件、数据库或 Redis。返回 `data.status=ok`。
- **匿名只读**：`GET /txapi/public/config` 仅 name/api_prefix；`GET /txapi/plans` 输出 id/name/traffic_limit_bytes/prices[{period,amount_minor}]/renewable，排除隐藏、停卖或售罄套餐；不公开后台路径/插件秘钥。
- **Sanctum 用户只读**：`GET /txapi/me` 返回安全字段白名单；`GET /txapi/orders` 支持 page/per_page/status，per_page 不超过 100，按 created_at DESC、id DESC 做稳定排序，用户 ID 在 SQL 层过滤；`GET /txapi/orders/{tradeNo}` 严格用户所有权验证。**当前没有任何 TXAPI 写单、付款或用户更新接口**。
- **统一响应**：成功包含 data、可选 meta、request_id，返回 X-Request-Id；错误包含 error.code、error.message、可选不含用户值的字段名列表及 request_id。所有 request_id 由服务端产生。
- **兼容**：/api/health、/api/v1、/api/v2、原支付回调及已有前端保持不变。Laravel 不提供 Gateway BFF 路由。
- **财务口径**：订单 amount_minor 来自原整型分字段；套餐价格由原主货币单位换算整数次级单位。P2/P3 必须完成币种、金额精度和价格格式的严格审计后才能作为跨币种报价契约。
- **回退**：P1-A 纯加法修改，无数据库 migration 和线上写入；旧客户端无需切换，部署上一个镜像即可撤销 TXAPI 第一批入口。

## Proposed response/error policy

- 成功：HTTP 2xx、`data`、可选 `meta` 和 `request_id`。
- 失败：HTTP 4xx/5xx、`error.code` 与安全的 `error.message`。新端点不继续传播 Xboard 的 status 字段。
- 分页：`meta.page/per_page/total/last_page`，服务端筛选与稳定排序；拟定 per_page 最大 100，P1 后冻结。
- 金额：整数最小货币单位 + 明确货币；流量：int64 字节；时间：RFC3339 UTC；输出字段白名单。
- 安全：User/Admin/Node/Agent 令牌互不提权；服务端资源隔离、权限、日志脱敏和速率限制。
- 状态变更：经过验证、审计；外部可重试的结算事件使用数据库幂等唯一键。

## 协议演进与迁移窗口

1. 先契约和客户端 schema 测试，再实现新 handler，旧路径暂不删除。
2. 旧路径仅是 legacy adapter，不可绕过新域服务的鉴权与事务。
3. 逐端验证 User/Admin、TX-Node、Agent/MCP、Gateway/Deploy、插件和支付平台消费者。
4. 在监控窗口内脱敏统计旧路由使用；保留线上回滚和未完成支付回调能力。
5. 全部确认后移除旧路由，另行发布 BREAKING CHANGE 说明。

## 进入实现的验收项目

- [ ] Method/path/auth/error schema / HTTP 状态契约
- [ ] 负向权限与身份混用测试
- [ ] 并发、重放、幂等与数据一致性测试
- [ ] User/Admin API typings；Node/Agent/插件消费者互通
- [ ] MySQL/Redis 测试、镜像探针与回滚
- [ ] 生产调用者清单/兼容窗口/监控验证
