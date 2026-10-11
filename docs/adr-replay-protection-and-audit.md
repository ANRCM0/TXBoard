# ADR：抗重放保护与加密性能/安全复核设计（GW-404 + GW-406）

- 状态：**已提出，待评审**
- 日期：2026-10-11
- 责任：GW + Redis + 独立安全审查
- 关联任务：[task-backlog.md](./task-backlog.md) GW-404（本文档 §2–§4）、GW-406（本文档 §5–§7）；上游 GW-401（威胁模型）、GW-402（密钥生命周期）、GW-403（封装/AAD）、GW-405（灰度协商）；下游 GW-407（可选响应体加密）；同级 GW-215（双 kid 轮换）
- 前置依赖：GW-403 的 HPKE sealed 协议与 Redis 重放保护已在 main（技术预览，PR #6/#7）；`crypto.ts` `CryptoService.open()`、`redis-security.ts` `RedisSecurity.reserve()` 为本次复核的基线代码
- 现状依据：[adr-layer-encryption.md](./adr-layer-encryption.md)（T5 重放、T7 时钟偏差威胁）、[app-crypto-preview.md](./app-crypto-preview.md)、`apps/gateway/src/services/crypto.ts`、`apps/gateway/src/services/redis-security.ts`、`apps/gateway/test/gw215-hpke-rotation.test.ts`

## 1. 背景与问题

- GW-404 验收原话："并发相同请求 100 次只处理符合协议的首次；Redis 故障敏感写拒绝"。现有实现已具备机制（Redis 原子 `SET NX PX`），但**验收矩阵（含 100 并发、跨 kid 独立跟踪、nonce 格式负例）未文档化**，且 Redis HA（切主/丢数据）语义未定义。
- GW-406 验收原话："key rotation、时钟偏差、断连/退回、额外 p95、第三方/独立安全审查"。现有状态：S8–S11（轮换演练、时钟偏差、性能 p95、独立审查）全部**未做**，其中 S11 是 M4 Go/No-Go 阻断项。
- 两个待回答问题：
  1. **重放保护的确切边界**：±60s 窗口、nonce 唯一性、并发语义、Redis 故障语义分别在什么条件下成立，边界在哪；
  2. **性能代价与故障行为是否可验收**：HPKE 带来的附加延迟预算、故障注入的可复现测试、独立安全审查的范围与判据。

## 2. GW-404 决策（抗重放）

五条决策：**nonce 唯一性由 Redis 单点原子语义保证（D1）；时间窗 ±60s 且不可配置放大（D2）；"仅首次"= 一次成功的 `SET NX` 消费，其余全 409（D3）；Redis 故障一律 fail-closed 503、零进程内回退（D4）；Redis 切主丢数据窗口为已知残余风险，由 nonce 空间 + 账号限流 + HA 验收收敛（D5）**。

### D1：nonce 唯一性（Redis SET NX PX）

- 键：`txbgw:v1:replay:<kid>:<nonce>`（`redis-security.ts` 现状）。kid 命名空间隔离使双密钥轮换期间两个 kid 的重放空间互不碰撞（已测：`tracks replay separately per kid across key versions`）。
- 原子性：`SET key 1 NX PX <ttl>` 单命令完成"判存 + 占位 + 过期"，无 GET/INCR 竞态窗口，跨 Gateway 副本一致——副本数量与启动顺序对判定无影响。
- TTL = `replayWindowMs * 2 + 1000` = **121s**（代码现状），取值依据：`|now - ts| ≤ 60s` 的合法请求最迟在 ts+60s 后到达，键必须在"该请求还能通过时间窗校验"的整个时段内存活；60s 之后到来的同一 nonce 会先被时间窗（400）拒绝，键已无存在必要。
- nonce 格式约束（`sealedRequestSchema` 校验，现状）：定长 22 字符 base64url（132 bit 熵），伪造/空/超长 nonce 在 schema 层 400，**绝不进入 Redis 键空间**——键长度有界，不会出现无界键内存放大。
- **明文信封字段（kid/ts/nonce/enc/ct）不落日志**：Gateway 脱敏规则（GW-205/214）明令禁止；日志只记 requestId 与结果码，重放判定可在 `/metrics` 以计数器聚合观测。

### D2：重放窗口（±60s 时钟偏差容忍）

