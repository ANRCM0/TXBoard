# TXBoard Legacy Retirement — Batch 3 (authentication / subscription security)

> 2026-10-09 · 本批仅迁移官方 Vue 用户端；外部兼容入口不得据此删除。

## LR-07 自服务敏感操作

- **CURRENT（代码）** `POST /txapi/auth/quick-login`：Sanctum 用户身份 + 5/min rate limit，重用已有 `LoginService::generateQuickLoginUrl` 和一次性临时 token，有效期 60 秒。只允许应用内路径，不接受外部 URL。
- **CURRENT（代码）** `POST /txapi/me/subscription-credentials/rotate`：Sanctum 用户身份 + 3/min rate limit，数据库行锁内更新 uuid + 订阅密钥，返回 `data.subscribe_url`；不触碰会话登录 token、套餐、计费或 Node 身份。
- Vue User 个人设置页使用上述原生接口；老 V1 重置安全密钥 GET 和快捷登录 URL POST **保留兼容**。服务端的鉴权限流/响应字段与敏感令牌日志审计是上线门禁。

## 下一阶段验收与风险

邮件验证码发送、忘记密码、邮件魔法链接签发与短令牌兑换仍依赖 `/api/v1/passport/*`，须分开做强制 Captcha、限流、回放/并发兑换与新旧共用领域服务的集成测试。不要直接把 V1 controller 移入 TXAPI 或将旧 token 暴露给普通 profile。

生产旧接口退役需收齐消费者覆盖、监控归零、可回退部署、跨仓库回归及维护者签发，不与本次开发合并。
