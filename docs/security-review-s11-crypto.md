# S11 独立密码学审查报告（GW-406 / M4 Go/No-Go 阻断项）

- 日期：2026-10-11
- 审查方：独立于 Gateway 实现者的内部安全组（首轮书面结论，符合 D8 建议承担方）
- 审查对象：HPKE 应用层请求加密的四项密码学焦点 + 密钥生命周期 + AAD 篡改检测
- 基线代码：`apps/gateway/src/services/crypto.ts`、`packages/theme-sdk/src/crypto.ts`、`apps/gateway/src/services/redis-security.ts`、`apps/gateway/src/routes/account.ts`、`apps/gateway/src/middleware/policy.ts`、`apps/gateway/src/contracts/schemas.ts`
- 上游设计：[adr-layer-encryption.md](./adr-layer-encryption.md)、[adr-key-lifecycle-and-ae.md](./adr-key-lifecycle-and-ae.md)、[adr-replay-protection-and-audit.md](./adr-replay-protection-and-audit.md)

## 0. 审查范围与方法

本审查为**静态密码学审查**：只读代码、ADR 与既有测试断言，不修改任何实现，不改任何 CI/工作流，不做 git commit。判据为"实现是否与其冻结的密码学设计（ADR）在密码学上自洽、是否引入非标准/自制原语、拒绝路径是否覆盖全部篡改场景"。

覆盖焦点（D8 冻结范围）：

1. AAD 绑定完整性（六段 `method/path/session` + `kid/ts/nonce` 字节格式）
2. nonce 空间与熵（envelope nonce 与 AEAD nonce 的关系、碰撞概率）
3. 时间窗推导（±60s 窗口 / TTL=121s 的数学正确性）
4. fail-closed 语义（Redis 故障零进程内回退、双闸门串联）
5. 密钥生命周期（kid 派生、双版本轮换窗口、kid 吊销语义）
6. AAD 篡改检测（GCM 认证覆盖、跨操作/跨会话移植拒绝）

---

## 1. AAD 绑定完整性 —— ✅ 通过

**六段字节格式**（`crypto.ts:32-40` Gateway `buildAad()` / `theme-sdk/src/crypto.ts:69-71` SDK `encryptForOperation()`）：

```
txboard-gateway:v1 \n POST \n /gateway/v1/secure/auth/{operation} \n {kid} \n {ts} \n {nonce} \n {session}
```

逐段核对：

| 段 | 值 | 绑定作用 | 证据 |
|---|---|---|---|
| 协议版本 | `txboard-gateway:v1` | v2 密文不可在本代解 | `crypto.ts:11` `aadPrefix` |
| method | `POST`（前缀固定） | 不可挪到 GET/其他方法 | `crypto.ts:11` |
| path | 前缀 + `{operation}`（login/register/email-code 三选一） | 不可跨操作端点移植 | `crypto.ts:39` 拼接 operation |
| kid | 24 位 hex | 绑定密钥版本 | `crypto.ts:39` |
| ts | 十进制整数 | 双写防与信封分离 | `crypto.ts:39` |
| nonce | base64url | 与信封一致 | `crypto.ts:39` |
| session | 单步恒 `-`；多步为会话令牌摘要 | 防跨会话移植 | `crypto.ts:17` `singleShotSession` |

**结论要点：**

- 两侧 AAD 由**同一常量 `aadPrefix` + 同一拼接形态**生成，字节级一致；格式漂移 → 全部请求 400，无静默不一致。session 段为多步流程预留，单步恒 `-`，段与换行不省略（`crypto.ts:12-17` 注释与实现一致）。
- AAD 覆盖了"密文可被重投到的全部路由维度"：方法、路径、操作、密钥版本、时间、nonce、会话。攻击者无法在不重新持有私钥的情况下构造一个能通过 GCM 的 AAD。
- `info` 域分隔串 `TXBOARD-GW-V1-LOGIN-HPKE`（`crypto.ts:6`、SDK `:16`）两侧一致，冻结不改。

**残余风险：** 无（密码学层面）。

---

## 2. nonce 空间与熵 —— ✅ 通过（附 1 处文档精度修正）

**关键区分（本次审查的核心澄清）：** 协议里有**两个不同的 nonce，不可混淆**：

1. **envelope `nonce`**（`SealedRequest.nonce`，SDK `theme-sdk/src/crypto.ts:67`）：`crypto.getRandomValues(new Uint8Array(16))` = **16 字节 = 128 bit 真随机**，base64url 编码为 22 字符。它只用于 **Redis 重放键 + AAD 段**，是应用层去重令牌。
2. **AEAD nonce**：HPKE base 模式下，AES-256-GCM 的 nonce 由 RFC 9180 从 KEM 共享秘密**确定性派生**（sequence 0），**不使用** envelope nonce。每次 seal 生成全新 ephemeral 密钥对 → 全新共享秘密 → 全新 AEAD 密钥。

