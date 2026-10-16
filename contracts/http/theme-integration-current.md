# 外部主题 TXAPI 对接手册

> 当前接口以 `api/routes/txapi.php`、`api/routes/web.php` 为准；外部部署须自行验证 HTTP 连通性。
>
> 2026-10-16：本分支含 C3/C4 候选接口，尚不代表已合并、通过集成验收或发布。路由注册不等于当前 native 安装准入；未实现的历史业务入口仍拒绝。当前状态及限制见[内核成熟度评审](../../docs/architecture/v2/core-maturity-review.md)。

## 1. 接入原则

- 站点同源访问：`https://<panel-host>/txapi`。外部独立域名必须自行处理受信任的 HTTPS 代理/CORS；文档**不承诺**跨域自动开放。只调用当前路由表实际注册的接口。
- 无登录的页面仅调用 `/public/*` 和公开套餐；登录后使用普通用户 Sanctum Bearer 调用 `/me/*`、`/orders/*`、`/billing/*` 等。**不要将管理员、Node、Machine、Agent 或支付提供商密钥打包进浏览器主题**。
- 原生成功通常是 `{"data": ..., "request_id":"..."}`，分页额外带 `meta`；原生业务失败是 `{"error":{"code":"...","message":"..."},"request_id":"..."}`，并返回真实 HTTP 状态及 `X-Request-Id`。请根据 HTTP 状态码与 `error.code` 判断失败。部分框架验证/异常响应可能不同，应保留状态码和请求 ID 进行排障。
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

`/public/site-config` 的 `theme_config` 仅含允许公开的主题字段。第三方主题使用 `txboard-theme.json` 与静态 SPA 资源，遵守 [当前 Theme Package](../theme-package/README.md)；旧 Blade 包和 `/themes/v2/upload` 重复入口已删除，唯一上传入口为管理员 `/themes/upload`。`GET /` 由当前主题提供首页（内置 `TXBoard` 为用户 SPA）；不要把 `GET /` 当成业务 JSON API。

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

## 3. 登录后的主题业务路由

以下为 `txapi.user` 身份域的业务路由。除特殊注明外均为 **User Bearer**，并受 `throttle:120,1` 及具体子接口限流约束。

| 分类 | 方法 + 路径 | 关键字段/用途 |
| --- | --- | --- |
| 用户 | GET `/me` | native：账户身份、`subscription` 与非秘密凭据状态；旧 UUID、余额及 User 权益字段不是 native 权威 |
| 用户 | GET `/me/subscription` | native：`core-entitlement/v1` 共同权益摘要，不返回代理秘密或旧 token URL |
| 用户 | GET `/me/dashboard-stats` | 未付款订单、未关闭工单、邀请人数 |
| 用户 | GET `/me/site-config` | 仅登录用户可见的站点配置 |
| 用户 | GET / PATCH `/me/preferences` | 偏好；PATCH 只支持 `remind_expire`、`remind_traffic` |
| 原生凭据 | POST `/me/access-subjects` | `subscription_id,provider_id,credential_profile_id,expected_version`；必需 `Idempotency-Key`，仅准备完成后的 native 领域可签发 |
| 原生凭据 | POST `/me/access-subjects/{id}/revoke` | `expected_version` 与 `Idempotency-Key`；先本地拒绝，再协调 Provider 撤销 |
| 原生凭据 | POST `/me/access-subjects/{id}/rotate` | `expected_version` 与 `Idempotency-Key`；保留 Subject、递增凭据代次并拒绝旧代次 |
| 原生凭据 | GET `/me/credential-operations/{operation_ref}` | 本人操作的非秘密状态；只读，不触发外部调用 |
| 原生凭据 | POST `/me/credential-operations/{operation_ref}/recover` | 空请求体，按原操作查询和恢复，不创建新的逻辑签发 |
| 原生分发 | POST `/me/subscription-deliveries` | `access_subject_id,recipient_public_key` 与可选固定 `format_id`；返回短期签名策略，由持有独立接收密钥的客户端向 Provider 取回配置 |
| 原生链接 | GET / POST `/me/subscription-links` | GET 支持 `after_id`；POST `access_subject_id,expires_at` 与 `Idempotency-Key`，bearer 仅首次返回 |
| 原生链接 | POST `/me/subscription-links/{linkRef}/revoke` | 空请求体，独立撤销本人链接，已签发有限授权不延长 |
| 原生用量 | GET `/me/usage-periods` | 本人真实 UsagePeriod；`subscription_id?,after_id?,limit?`，不读取旧 StatUser 作为权威 |
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
| 账单 | GET `/billing/wallet` | native：`{schema:"native-wallet/v1",balances:[...]}`，按币种返回账本可用/保留金额 |
| 账单 | GET `/billing/commissions` | 佣金明细分页 |
| 支付 | GET `/billing/payment-methods` | 可用订单支付方式，服务端移除私密配置 |
| 支付 | GET `/billing/payment-attempts/{attemptId}` | 仅本人原尝试的非秘密状态，不发起外部查询 |
| 支付 | POST `/billing/payment-attempts/{attemptId}/query` | 仅本人，按原冻结插件配置查询/恢复，不接受客户端收款声明 |
| 优惠券 | POST `/billing/coupons/check` | `code,plan_id,period` |
| 订单 | GET / POST `/orders` | GET 支持 `page,per_page,status`；native POST 仅 `offer_id` 与 `Idempotency-Key`（201） |
| 订单 | GET `/orders/{tradeNo}` | 轻量状态 |
| 订单 | GET `/orders/{tradeNo}/detail` | 订单详情 |
| 订单 | POST `/orders/{tradeNo}/checkout` | native 必需 `Idempotency-Key`；`method?`、`wallet?`、字符串 `wallet_amount_minor?`，禁止渠道 token；免费订单仅空请求体 |
| 订单 | POST `/orders/{tradeNo}/cancel` | 仅本人可取消待付订单 |
| 充值 | GET `/billing/recharge-payment-methods` | 仅已验证支持充值的支付方式 |
| 充值 | GET / POST `/billing/recharges` | GET 游标 `after_id,limit`；POST 字符串 `amount_minor,payment_method_id` 且需要 UUID `Idempotency-Key`（201） |
| 充值 | GET `/billing/recharges/{tradeNo}` | 本人的充值记录 |
| 充值 | POST `/billing/recharges/{tradeNo}/checkout` | 空请求体，启动原充值单支付；禁止 `token` |

