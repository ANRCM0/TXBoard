# 外部主题接入 TXAPI（CURRENT）

> 对应 TXBoard `main`，核对日期：2026-10-10。本文是**现行服务端路由的外部主题对接指南**，不是历史 Xboard/V2 接口说明，也不代表已在第三方主题实际联调。HTTP 的权威源是 `api/routes/txapi.php` 和 `api/app/Providers/RouteServiceProvider.php`；[HTTP 路由清单](../../docs/architecture/http-route-inventory.md) 可在 CI 导出。**不存在** `/api/v1/*`、`/api/v2/*` 兼容 API。

## 1. 接入原则

- 站点同源访问：`https://<panel-host>/txapi`。外部独立域名必须自行处理受信任的 HTTPS 代理/CORS；文档**不承诺**跨域自动开放。不要硬编码 `/api/v1`、`/api/v2` 或 BFF 计划中的路径。
- 无登录的页面仅调用 `/public/*` 和公开套餐；登录后使用普通用户 Sanctum Bearer 调用 `/me/*`、`/orders/*`、`/billing/*` 等。**不要将管理员、Node、Machine、Agent 或支付提供商密钥打包进浏览器主题**。
- 原生成功通常是 `{"data": ..., "request_id":"..."}`，分页额外带 `meta`；原生业务失败是 `{"error":{"code":"...","message":"..."},"request_id":"..."}`，并返回真实 HTTP 状态及 `X-Request-Id`。不要解析旧版 `{"status":...}`。部分框架验证/异常响应可能不同，应保留状态码和请求 ID 进行排障。
- `auth/login` 的 `data.auth_data` **已经包含** `Bearer ` 前缀，直接原样放在 `Authorization` 请求头；不要再次拼接 `Bearer `。浏览器不要持久化管理员令牌/订阅密钥，不要写进 URL 或日志。
- 金额以 `*_minor`（最小货币单位的整数）为准，流量以 `*_bytes` 为准；日期应看字段：多数订单时间为 ISO 8601 UTC，**充值记录的 `created_at` / `paid_at` 当前为 Unix 秒时间戳**，不可统一盲转。
- 对支付类写入，只有服务端或经过签名的支付回调能改变支付状态；浏览器不能以 `checkout` 返回为支付成功凭据。

## 2. 公开页面和登录

下表的路径均以 `/txapi` 为前缀：

| 方法 | 路径 | 鉴权 | 用途 |
| --- | --- | --- | --- |
| GET | `/health` | 无 | Laravel 存活探针，**不是** DB/Redis 健康验收 |
| GET | `/public/config` | 无 | `{name,api_prefix}` |
| GET | `/public/site-config` | 无 | 站点名称、Logo、注册/CAPTCHA 开关、`frontend_theme` 与安全过滤后的 `theme_config` |
| GET | `/plans` | 无 | 展示可售套餐（公开列表） |
| POST | `/public/invite-page-view` | 无，限流 | 邀请页访问记录 |
| POST | `/auth/login` | 无，限流/CAPTCHA | `email,password`；返回 `data.auth_data` |
| POST | `/auth/register` | 无，限流/注册规则 | 注册，返回 `data.auth_data`（201） |
| POST | `/auth/mail-link` | 无，限流/CAPTCHA | 发起邮件登录链接 |
| POST | `/auth/one-time-token` | 无，限流 | `{"verify":"<one-time-code>"}` 换取登录 |
| POST | `/auth/email-code` | 无，限流/CAPTCHA | 发送验证邮件 |
| POST | `/auth/password/forgot` | 无，限流/CAPTCHA | 找回密码 |
| POST | `/auth/admin/login` | **仅管理员登录界面** | 返回专用管理员授权数据与 `secure_path`；普通主题不使用 |

`/public/site-config` 的 `theme_config` 仅含允许公开的主题字段。第三方主题的 ZIP 格式、`config.json`、`dashboard.blade.php` 仍遵守 [Theme Package v1](../theme-package/README.md)。`GET /` 由当前主题提供首页（内置 `TXBoard` 为用户 SPA）；不要把 `GET /` 当成业务 JSON API。

**匿名开始的最低调用：**

