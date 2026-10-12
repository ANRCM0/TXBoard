# ADR：HPKE 密钥生命周期与标准封装/解封（GW-402 / GW-403）

- 状态：**已提出，待评审**
- 日期：2026-10-11
- 责任：**GW + DEP**（GW-402 密钥发布/轮换/吊销）、**GW + SDK**（GW-403 封装/解封与 AAD 契约）
- 关联任务：[task-backlog.md](./task-backlog.md) GW-402（本文档 §1–§5）、GW-403（本文档 §6–§9）、GW-215（双 kid 轮换，与本 ADR 共享同一实现）、GW-401（威胁模型，上游）、GW-404（Redis 抗重放）、GW-405（灰度协商）、GW-406（性能/故障注入/独立复核）、GW-407（可选响应加密）
- 前置依赖：GW-401 ADR（[adr-layer-encryption.md](./adr-layer-encryption.md) §6 密钥生命周期设计）已提交待评审；HPKE sealed 协议技术预览已在 main（`apps/gateway/src/services/crypto.ts`、`packages/theme-sdk/src/crypto.ts`）
- 现状依据：`crypto.ts`（Gateway）已实现 kid 派生、双窗口代码路径、fail-hard 加载、AAD 绑定；`crypto.ts`（SDK）已实现发现响应校验、密钥选择、seal。**双窗口运行时行为、kid 吊销登记、丢失密钥恢复 Runbook、跨浏览器测试向量尚未形式化——本文档为之定义目标行为与验收矩阵**

## 1. 背景与问题

GW-401 冻结了算法套件（RFC 9180 `DHKEM(P-256,HKDF-SHA256)+HKDF-SHA256+AES-256-GCM`）、AAD 语义与密钥生命周期骨架，但只到设计意图层。剩余两个 P0 任务需要把"能用"变成"可运维、可验收"：

1. **GW-402（密钥生命周期）**：公钥如何被发现与发布、私钥如何被安全保存、轮换怎么做到零感知零丢失、吊销怎么做到"新密文不可生成"、密钥**丢失**（文件误删、KMS 故障、全员误操作）后如何恢复。当前代码有双窗口代码路径，但没有轮换操作手册、没有吊销登记机制、没有恢复 Runbook、没有可验证的演练矩阵。
2. **GW-403（标准封装/解封）**：密文信封的字段级契约、AAD 的精确字节格式（含 session 绑定）、篡改/降级/错 AAD 的拒绝路径，以及**跨浏览器与 Node 行为一致的测试向量**——Chromium（WebCrypto 原生）与 Node（`@hpke/core` 原生）必须产出字节级互通的封装结果，否则 SDK 在 SSR 与浏览器两个运行时会出现"自己加密自己解不开"的事故。

## 2. 决策（Decision）

**GW-402 部分：**

1. **公钥发现保持现状契约**（D1）：`GET /gateway/v1/crypto/key` 响应结构 `{protocol, suite, keys[]}` 不变；`kid` = 序列化公钥 SHA-256 前 24 位十六进制（内容寻址，换钥自动换 kid）；响应永不含私钥分量。
2. **私钥保存 = Docker 文件 Secret + fail-hard**（D2）：`GATEWAY_HPKE_KEY_FILE` 绝对路径必存在、必合法；缺失/非法 → 进程拒绝启动，**绝不生成临时密钥**；`GATEWAY_HPKE_MODE=disabled` 时零密钥加载。KMS/Vault 信封加密为 M4 后目标态，本文档只定义接口预留，不实施。
3. **双版本轮换 = 24h 旧密钥窗口，配置驱动**（D3）：新当前 + 旧密钥（`GATEWAY_HPKE_PREVIOUS_KEY_FILE` + `GATEWAY_HPKE_PREVIOUS_KEY_TTL_MS`，默认 86_400_000ms，区间 60s–7d）；窗口内发现端点返回 2 个 kid、旧 kid 密文仍可解；窗口外自然失效。轮换不迁移数据、回滚成本为零。
4. **kid 吊销 = 独立于窗口的强制失效**（D4）：新增 `GATEWAY_HPKE_REVOKED_KIDS`（逗号分隔 kid 列表）。被列出的 kid：① 立即从发现端点移除（新密文无法再用它生成）；② `open()` 拒绝（即使仍在旧密钥窗口内）。吊销**不追溯历史密文**——历史密文防御靠 GW-404 的 nonce + ±60s 窗口（GW-401 T5）。
5. **丢失密钥恢复 = 新钥 + 客户端重发现 + 显式 Runbook**（D5）：私钥丢失 = 当前 kid 永久失效。恢复路径不是"找回密钥"，而是：生成新密钥 → 发布新 kid → 旧 kid 强制失效 → 客户端 SDK 按 §5.5 重发现重试。对"正在飞行中的密文"（提交瞬间丢失）不可恢复，如实声明。

