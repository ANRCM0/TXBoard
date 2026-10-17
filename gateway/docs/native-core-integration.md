# Gateway 与当前 Native Core 接入

本文区分当前服务端实现、浏览器适配和历史联调证据，不是后续开发计划。Core 始终持有身份、权限、订单、钱包和权益权威。

## 服务端 Native operation 客户端

`apps/gateway/src/services/native-internal.ts` 的 `NativeCoreClient` 和 `scripts/native-core-client.mjs` 调用固定 `/txapi/internal/native/v1/{user|admin}/operations/{operation}`。正式 envelope、operation/授权及服务引导见 [Native 服务契约](../../contracts/v2/native-internal-api-v1.md)。

- User 与 Admin 分别使用 `GATEWAY_CORE_USER_*`、`GATEWAY_CORE_ADMIN_*` 配置：`ENABLED`、`SERVICE_ID`、`CREDENTIAL`、`EPOCH`、`CA_FILE`、`CERT_FILE`、`KEY_FILE`。显式启用但配置不全拒绝启动，不回退到不受信的明文通道。
- 使用 HTTPS/mTLS、正常 hostname 校验和私有 CA；不跟随重定向、不自动重试，不关闭全局 TLS 验证。请求/响应大小有界，总 deadline 覆盖文件读取、连接和响应正文。
- service 身份不是 actor：受保护 operation 仍传原用户 Bearer，由 Core 重新校验。Admin 还要求当前安全路径和 Core Admin/RBAC，User/Admin 不能互借凭据。
- operation 来自固定 registry；写操作要求原始 UUID 幂等键，5xx/网络中断为未知结果，保留原键恢复，不自动换键重发。
- mTLS 私钥、service credential 与管理员安全路径只注入服务端，不进 Theme SDK、浏览器、URL、参数历史或日志。客户端不自制可信 TLS 代理断言头。

CLI 依赖已构建的 `apps/gateway/dist`，从 stdin 读取业务 input，actor 从运行时秘密环境读取；不要将密码/Bearer 写成命令参数。源码中的可调用项不自动提供对应浏览器路由，也不意味着生产 PKI/代理/部署已经验收。

## 当前浏览器通道

`services/upstream.ts` 中只有语义已对齐的 `login -> identity.session.create`、`guestConfig -> settings.public.get` 在配置 User service 后选择上述通道；独立 Admin client 不由浏览器 operation 选取。其他操作仍走固定 Core `/txapi/*` 和现有投影。

外部 `/gateway/v1` 的 `{ok,data,meta}` 不直接等同内部 Native envelope。输入 strict schema、上游 envelope/DTO 校验及输出字段白名单仍必需，不能只替换路径。

**订单创建仍使用历史 `plan_id/period`，Native Core 要求 `offer_id`。** `GATEWAY_ORDER_WRITE_ENABLED` 默认 false，关闭时 405/零上游调用；打开只提升 Gateway 策略，不完成 native 购买 DTO 迁移。其他历史展示适配也须按当前消费者逐项核对，不能沿用旧通过结论。

匿名 `/catalog/payment-methods` 固定 404、零上游调用；历史 catalog flag 不能为受保护支付接口发明共享 Bearer。`/payments` 仍需要当前用户凭据，Gateway 不持支付商密钥或处理回调。密码恢复、会话管理和订单取消的旧浏览器原型/SDK 方法未因服务客户端存在而恢复。

`user.profile` 只公开 `{id,email,telegram_id}`。`user.subscription` 是当前 `core-entitlement/v1` 判别联合：拒绝时只给 reason，授权时标识/字节/限制/Unix 秒保持十进制字符串，不提供订阅原始 URL。订单列表保留状态 0..4 和分页，不猜测支付/履约结果。

## 隔离工具与证据边界

[native-core-baseline.json](../tests/integration/evidence/native-core-baseline.json) 是旧版直接 TXAPI 联调快照，只覆盖其记录的源码/环境；不证明当前 Native Offer/mTLS、生产环境、外部 CAPTCHA/邮件或 MySQL 8.4 已通过。原始 JSON 保持不变，不覆盖成当前结果。

`tests/integration/run-linux.sh` 是现有隔离工具：从 **committed HEAD** 导出 Core 快照、复制受控 vendor，不读未提交业务改动；`TXBOARD_CORE_REPO` 是主仓根，`TXBOARD_CORE_VENDOR` 是其 `api/vendor`。只授权专用 `txboard_gateway_test`，fixture 检查 testing 环境/数据库名/mail.driver=log，独占锁保护固定 fixture/端口，Redis 使用专用 DB 12。不得连接生产或共享测试数据，也不得禁用 Core 原有限流让测试通过。

这套工具及旧 fixture 的存在不保证当前 schema/业务契约继续兼容。完整数据库/进程/网络验收在 CI/获授权环境进行，本次文档整理没有运行它。

## 当前验证边界

- Windows 静态检查不能替代 Linux Docker Secret 绝对路径/权限与真实 TLS/mTLS 验收，不为适应本地测试放宽生产规则。
- 服务配置、actor 撤销/域/RBAC、敏感字段和未知结果的检查须对应实际最终源码；已有历史报告不自动适用于后续提交。
- 生产 PKI/代理链、外部 CAPTCHA 成功验证/邮件投递、支付 Provider/回调、多副本/Redis 故障恢复、MySQL 8.4 和正式 SDK 发布分别需要真实证据。

[当前状态](development-status.md) · [Gateway HTTP 契约](../contracts/gateway-v1.md) · [合仓与 CI](monorepo-current.md) · [安全边界](../SECURITY.md)。