```bash
curl -fsS 'https://<panel-host>/txapi/public/site-config'
curl -fsS 'https://<panel-host>/txapi/plans'
```

**普通用户登录：**

```http
POST /txapi/auth/login HTTP/1.1
Content-Type: application/json
Accept: application/json

{"email":"user@example.test","password":"<password>"}
```

成功示意：`{"data":{"auth_data":"Bearer <opaque-session-token>"},"request_id":"<request-id>"}`。启用 CAPTCHA 时还需提交当前站点验证码机制要求的字段；以实际服务端校验为准。后续请求：

```http
GET /txapi/me HTTP/1.1
Accept: application/json
Authorization: Bearer <opaque-session-token>
```

## 3. 登录后的主题业务路由（全量 user group）

这里列出 `api/routes/txapi.php` 中 `txapi.user` 身份域的路由，不含管理员、Node、Agent。除特殊注明外均为 **User Bearer**，并受 `throttle:120,1` 及具体子接口限流约束。

| 分类 | 方法 + 路径 | 关键字段/用途 |
| --- | --- | --- |
| 用户 | GET `/me` | `id,email,plan_id,uuid,balance_minor,commission_balance_minor,expired_at,traffic` |
| 用户 | GET `/me/subscription` | 用户自己的订阅链接、当前套餐和流量、重置日；**敏感 URL** |
| 用户 | GET `/me/dashboard-stats` | 未付款订单、未关闭工单、邀请人数 |
| 用户 | GET `/me/site-config` | 仅登录用户可见的站点配置 |
| 用户 | GET `/me/nodes` | 面向主题的安全节点列表，不返回节点密钥/证书 |
| 用户 | GET / PATCH `/me/preferences` | 偏好；PATCH 只支持 `remind_expire`、`remind_traffic` |
| 用户 | POST `/me/subscription-credentials/rotate` | 轮换订阅凭据；旧链接立刻失效，强限流 |
| 用户 | GET `/plans/{planId}` | 登录后的套餐详情与购买资格（无权时 404） |
| 安全 | POST `/auth/logout` | 注销当前 Sanctum 会话 |
| 安全 | GET `/auth/sessions` | 安全字段的会话清单 |
| 安全 | DELETE `/auth/sessions/{sessionId}` | 只可撤销本人会话 |
| 安全 | POST `/auth/password` | 更换密码 |
| 安全 | POST `/auth/quick-login` | 短期站内跳转登录，严格约束 `redirect` |
| 内容 | GET `/notices` | 公告；支持 `page,per_page` |
| 内容 | GET `/knowledge` | 知识库；`language,keyword` |
| 内容 | GET `/knowledge/categories` | 知识库分类，可选 `language` |
| 内容 | GET `/knowledge/{articleId}` | 文章详情、内容权限过滤 |
| 工单 | GET / POST `/tickets` | 查询/新建（`subject,level,message`） |
| 工单 | GET `/tickets/{ticketId}` | 仅本人工单详情 |
| 工单 | POST `/tickets/{ticketId}/messages` | 回复，`message` |
| 工单 | POST `/tickets/{ticketId}/close` | 关闭工单 |
| 流量 | GET `/traffic/logs` | 本人流量记录，`page,per_page` |
| 邀请 | GET / POST `/invites` | 查询/生成邀请码 |
| 返佣 | POST `/billing/commission-transfer` | 转移返佣余额 |
| 礼品卡 | POST `/gift-cards/check` | 检查兑换码 |
| 礼品卡 | POST `/gift-cards/redeem` | 兑换 |
| 礼品卡 | GET `/gift-cards/history` | 使用历史 |
| 礼品卡 | GET `/gift-cards/types` | 模板类型 |
| 礼品卡 | GET `/gift-cards/history/{id}` | 单条历史 |
| 其他 | POST `/billing/stripe-public-key` | Stripe 可公开配置 |
| 提现 | POST `/billing/withdrawals` | 提现申请 |
| 账单 | GET `/billing/wallet` | `balance_minor,commission_balance_minor` |
| 账单 | GET `/billing/commissions` | 佣金明细分页 |
| 支付 | GET `/billing/payment-methods` | 可用订单支付方式，服务端移除私密配置 |
| 优惠券 | POST `/billing/coupons/check` | `code,plan_id,period` |
| 订单 | GET / POST `/orders` | GET 支持 `page,per_page,status`；POST `plan_id,period,coupon_code?`（201） |
| 订单 | GET `/orders/{tradeNo}` | 轻量状态 |
| 订单 | GET `/orders/{tradeNo}/detail` | 订单详情 |
| 订单 | POST `/orders/{tradeNo}/checkout` | `method?`、`token?`；获得支付动作，不是成功通知 |
| 订单 | POST `/orders/{tradeNo}/cancel` | 仅本人可取消待付订单 |
| 充值 | GET `/billing/recharge-payment-methods` | 仅已验证支持充值的支付方式 |
| 充值 | GET / POST `/billing/recharges` | GET 分页；POST `amount_minor,payment_method_id` 且需要 UUID `Idempotency-Key`（201） |
| 充值 | GET `/billing/recharges/{tradeNo}` | 本人的充值记录 |
| 充值 | POST `/billing/recharges/{tradeNo}/checkout` | `token?`，启动充值支付 |