**为什么这是安全的设计（重要结论）：** 即便 envelope nonce 发生碰撞，AEAD 密钥也已因 fresh ephemeral 而不同——**不存在 AES-GCM 灾难性 nonce 复用**。envelope nonce 的唯一职责是重放去重，碰撞只影响"是否放行一次重复提交"，不影响保密性/完整性。这个分层是对的。

**熵与碰撞概率：**

- envelope nonce 真熵 = **128 bit**（16 字节），非文档所述 132 bit。22 个 base64url 字符理论上 132 bit 空间，但仅承载 16 随机字节 → 实际熵 128 bit。
- 生日碰撞界 ≈ 2^64；即使 10^9 请求/天，碰撞概率仍可忽略。schema 层强制**定长 22 字符 base64url**（`schemas.ts:39` `/^[A-Za-z0-9_-]{22}$/`），非 22 位/非规范化值在 schema 层 400，**不进 Redis 键空间**（RP-8），键长有界、无无界内存放大。
- Redis 键 `txbgw:v1:replay:<kid>:<nonce>`（`redis-security.ts:51-52`）以 kid 命名空间隔离，双密钥期间两 kid 重放空间互不碰撞（RP-4）。

**修正建议（非阻断）：** ADR（`adr-replay-protection-and-audit.md` §D1、`security-review-gw404-gw406.md` §3.2）将 nonce 熵表述为"132 bit 熵"，实际为 **128 bit**。结论（碰撞可忽略）不受影响，但建议后续文档统一为 128 bit 以求精确。

---

## 3. 时间窗推导（±60s / TTL=121s）—— ✅ 通过（数学正确）

**窗口判据**（`crypto.ts:186`）：`Number.isSafeInteger(ts) && |Date.now() - ts| > 60_000` → 400。即接受 `ts ∈ [now-60s, now+60s]`，且**硬编码不可配置放大**（`crypto.ts:18` `replayWindowMs = 60_000`，无 env 读取路径）。时间窗校验**先于解密与 Redis 占用**执行。

**TTL=121s 的数学正确性证明：**

设某密文时间戳为 `ts`，首次成功解封到达时刻为 `now0`（满足 `|now0-ts| ≤ 60`）。此刻创建重放键，TTL = 121s，键过期时刻 = `now0 + 121`。

该 nonce 的重放窗口关闭时刻 = `ts + 60`（此后 `now - ts > 60`，被时间窗 400 拒绝）。

**要求：键必须比窗口关闭时刻活得更久**，即 `now0 + 121 > ts + 60`，即 `now0 > ts - 61`。

由于 `|now0 - ts| ≤ 60` ⟹ `now0 ≥ ts - 60 > ts - 61`。**恒成立**，且最坏情况（`now0 = ts-60`，最早到达）键过期于 `ts+61`，比窗口关闭 `ts+60` 晚 **1s**。

**结论：TTL = 2×窗口 + 1s = 121s 是最小且充分的取值，推导严格正确，且恰好留 1s 安全余量。** 虚拟时钟测试 RP-9（`gw404-replay-protection.test.ts:299`）锁定"TTL 过期后同 nonce 必先被时间窗拒绝、零 Redis 接触"。RP-7（`:244`）锁定 ±61s 起 400 且不接触 Redis。

**残余风险：** 时钟偏差客户端在 ±60s 外不可用（T7，已如实声明，退化明文端点可用）。

---

## 4. fail-closed 语义 —— ✅ 通过

**双闸门串联，任一 Redis 故障即拒绝，无半放行：**

1. **限流闸门**（`redis-security.ts:59-101` `check`）：`ready()` 未就绪 → 503（`:44-46`）；命令异常 → 503（`:70` `:91`）；非安全整数回复 → 503（`:72` `:93`）。对 secure 登录/注册/验证码均 fail-closed。
2. **重放闸门**（`crypto.ts:221-227` `openWith`）：`reserve()` 抛错 → 503 `Replay protection unavailable`；非 OK → 409。

**零进程内回退（关键判据）：** `reserve()`（`redis-security.ts:48-58`）三条失败路径（未就绪/异常/非 OK）统一 503，**代码中不存在任何进程内 Map/Set 回退分支**。多副本下进程内回退 = 每副本各放行一次（N×重放），本实现正确避免。RP-5（异常，`gw404-replay-protection.test.ts:210`）、RP-6（未就绪，`:230`）分别覆盖两条路径，均断言 503 + 零上游调用。