**GW-403 部分：**

6. **信封契约冻结**（D6）：`{kid, ts, nonce, enc, ct}`，字段级 base64url 规范化 + 长度上限（§6.2）；**信封内不存在任何算法/版本字段**——算法由 kid 派生，客户端声明的任何 `alg/enc` 提示服务端零权重（结构性防降级）。
7. **AAD 冻结为六段格式**（D7）：`txboard-gateway:v1\n{HTTP方法}\n{固定操作路径}\n{kid}\n{ts}\n{nonce}\n{session}`——session 段为多步流程（如快速购买 GW-310）预留，login/register/email-code 固定为 `-`（空占位，字节格式固定，不省略换行）。
8. **完整性即拒绝**（D8）：任何信封字段篡改 → AES-256-GCM 认证失败 → 400 `Invalid encrypted request`，零上游调用；GCM tag 即完整性证明，不引入第二套 MAC。
9. **跨运行时测试向量**（D9）：维护一组**固定密钥、固定 ts/nonce 的 KAT 向量**（§9），Chromium E2E 与 Node 单元测试对同一向量产出字节级一致的 `ct`，并跨运行时解封对方产出——这是"跨浏览器/Node 测试向量通过"的验收定义。

## 3. 范围与边界

- **本文档范围**：GW-402 密钥生命周期（发现/发布/存储/轮换/吊销/恢复）、GW-403 封装/解封契约（信封/AAD/降级防护/测试向量）。
- **不在本文档**：Redis 抗重放（GW-404，本文档只引用其 §"解密成功后、上游调用前"的时序）；灰度协商与阶段（GW-405）；性能与故障注入演练（GW-406，本文档提供演练清单）；响应体加密（GW-407）。
- **兼容性**：所有信封字段与 AAD 格式与 main 现状**字节级一致**（§6 实现与现状对照表），本文档是形式化与补漏（吊销登记、session AAD 占位、测试向量），不改变任何现有密文的可解性。现有 `app.test.ts` 中"发现端点无 `d` 分量"负例断言与 SDK 发现测试全部保持。

## 4. GW-402：密钥生命周期

### 4.1 公钥发现与发布

- 端点与路由：`GET /gateway/v1/crypto/key`（策略 `cryptoKey`，publicRead；仅 HPKE 接线时 active——未接线时路由缺席 404，本身就是"不支持加密"的通告，GW-405 D2）。
- 响应（`KeyDiscoveryResponse`，与现状一致）：

  ```
  { protocol: "HPKE-RFC9180",
    suite: "DHKEM(P-256,HKDF-SHA256)+HKDF-SHA256+AES-256-GCM",
    keys: [ { kid, publicKey, scope, expiresAt? }, ... ] }
  ```

- **kid 派生**：`kid = hex(SHA-256(压缩公钥序列化))[0..24)`。含义：kid 即公钥内容寻址——同一 kid 必对应同一公钥，kid 表即公钥表；发现端点回显 kid 集合等价于回显"当前可用的公钥集合"。
- **keys 数组顺序即优先级**：当前密钥恒为 `keys[0]`（无 `expiresAt`），未过期旧密钥依次在后。SDK `selectKey` 取第一个合法项 = 当前密钥优先，旧密钥为轮换期回退。
- **scope**：`login-only` / `account-workflows`（`GATEWAY_ACCOUNT_WORKFLOWS_ENABLED=true` 时当前与旧密钥均标后者）。SDK 按 scope 过滤：非 login 操作只接受 `account-workflows` 密钥。
- **发布纪律**：
  - 发现端点是**唯一**权威公钥来源；主题代码、边缘缓存、bootstrap capabilities 都不携带公钥。
  - SDK 每次加密操作前发现（不长期缓存公钥），轮换/吊销即时生效；发现失败抛错，不回退旧 kid 缓存（GW-405 D6 冻结语义）。
  - 边缘/CDN 可对 `crypto/key` 做**短 TTL 缓存**（建议 ≤60s，与 ±60s 时间窗同量级），但部署层不得因缓存导致吊销 kid 停留超过一个窗口。

