# 本地管理 Internal 消费者 v1

> 已退役（2026-10-16）：本文保留旧隔离切片的历史协议和验收说明。对应 Core/Gateway 运行入口、配置、SDK 原型消费者、夹具及专属 CI 已移除；下文 Current/local-testing 状态仅描述当时实现。正式 Internal API 仍属 C4 待交付，不提供旧路径或旧版本兼容。见 [当前清理边界](../../../docs/development/core-cleanup.md)。

B5.18 增量 Current：Gateway 中增加隔离测试用管理客户端，只消费固定 `auth.admin.login`、`admin.identity.get`、`admin.dashboard.get` version `1`。不注册公开 Admin HTTP 路由，不接入旧 User HPKE 通道，也不引入新的前端存储或业务数据。

独立 `GATEWAY_INTERNAL_ADMIN_ENABLED=true` 与 `APP_ENV=local/testing` 门禁默认关闭；生产或歧义开关拒绝。服务端固定配置文件只能给出 HTTPS loopback/Internal Core origin、服务器名、CA、CRL、管理客户端证书与私钥路径。管理客户端证书 SAN 必须恰好 `DNS:admin-gateway.internal`，不能复用 User 服务身份；请求只使用 TLS1.3、当前有效证书、严格 Core SAN 和信任链，不跟随重定向。

请求构造固定 operation/version、随机 request_id、最多5秒 deadline 和闭集 input。登录不带 Authorization，仅接受合成 `@example.test` 邮箱；读取要求实际 Bearer 与当前 admin_path，不接受 caller actor/role/proof、任意上游 URL或额外字段。客户端不持有管理角色和统计权威；Core 每次重查当前角色、封禁、路径和会话。不会通过身份成功缓存后续授权。

响应必须是严格 JSON（拒绝重复成员）、闭集 TXAPI envelope、正确 slice/version 和对应类型；保留 request_id，金额/字节字符串原样返回，绝不转为 Number。登录凭据和 secure_path 仅在返回值中私有交付，不记录日志；错误只保留允许的状态和固定代码，不反射上游消息、HTML、SQL或凭据。请求和响应分别最多16384/65536字节，独立壁钟超时、取消和连接失败均收敛。没有自动重试、公开/Native 回退或结果缓存；登录网络失败/审计503仍可能隐藏已提交会话。

真实 Core/MariaDB/mTLS 联调已验证管理客户端登录→当前身份→仪表盘和撤销/降权负例，见[证据报告](../../../docs/architecture/v2/internal-admin-consumer-verification.json)。正式管理网络入口、浏览器协议、SC-v2、生产 PKI和细粒度授权保持后续验收。
