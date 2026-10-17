# Gateway 加密、安全状态与实验边界

当前实现以 `apps/gateway/src/services/crypto.ts`、`services/redis-security.ts`、`contracts/schemas.ts` 和 `packages/theme-sdk/src/crypto.ts` 为准。本文保留现行协议及运行限制，不是生产密码学批准。

## HPKE v1 请求协议

- RFC 9180 base mode，`DHKEM(P-256,HKDF-SHA256) + HKDF-SHA256 + AES-256-GCM`；实现使用锁定的 `@hpke/core`。
- 固定 info 为 `TXBOARD-GW-V1-LOGIN-HPKE`。`GET /gateway/v1/crypto/key` 返回 `{protocol,suite,keys[]}`，只含公钥、`kid`、`scope` 与可选 `expiresAt`，绝不含私钥。
- `kid` 是 HPKE 序列化公钥 SHA-256 的前 24 位小写十六进制。SDK 每次加密前发现公钥，优先第一条合法、未过期且 scope 适用的 key；发现/加密失败直接报错，不自动改用明文。
- 密文 envelope 固定为 `{kid,ts,nonce,enc,ct}`；未知字段被 strict schema 拒绝。`ts` 是 Unix **毫秒**安全整数，接受 `abs(Date.now()-ts) <= 60_000`，不是秒。
- SDK nonce 是 CSPRNG 生成的 16 字节随机值（**128 bit 熵**），无填充 base64url 长度为 22；它是应用层 replay 标记，不是直接传入 AES-GCM 的 nonce。`enc/ct` 由规范 base64url 解码并受长度上限约束。
- AAD 固定 UTF-8 字节如下，最后的 session 占位及其换行不得省略。当前 HTTP 只使用 `login/register/email-code`，没有多步购买流程。

```text
txboard-gateway:v1\nPOST\n/gateway/v1/secure/auth/{operation}\n{kid}\n{ts}\n{nonce}\n-
```

篡改、跨操作移植、错误密钥或过期时间戳返回 400，不访问业务上游。解密 JSON 拒绝重复解码键及超过 30 层嵌套，再按操作 DTO 校验。

HPKE **只保护请求体**：公钥发现、响应、Bearer 和元数据仍需 HTTPS；可信 Gateway 解密后及 Core 仍可见明文。它不防主题 XSS/恶意脚本，不认证 HPKE 发送者身份，也不提供长期接收私钥泄露后的历史密文前向保密。不能把自制 MAC、路径混淆或共享前端密钥作为替代。

## Redis replay 与限流

成功解密后、任何 Core 副作用前，以 `SET NX PX` 原子登记 `kid:nonce`，TTL 为 **121000 毫秒（121 秒）**，覆盖两侧 60 秒接受窗并留 1 秒余量。

- 一个有效首次登记可继续；同密文重复请求为 409，零重复业务调用。
- Redis 不可用/未就绪为 503，敏感请求 fail-closed；没有进程内 Map 回退，也不能换明文重发。
- 原子性只在当前正常工作的共享 Redis 上成立；异步 failover、旧快照恢复、丢键和分区可能丢 replay 标记。AOF/副本不是跨故障强一致保证。
- 这是有限时间窗的字节重放防线，不是交易幂等。新 nonce 的相同业务意图仍由 Core 的权限、幂等及业务事务处理。

路由级 IP/账号双维滑动窗口与 `AccountLimiter` 的邮箱预算是两道独立防线。后者默认 login 8/60 秒、register 3/600 秒、email-code 2/600 秒；超额 429。路由配额、热重载与 Redis 故障策略见 [限流说明](rate-limiting.md)。Redis identity 摘要不等于匿名化或带秘密密钥的 HMAC。

## 密钥加载、轮换与吊销

**已实现**当前 key + 一个 previous key、旧 key 过期及 kid 吊销，不再把它们列为未实现 ADR。

| 配置 | 当前语义 |
| --- | --- |
| `GATEWAY_HPKE_MODE=disabled` | 默认；不加载密钥 |
| `GATEWAY_HPKE_MODE=optional` | 必须有 Redis 和持久 P-256 EC 私钥 JWK |
| `GATEWAY_HPKE_KEY_FILE` | 绝对 Docker Secret 路径；缺失/无效拒绝启动，不生成临时 key |
| `GATEWAY_HPKE_PREVIOUS_KEY_FILE` | 可选旧私钥的绝对路径 |
| `GATEWAY_HPKE_PREVIOUS_KEY_TTL_MS` | 默认 86400000，允许 60000..604800000；从本次加载时刻计算 |
| `GATEWAY_HPKE_REVOKED_KIDS` | 逗号分隔的 24 位小写十六进制 kid；非法格式拒绝启动 |

