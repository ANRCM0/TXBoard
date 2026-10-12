# Internal API 当前只读切片 v1

状态：Current / local-testing-only，B5.1 最小验证切片。此文件描述 request version 1；[B5.2 User DTO version 2](current-user-dto-v2.md) 的 Gateway opt-in 独立描述。完整 B5、B6、SC-v2 和生产上线门禁仍未完成。

实际注册入口为 `POST /txapi/internal/v1/operations/{id}`。Draft 的 `/internal/v1/operations/{operation}` 改为 `/txapi/internal/v1/operations/{id}`，遵循仓库 AGENTS.md 的已注册 TXAPI 路由要求。没有任意 URL、类名、方法名或查询参数透传。

`CORE_INTERNAL_READONLY_ENABLED` 默认 false，且只有 `APP_ENV=local/testing` 可启用；production 即使设置 true 也返回 404。路由总是注册，默认关闭由 `App\Http\Middleware\InternalServiceProof` 强制执行。此开关不代表生产批准。TLS 边界 fixture 只监听 `127.0.0.1`，Core 必须同机 loopback 监听；禁止把内部路径通过外部 wildcard 反代开放。

## Operation 与授权

| id | 唯一服务角色 | 输入 | 当前 Core 最终授权 |
| --- | --- | --- | --- |
| `user.profile.get` | `user-gateway` | `{}` | Sanctum 有效会话、TxapiUser 未封禁，且 actor 不是管理员；复用 AccountController::me |
| `user.order.get` | `user-gateway` | `{"trade_no":"..."}` | 同上；复用 AccountController::order 的 user_id 查询，别人的订单 404 |
| `admin.order.get` | `admin-gateway` | `{"id":"123","admin_path":"..."}` | Sanctum、TxapiUser、现有 Admin 的 is_admin、AdminPath 的动态路径；复用 OrderAdminController::detail |

User/Admin 服务互相拒绝对方的操作。服务证书不能代替 actor 会话；管理员会话不能成为共享服务凭据。Core 当前没有此切片所需的细粒度 RBAC Policy，因此本次准确复用 **is_admin 与动态路径**，没有宣称额外 RBAC 已实现。每次操作清除 request guard 缓存并由 Sanctum 重新读取 token/actor，过期、撤销、封禁及角色变更以当前数据库状态为准。

这些操作不修改订单、余额、订阅或账号业务状态。Sanctum 的会话使用时间和本切片 nonce、限流、审计记录仍有元数据写入。

## 请求与输出

```json
{
  "contract_version": "1",
  "request_id": "00000000-0000-4000-8000-000000000000",
  "deadline_at": 1791763260,
  "input": {}
}
```

请求及 input 必须是 JSON object，拒绝未知、缺失、重复键（包括转义后重名）；数组、浮点 deadline、错误版本拒绝。Content-Type 只接受 application/json，可附 charset=utf-8。request_id 必须 UUID，只作客户端关联，Core 生成自己的执行 request_id。deadline_at 为 UTC Unix 秒，严格大于当前秒且最多未来 5 秒；绑定原始正文 hash，执行后再次检查。所有操作只允许 POST，完整 request URI 必须精确匹配，查询参数拒绝。

trade_no 为 1–64 字符的 ASCII 字母、数字、下划线或连字符。admin id 为 1–18 位非零开头十进制字符串，admin_path 为 1–128 字符的相同 ASCII 字符集。没有 actor_id、role、tenant 或金额输入。

成功采用 Native `{data,request_id,meta}`，meta 仅含 `current_only:true`、`slice:"internal-readonly/1"`。启用时处理的操作响应 `Cache-Control: private, no-store`。

输出字段只有：

- profile：id、email、plan_id、balance_minor、commission_balance_minor、expired_at、traffic（upload_bytes/download_bytes/limit_bytes）。
- user order：id、trade_no、plan_id、period、type、status、amount_minor、plan（id/name 或 null）、created_at、paid_at。
- admin order：上述 order 字段及 user_id；不返回用户邮箱、callback_no、邀请关系、佣金详情或管理路径。

模型 ID、minor money、bytes 均为十进制字符串，由 Core 原生整数投影，未经过 JavaScript Number。timestamp 为 UTC Unix 秒或 null；period 使用 PlanService 的原生周期键；订单状态精确保留 0–4，包括历史 DISCOUNTED=4。UUID、订阅 token/url、password、节点/支付秘密不会进入 DTO。

## mTLS 与绑定证明