`/billing/recharges` 的 `limit` 上限 **50**，订单/工单等大多数列表的 `per_page` 上限 **100**。动态参数 `tradeNo` 不等于数据库自增 ID。对用户私有 URL、账号与付款响应禁止共享 CDN 缓存。

原生凭据路由沿用 C2 已交付的真实安装门禁：未初始化或未完成 preflight/activate 的安装拒绝业务；激活后仅开放 `NativeRuntimeAdmission` 明确允许的入口，不能把本表所有历史注册路由当作 native 已开放。凭据响应只含 `core-credential-operation/v1` 元数据，不返回 UUID、私钥或订阅内容。签发回执不表示 Node 已获授权，`unknown` 也不代表没有执行。见 [Provider 签发契约](../v2/credential-provider/native-issuance-v1.md)。

链接兑换不是 User Bearer 路由：`POST /txapi/subscription-links/{linkRef}/redeem` 使用独立链接 bearer 与闭合请求体中的 `recipient_public_key`。只返回至多 30 秒且受链接到期时间约束的 Provider 渲染授权，不返回代理材料。见[链接契约](../v2/native-provider-lifecycle-v1.md)。正式 Internal API 是服务端 mTLS + 独立服务凭据入口，浏览器不得持有这些凭据，见[服务契约](../v2/native-internal-api-v1.md)。

## 4. 主题常见完整业务流程

### 套餐购买

1. native `GET /txapi/plans` 返回不可变已发布 Offer；从返回值选择 `offer_id`，不提交可变套餐价格或猜测周期。
2. `POST /txapi/orders` 提交 `{"offer_id":"<offer_id>"}` 与 `Idempotency-Key`；响应丢失时用原键/原输入重试。native 创建键永久保留，取消或时间流逝不释放原键；直接入口允许 1—128 位 `A-Za-z0-9._~-`，服务入口另要求 UUID。
3. 取 `data.trade_no`。免费订单 checkout 为空体；全钱包使用 `{"wallet":true}`；渠道使用 `{"method":1}`，可选字符串 `wallet_amount_minor` 冻结混合份额。checkout 使用独立永久键，所有重试保留原键/输入；新客户端建议统一使用 UUID。
4. 根据 checkout 的 `data.type` 和 `data.data` 处理动作，轮询订单/原支付尝试。实际收款通过验签回调/原尝试查询进入 Core；SQL work 负责可恢复履约，不要求 Webhook 内同步完成。

本候选仅支持 CNY、无手续费与已实现的渠道插件。付费套餐替换因尚缺不可变来源绑定的升级折抵而明确拒绝；已履约续费/重置等精确全退仍未交付。不能以支付失败、浏览器回跳或本地订单取消推断渠道未收款。详见[支付结算](../v2/native-payment-settlement-v1.md)与[钱包退款](../v2/native-wallet-refunds-v1.md)。

### 钱包充值（幂等）

```http
POST /txapi/billing/recharges HTTP/1.1
Authorization: Bearer <opaque-session-token>
Content-Type: application/json
Idempotency-Key: 00000000-0000-4000-8000-000000000001

{"amount_minor":"2500","payment_method_id":1}
```

`Idempotency-Key` 必须是 UUID；同一业务重试使用原键，避免重复创建充值单。随后按返回的 `trade_no` 调用 `POST /billing/recharges/{tradeNo}/checkout`，使用查询接口确认状态。以上 ID/金额仅示例，不表示任何已配置支付方式。

### 用户订阅

`GET /txapi/me/subscription` 返回共同权益摘要（`core-entitlement/v1`），不返回代理 UUID 或长期订阅链接。连接配置通过 `POST /txapi/me/subscription-deliveries` 提交当前客户端 PoP 公钥，换取短期、绑定客户端的 Provider 渲染授权；获取实际连接材料由受信客户端直连 Provider，参见 [当前分发契约](../v2/native-c2-runtime-v1.md)。旧 token URL、节点枚举和 User UUID 轮换入口已删除。

## 5. 错误、安全与验收

- **401** 未登录/Token 失效：停止带旧令牌重试；**403** 无权限；**404** 资源隐藏或不存在；**409** 业务状态冲突；**422** 表单/字段校验失败；**429** 命中限流；**5xx** 服务端问题。除 401 外也不能以任何状态码直接判定支付成功。
- 前端主题的用户 Token 与管理员 Token 严格隔离；管理员使用 `/txapi/admin/{admin_path}/*`，不要猜测或硬编码管理员动态路径。
- 所有请求均使用 TLS；敏感数据仅在受控客户端状态中处理。对 CDN、日志和第三方遥测脱敏 `Authorization`、`auth_data`、`token`。
- 主题开发者先验证公开配置、登录、个人中心、套餐/订单、回调后状态、工单与支付异常；生产联调另按 [发布验收](../../docs/operations/release-staging-acceptance.md)。完整**服务端注册**路由见 CI 导出的 `txboard-http-route-catalog`，未列入上述用户表的管理员/Agent/插件路由不得擅自复用。
