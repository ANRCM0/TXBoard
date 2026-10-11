# ADR：加密灰度协商、TLS-only 兼容与禁止静默降级（GW-405）

- 状态：**已提出，待评审**
- 日期：2026-10-11
- 责任：**GW**（Gateway 策略表/协商阶段门禁）+ **SDK**（theme-sdk 能力探测与加密优先，禁止回退）+ DEP（边缘 TLS 终止与流量观测）
- 关联任务：[task-backlog.md](./task-backlog.md) GW-405（本文档）；上游依赖 GW-401（威胁模型）、GW-402（密钥生命周期，双 kid 未完成）、GW-403（封装/AAD 契约）、GW-404（Redis 抗重放 fail-closed）；下游 GW-406（性能/故障注入/独立安全复核）、GW-407（可选响应体加密）；同级 GW-215（密钥轮换）、GW-217（Luma SDK 迁移）共享同一能力协商信号
- 前置依赖：GW-403/404 的 HPKE sealed 协议与 Redis 重放保护已在 main（技术预览，见 [app-crypto-preview.md](./app-crypto-preview.md)）；本文档只定义**协商与降级语义**，不改协议本身
- 依据：GW-405 验收原话"服务端强制策略生效；旧主题不被强制断线；敏感 operation 不会降级明文"

## 1. 背景与问题

- 现状（技术预览，已合并 #6/#7）：`POST /gateway/v1/secure/auth/login|register|email-code` 走 HPKE sealed 请求体（策略 `secureLogin/secureRegister/secureEmailCode`，`body: 'hpke'`）；明文兼容端点 `POST /gateway/v1/auth/login` 仍存在；register/email-code 仅 HPKE 且默认关闭（`GATEWAY_ACCOUNT_WORKFLOWS_ENABLED=false` 时路由整体缺席，404）。
- 现有保证：策略表（`config/policies.ts`）是编译期冻结的唯一权威，HTTP 头、主题 manifest、客户端能力、数据库主题配置**都不能**运行时改写；Redis 断连对加密敏感写 fail-closed（503，不回退进程 Map）；SDK `encryptedLogin=true` 时不回退明文。
- 未定义的三件事（本文档范围）：
  1. **灰度阶段语义**：明文 login 端点何时存在、何时收敛、收敛过程如何不打断旧主题；
  2. **能力协商协议**：服务端如何通告"本部署支持哪些加密 operation"，客户端如何声明支持，双方谁都不能凭头/manifest 放宽服务端策略；
  3. **禁止静默降级的完整边界**：任何一条"敏感 operation 因协商失败而回退明文"的路径都不存在——包括路由缺席、body 形状、Redis 故障、阶段过渡期。
- 核心风险：旧 Blade 主题（GW-217 迁移前）只认 `POST /gateway/v1/auth/login`；一刀切下线明文端点会强制断线；反之若把明文端点长期保留或让客户端"协商降级"，注册/验证码/登录凭据就会在应用层明文传输（TLS 终止于边缘时密文对中间链路不可见）。

## 2. 决策（Decision）

六条决策：**operation 分级为服务端策略属性（D1）；协商=服务端通告+客户端声明，服务端策略永不因客户端信号放宽（D2）；失败一律 fail-closed 拒绝（D3）；旧主题只在显式阶段切换后下线，且仅下线入口不做明文兼容转换（D4）；阶段由部署环境变量推进且只能单向收紧（D5）；SDK 禁止任何自动明文回退（D6）**。

### D1：operation 三级分级（服务端策略属性）

分级冻结在 `config/policies.ts` 的策略定义中，不随运行时协商变化：

| 级别 | operation | 路由/策略（现状） | 加密要求 |
| --- | --- | --- | --- |
| **必须加密** | login（凭据）、register、email-code | `secureLogin/secureRegister/secureEmailCode`（`body:'hpke'`）；明文 `login` 为**独立的显式兼容路由**，按 D5 阶段收敛 | 明文入口在最终阶段整体缺席；任何阶段都不存在"先明文再升级"的回退 |
| **可选加密** | profile / subscription / orders（用户只读） | `userRead`（`auth:'bearer'`，`body:'none'`） | TLS + Bearer 可接受；请求体加密为可选增强（响应体加密另行决策，GW-407） |
| **只读公开** | bootstrap / plans / theme-config / catalog | `publicRead` | 无加密要求 |

- "必须加密"的**唯一豁免是阶段（D5）内的独立明文登录路由**，它是一条显式声明的兼容路由，不是任何加密路径的 fallback 分支；`/auth/login` 与 `/secure/auth/login` 在路由表中是两个独立条目，任一存在的路由都以自己的策略执行，**请求不会在两个端点之间被改写或重试**。

### D2：协商协议——服务端通告 + 客户端声明，权威单边