`api/internal-readonly-fixtures/tls-boundary.mjs` 使用 Node 22 内置 HTTPS/TLS、OpenSSL CA 验证及 CRL，固定 TLS 1.3、requestCert=true、rejectUnauthorized=true。客户端必须保留默认服务端 CA、有效期和主机名验证，服务端 SAN 固定 `DNS:internal-core.local`。

客户端 SAN 必须精确等于唯一 `DNS:user-gateway.internal` 或 `DNS:admin-gateway.internal`，CN、未知 SAN、多 SAN 均拒绝。服务证书 SHA-256 fingerprint 必须在可信配置的角色 allowlist 中。每次请求重新检查当前配置、leaf 有效期及吊销标记，覆盖已有 keepalive 连接；握手另外由真实 CRL 拒绝吊销证书。CRL 文件变更需要重启 TLS listener，立即撤销可先移除/撤销 fingerprint；这是本机 fixture 边界，不是生产证书自动轮换系统。

TLS 边界丢弃来访者的所有身份、actor、证书及 proof header，仅转发明确定义的 Authorization、Content-Type 和新签名 proof。Core 不信任 `X-Client-Cert`、`X-Actor-*` 或任何未验签身份头。

TLS 证书密钥与 proof RSA 密钥分开。边界使用标准 compact JWS **RS256 / RSA≥2048**；Core 使用锁文件已有 firebase/php-jwt 6.11.1 的显式 `Key(publicKey,'RS256')`，没有新增算法或依赖。JWS header 只允许 alg/typ/kid，alg 必须 RS256，typ 必须 JWT，不接受 jku/jwk/x5u 等来访者 key 转换或选择来源。

边界在每个 HTTP request 开始时读取其可信配置；已开始接收 body 的请求不会因边界配置文件途中改动立即取消。签发时仍夹紧实际客户端证书 expiry，Core 验签时再次读取自身当前 key/fingerprint 窗口和撤销配置，随后由当前 Sanctum actor 判定封禁/角色。紧急撤销需同步 Core trust；后续 request（包括 keepalive）读取更新后的边界配置。本切片没有跨进程的即时中断机制。

Claims 精确为 v/epoch/iss/aud/service/cert_sha256/method/path/body_sha256/actor_sha256/iat/nbf/exp/jti：

- v=`internal-readonly/1`，iss=`txboard-local-tls-boundary`，aud=`txboard-core-local` 固定 Core 配置。
- 每个 kid 对应一个服务角色、一个 epoch、一个固定可信 absolute public_key_path 和启用时间窗口；key revoked、未知 kid/epoch、错误角色拒绝。
- cert fingerprint 只能引用该配置角色的当前 allowlist 项，具有单独有效期/撤销状态。来访者的 body 不能选择 trust file、证书 CA 或公钥。
- method、完整 path、原始 body SHA-256、精确 Authorization SHA-256 必须一致；deadline 位于同一个被绑定的 body 中。
- iat/nbf/exp 均整数，iat 最多未来 2 秒、最长存活 10 秒，nbf=iat−2，exp 不越过 key/cert 配置窗口；已过期 proof 拒绝。不会修改 JWT 静态 leeway/timestamp，避免 Octane worker 状态污染。
- jti 是 128-bit 随机 nonce 的 32 位小写 hex。结构、签名、服务 scope、body 与 actor credential hash 全部通过并且 Sanctum actor 有效后，才消费持久 nonce。

Core 的 `CORE_INTERNAL_READONLY_TRUST_FILE` 是管理员设置的固定绝对文件，最大 32KiB；公钥最大 8KiB。可信文件每请求读取，未使用全局 identity 缓存；角色撤销仍由 Core 当前 actor 判定。轮换可以短期配置不同 kid/epoch 的重叠窗口，超过 not_after 或 revoked 后立即拒绝；fixture 验证 epoch 1→2 使用不同 RSA key。