- 判据（`crypto.ts` 现状）：`Number.isSafeInteger(ts) && |Date.now() - ts| ≤ 60_000`，违反 → `VALIDATION_ERROR 400 Expired encrypted request`。
- 该检查在**解密之前**执行：时钟严重偏差的客户端在 Redis 接触前就被拒，不给攻击者用未来 ts 消耗 nonce 键空间的机会。
- 窗口**硬编码不可配置**：环境变量、HTTP 头、主题配置都不能放大它。这是设计纪律——窗口每放大一倍，"已捕获密文的可用时长"也放大一倍（T5 残余风险随窗口线性增长）。
- 窗口上界与下游一致：账号维度限流（login 5/15min、register 3/30min、email-code 2/30min）远宽于 60s，即"时间窗拒绝"不会成为限流之外的额外拒绝面；窗口下界与 AAD 绑定（ts 进 AAD）叠加——即使时钟校验被绕过（假设性），篡改 ts 也会使 GCM 认证失败 400。

### D3：并发相同请求——"恰一次"语义

- 语义定义：**N 个并发相同密封请求（同 kid/ts/nonce/enc/ct）→ 恰好 1 个通过重放层进入上游，其余 N-1 个收到 409，且零上游调用**。
- 正确性不依赖客户端串行：`SET NX` 在 Redis 服务端单线程原子执行，并发 N 个请求到达任意 Gateway 副本，N 个 `SET NX` 串行仲裁，唯一返回 `OK` 的那个放行。这消除"同进程两请求交错先判后写"的所有交错。
- 现有测试覆盖：64 并发 nonce 用例（#7 合并）+ GW-215 单 kid 重复拒绝；**GW-404 验收要求扩到 100 并发**（RP-1，见 §3）。
- 已知非目标：本机制防的是**字节级相同请求的重放**。若客户端每次提交生成新 nonce（协议允许，nonce 就是为此存在），相同业务意图的请求不会被此处拦截——那由账号/邮箱限流（`RedisSecurity.check`）与 Laravel CAPTCHA 拦截。重放保护与限流是两道独立闸门，不能互相替代。

### D4：Redis 故障 fail-closed（无进程内回退）

- `reserve()` 的每条失败路径（连接未就绪 `isReady=false`、命令异常、回复非 `OK/nil`）统一映射 `UPSTREAM_UNAVAILABLE 503 Replay protection unavailable`（代码现状）。
- **绝无进程内 Map/内存回退**：进程内回退在多副本下等于每个副本独立放行一次重放（N 副本 × 每次重放 = 破坏"恰一次"），且重启即丢失——比拒绝服务更糟。503 的代价（可用性）明确小于放行重放的代价（凭据重放撞库/验证码爆破）。
- 与限流 fail-closed 同构：`RedisRateLimiter.check` 对 `failClosed: true` 的策略（secure 登录/注册/验证码）同样 503；只读路由 fail-open 且标 `degraded`。加密敏感写的判定链是**两道 fail-closed 闸门串联**（限流 503 或重放 503 任一触发即拒绝），任一闸门故障都不会"半放行"。
- 与 GW-405 衔接：503 不会触发"换明文端点重发"——SDK 侧 `encryptedLogin=true` 遇 5xx 抛错（D6），服务端两路由独立无跳转。**Redis 故障期间加密端点不可用但明文端点不受牵连**（明文登录路由有自己的 fail-closed 链，Redis 故障时同样 503——两条链独立故障、独立恢复）。

### D5：Redis 切主/丢数据的残余风险（GW-406 前置）

- 风险陈述：Redis 异步故障转移（AOF 落后、切主丢写）可能丢弃已消费的 nonce 键 → 该 nonce 在"键丢失后"可被重放一次。
- 影响半径：**单次、单 nonce、且仍需落在 ±60s 时间窗内**（键丢失重放也要过 D2 的时间校验）；结合账号限流（同一 email 的重放撞不出配额）与 Laravel CAPTCHA 最终裁决，实际可利用性低但**不为零**——如实声明，不宣称"绝对不可重放"。
- 收敛路径（GW-406 故障注入 FI-4/FI-5）：Redis AOF `everysec` + 切主演练验证丢键窗口；演练期间观测 nonce 重放计数指标（`replay_rejected_total` 之外的 `replay_key_lost_suspected` 需新增告警规则）；HA 未达标前加密端点保持 `optional` 阶段（GW-405 D5），敏感操作由限流兜底。