- **服务端通告（已实现，扩展语义）**：`GET /gateway/v1/bootstrap` 的 `capabilities` 数组**按部署实际能力生成**（现有实现即如此）：HPKE 接线时追加 `auth.login.encrypted`；账户工作流解锁时追加 `auth.register.encrypted`、`auth.email-code.encrypted`；订单写启用时追加 `orders.create`。新增冻结语义：**capabilities 是 activeRouteDefinitions 的投影**——不存在于 active 表的 operation 绝不出现于 capabilities。
- **客户端声明（SDK）**：客户端"声明支持加密"= ① SDK 构造选项 `encryptedLogin=true`；② 调用前经 bootstrap/或 `/crypto/key` 探测服务端能力；③ 探测失败或能力缺失时**抛错，不降级**（现有行为，冻结为规范）。
- **服务端策略永不放宽**：客户端头（含预留的观测用头 `X-TXBoard-Crypto: hpke`）、主题 manifest、DB 主题配置**对策略判定零权重**。协商失败只有两种合法结果：客户端按低级别操作继续（TLS-only），或客户端收到拒绝——**不存在服务端因客户端信号切换明文策略的代码路径**。这与 middleware-architecture §3"策略表编译期只读"完全一致。
- `/gateway/v1/crypto/key` 仅 HPKE 接线时存在（现有实现）；其缺席本身即是"本部署不支持加密"的通告。

### D3：协商失败一律 fail-closed（禁止静默降级）

失败矩阵（NGF-1～8，验收负例）：

| 编号 | 场景 | 期望行为 | 现状依据 |
| --- | --- | --- | --- |
| NGF-1 | 明文 JSON 发往 `/secure/auth/*` | `400 VALIDATION_ERROR`（设计细化：明文 body 建议映射 `403 ENCRYPTION_REQUIRED`，与"畸形 sealed 信封"的 400 区分，便于客户端诊断），**零上游调用** | policy 中间件 `looksSealed` 拒绝 |
| NGF-2 | HPKE 未接线时调用 `/secure/auth/register` | 路由整体缺席 → **404**，零上游调用 | activeRouteDefinitions 过滤 |
| NGF-3 | HPKE 未接线时调用 `/auth/register`（猜测明文入口） | **404**——明文注册入口从未存在于路由表 | 路由表结构 |
| NGF-4 | `encryptedLogin=true` 但 `/crypto/key` 404 | SDK 抛错（`Unsupported Gateway encryption parameters`/404），**不重试明文** | SDK 现状，冻结 |
| NGF-5 | 密封信封 replay | `409`（Redis 原子 SET NX PX 首次消费） | GW-404 已实现 |
| NGF-6 | Redis 断连 + 加密敏感写 | **503 fail-closed**，绝不退回进程 Map/明文上游 | GW-404 已实现 |
| NGF-7 | 阶段推进到 P2 后明文 login | 路由缺席 → **404**，**不重定向、不重写到明文端点** | 本文档 D5（实现项） |
| NGF-8 | 客户端伪造能力头/manifest 声明加密支持 | 服务端判定不受影响；明文敏感请求依旧按 NGF-1/3 拒绝 | 策略表零头权重（D2） |

**铁律：**"降级"在本设计中只有两个合法方向——① 旧主题在 P0/P1 阶段**本来就跑在明文兼容路由上**（TLS-only，属设计内而非降级事件）；② 用户只读/公开操作无加密要求。任何"加密路径失败→换明文路径重发同一请求"的实现都是缺陷，不区分 flag、头、环境变量。

### D4：旧主题兼容——TLS-only 是显式模式，不是降级结果

- 旧 Blade 主题（迁移前）调用链：`bootstrap → plans → /auth/login → profile/orders`。GW-405 对该链的**保证**：P0/P1 阶段零改动可用，不强制断线；新增 capabilities 字段与 `/secure/*`、`/crypto/key` 路由是纯增量，旧客户端忽略未知字段（SDK 侧 BootstrapData 类型按附加字段扩展，向后兼容）。
- 旧主题的支持能力信号：响应头 `X-TXBoard-Crypto: tls-only`（预留，观测用途，非权威），供边缘/日志区分旧客户端流量；P2 切换前以"明文登录流量归零连续 N 天（默认 7）"为迁移完成判据。
- P2（必须加密）是**部署级动作**：设置 `GATEWAY_LOGIN_PLAINTEXT_ENABLED=false` → 明文 login 路由从 active 表缺席 → 旧主题收到 404。此时旧主题属于**未迁移客户端**，契约上必须走显式迁移（GW-217），不允许也不提供任何服务端明文兼容转换。旧主题的 TLS 安全基线（HSTS/证书校验/边缘策略）在切换前后不变——"只降级到 TLS-only"仅指 P0/P1 阶段旧主题的合法运行态。