### 4.2 私钥安全存储

| 环境 | 载体 | 要求 |
|---|---|---|
| 开发/测试 | 本地文件 `secrets/hpke-key.json` | `.dockerignore` 排除 `secrets/`；`chmod 600`；绝不提交 git |
| 生产（当前） | **Docker 文件 Secret**：`GATEWAY_HPKE_KEY_FILE`（绝对路径） | 挂载只读；容器 UID 1000 可读、其他 UID 不可读；宿主目录不进镜像构建上下文 |
| 生产（目标，M4 后） | KMS/Vault 信封加密 | 私钥不出 KMS；Gateway 启动时用信封解密 JWK 落盘加载；本文档定义行为等价性要求，不实施 |

**fail-hard 硬规则**（现状代码断言，本文档冻结）：

1. `GATEWAY_HPKE_MODE` ∉ {`disabled`,`optional`} → 启动抛错。
2. `disabled` → 零密钥材料加载，攻击面为零。
3. `optional` 但 `GATEWAY_HPKE_KEY_FILE` 缺失/非绝对路径 → 抛错。
4. 文件不存在 / 非 JSON / 非 P-256 EC 私钥 JWK（缺 `d/x/y`）→ 抛错。**任何情况下不生成、不降级为临时密钥**——临时密钥会让重启后 kid 漂移、历史密文全废。
5. 私钥内容禁止出现在日志、错误消息、`/metrics`、`/readyz`（错误只报"invalid key file"级事实）。
6. 多实例必须共享同一私钥文件与同一 Redis（同 kid、同 nonce 空间），否则单实例无法解另一实例收到的密文。

### 4.3 双版本轮换（24h 窗口）

**目标：轮换期间零客户端感知、零密文丢失。**

**前置条件**：`GATEWAY_HPKE_MODE=optional` 已运行；新密钥 JWK 已用 `node scripts/generate-hpke-key.mjs` 生成（只新建不覆盖）。

**操作步骤**：

| # | 动作 | 验证点 |
|---|---|---|
| 1 | 生成新密钥 JWK，部署到新 Secret 路径（如 `/run/secrets/hpke_key_new`） | JWK 为合法 P-256 EC 私钥；kid 与旧 kid 不同（内容寻址保证） |
| 2 | 环境变量：`GATEWAY_HPKE_KEY_FILE=/run/secrets/hpke_key_new`（新当前）；`GATEWAY_HPKE_PREVIOUS_KEY_FILE=` 旧路径（旧密钥）；`GATEWAY_HPKE_PREVIOUS_KEY_TTL_MS=86400000` | 三个变量指向存在且合法的文件；TTL ∈ [60_000, 604_800_000] |
| 3 | **滚动重启 Gateway 全副本**（先滚 1 个副本验证，再滚其余） | ① `/crypto/key` 返回 keys 长度 = 2（新当前 + 未过期旧）；② 旧 kid 密文提交 → 200（窗口内仍解）；③ 新 kid 密文 → 200 |
| 4 | 观察一个完整窗口（24h） | 旧 kid 密文仍 200；发现端点旧 kid `expiresAt` 正确回显 |
| 5 | 窗口结束后自然生效：旧密钥不再出现于发现端点、不再可解 | ① 发现端点 keys 长度 = 1；② 旧 kid 密文 → 400 `Unknown encryption key` |
| 6 | 轮换审计记录：时间、新 kid 前 12 位、旧 kid 前 12 位、窗口时长、操作人（**不记私钥**） | 审计条目可追溯 |

**窗口内时序语义**（`open()` 顺序，与现状一致）：kid 匹配当前 → 当前私钥解；匹配旧且未过期 → 旧私钥解；否则 400 `Unknown encryption key`。

**窗口长度依据**：≥ SDK 最长离线重放延迟（默认 24h）。窗口每加大一倍，旧私钥泄露的暴露面也加大一倍——这是可调的安全-可用性权衡，**不得**超过 7 天。

**回滚**（任一步失败时）：把旧密钥文件换回 `GATEWAY_HPKE_KEY_FILE`、移除 `GATEWAY_HPKE_PREVIOUS_KEY_FILE`、重启——恢复单密钥态。轮换本身不涉及数据迁移、幂等表、Redis 状态，回滚成本为零，且回滚动作与"未发生轮换"在客户端侧不可区分（kid 回到旧值，发现端点重新回显旧 kid）。