## 3. GW-404 验收矩阵（RP 系列，实现时 TDD 输入）

| 编号 | 用例 | 期望 |
|---|---|---|
| RP-1 | 100 并发提交**同一**密封请求（同 kid/ts/nonce） | 恰 1 次通过重放层、恰好 1 次上游调用；其余 99 个 `VALIDATION_ERROR 409 Replayed encrypted request`（现有 64 并发用例扩到 100） |
| RP-2 | 100 并发，每次独立 nonce（合法客户端行为） | 100 次全部通过（重放层不误伤正常并发流量）；上游按业务处理 |
| RP-3 | 同一 kid+nonce 串行重放（非并发） | 第二次 409（已覆盖：gw215 rotation 用例） |
| RP-4 | 双 kid 期间：旧 kid 密文 + 新 kid 密文各自重放 | 两 kid 各自 409，命名空间独立（已覆盖） |
| RP-5 | Redis `reserve()` 抛错（连接断开模拟）+ 加密敏感写 | `UPSTREAM_UNAVAILABLE 503`，**零上游调用**，无进程内回退（现有 fail-closed 断言，回归保留） |
| RP-6 | Redis 未就绪（`isReady=false`）+ 加密写 | 503（与 RP-5 同语义两条路径） |
| RP-7 | ts = now+61s / now-61s | 400 `Expired encrypted request`，**不接触 Redis**（时间窗先于解密与 nonce 占用） |
| RP-8 | ts 非安全整数 / 缺字段 / nonce 非 22 位 base64url | schema 或校验层 400，不进入 Redis 键空间 |
| RP-9 | nonce 键 TTL 到期后同一 nonce 再提交 | 先被时间窗（>120s 后 ts 必然出窗）400 拒绝——TTL 与窗口的一致性断言（可用 fake clock 验证 121s 后行为） |
| RP-10 | 并发 100 × 跨 kid 混合（50 旧 kid / 50 新 kid，各自同 nonce） | 每个 kid 恰好 1 次通过、各 49 个 409 |

## 4. GW-404 状态结论

- **机制代码已实现并已有基础测试**（RP-3/4/5 覆盖）；本文档将其固化为验收矩阵，明确"100 并发恰一次"的验收口径（RP-1/2/10 为实现项）。
- 未验收项：RP-1/2/10 的 100 并发测试实现、RP-9 的 TTL-窗口一致性验证、D5 的 Redis HA 演练（并入 GW-406 FI-4/5）。

## 5. GW-406 决策（性能、故障注入、安全复核）

四条决策：**性能预算以"Gateway 附加延迟"计且含端到端 p95（D6）；故障注入为可复现的演练矩阵（FI 系列）而非一次性手工测试（D7）；独立安全审查为书面阻断项，清单范围冻结（D8）；轮换/时钟/断连三类故障各有明确判据与回滚路径（D9）**。

### D6：性能预算

| 指标 | 预算 | 测法 |
|---|---|---|
| HPKE 服务端解密（`open()` 全链路：P-256 ECDH 解封装 + AES-256-GCM + nonce 原子占用） | p95 **≤ 30ms**（Gateway 附加延迟，含 Redis 往返；预期实际 < 5ms，预算是保守上界） | 基准：同一客户端压测 `/gateway/v1/auth/login`（明文基线）vs `/gateway/v1/secure/auth/login`（HPKE），同并发、同机器、同一 mock Laravel，报告 p50/p95/p99 与绝对值差 |
| 客户端 SDK 密封开销 | p95 ≤ 10ms | SDK 侧基准（浏览器与 Node 各一组） |
| 公钥发现 `/crypto/key` | p95 ≤ 5ms，零密码学运算（序列化预计算） | 同基准组 |
| Redis `SET NX PX` 单项 | p95 ≤ 2ms | 压测中单列 |
| 回归口径 | HPKE 模式 p95 相对明文基线的**增量**记录在案（ADR GW-401 §5 的 < 2ms 是"加密运算本身"的估计，本 ADR 的 30ms 是含 Redis 的端到端附加预算——两者不矛盾，前者是分量后者是预算） | 报告归档 `docs/perf-hpke-baseline.md` |