### D5：灰度阶段（单向收紧，环境变量驱动）

| 阶段 | `GATEWAY_ENCRYPTION_PHASE`（默认 `tls-only`） | 明文 `/auth/login` | register/email-code | bootstrap capabilities | 适用场景 |
| --- | --- | --- | --- | --- | --- |
| **P0 tls-only** | `tls-only` | 存在 | 仅 HPKE，默认 off（现状） | 按现有能力投影 | 当前技术预览；旧主题默认运行态 |
| **P1 hpke-preferred** | `hpke-preferred` | 存在（响应加 `Deprecation`/`Sunset` 头，公布 P2 日期） | 同上 | 加密能力置顶通告；SDK 新主题默认 `encryptedLogin=true` | 新主题加密上线、旧主题继续可用；观测明文登录流量 |
| **P2 hpke-required** | `hpke-required` | **路由缺席（404）** | 仅 HPKE | 明文 login 能力不再通告 | 明文登录流量归零 + GW-406 独立安全复核通过后 |

- 阶段**只能单向推进**（`tls-only → hpke-preferred → hpke-required`），回退是新的部署动作（环境变量改回），等价于恢复到前一阶段——不是"降级加密强度"，而是恢复上一阶段路由表。**任何阶段 register/email-code 都不存在明文路由**（编译期结构保证，与阶段无关）。
- 阶段判定只来自部署环境（`PolicyEnvironment` 扩展一个 `encryptionPhase` 字段，默认缺省 = `tls-only`），不是请求头、不是主题配置、不是数据库。activeRouteDefinitions 在阶段过滤时**只做整条路由的缺席**，不做"同路由换策略"——保留现有"策略只从 disabledWrite→orderWrite 提升一次"的同构纪律。

### D6：SDK 禁止自动明文回退（冻结规范）

- `encryptedLogin=true`：`/crypto/key` 探测失败、能力缺失、seal/解封错误、`/secure/*` 返回 4xx/5xx → **抛 `GatewayApiError`，绝不重试 `/auth/login`**（现有实现即此语义，冻结为 ADR 规范）。
- `encryptedLogin` 缺省（false）：使用明文兼容路由——这是 P0/P1 阶段的**显式选择**，SDK 文档必须明示"仅适用于 tls-only/hpke-preferred 阶段"；P2 部署下调用将得到 404，SDK 应向调用方抛出可诊断错误而非静默重试。
- register/email-code 永远只有加密实现（`secureInvoke`），与阶段无关。

## 3. 协商时序图

```text
旧主题（TLS-only，P0/P1）                新主题（加密，P1+）
  │                                          │
  ├ GET /bootstrap ────────────────► capabilities（投影 active 表）
  │  （忽略加密能力字段）                       ├ 读到 auth.login.encrypted
  │                                          ├ GET /crypto/key → kid/suite
  ├ POST /auth/login ─────────────►  明文兼容路由（显式策略，P2 起缺席）
  │                                          ├ seal(AAD 绑定操作/kid/ts/nonce)
  │                                          └ POST /secure/auth/login ──► 解密→Laravel
  │                                          （任何失败：抛错；无明文重发）
```

- 服务端在两种路径上分别执行**各自的冻结策略**；两条路径之间没有代码跳转。

## 4. 错误码冻结（草案，实现时与 contracts/gateway-v1.md 同步）

| 场景 | 冻结码（草案） |
| --- | --- |
| 明文 body 发往 secure 路由 | `ENCRYPTION_REQUIRED`（403，区分于畸形信封的 `VALIDATION_ERROR` 400） |
| 加密能力未接线时的 secure 调用 | 维持 `NOT_FOUND`（404，路由缺席，不泄露能力细节） |
| P2 阶段明文 login | `NOT_FOUND`（404） |
| 重放 | `VALIDATION_ERROR` 409（现有） |
| Redis 故障 | `UPSTREAM_UNAVAILABLE` 503（现有） |

- 只新增**一个**错误码位（`ENCRYPTION_REQUIRED`）；不新增 HTTP 状态体系。

## 5. 验收测试矩阵（NCG，实现时的 TDD 输入）

