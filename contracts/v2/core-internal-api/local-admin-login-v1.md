# 本地管理登录 v1

B5.16 Current 增量：`auth.admin.login` version1 仅由 `admin-gateway` 服务调用，Core 独立 `CORE_INTERNAL_ADMIN_LOGIN_ENABLED=false` 默认关闭，另要求原 Internal 开关、local/testing 和严格隔离副本。仅合成 `@example.test` 邮箱进入；请求必须没有 Authorization，包括空头。未开放公网 Admin Gateway、新管理前端或 SC-v2。

输入复用原登录闭合 schema：email/password 以及可选的三个原生 CAPTCHA 字段；未知字段、重复 JSON key、非法类型/大小、版本、期限或服务 proof 拒绝。临时 FormRequest 保留原 TrimStrings（密码空格不去掉）、校验、10/min共享原生登录限流、LoginService 的密码预算/旧hash/封禁及 hook。CAPTCHA 仅确认SQL/缓存/已加载配置已知停用时允许，不访问外部服务。请求绑定在 finally 恢复，不向下一 Octane 请求保留临时 User/Request。

直接委托原 `AuthController::adminLogin`：密码验证成功后先核对当前 `is_admin`/封禁，再发行真实 Sanctum 会话。普通 User 或仅 staff 不能收到管理会话或路径。成功 DTO 恰好为 `auth_data`（Bearer）、`is_admin:true`、`secure_path`，供本次管理员引导；响应 `private, no-store`，技术审计 actor_id=0，不记录邮箱、密码、路径、token/proof。订阅秘密、UUID、Provider/Node配置不返回。服务身份不构成业务身份，后续管理调用仍重新验证会话/角色/路径。

原生 LoginService 在管理员角色检查前已经更新 last_login_at 并调用 user.login.after，因此正确密码的普通账户403仍可能更新该字段；没有人为将原行为改成事务。会话签发、hook、SQL审计、deadline与响应并非一个原子事务：审计失败503、超时或丢失响应可能隐藏已提交会话。nonce仅防同proof重放，不构成登录幂等，新proof可发行另一个会话；没有自动重试、补偿、撤销或恢复。未知/错误/封禁账号沿用401，原密码预算与HTTP限流429保留，服务预算为SERVICE_RATE_LIMITED。

正式 CAPTCHA/生产账户、管理会话消费者、其他管理Operation、细粒度RBAC、正式SC-v2和生产PKI仍待实施。