`/billing/recharges` 的分页上限 **50**，订单/工单等大多数列表的 `per_page` 上限 **100**。动态参数 `tradeNo` 不等于数据库自增 ID。对用户私有 URL、账号与付款响应禁止共享 CDN 缓存。

## 4. 主题常见完整业务流程

### 套餐购买

1. `GET /txapi/plans` 展示可售套餐；登录后 `GET /txapi/plans/{planId}` 查询本人资格。
2. `POST /txapi/orders`，例如 `{"plan_id":1,"period":"month_price"}` **仅是字段形状示例**；`period` 的合法值须从当前套餐的 `prices[].period` 选择，不能假定所有实例都接受 `month_price`。
3. 取 `data.trade_no`，`GET /txapi/billing/payment-methods`，然后 `POST /txapi/orders/{tradeNo}/checkout` 提交选定的 `method` ID。
4. 根据 checkout 的 `data.type` 和 `data.data` 处理重定向/支付动作，并轮询 `GET /txapi/orders/{tradeNo}` 的状态。不可用客户端金额或回跳 URL 强行标记已支付。

### 钱包充值（幂等）

```http
POST /txapi/billing/recharges HTTP/1.1
Authorization: Bearer <opaque-session-token>
Content-Type: application/json
Idempotency-Key: 00000000-0000-4000-8000-000000000001

{"amount_minor":2500,"payment_method_id":1}
```

`Idempotency-Key` 必须是 UUID；同一业务重试使用原键，避免重复创建充值单。随后按返回的 `trade_no` 调用 `POST /billing/recharges/{tradeNo}/checkout`，使用查询接口确认状态。以上 ID/金额仅示例，不表示任何已配置支付方式。

### 用户订阅

`GET /txapi/me/subscription` 获取本人的 `subscribe_url`；客户端订阅下载真正走 `GET /{subscribe_path}/{token}`，由站点配置动态决定 `subscribe_path`。这不是普通主题拉取节点明文凭据的接口。生成二维码时应避免把完整订阅地址发给外部分析服务。

## 5. 错误、安全与验收

- **401** 未登录/Token 失效：停止带旧令牌重试；**403** 无权限；**404** 资源隐藏或不存在；**409** 业务状态冲突；**422** 表单/字段校验失败；**429** 命中限流；**5xx** 服务端问题。除 401 外也不能以任何状态码直接判定支付成功。
- 前端主题的用户 Token 与管理员 Token 严格隔离；管理员使用 `/txapi/admin/{admin_path}/*`，不要猜测管理员动态路径或将它写死在主题源码。
- 所有请求均使用 TLS；敏感数据仅在受控客户端状态中处理。对 CDN、日志和第三方遥测脱敏 `Authorization`、`auth_data`、`subscribe_url`、`token`。
- 主题开发者先验证公开配置、登录、个人中心、套餐/订单、回调后状态、工单与支付异常；生产联调另按 [发布验收](../../docs/operations/release-staging-acceptance.md)。完整**服务端注册**路由见 CI 导出的 `txboard-http-route-catalog`，未列入上述用户表的管理员/Agent/插件路由不得擅自复用。