| 编号 | 用例 | 期望 |
| --- | --- | --- |
| NCG-1 | 各阶段 bootstrap capabilities 与 activeRouteDefinitions 快照逐项一致 | 能力投影零漂移（含 `orders.create`、`auth.*.encrypted`） |
| NCG-2 | 明文 JSON 发往 `/secure/auth/login` | 403/400 拒绝，**零上游调用**（负例） |
| NCG-3 | HPKE 未接线部署下 `/secure/auth/register` 与 `/auth/register` | 均 404，零上游调用 |
| NCG-4 | `encryptedLogin=true` + `/crypto/key` 404 | SDK 抛错，mock 断言**零** `/auth/login` 调用（无静默降级核心断言） |
| NCG-5 | P1 阶段旧主题全流程（bootstrap→plans→明文 login→profile/orders，模拟 Laravel） | 全绿且不触碰 `/secure/*` |
| NCG-6 | P2 阶段明文 login | 404；断言无重定向/无路由重写；新主题加密登录全绿 |
| NCG-7 | 阶段推进/回退（环境变量切换）后 validatePolicies | 各阶段表合法；register/email-code 明文路由在任何阶段都不存在（结构断言） |
| NCG-8 | 伪造 `X-TXBoard-Crypto`/manifest 声明加密支持 | 明文敏感请求仍被拒（头零权重断言） |
| NCG-9 | Redis 断连 + 加密敏感写 | 503（复用 GW-404 用例，防回归） |
| NCG-10 | 旧主题在 P2 前的迁移判据：明文登录流量指标存在（边缘/Gateway metrics，脱敏） | `login_plaintext_total` 计数器（GW-214 观测体系同库） |

- 浏览器 E2E（Chromium，现有 HPKE 套件扩展）：新主题加密登录/注册/邮件 + 旧主题明文登录并存场景，对接受控模拟 Laravel。
- NCG 全绿 + GW-406 独立安全复核通过，才允许 `GATEWAY_ENCRYPTION_PHASE=hpke-required`。

## 6. 与相邻任务的边界

- **GW-402/215（密钥生命周期）**：双 kid 轮换/吊销未完成不影响本文档——协商信号与密钥版本独立（`/crypto/key` 的 keys 数组已支持多版本）。轮换落地后 capabilities 与 kid 发现协议不变。
- **GW-404（重放）**：nonce/时间窗/503 fail-closed 全部复用，本文档零改动。
- **GW-406**：本文档的 NCG 矩阵是 GW-406"退回/断连/时钟偏差"故障注入的前置输入。
- **GW-407（响应加密）**：只影响"可选加密"级别的未来升级，不改变必须加密级别的请求语义。
- **GW-217（Luma 迁移）**：旧主题迁移完成是 P2 的前置业务条件（明文流量归零），本文档提供该判据的指标。
- **GW-212/215 策略表纪律**：阶段过滤沿用"整条路由缺席"而非"路由内换策略"，与 `disabledWrite→orderWrite` 唯一提升点同构。

## 7. 风险与回滚

- **风险：阶段跳变误伤** → 阶段只单向收紧、回退=改环境变量恢复旧阶段路由表（同一部署动作）；P2 前强制明文流量归零判据（D4）。
- **风险：客户端误读 capabilities** → capabilities 是 active 表投影 + 快照测试（NCG-1）；SDK 探测失败即抛错（D6）。
- **风险：错误码新增破坏既有 SDK 解析** → 仅新增一个码位且走既有失败信封；旧 SDK 按未知 code 走通用错误路径（契约既有容错）。
- **回滚**：`GATEWAY_ENCRYPTION_PHASE` 回退到上一阶段即刻恢复路由表；无数据卷触碰、无密钥触碰、无幂等表触碰（纯路由与策略层变更）。撤销 `/secure/*` 反代等价于阶段回退到 `tls-only`。
- **未验收**：本文档为设计文档，未改任何代码/CI/测试；双 kid 轮换（GW-215）、真实联调（GW-216）、独立安全复核（GW-406）仍是 P2 前置。Gateway 基线 346/346 + SDK 32/32 不变（纯文档变更）。

## 8. 未决问题（评审输入）

1. `ENCRYPTION_REQUIRED` 用 403 还是维持 400 `VALIDATION_ERROR`？倾向 403（语义=需要加密能力，非请求畸形），需与既有错误字典评审确认。
2. `GATEWAY_ENCRYPTION_PHASE` 命名与 `GATEWAY_LOGIN_PLAINTEXT_ENABLED`（等价物）取哪个？倾向单一 phase 变量，避免两 flag 组合出非法状态（validatePolicies 可对组合做负例断言）。
3. P1 的 `Deprecation`/`Sunset` 响应头是否会影响旧主题对登录响应信封的严格 schema 校验（若有）？实现时用现有 E2E 回归验证。
4. 明文登录流量归零判据 N 天（默认 7）是否与站点运营节奏匹配？

## 附录：策略表扩展草案（实现时形态，非当前代码）

```ts
// PolicyEnvironment 新增（默认缺省 = tls-only）
encryptionPhase?: 'tls-only' | 'hpke-preferred' | 'hpke-required'

// activeRouteDefinitions 新增过滤（只做整条路由缺席）：
//   phase === 'hpke-required' → 过滤掉 policy.name === 'login' 的条目
// validatePolicies 新增静态断言：
//   - 任何策略下都不存在明文 register/email-code 路由（结构保证，回归用）
//   - 'login' 路由只在 tls-only / hpke-preferred 出现
```