参考成熟原语的官方说明：[Node TLS](https://nodejs.org/docs/latest-v22.x/api/tls.html)、[Node HTTPS](https://nodejs.org/docs/latest-v22.x/api/https.html)、[php-jwt Key/算法绑定](https://github.com/googleapis/php-jwt/blob/main/README.md)。

## 有界状态与错误

body≤16KiB，proof≤8KiB，Authorization≤4KiB，response≤64KiB。TLS 边界 header 数≤30、header timeout 5 秒、body 接收 5 秒、upstream 总 walltime 5 秒、keepalive 120 秒；upstream 使用独立定时器，连续小 chunk 不能重置期限。正常 end、错误、超大响应、异常 input close 和客户端 response close 均清理 timer 并关闭 upstream。各传输阶段分别有界，不能把它们相加的全过程宣称为固定 5 秒。

Core 接受的业务 deadline 最多未来 5 秒，在同步 handler 返回后再次检查并拒绝超期结果；这不是 SQL/操作系统调用取消，不能保证中断正在阻塞的数据库查询。边界的 walltime 负责不再等待并关闭连接，Core 同步请求可能继续至底层调用返回。本切片全部业务只读，但相应元数据仍可能提交。只访问固定 loopback Core 端口和三个操作路径，没有重定向、任意 origin 或自动重试。

000008 migration 新建 tx_internal_readonly_nonces/windows/audits。kid+jti 唯一约束和事务跨 worker/进程拒绝重放；每服务每 UTC 分钟最多 60 个已验签且有效 actor 的操作。限流拒绝的事务不执行操作。nonce/rate/audit 存储未知故障返回 503，审计写入失败也不返回业务成功；委派业务 Controller 的其他未处理异常沿用 Native 的非秘密 500 INTERNAL_ERROR。

nonce 保留至 exp；window 保留 120 秒；audit 保留 24 小时。已接纳请求清理最多 100 个过期 nonce、100 个过期 audit、100 个过期 window；删除时再次比较 expiry，不能删除未过期 nonce。默认关闭、请求 schema/deadline、service proof 失败、无效/撤销/过期 actor、nonce replay、限流及 admit 存储失败均在接纳前拒绝，不写 slice operation audit，避免匿名请求扩大持久状态；因此不是所有拒绝都有审计。有效 Sanctum actor 的封禁在 admit 后由 TxapiUser 判定，会记录 403。slice audit 覆盖已接纳 operation 的成功、封禁、角色/路径/归属拒绝，只有 service/operation/actor_id/Core request_id/status/error_code/expiry，不存正文、proof、token、证书或密码；audit 写入本身失败时返回 503 并可能缺失该行。

| HTTP | 本切片错误 |
| --- | --- |
| 400 | INVALID_REQUEST / UNKNOWN_OPERATION |
| 401 | SERVICE_UNAUTHORIZED / ACTOR_UNAUTHORIZED |
| 403/404 | 现有 Core FORBIDDEN / NOT_FOUND（服务在边界 scope 不匹配为 SERVICE_FORBIDDEN） |
| 409 | SERVICE_REPLAY |
| 413/502 | PAYLOAD_TOO_LARGE / RESPONSE_TOO_LARGE |
| 429 | RATE_LIMITED |
| 503/504 | DEPENDENCY_UNAVAILABLE / DEADLINE_EXCEEDED |

只返回固定非秘密消息，不把 JWT、数据库错误、证书内容或输入值交给调用者。默认关闭/生产模式统一 404。

## 验证与范围限制

可复现流程见 [fixture README](../../../scripts/tests/internal-readonly/README.md)。独立 committed Core HEAD + 本切片覆盖，复制 vendor 避免 Composer 相对 classmap 加载来源应用；不带入并发修改。HTTP fixture 使用唯一空的 txboard_internal_test，禁止 migrate:fresh 或生产数据库。

当前验证结果由对应 evidence 文件记录，包括代码 hash/HEAD、TLS 参数、真实 CA/CRL、证书过期 keepalive、Node/PHP runtime、并发与重启 replay 以及 sanitized audit 行数。fixture 完成时停止服务，删除临时私钥、actor token、可信配置和 CA 私密状态，保留公开证书 fingerprint、routes、日志及无秘密 result。

2026-10-12 最终本机记录：固定 committed Core `3f90cba6b9070496c3b84697bbd33ed0d6c37216` + 本切片覆盖；sqlite 15 tests / 147 assertions（含原生权限回归），route 静态 13 tests，真实 Core/mTLS 34 checks，slow-upstream mTLS 6 checks。环境 Node 22.23.2、PHP 8.4.16、MariaDB 11.4.12。精确 case/hash/公开证据见 [Current-only 验证记录](../../../docs/architecture/v2/internal-readonly-verification.json)。

此切片没有完成生产网络隔离、真实 MySQL 8.4、Redis/集群接管、生产 PKI 自动续期与 CRL 发布、多租户/细粒度 RBAC、写入 Operation 幂等、Gateway Internal adapter、SC-v2 或全部业务回归。不得据此标记 B5/B6 或生产门禁完成。