### 4.4 kid 吊销

**触发条件**：私钥疑似泄露、合规要求、算法/实现降级预警。

**机制（新增，GW-402 交付）**：`GATEWAY_HPKE_REVOKED_KIDS` = 逗号分隔的 kid 列表（如 `a1b2c3d4e5f6...,f6e5d4c3b2a1...`），随环境变量滚动生效：

1. **发现端点移除**：被列出的 kid 从 `publicKey().keys` 移除（即使它在旧密钥窗口内）→ 新客户端无法再用它加密。
2. **拒解密**：`open()` 在任何私钥尝试之前先查吊销表 → 被吊销 kid 一律抛 400 `Unknown encryption key`，**包括尚未过期的旧密钥窗口内的 kid**。
3. **吊销清单校验**：吊销表加载时校验 kid 格式（24 位十六进制）；非法格式 fail-hard 启动（防止误拼导致"以为吊销了其实没有"）。吊销一个不存在的 kid 是合法 no-op（幂等，便于重复部署）。
4. **吊销审计**：时间、被吊销 kid 前 12 位、原因、操作人，落运维审计。

**灰度与客户端行为**：吊销瞬间，仍持有旧发现的客户端会提交被拒密文 → SDK 行为是**重新发现 key 后用新 kid 重试一次**；失败则抛错，**不静默降级明文**（GW-405 D6 冻结语义）。

**吊销的能力边界（重要，须写进 Runbook）**：

- 吊销**不追溯历史密文**：已捕获的密文不依赖发现端点，吊销对它无防御力。历史密文的唯一防御是 GW-404 的 nonce 原子占用 + ±60s 时间窗。
- 因此吊销的语义是"**止血**"（停止新密文生成），不是"**消毒**"（让历史密文失效）。疑似泄露时的正确顺序：先吊销，再轮换（§4.3），两者是独立动作。
- 多副本一致性：吊销是配置变更，必须随 Gateway 全副本滚动生效；未生效副本仍能解被吊销 kid 的密文——**吊销演练必须验证全副本生效**（§4.6 KL-6）。

### 4.5 丢失密钥恢复

**场景**：私钥文件误删、宿主卷损坏、Secret 误删、KMS（未来）故障。**丢失即永久**——找不到就是找不到，ECDH 私钥没有找回路径。

**恢复 Runbook**：

| # | 动作 | 验证点 |
|---|---|---|
| 1 | 确认丢失范围：哪些 kid 受影响（旧 kid 全丢 = 当前+旧窗口全部失效） | 审计与 kid 记录 |
| 2 | 生成全新密钥，走 §4.3 轮换流程，但把**丢失的 kid 全部列入 `GATEWAY_HPKE_REVOKED_KIDS`** | 发现端点无任何丢失 kid；丢失 kid 密文 → 400 |
| 3 | 全副本滚动重启，验证新 kid 全链路（发现→seal→提交→200） | 新客户端全绿 |
| 4 | 观测窗口：丢失 kid 的 400 `Unknown encryption key` 速率 | 客户端重新发现后归零（SDK 重试一次语义，§4.4 灰度） |
| 5 | 事件复盘：文件权限、备份策略、Secret 托管方审查 | 防范复发 |

**不可恢复的如实声明**：提交瞬间（seal 之后、Gateway 解密之前）私钥丢失的飞行密文**永久不可解密**，对应用户操作失败。客户端 SDK 的重试语义（重新发现→重密封→重发）对**新操作**有效，对"已提交失败的那一次操作"不重试（避免重复业务副作用，与 GW-302 幂等键配合）。这是该场景的天然代价：**飞行窗口 = ±60s 时间窗内的密文量**，量级极小。

**相关保障**：私钥文件应有独立备份（异地 Secret 副本或托管快照），备份本身遵守 §4.2 全部存储规则。

### 4.6 验收矩阵（KL，GW-402 TDD 输入）