- 压测环境要求：与 CI 隔离（CI 跑功能测试），压测跑在 staging 同构容器（GW-104 沙箱），数据不得用生产账号；并发梯度至少 10/50/100 三档。
- 超预算处置：p95 超 30ms → 定位分量（HPKE 运算 / Redis RTT / JSON 解析），Redis RTT 超支优先查连接复用与副本拓扑；仍超 → 上报评审，**不通过调大预算"达标"**。

### D7：故障注入矩阵（FI 系列）

| 编号 | 故障 | 注入方式 | 期望行为 | 判据 |
|---|---|---|---|---|
| FI-1 | **key rotation**：部署新 kid + 旧 kid 窗口内 | 双密钥文件 + `GATEWAY_HPKE_PREVIOUS_KEY_FILE/TTL_MS`（代码路径已存在） | ① 旧 kid 密文窗口内 200；② 发现端点返回 2 kid；③ 窗口过期后旧 kid 密文 400、发现端点 1 kid；④ 回滚（换回旧文件）恢复 | 每一步对 mock 上游的调用计数符合预期；无 kid 漂移 |
| FI-2 | **kid 吊销**：从发现端点移除 kid | 不加载对应私钥文件重启 | 新密文 400 `Unknown encryption key`；客户端重新发现后用新 kid 重试成功；**不静默降级明文**（零 `/auth/login` 重发断言，复用 GW-405 NCG-4 判据） | 吊销后已捕获旧密文的行为如实记录（±60s 内仍可用——吊销不追溯历史，ADR GW-401 §6.4） |
| FI-3 | **时钟偏差**：客户端 ±90s / ±300s | 篡改密封信封 ts（mock 客户端，不动系统时钟） | ±61s 起 400 `Expired encrypted request`，拒绝明确；窗口不放大；偏差客户端退化到明文端点行为明确（P0/P1 阶段可用，P2 404） | 拒绝消息含可诊断原因；无 5xx 混淆 |
| FI-4 | **Redis 断连**：kill 连接 | 关闭 mock/真实 Redis 客户端 | 加密敏感写 503 零上游调用（RP-5）；只读 fail-open 标 degraded；恢复后流量自动恢复无需重启 | 503 期间 `/metrics` 有 redisFailures 计数 |
| FI-5 | **Redis 切主/丢数据**：模拟 AOF 丢失 | 演练环境手工触发（`DEBUG SLEEP`/主从切换脚本），验证 nonce 键丢失窗口 | 丢键后同 nonce 重放被时间窗或限流拦截；`replay_key_lost_suspected` 告警可触发；HA 未达标前加密维持 `optional` | 记录丢键窗口时长；对账"允许的重放次数"为已知残余风险（D5），不宣称零 |
| FI-6 | **多副本不一致**：两副本不同密钥文件 | 滚动部署中途状态 | 未生效副本拒新 kid 密文 400（正确 fail 方向）；部署顺序文档化；绝不放行到明文 | 与 FI-1 联动，回滚即恢复 |

### D8：独立安全审查清单（S 系列，GW-406 硬性阻断项）

审查范围冻结为以下 12 项（GW-401 §7 清单的 GW-404/406 增量细化）：

| # | 检查项 | 证据 |
|---|---|---|
| S1 | 威胁模型 T1–T7 与残余风险显式接受 | GW-401 §3 |
| S2 | 密文可见边界与日志脱敏一致（无凭据/密文落任何中间层日志） | GW-401 §4 + GW-205/214 断言 + 反代配置（DEP 侧确认） |
| S3 | RFC 9180 标准套件 + `@hpke/core`，无自制密码学 | 代码事实 |
| S4 | AAD 负例：跨操作移植/kid 篡改/时间窗外/GCM 篡改全拒 | GW-403 测试 |
| S5 | 重放负例：100 并发恰一次（RP-1）、Redis 断连 503（RP-5） | RP-1/5 |
| S6 | 无算法降级路径（GW-405 NGF-1～8 矩阵） | GW-405 测试 |
| S7 | 私钥不出镜像/git/日志：`.dockerignore` + fail-hard + 日志断言人工走查 | docker-deployment + 审查记录 |
| S8 | 双窗口轮换演练 FI-1 全绿 | FI-1 记录 |
| S9 | 时钟偏差演练 FI-3 全绿 | FI-3 记录 |
| S10 | 性能增量实测记录（D6 口径，≤30ms 附加 p95） | perf 报告 |
| S11 | **独立密码学审查**：第三方或独立于实现者，覆盖 AAD 设计、nonce 空间（132 bit + kid 命名空间）、时间窗取值（60s/121s 推导）、fail-closed 语义 | **书面报告归档——M4 Go/No-Go 阻断项，未做** |
| S12 | 依赖审计：`@hpke/core` 版本锁定、无高危 CVE、SBOM 可复现 | lockfile + SBOM 工件 |