**`SET NX PX` 原子性**（`redis-security.ts:51-53`）：单命令完成"判存+占位+过期"，跨副本串行仲裁，无 GET/INCR 竞态。`disableOfflineQueue: true` + `reconnectStrategy: false`（`:35`）确保故障期命令立即抛错而非排队——与 fail-closed 一致。

**与灰度衔接：** 503 不触发换明文端点重发（SDK 5xx 抛错，GW-405 D6）；服务端两路由独立无跳转。

**残余风险：** Redis 成为加密写的新单点（可用性下降，已如实声明）；D5 切主/丢数据窗口内单次重放（已知，HA 验收前置）。均为已声明的可接受残余风险，非密码学缺陷。

---

## 5. 密钥生命周期

### 5.1 kid 派生 —— ✅ 通过

`kid = hex(SHA-256(序列化压缩公钥))[0:24]`（`crypto.ts:114`、`:135`）= **96-bit 内容寻址 id**。同一公钥必得同一 kid；换钥自动换 kid。96-bit 空间的生日碰撞界 ≈ 2^48，对少量密钥完全可忽略。kid 本身无秘密，可安全出现在发现端点与日志。当前/旧密钥派生路径一致（`:114` vs `:135`）。

### 5.2 双版本轮换窗口 —— ✅ 通过（附 1 处运维观察）

- `publicKey()` 返回当前 + 未过期旧 kid（`crypto.ts:151-164`），keys[0] 恒为当前，SDK 优先取当前、回退旧（`theme-sdk/src/crypto.ts:54`）。
- `open()` 先当前 kid、再未过期旧 kid（`crypto.ts:197-203`），窗口内旧密文仍可解，窗口后 400。
- TTL 区间强制 `[60s, 7d]`（`crypto.ts:292` `intSetting`），越界 fail-hard（KL-9）。24h 默认覆盖 SDK 发现延迟，7d 上限限制旧私钥暴露面——权衡合理。

**运维观察（非密码学阻断）：** 旧密钥窗口锚定于**进程启动时刻**（`crypto.ts:293` `previousExpiresAt = Date.now() + ttlMs`），非密钥生成时刻。副本每次重启会**重新起算 24h 旧密钥窗口**。长驻进程无碍，但频繁滚动重启会反复延长旧私钥生命周期，间接放大 T6（旧私钥泄露暴露面）。建议运维侧约束"旧密钥文件部署后尽快收敛重启"，或在 Runbook 中记录该语义。属运维纪律，不阻断 Go。

### 5.3 kid 吊销语义 —— ✅ 通过（止血非消毒，边界如实）

- 吊销检查**先于任何私钥尝试**（`crypto.ts:192-194`）：被吊销 kid 一律 400，**含仍在旧密钥窗口内的 kid**——强制失效，不依赖窗口。符合 ADR D4。
- 被吊销 kid 立即从发现端点移除（`crypto.ts:171-175` `visibleVersion`），新客户端无法再用它加密。
- 吊销表非法 kid 格式 fail-hard 启动（`crypto.ts:250-262` `parseRevokedKids` + `:297`），防"以为吊销了其实没有"；吊销不存在 kid 为幂等 no-op。

**语义边界（设计已如实声明，审查确认正确）：** 吊销**不追溯历史密文**——已捕获密文不依赖发现端点，吊销对它无防御力。吊销 = **止血**（停新密文生成），非**消毒**（历史密文失效靠 nonce + ±60s 窗）。多副本一致性要求全副本滚动生效，未生效副本仍可解被吊销 kid——属已声明的部署时序残余风险。

**残余风险：** 吊销前已捕获密文在 ±60s 内仍可用一次（单次、低成本、可接受，T5/T6 已声明）。

---

## 6. AAD 篡改检测 —— ✅ 通过（覆盖全部场景）

**铁律验证：解封成功 = GCM tag 通过 + AAD 六段全匹配 + ts 窗内 + nonce 首占。任一失败 → 400/409/503，零上游调用。**

GCM tag 同时认证密文与全部 AAD。逐场景核对拒绝路径：

