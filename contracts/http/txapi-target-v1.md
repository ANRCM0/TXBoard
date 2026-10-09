# TXAPI Contract v1（P1/P2 代码已实施 + P3–P7 目标）

**当前状态：** P1/P2 原生代码已合并 TXBoard main；已实施路由以 [P2 阶段验收记录](../../docs/architecture/p2-completion.md) 为准（部署需使用包含该代码的镜像，不代表线上已经更新）。本文件中未列为已实施的 Node/Agent/Plugin/Admin/Webhook/Gateway 等路径**仍是目标协议，不得按已上线接口调用**。原 `/api/v1`、`/api/v2` 等兼容入口必须保留。

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
| TX-Node | `/txapi/node/v1/*` | 独立 Node identity/protocol |
| Agent Ops | `/txapi/agent/v1/*` | Agent abilities/scope/approval |
| Extensions | `/txapi/extensions/{code}/v1/*` | Module enabled + permissions |
| Payment webhook | `/txapi/payment/webhooks/*` | 签名 + 重放防护 + 幂等 |

Hono Gateway 独立的 [TXAPI BFF Target](txapi-bff-target-v1.md) 保留 `{ok,data,meta}` 的 v1 SDK envelope，和 Laravel 原生响应不同。

## P2-E Native authentication

- `POST /txapi/auth/login`、`POST /txapi/auth/register` 复用原注册、Captcha、密码限制与 Sanctum；仅返回 auth_data，不包含 subscription token 或 admin secure_path。
- `POST /txapi/auth/logout` 注销当前 token；`GET /txapi/auth/sessions` 返回不含 token hash 的当前用户会话；`DELETE /txapi/auth/sessions/{sessionId}` 仅能操作当前用户 token；`POST /txapi/auth/password` 验证旧密码后撤销其他会话。
- 邮件链接、Telegram、验证码发送、忘记密码和订阅密钥重置仍走 legacy（业务安全与交互契约保持不变），用户前端的普通登录注册/会话管理已迁移。

## P2-D Tickets

- `GET /txapi/tickets?page=&per_page=`：Sanctum 用户隔离、稳定数据库分页、数据+meta；`GET /txapi/tickets/{id}`：仅所有者可见，messages 带 is_me、时间使用 ISO UTC。
- `POST /txapi/tickets`（subject/level/message）返回 201 和 id；`POST /txapi/tickets/{id}/messages` 回复；`POST /txapi/tickets/{id}/close` 关闭（幂等）。全部使用固定响应和严格 401/404/409/422 状态。
- 重用既有 TicketService 的状态规则和扩展事件；旧 V1 工单及提款功能仍保留，不涉及任何管理员功能调整。

## P2-C Account 与 Content

- `GET /txapi/me` 增加 uuid、balance_minor、commission_balance_minor、expired_at、telegram_id；所有字段为固定白名单，不返回私有订阅 token。
- `GET /txapi/notices?page=&per_page=` 仅认证用户、仅 show 的公告、数据库分页/总数；公告日期使用 ISO UTC。
- `GET /txapi/knowledge?language=&keyword=`、`/txapi/knowledge/categories`、`/txapi/knowledge/{articleId}` 认证用户专属，隐藏未公开文章；所有正文按当前用户的订阅有效性进行 gated content 替换和 subscribeUrl 插值。
- 用户前端针对正文保留 DOMPurify；本阶段未实现新登录写接口和工单写接口。

## P2-B 用户套餐页面适配

Vue 套餐列表/详情现使用原生 /txapi/plans 和认证详情 /txapi/plans/{planId}；前端业务页 adapter 将 native 周期、整数价格、流量字节映射到现有 Vue 页面视图字段。旧订单创建、优惠券、checkout 保持旧 V1，并在结算阶段重复验证资格与金额。

## P2-A 已实施的套餐只读协议

- `GET /txapi/plans`：匿名可用、仅展示 show+sell 并有容量的套餐；输出 id/name/content/tags/traffic_limit_bytes/speed_limit_mbps/device_limit/capacity_limit/reset_traffic_method/prices[{period,amount_minor}]/renewable。
- `GET /txapi/plans/{planId}`：仅已登录用户可用，复用现存 PlanService 资格检查；本人可读取有续费权的隐藏套餐，其他用户返回 404。禁用/不存在的套餐不泄露是否真实存在。
- 查询/展示不等于预定价格或完成购买资格审核；创建订单时仍以当前权限、容量和实时计价为准。旧 PlanResource 的 `month_price` 等字段仅旧接口继续输出。
- 有容量限制的公开套餐使用一次 group-by 统计有效用户而不是逐计划 count；不缓存跨用户的详情响应。

## P1-B 首个页面已迁移

Vue 用户订单列表现已直接使用 `GET /txapi/orders` 的服务端分页/状态过滤和原生响应。增加只读展示字段 type、plan{id,name}、paid_at（RFC3339 UTC 或 null）；旧 Vue UI 使用的金额/时间/周期格式仅在前端 adapter 转换。订单详情、创建、支付、取消仍使用旧 V1 路径；Admin 当前仍使用旧动态路由。

## P1-B 前端适配准备

Vue User 与 React Admin 已引入隔离的 native HTTP 客户端和类型化 envelope；Vue 登录态优先读 `txboard_auth_data`、兼容迁移旧存储。业务 UI 仍通过 legacy adapters 请求它们尚未迁移的接口。新 /txapi DTO 尚未支持的字段不得伪造或从老 UI 偷偷兜底为假成功。

## P1-A 当前已实现的协议冻结面

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
