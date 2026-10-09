# TXAPI Target Contract v1 (P1-A 逐步实现)

**当前状态：** P1-A 第一批原生接口已在 TXBoard 源码中实现（需随版本部署，不代表线上已启用）：GET `/txapi/health`、`/txapi/public/config`、`/txapi/plans`、`/txapi/me`、`/txapi/orders`、`/txapi/orders/{tradeNo}`。其余表内路径仍是目标协议，**不得按已实现 API 调用**。Node、Agent、插件、管理员、Webhook、Gateway 均保留现有入口。

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