| 编号 | 用例 | 期望 |
|---|---|---|
| KL-1 | 发现端点结构 | 符合 §4.1；响应 body 不含 `d`、不含 `private` 字样（现有断言保持） |
| KL-2 | 缺失/非法 key 文件启动 | 进程拒绝启动，零临时密钥生成（fail-hard） |
| KL-3 | 双窗口期 | 发现端点 2 个 kid；旧 kid 密文窗口内 200；SDK 优先用 keys[0] |
| KL-4 | 窗口过期后 | 发现端点 1 个 kid；旧 kid 密文 400 `Unknown encryption key` |
| KL-5 | 轮换回滚 | 换回旧密钥文件后，旧 kid 密文恢复 200，新 kid 不再被接受 |
| KL-6 | 吊销（窗口内 kid） | 被吊销 kid 从发现端点移除；其密文（窗口内）一律 400；其余 kid 不受影响；**全副本验证** |
| KL-7 | 吊销非法 kid 格式 | 启动 fail-hard；吊销不存在的 kid = no-op |
| KL-8 | 丢失密钥恢复 | 按 §4.5 Runbook：新 kid 全绿 + 丢失 kid 全 400 |
| KL-9 | TTL 越界 | `GATEWAY_HPKE_PREVIOUS_KEY_TTL_MS` < 60s 或 > 7d → 启动 fail-hard |
| KL-10 | 私钥零泄露面 | 日志/`/metrics`/`/readyz`/错误消息不含私钥分量（人工走查 + 断言） |

## 5. GW-403：标准封装/解封

### 5.1 协议栈（冻结）

- HPKE RFC 9180 **base 模式**（single-shot seal/open，无 PSK/多路复用扩展）。
- KEM `DHKEM(P-256, HKDF-SHA256)`、KDF `HKDF-SHA256`、AEAD `AES-256-GCM`；实现 `@hpke/core`（Node 与浏览器同一包，WebCrypto 后端）。
- `info` = 固定域分隔串 `TXBOARD-GW-V1-LOGIN-HPKE`（历史命名保留，字节与现状一致——**不得**修改，修改即全部历史密文作废）。
- 密文随机性来自 128 位 random nonce（envelope `nonce`，16 字节 base64url）+ HPKE sender ephemeral（envelope `enc`）——**nonce 复用风险为零**（每请求 16 字节全随机，碰撞概率可忽略；Redis 侧再加 kid:nonce 原子占用兜底，GW-404）。

### 5.2 信封契约（`SealedRequest`）

| 字段 | 类型 | 校验 | 说明 |
|---|---|---|---|
| `kid` | string | `^[a-f0-9]{24}$` | 定位私钥；不匹配任何受信 kid → 400 |
| `ts` | integer | 安全整数；`|now-ts| ≤ 60_000` | 时间窗；越界 → 400 `Expired encrypted request` |
| `nonce` | string | base64url 规范（往返编码一致）、≤ 64 字符 | 重放键 + AAD 分量 |
| `enc` | string | base64url 规范、≤ 500 字符 | HPKE ephemeral 公钥 |
| `ct` | string | base64url 规范、≤ 20000 字符 | 密文 + GCM tag |

- **信封无算法字段**：算法由 kid 派生（发现端点已在 protocol/suite 字段通告套件）。客户端提交的任何 `alg`/`enc`/`version` 提示字段服务端**忽略**（结构上不存在读取路径）——这是算法降级防护的第一层。
- **base64url 规范化**：`fromB64` 拒绝非 base64url 字符与"往返编码不一致"（如错误填充、非规范化位）的值——防止利用编码歧义构造同一密文的多个表示绕过重放检测。

### 5.3 AAD 绑定（冻结字节格式）

```
txboard-gateway:v1\n{method}\n{path}\n{kid}\n{ts}\n{nonce}\n{session}
```

| 段 | 值（现状操作） | 作用 |
|---|---|---|
| 协议版本 | `txboard-gateway:v1` | 协议升级隔离：v2 协议密文不可在本代解封 |
| method | `POST` | 密文不可从 POST 挪到 GET/其他方法 |
| path | `/gateway/v1/secure/auth/{login,register,email-code}` | 密文不可跨操作端点移植（GW-401 T4） |
| kid | 24 位 | 密文绑定密钥版本：轮换后用旧 kid 重投 → 认证失败（而非静默用旧私钥解） |
| ts | 十进制整数 | 与信封 ts 一致（双写防分离） |
| nonce | base64url | 与信封 nonce 一致 |
| **session** | `-`（login/register/email-code）；多步流程（如 GW-310 快速购买）为 16 字节 session 标识 base64url | 多步流程密文绑定会话上下文，防止跨会话移植 |