- 审查承担方（GW-401 §11 未决问题 3）：建议**独立于 Gateway 实现者的内部安全组**出具首轮书面结论；对外部审计的需求在 M4 结束后按合规要求评估。排期依赖此决策，本文档将其显式列为阻断项而非"建议"。
- 判据：S1–S12 全绿（S11 为书面通过）才向 opt-in 主题开放加密端点；S11 不通过则继续 TLS-only，**不阻塞非加密功能**（GW-401 Go/No-Go 语义延续）。

### D9：三类故障的回滚路径

- **轮换失误**（FI-1 异常）：旧密钥文件换回 `GATEWAY_HPKE_KEY_FILE` 即恢复，零数据迁移；窗口 ≥24h 保证缓冲。
- **性能超支**（D6）：`GATEWAY_HPKE_MODE=optional` 维持（功能默认关闭本就是 P0/P1 态）；加密端点保持可选，明文兼容路由不受影响。
- **安全审查不通过**：维持 TLS-only；`GATEWAY_HPKE_MODE=disabled` 完全摘除加密路径（GW-401 §10 回滚语义）。

## 6. 与现有实现的一致性核对（本次复核发现）

| 项 | 代码现状 | 本文档立场 |
|---|---|---|
| 窗口 | `replayWindowMs = 60_000` 硬编码（`crypto.ts`） | 符合 D2（不可配置放大） |
| TTL | `replayWindowMs * 2 + 1000` = 121s | 符合 D1 推导 |
| nonce 键 | `txbgw:v1:replay:<kid>:<nonce>`，`SET NX PX` | 符合 D1 |
| 故障语义 | 未就绪/异常/非 OK → 503 `Replay protection unavailable` | 符合 D4 |
| 解密前时间校验 | `open()` 先 `ts` 校验后解密后 reserve | 符合 D2（先 400 后接触 Redis） |
| AAD | `v1\nPOST\n/secure/auth/<op>\nkid\nts\nnonce` | 与 GW-401 一致；本文档不改协议 |
| 现有测试 | 64 并发（#7）、GW-215 双 kid 重放/轮换/吊销拒绝 | 基础上补 RP-1/2/10（100 并发）与 RP-9 |

**结论：现有代码与本文档设计无冲突；GW-404/406 均为"设计固化 + 测试/演练扩展 + 审查"工作，不需要改协议或密钥管理语义。**

## 7. 未决问题（评审输入）

1. 独立安全审查承担方（内部安全组 vs 外部审计）与时点——决定 S11 排期与 M4 时间表（D8）。
2. Redis HA 验收标准的具体数值（AOF 策略、切主演练频次、允许的丢键窗口上限）——D5/FI-5 的实现输入，与 DEP 仓库的 Redis 部署形态联动。
3. `replay_key_lost_suspected` 指标如何在不接触密文内容的前提下推导（建议：TTL 未过期但收到的时间窗内 409 之外的信号组合）——观测设计待定。
4. 压测基准机器规格与并发梯度终值——D6 执行输入，与 GW-109 staging 压测共用环境时需统一口径。

## 8. 风险与回滚

- **风险：503 成为加密写可用性新瓶颈** → fail-closed 语义是有意选择（拒服务优于放行重放）；Redis HA（D5）与只读 fail-open 分层控制爆炸半径；`disabled` 模式零影响。
- **风险：性能预算定义漂移**（"加密运算 <2ms"被误读为端到端预算）→ D6 明确两个口径（分量估计 vs 端到端预算），报告必须同时给出。
- **风险：审查拖延阻塞 M4** → S11 显式为阻断项而非隐式依赖；不通过即 TLS-only，功能路径清晰，不制造"待审状态"的模糊区间。
- **回滚**：GW-404/406 是验收与演练工作，本身无运行时代码；其输入（协议、密钥管理）的回滚路径见 GW-401 §10 / D9。
