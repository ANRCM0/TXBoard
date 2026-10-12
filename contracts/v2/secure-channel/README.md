# Secure Channel v2

> 状态：Target / Draft。本批冻结安全边界与验收要求，尚未冻结密码学字节格式；不能据此宣称 SC-v2 已实现或已获安全批准。

B6.1 当前仅有 [实验 metadata codec](metadata-codec-prototype-v1.md) 与独立 Python/Node/Chromium 字节向量。它不执行加密、网络或业务授权，也不冻结正式密码协议；本页门禁继续生效。

## 1. 当前实现与目标

当前 Gateway 提供可选 HPKE 请求加密（登录/注册/邮件验证码）和 Redis 防重放。响应、会话和其余业务调用尚不构成统一双向通道。现有 Theme SDK 和 `/gateway/v1` 是独立 current 协议；本契约不能悄悄改变其编码。

SC-v2 的目标是将 User/Admin Web API 的秘密请求和响应放入同一个经过认证的通道，防止错误操作绑定、密文重放与静默明文降级。HTTPS 必须保留；SC-v2 不防御可信页面中的恶意脚本、终端失陷或 Gateway 解密后的失陷。对密文日志的限制仍必须执行。

## 2. 安全域和协商

- User 与 Admin 有独立服务身份、密钥用途、Operation allow-list、Origin 策略、限流及 SDK 上下文。密钥发现元数据只提供公开钥匙和公开兼容信息。
- 协商在秘密调用之前完成，明确通道版本、套件、密钥版本、有效期和安全域；浏览器或 Node 不支持时拒绝秘密操作，禁止自动回退明文。
- key discovery 不授权任何操作；路由策略与 Core actor 权限独立执行。capability 或前端按钮显隐不授予权限。
- 当前旧客户端保留在明确隔离的旧契约入口，直到对应迁移门禁通过；不能把 B0 文档作为强制加密现行所有接口的发布指令。

## 3. 加密不变量

请求采用 HPKE，响应由同一请求上下文的 Exporter 派生方向独立的密钥，并采用经审查的 AEAD。请求与响应不能复用同一方向的 key/nonce；SDK 不得跨请求复用一次性 sender context。禁止自制不经协议审查的密码构造。

AAD/Exporter context 必须以规范化且无歧义的字节编码绑定：通道版本、套件、密钥 ID、安全域、Operation 版本、请求 ID、时间窗口、抗重放标识和传输目标。响应额外绑定原请求密文 digest、响应类型/状态；交换两个请求的响应或更改 Operation 必须解密失败。序列化需覆盖 Unicode、字段顺序、缺失/null、数字精度及长度边界，不能用未经规范化的 JSON stringify 猜测双方一致。

对外通道元数据仅携带定位/解密所必需的非秘密信息，不携带用户名、Token、权限或订阅归属。用户和管理员的 bearer 可以作为受保护内容交付 Gateway，但 Core 必须重新验证。请求 plaintext、Authorization 和 Exporter secret 不写日志、不进 tracing attributes 或通用异常回显。

## 4. 防重放与业务幂等

Gateway 在完全校验版本/用途/时间窗口并验证密文后，通过共享 Redis 原子消费抗重放身份，再调用 Core。时间偏差、nonce 碰撞、重复请求、Redis 故障一律拒绝受保护操作；重放存储 TTL 必须覆盖允许时间窗口及偏差。多副本不能各用内存去重。

SC 防重放不替代业务幂等。响应丢失时，SDK 使用新的合法加密请求和新抗重放标识，但保留原 `Idempotency-Key`；Core 返回原业务结果。不能复发同一密文绕过重放判定，也不能用新幂等键重复扣款。

解密前错误只返回有界非秘密错误，不回显密文或身份。解密成功后的业务成功/失败响应都走响应加密；无法加密时关闭调用并返回非秘密传输失败，不发送 plaintext 业务正文。

## 5. 密钥生命周期

密钥有用途、版本、发布/生效/停止新请求/撤销状态及审计记录；私钥由受限部署秘密机制交付，不进入镜像、仓库或共享 Theme 配置。轮换允许已发布的有界重叠窗口；吊销优先于缓存和未过期 key discovery。旧密钥终止后不继续解密旧请求。

密钥轮换、Redis 部署与 Core 服务证书轮换是独立流程，Deploy 按 [升级 Manifest](../upgrade-manifest/README.md) 编排兼容版本，不能把镜像降级当成密钥或数据库回滚。

## 6. B6 开工前仍须冻结的字节规范

| 项目 | 必须产出 | 缺失时的处理 |
| --- | --- | --- |
| 套件与实现 | HPKE mode/KEM/KDF/AEAD ID、Exporter 长度、各端可用库及版本 | 不启动生产密码协议实现 |
| 编码 | envelope Schema、base64url 规则、AAD/Exporter 字节向量 | 不开放 SDK 互操作 |
| 响应密钥 | 域分离标签、context、key/nonce 派生及一次性约束 | 不宣称双向加密完成 |
| 重放参数 | 窗口、偏差、TTL、唯一键、并发消费与故障策略 | 不启用秘密写 Operation |
| 错误 | 解密前后错误编码、错误响应 nonce 唯一性 | 不启用静默降级 |
| 审查 | 威胁模型、独立审查与测试向量 | 不放量 |

## 7. 验收

浏览器/Node 双向测试向量必须覆盖空正文、中文、超大值、AAD 改动、错 key/域/Operation、响应调包、并发重放、Redis 中断、时钟偏差、轮换/吊销与丢响应后业务幂等重试。除正常解密外，所有错误分支验证零 plaintext 业务泄漏、零 Core 越权调用。

真实 Core + MySQL + Redis + Gateway 的 User/Admin 身份隔离、写入重试、密钥恢复演练和上线审查通过后，才可登记为交付。单元测试、mock upstream、设计文件或模板示例不构成该证据。

[内部身份与 Operation](../core-internal-api/README.md) · [实施台账](../../../docs/architecture/v2/implementation-plan.md)