- **session 段设计**：单步操作恒为 `-`（ASCII 连字符，字节格式固定——**不得**省略段或换行，否则 AAD 字节位移与多步流程不兼容）。多步流程的 session 标识由服务端签发的流程令牌派生（**不是** raw token 本身，取令牌 HMAC/SHA 摘要前 16 字节 base64url），保证 AAD 不携带秘密但绑定会话。
- **格式即契约**：AAD 拼接代码在 Gateway 与 SDK **两侧**以同一常量 + 同一函数形态存在（现状即如此，本文档冻结）；任何一侧格式漂移 → 全部请求 400。回归测试（AE-4～AE-7）锁定每一段。

### 5.4 篡改、降级、错 AAD 的拒绝路径

| 攻击 | 拒绝路径 | 错误码 |
|---|---|---|
| 篡改 `ct`/`enc`/`nonce`/`kid`/`ts` 任一字节 | AES-256-GCM 认证失败 → `open()` 抛 `Invalid encrypted request` | 400，零上游调用 |
| 跨操作移植（login 密文投到 email-code） | AAD path 段不匹配 → GCM 失败 | 400 |
| 跨方法/协议版本移植 | AAD method/version 段不匹配 → GCM 失败 | 400 |
| 降级为明文 body 发往 `/secure/*` | policy 层 `looksSealed` 拒绝（GW-405 NGF-1） | 400/403（GW-405 草案 `ENCRYPTION_REQUIRED`） |
| 诱导客户端用弱套件（伪造 `alg` 字段、伪造发现响应） | SDK `selectKey` 严格校验 protocol/suite/keys 结构；服务端信封无算法字段 | SDK 抛 `Unsupported Gateway encryption parameters` |
| 错误 session AAD（跨会话） | session 段不匹配 → GCM 失败 | 400 |
| 过期 `ts` | 时间窗校验先于解密 | 400 `Expired encrypted request` |
| 重放 | Redis `kid:nonce` 原子占用（GW-404，解密成功后、上游调用前） | 409 |

**铁律：解封成功 = GCM tag 验证通过 + AAD 六段全部匹配 + ts 在窗内 + nonce 首次占用。任一环节失败 → 400/409/503，零上游调用。**

### 5.5 客户端封装契约（SDK）

1. 每次操作前 `GET /crypto/key`（不缓存公钥跨操作）。
2. `selectKey`：protocol/suite 精确字符串匹配；`kid` 格式校验；scope 匹配操作；`expiresAt` 过滤。
3. seal 前取 16 字节 `crypto.getRandomValues` 作 nonce；`ts = Date.now()`。
4. AAD 六段与 Gateway 完全一致；`JSON.stringify(data)` 为明文载荷。
5. 任何失败（发现 404/参数不符/seal 异常/`/secure/*` 4xx/5xx）→ 抛 `GatewayApiError`，**绝不重试明文端点**（GW-405 D6）。
6. SSR（Node）与浏览器（Chromium）同一代码路径，`@hpke/core` 双端 WebCrypto——§5.6 测试向量保证两端互通。

## 6. 跨运行时测试向量（AE，验收核心）

### 6.1 KAT 向量集

固定输入（**不随机**）：固定测试 P-256 密钥对 JWK、固定 `ts`、固定 16 字节 nonce、固定明文载荷 `{"email":"vector@example.test","password":"vect0r-pass"}`（仅测试夹具，非任何真实凭据）。

| 向量 | 输入 | 断言 |
|---|---|---|
| KAT-1 | 固定全部输入，Node 环境 seal | 产出 `{kid,ts,nonce,enc,ct}` 中 `enc`/`ct` 与**固定快照**逐字节一致（HPKE base 模式对固定 ephemeral 确定性——通过固定 ephemeral 私钥导入实现） |
| KAT-2 | 同一向量的 `ct`，Node `open` | 解回原明文；kid/ts/nonce 信封一致 |
| KAT-3 | 同一向量快照 `ct`，**Chromium** 环境 `open` | 解回同一明文（跨运行时互通：浏览器解 Node 产的密文） |
| KAT-4 | Chromium seal 的同一输入 | 产出与 KAT-1 逐字节一致（`enc` 一致证明 ephemeral 固定导入生效） |