发现按当前 key、未过期 previous key 排序，过滤被吊销 key；解密先拒吊销，再匹配当前或未过期旧 key。吊销一个不存在的 kid 是 no-op。列表随配置加载，不是自动热加载；全副本需一致更新。旧窗口从启动/加载重新锚定，滚动重启可能延长接受期，不能当作全局固定截止时间。

轮换操作保持私钥只读挂载、UID 1000 可读且其他用户受限；先准备新 key，将原 key 配为 previous，同步受控副本与 Redis，验证发现及新旧请求；窗口结束后移除旧文件配置。发现 GET 当前允许公共短缓存，故不能承诺吊销配置更新后所有缓存立即同步。疑似泄露时拒绝受影响 kid、部署新 key，并核对全副本和缓存，不回退到已泄露 key。

丢失私钥的飞行密文不能恢复；用新 key 恢复新请求，不伪造已执行结果。吊销能阻止已加载该配置的服务器继续接受该 kid，但不能销毁攻击者已取得的私钥或过去捕获的密文。`scripts/generate-hpke-key.mjs` 只新建不覆盖；密钥及其备份不得进仓库、镜像或日志。

## 账户与业务范围

明文 `/auth/login` 是显式 TLS-only 入口，不是 HPKE 失败后的降级分支。加密登录需 HPKE；加密注册和邮箱码还需 `GATEWAY_ACCOUNT_WORKFLOWS_ENABLED=true`（默认 false）。Core 最终验证 CAPTCHA、密码、邮箱、邀请与账号归属。当前没有加密响应、快速购买、支付回调或 checkout/cancel 浏览器路由。

## 保留的离线 SC-v2 实验

以下均为独立 experimental 子路径，不由 Gateway HTTP/Core 业务导入，不替代 v1。

| 子路径 | 当前作用与限制 |
| --- | --- |
| `experimental/sc-v2-codec` | 元数据定长/长度前缀 framing、AAD/digest 和严格编码；不加密、不发现 key、不做 replay/actor 授权 |
| `experimental/sc-v2-crypto` | 离线 HPKE 请求 + exporter 分离的响应 key/nonce；每个 closure 单次使用，不代表同一请求跨 closure/worker 只有一个响应 owner |
| `experimental/sc-v2-response-salt` | 独立离线 salt 候选，接收端为每次响应生成随机盐派生 key/nonce；不是 OHTTP 线格式，也不证明业务 replay/恢复或熵源可靠性 |

codec 的时间字段是 Unix **秒**，窗口至多 60 秒、未来偏差至多 5 秒、严格过期；不要与 v1 毫秒 `ts` 混用。base HPKE 的语法合法元数据不是 actor 授权。实验拒绝 unknown/accessor/symbol 字段、快照输入并限制字节；closure 在反射/await 前消费，失败不恢复 context；不承诺 JavaScript 引擎秘密清零。

`sc-v2-crypto` 对同一 envelope 重新 open 会得到相同确定性响应材料，第二个 owner 不能再加密不同成功/错误响应。共享 owner 工程夹具及 Redis 丢键/故障限制见 [实验说明](../tests/experimental/sc-v2-shared-owner/README.md)：owner 校验与 WebCrypto 加密不是原子 fence，不能用于真实网络/业务消费者。salt 候选不消除 actor、admission、恢复、key trust/轮换/吊销及独立协议审查门禁。

当前实验字节契约见 [SC-v2 契约](../../contracts/v2/secure-channel/README.md) 和 [salt 候选契约](../../contracts/v2/secure-channel/response-salt-experiment-v1.md)。保留的 JSON 结果只证明其中记录的源码与环境，不是本批或生产批准。

## 验证入口与剩余证据

现有针对性用例包括 `gw215-hpke-rotation.test.ts`、`gw402-key-lifecycle.test.ts`、`gw403-hpke-kat.test.ts`、`gw404-replay-protection.test.ts` 及 `gw402-403-e2e.test.ts`；SDK 含 KAT、codec/crypto/salt 字节与浏览器检查。当前批次是否执行以实际 CI 记录为准，不沿用历史审查结论宣布现版通过。生产代理/PKI、多副本轮换与缓存传播、Redis 故障持久性、外部 CAPTCHA/邮件和正式密码学审核仍须提供匹配版本的证据。