| 篡改/攻击场景 | 拒绝机制 | 证据/测试 |
|---|---|---|
| 篡改 `ct` 任一字节 | GCM 认证失败 → 400 `Invalid encrypted request` | `crypto.ts:215-218`；AE-1 |
| 篡改 `enc` | 解封装失败 → catch → 400 | `crypto.ts:210-218`；AE-2 |
| 篡改 `nonce` | AAD nonce 段不匹配 → GCM 失败 400 | `crypto.ts:215` |
| 篡改 `kid`（信封） | 未知 kid 400；或换到其他合法 kid → AAD kid 不匹配 GCM 失败 | `crypto.ts:192-204`；AE-5 |
| 篡改 `ts` | 窗外先被时间窗 400；窗内 → AAD ts 不匹配 GCM 失败 | `crypto.ts:186` + `:215`；AE-3 |
| **跨操作移植**（login 密文投 email-code） | AAD path 段（operation）不匹配 → GCM 失败 | `crypto.ts:39`；AE-4 |
| **跨会话移植** | AAD session 段不匹配 → GCM 失败 | `crypto.ts:39`；AE-6 |
| 跨方法/协议版本 | AAD method/version 段不匹配 → GCM 失败 | `crypto.ts:11` |
| 降级明文 body 发往 `/secure/*` | policy 层 `looksSealed` 拒绝 → 400 零上游 | `policy.ts:123-129`、`account.ts:269-270` |
| 非规范化 base64（构造同密文多表示绕过重放） | `fromB64` 往返编码校验拒绝 | `crypto.ts:70-75`；AE-9 |
| 诱导弱套件（伪造 alg/发现响应） | SDK `selectKey` 严格校验 protocol/suite/keys | `theme-sdk/src/crypto.ts:39-55`；AE-8 |
| 重放 | Redis `kid:nonce` 原子占用（解密后、上游前） | `crypto.ts:221-227`；RP-1~4 |

**结论：所有篡改/移植/降级场景均有明确的 400/409 拒绝路径，且全部在零上游调用前完成。** 时间窗校验先于解密（`crypto.ts:186` 早于 `:215`），时钟严重偏差客户端在接触 Redis 前即被拒，不给攻击者用未来 ts 消耗键空间的机会。base64url 规范化防止编码歧义绕过重放去重。

**残余风险：** 无（密码学层面）。

---

## 7. 总体结论与 Go/No-Go 建议

### 领域结论汇总

| 领域 | 结论 |
|---|---|
| AAD 绑定完整性 | ✅ 通过，六段覆盖全部路由维度，两侧字节级一致 |
| nonce 空间与熵 | ✅ 通过（真熵 128 bit 非 132，结论不变；AEAD nonce 由 HPKE 派生，无灾难性复用） |
| 时间窗推导 | ✅ 通过，TTL=121s 数学最小且充分，留 1s 余量 |
| fail-closed 语义 | ✅ 通过，双闸门串联，零进程内回退 |
| kid 派生 | ✅ 通过，96-bit 内容寻址 |
| 双版本轮换窗口 | ✅ 通过（附运维观察：窗口锚定进程启动，重启重算） |
| kid 吊销语义 | ✅ 通过，止血非消毒边界如实，先于私钥尝试拦截 |
| AAD 篡改检测 | ✅ 通过，覆盖全部场景，零上游调用 |

### Go/No-Go 建议：**GO（有条件通过）**

**理由：** 四项密码学焦点 + 密钥生命周期 + 篡改检测在密码学上**全部自洽且正确**：使用 RFC 9180 标准套件与成熟库 `@hpke/core`（无自制原语）；AAD 绑定完整；nonce 分层正确（无 GCM nonce 复用）；TTL 推导严格；fail-closed 无回退；拒绝路径覆盖全部篡改场景。**不发现任何阻断性密码学缺陷。**

**非阻断修正项（建议在 M4 收尾一并处理，不阻塞 Go）：**

1. **文档精度**：将 nonce 熵从"132 bit"更正为"128 bit"（真随机 16 字节），涉及 `adr-replay-protection-and-audit.md` §D1 与 `security-review-gw404-gw406.md` §3.2。
2. **运维 Runbook**：记录"旧密钥窗口锚定进程启动时刻、滚动重启会重算窗口"的语义，约束频繁重启放大旧私钥暴露面。

**既有已声明残余风险（审查确认可接受，维持接受）：** Redis 切主/丢数据窗口内单次重放（D5）；Redis 为加密写单点（fail-closed 有意选择）；吊销/多副本滚动时序（未生效副本短暂仍可解被吊销 kid）；时钟偏差客户端 ±60s 外不可用（T7）。

**开放项（不属本次 S11 范围）：** S1/S2/S7 人工走查签字、FI-2/FI-6 需演练环境的多副本项，按 `security-review-gw404-gw406.md` §2 跟踪。

**回滚语义不变：** 若后续任一批判项翻案，`GATEWAY_HPKE_MODE=disabled` 即完全摘除加密路径，维持 TLS-only，不阻塞任何非加密功能。

---

*本报告为 S11 独立密码学审查首轮书面结论。审查方未修改任何实现代码、CI/工作流，未做 git commit。*
