# TXBoard Legacy Retirement — Batch 3 (authentication / subscription security)

> 2026-10-09 · 本批仅迁移官方 Vue 用户端；外部兼容入口不得据此删除。

## LR-07 自服务敏感操作

- **CURRENT（代码）** `POST /txapi/auth/quick-login`：Sanctum 用户身份 + 5/min rate limit，重用已有 `LoginService::generateQuickLoginUrl` 和一次性临时 token，有效期 60 秒。只允许应用内路径，不接受外部 URL。
- **CURRENT（代码）** `POST /txapi/me/subscription-credentials/rotate`：Sanctum 用户身份 + 3/min rate limit，数据库行锁内更新 uuid + 订阅密钥，返回 `data.subscribe_url`；不触碰会话登录 token、套餐、计费或 Node 身份。
- Vue User 个人设置页使用上述原生接口；老 V1 重置安全密钥 GET 和快捷登录 URL POST **保留兼容**。服务端的鉴权限流/响应字段与敏感令牌日志审计是上线门禁。

## LR-08 原生邮件登录与临时令牌兑换

- `POST /txapi/auth/mail-link`：复用原 `MailLinkService::handleMailLink`，进行邮箱验证与管理员开启的 Captcha 校验；对不存在的账户返回统一成功；已知账户的发送冷却仍有效。
- `POST /txapi/auth/one-time-token`：复用原 `handleTokenLogin` 一次性短令牌与 `AuthService::generateAuthData` 颁发 Sanctum，仅返回 `auth_data`。令牌通过 POST body 传输。
- Vue 邮件登录链接申请与临时令牌兑换改用原生 POST，旧 V1/V2 继续保留供旧邮件链接和外部客户端；并发重放与服务器访问日志审计仍须单独验收。
- 邮件验证码/找回密码及 Telegram 另属 LR-09+，不在本 PR 移除或修改。

## LR-09 原生邮箱验证码与找回密码

- `POST /txapi/auth/email-code` 与 V1 共享 `EmailVerificationService`，沿用原 CacheKey、邮件模板、域名白名单与冷却，改用随机安全的 `random_int` 和原子发送冷却。
- `POST /txapi/auth/password/forgot` 沿用 `LoginService::resetPassword` 的验证码和错误频率规则，并校验配置启用的 Captcha。
- **安全行为变更（V1 与原生共有）**：成功找回密码时撤销用户所有现有 Sanctum 会话；邮箱验证码也会在重置后失效。请在发布说明中告知用户须重新登录。
- Vue 用户端验证码申请及找回密码已迁入 TXAPI；旧路由仍为其他消费者保留。

## 下一阶段验收与风险

邮件验证码发送、忘记密码及 Telegram 登录仍依赖 `/api/v1/passport/*`，须分开做强制 Captcha、限流、回放/并发兑换与新旧共用领域服务的集成测试。不要直接把 V1 controller 移入 TXAPI 或将旧 token 暴露给普通 profile。

生产旧接口退役需收齐消费者覆盖、监控归零、可回退部署、跨仓库回归及维护者签发，不与本次开发合并。