- 快照存于 `packages/theme-sdk/test/vectors/hpke-kat.json`，随测试提交；任何实现/依赖升级导致字节漂移必须显式评审（漂移 = 行为变更，需重跑 KL 全矩阵确认兼容）。
- ephemeral 固定导入：`@hpke/core` 支持导入固定 DH 私钥作 ephemeral——用测试夹具私钥（非任何部署密钥）派生，保证 KAT 确定性。

### 6.2 负例矩阵（必须拒绝）

| 编号 | 用例 | 期望 |
|---|---|---|
| AE-1 | 篡改 `ct` 最后 1 字节 | 400 `Invalid encrypted request`，零上游 |
| AE-2 | 篡改 `enc` | 400，零上游 |
| AE-3 | 篡改 `ts`（GCM 外字段→先过时间窗则入 AAD 失败） | 时间窗外 400 `Expired`；窗内 400 `Invalid encrypted request` |
| AE-4 | 同密文改投不同 operation（AAD path 段） | 400 |
| AE-5 | 篡改 `kid` | 时间窗/AAD 任一失败 → 400 |
| AE-6 | 错误 session AAD（KAT + 会话段替换） | 400 |
| AE-7 | 明文 JSON body 发往 `/secure/auth/login` | 400/403，零上游（GW-405 NGF-1 复测） |
| AE-8 | SDK 收到伪造发现响应（suite 篡改） | SDK 抛 `Unsupported Gateway encryption parameters`，零提交 |
| AE-9 | 信封 `nonce` 非规范化 base64 | 400（往返编码校验） |
| AE-10 | KAT-1/3/4 快照回归 | 逐字节一致（实现升级防漂移） |

## 7. 与相邻任务的边界

- **GW-401**：本文档是其 §6 密钥生命周期的落地细化，威胁模型/AAD 语义/算法栈不变。
- **GW-404**：本文档的 open 时序引用其 Redis nonce 占用（解密成功后、上游调用前）；重放用例（AE 之外）以 GW-404 矩阵为准。
- **GW-405**：本文档的拒绝错误码（400/403）与其 NGF-1 草案 `ENCRYPTION_REQUIRED` 对齐；SDK 不降级语义引用其 D6。
- **GW-406**：本文档 §4.6 KL 与 §6.2 AE 矩阵是其故障注入与性能演练的前置输入（轮换演练、时钟偏差演练直接复用 KL-3～KL-5 步骤）。
- **GW-215**：双 kid 轮换的代码（`GATEWAY_HPKE_PREVIOUS_KEY_FILE` 路径）已存在，本文档提供其运维手册与验收；吊销登记（`GATEWAY_HPKE_REVOKED_KIDS`）是 GW-402 新增交付。
- **GW-310（快速购买）**：其 HPKE 编排载荷若走多步流程，session AAD 段按 §5.3 使用；本文档为占位设计，具体令牌派生格式由 GW-310 ADR 定稿后对齐。

## 8. 风险与回滚

- **风险：轮换窗口期内旧私钥泄露** → 暴露面 = 窗口时长（≤7d）；疑似泄露时先吊销（§4.4）再轮换，吊销不依赖窗口。
- **风险：吊销/轮换配置误操作** → 非法格式 fail-hard（吊销表/kid 校验）；回滚 = 恢复环境变量（零数据迁移）。
- **风险：私钥丢失导致飞行密文失败** → §4.5 如实声明；缓解 = 私钥独立备份 + 备份演练。
- **风险：实现升级导致 KAT 字节漂移** → AE-10 快照回归强制显式评审。
- **回滚**：`GATEWAY_HPKE_MODE=disabled` 完全摘除加密路径（GW-401 §10）；轮换/吊销为纯配置变更，回滚成本为零。

## 9. 未决问题（评审输入）

1. 吊销表用环境变量（滚动重启生效）还是 Redis 热加载（即时生效）？倾向**环境变量 + fail-hard**（与密钥文件同源同权威，热加载引入第二信任源）；若业务要求秒级吊销再议。
2. `GATEWAY_HPKE_REVOKED_KIDS` 与"不加载旧密钥"两种吊销形态是否都需要保留（前者窗口内强制失效，后者窗口外自然失效）？倾向**都需要**——前者是合规止血，后者是常规退场。
3. KAT 快照的存储位置与评审粒度（谁有权 regen 快照）？建议固定私钥与快照同 PR 评审。
4. session AAD 段与 GW-310 快速购买令牌派生的最终字节格式，待 GW-310 ADR 定稿后在本 ADR 追加附录。
