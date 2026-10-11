# TXBoard Gateway 开发状态台账

> 更新时间：**2026-10-11（GW-506/507 追加）**；旧阶段记录为历史快照，以下新增最新 main 代码核查。此文档记录代码和 CI，**不是生产就绪证明**。默认分支 main。当前不接入真实 TXBoard 环境，真实联调的验收暂按产品决策**延期**。

## 2026-10-11：GW-506/507 Next.js SSR 安全模板与版本协商（设计交付，未实施 Gateway 改动）

- 新增 [ssr-template-and-versioning.md](./ssr-template-and-versioning.md)（待评审）与 `examples/next-theme/` 骨架（Next.js 14 App Router 全 server component），并扩展 `examples/theme-template-check.mjs` 结构断言（TPL-1～5 覆盖 next-theme + SSR 专属断言，含负例验证，全部通过）。
- **GW-506（SSR 模板）**：请求级 `createClient()`（`getToken` 经 `next/headers` `cookies()` 读当前请求 cookie）——**禁模块级 SDK 单例**（SPA 模板的模块单例写法搬到 SSR 即捕获首个请求 token 并重放给所有后续用户=跨请求 session 污染，结构断言强制拒绝）；token 只存 httpOnly+secure+sameSite=lax cookie（登录 server action 服务端消费 `auth_data`，浏览器 JS 物理不可读，XSS 偷 token 路径结构性关闭；SPA 的 localStorage 方案在 SSR 下整体消除）；`'server-only'` 标记防 token 进客户端 bundle/RSC 载荷；strict CSP（`script-src 'self'`，对齐 GW-501 §6/TP-9）；与 Vue/React SPA 模板的安全语义（CAPTCHA fail-closed、capability 门控、幂等键、错误映射、safe-url）逐字节一致，唯一分叉=会话模型（见文档 §3.2 对照表）。
- **GW-507（版本协商）**：三维版本平面（Gateway HTTP 契约 `meta.version` 当前 '1' × SDK 包 0.1.0 × 主题 manifest `engines.gateway`/`gateway.contract` 构建期注入）独立 SemVer；`negotiateVersion()` 任一平面不符即 fail-closed 拒绝并渲染 `/version-incompatible` 页——**拒绝而非降级**（与 GW-405 禁止静默降级同构；协商先于第一个受保护调用，版本不符零上游调用）；SDK 层 `VERSION_MISMATCH` 为第二道闸（既有）；capability（bootstrap.capabilities 与 manifest capabilities）仅为 UI 可见性提示、对 Gateway 服务端策略零权重（GW-405/GW-501），真正授权=Gateway 路由策略。
- **验证**：运行时脚本（Node type-stripping + SDK dist）13/13 通过——NEG-1～6 版本真值表（含"无静默降级分支"断言）、capability 门控真值、SSR-1 双用户 token 隔离（交错并发两个 cookie 的 client，bearer 集合零交叉）；结构断言正例+负例（注入 `/api/v1` fetch 与模块单例均非零退出）通过；Gateway **391/391** + SDK **32/32** 已重跑维持。
- **未验收**：未真实 `next build`/联调（工作区未装 next/react，与 SPA 模板同等结构断言级）；NEG-9 真实 Gateway 契约升级演练与真实浏览器 CSP 验收待 GW-216/GW-508；`entry.type:'ssr'` 的 Runtime 安装策略仍属 GW-502 + [adr-theme-package-v2.md](./adr-theme-package-v2.md) §12 未决问题 1。**纯文档+examples 交付**：未改任何 Gateway/SDK 代码、未改 CI（`.github/workflows/` 零改动）、未接入 `npm test`、未 git commit。回滚=删除本文档与 `examples/next-theme/`。

## 2026-10-11：GW-508 发布/监控/回滚/兼容性总验收方案 + GW-509 第三方开发者文档（设计，未实施放量）

- 新增 [release-acceptance-gw508.md](./release-acceptance-gw508.md)（**GW-508 总验收方案，待评审**）：三发布对象（Gateway 镜像 / 主题包 v2 manifest / SDK npm tag）三段式总装与独立回滚面；**canary 分级 1%→5%→25%→100%**（分流在边缘/反代层，Gateway 内不做百分比分流器；优先按用户会话 cookie 固定两侧），每级观测时长（24h/48h/72h）与晋级/回退指标，canary 报告模板；**镜像 digest 固定**口径（基础镜像 pin sha256 抗 tag 漂移、发布镜像按 digest 部署并记入 release-checklist 台账、cosign 签验对象为 digest、回滚锚点=台账 digest；Dockerfile/compose 改动归 DEP 真实部署 PR）；**旧主题 v1 回归矩阵 RT-1～6**（核心：回滚态 = 发布前态；新旧订单路径同一 Laravel 派生幂等权威）；**SLO**：Gateway 端到端 p95 ≤30ms（[perf-hpke-baseline.md](./perf-hpke-baseline.md) 实测 9.09ms，余量 3.3×；超预算按 D6 定位分量，不调参达标）、写路径 p95 增量 ≤50ms、5xx ≤0.1%、幂等冲突率 ≈0（`IDEMPOTENCY_CONFLICT` 出现即回退，派生键下应不可能）、重复成单零容忍；**回滚三层**（边缘比例→0 秒级 / `GATEWAY_ORDER_WRITE_ENABLED=false` ≤5min / 主题切回 v1 或 SDK dist-tag 回指），T+0 止血四步（先止血后诊断）、零数据卷触碰、演练复用 `scripts/rollback-drill.sh` + 故障注入变体（Gateway kill / Redis 断连 / Laravel 慢响应）；兼容性矩阵 CMP-1～6（契约字节级兼容 / SemVer 纪律 / engines 拒绝非降级 / 三方可追溯）；总验收关卡 **GA-1～8** 逐项签署。**硬前置**：GW-503/507/216 未验收前分级全是零。
- 新增 [developer-guide.md](./developer-guide.md)（**GW-509 第三方开发者文档，待评审**）：心智模型四条铁律（唯一数据通道=SDK→`/gateway/v1`、不持管理员 token、manifest 声明≠授权、主题配置即外观）；五分钟上手（vue-theme/react-theme 模板 + 同源代理）；**从零创建主题七步可复现演示**（init → SDK 单实例 → bootstrap/能力门控 → 只读链路 + CAPTCHA fail-closed 登录 → TPL-1～5 结构断言自检 → 最小 `txboard-theme.json` + 打包纪律 → 八项验证清单），**全程只用普通用户凭据与公开接口**（不碰管理员后台/Laravel/数据库/支付回调）；SDK 契约速查；写请求幂等键生命周期（UUID v4 刷新前持久化、同键重试、禁止自动重发）；**v1→v2 迁移指南**（迁移动机、v1↔v2 字段映射表、八步流程、红线：不改 v1 行为/声明不成权限/私密禁令/离线打包）；FAQ（403/405/404/`VERSION_MISMATCH`/管理员 token 拒绝）。
- **纯文档交付**：两份文档 + task-backlog GW-508/509 状态更新；未改任何代码、CI（`.github/workflows/` 零改动）、测试，未 git commit。Gateway **391/391** + SDK **32/32** 基线维持（无代码变更触发条件，未重跑）。生产放量、真实联调（GW-216）、SDK 正式发布（GW-503）、Theme Runtime（GW-502）、版本协商（GW-507）仍为前置未验收项。

## 2026-10-11：GW-501/503 Theme Package v2 Manifest ADR 与 SDK 发布策略（设计，未实施）

- 新增 [adr-theme-package-v2.md](./adr-theme-package-v2.md)（待评审，同一文档覆盖 GW-501 §1–§7 与 GW-503 §8–§9）：
- **GW-501（manifest）**：独立 v2 manifest 文件 `txboard-theme.json`（`format:2` 防 v1 误装）与 v1（`config.json`+`dashboard.blade.php`）**并存、v1 加载器零改动**；冻结字段=标识 slug/语义版本/`engines`（txboard+gateway+contract 三维 SemVer 区间）、`entry.type`（spa/ssr）、编译产物与 `assets[].integrity`（sha256 SRI）、`gateway.contract:1.x` + 仅 UI 可见性提示的 capabilities、`config`（JSON Schema/defaults/public 候选公开字段）、作者/依赖（运行时依赖恒空、离线打包）/CSP（strict、禁远程代码、token 不入包不入 localStorage）。
- **关键零权重原则**：manifest 的 capability/版本声明只用于 Runtime 兼容判定与 UI 提示，对 Gateway 服务端策略**零权重**（延续 [middleware-architecture.md](./middleware-architecture.md) 与 [GW-405](./adr-encryption-negotiation.md) 冻结语义）；公开配置最终权威是 Laravel `ThemeService::getPublicConfig` 过滤，manifest 的 `config.public` 不可扩大公开集。Gateway 零代码零路由（不解包、不访问主题 FS、不信任主题代码），安装/校验/回滚由 TXBoard Theme Runtime（GW-502，独立 TXBoard PR）负责。
- **GW-503（SDK 发布）**：五环境兼容验收口径（npm pack dry-run/干净安装/浏览器 ESM/SSR 请求级 token 隔离/tsc 声明，多数已有 GW-103/105/106 基线）；SemVer 冻结（major=破坏性导出/线格式/错误码删除/engines 下限/Gateway 契约主版本弃支持且先发 beta；minor=纯附加；patch=无契约；0.x 期 minor 可 breaking；`1.0.0` 门槛=只读稳定集冻结，写路径不纳入承诺）；`KNOWN_ERROR_CODES` 只增不删；发版追溯 7 步（冻结→CHANGELOG→tag `theme-sdk/vX.Y.Z`→GitHub Release 记 commit/CI/provenance→`npm publish --provenance`→验证→dist-tag 回滚），tag 解析支撑 GW-507 三维协商。
- 验收矩阵 TP-1～9 + 安全评审 S1–S5；备选否决 5 项（扩展 v1 config.json / capability 即授权 / Gateway 解析包 / 先发 1.0 再冻结 / 远程依赖）；4 项未决问题（ssr 安装策略/manifest 签名/@hpke/core 改 peer/旧 major 支持窗口）。
- **纯文档变更**：未改任何代码、CI（`.github/workflows/` 零改动）、测试，未 git commit。Gateway **391/391** + SDK **32/32** 基线维持。Theme Runtime 实现（GW-502）与 SDK 实际发版仍待评审与后续任务。


## 2026-10-11：GW-405 加密灰度协商与兼容设计（设计，未实施）

- 新增 [adr-encryption-negotiation.md](./adr-encryption-negotiation.md)（待评审）：operation 三级分级冻结为服务端策略属性——**必须加密** login/register/email-code（register/email-code 任何阶段都无明文路由，编译期结构保证）、**可选加密** profile/subscription/orders、**只读公开** bootstrap/plans/theme-config。
- 协商语义：服务端 `bootstrap.capabilities` = activeRouteDefinitions 投影（能力字段零漂移）+ `/crypto/key` 缺席即"不支持加密"；客户端经 SDK `encryptedLogin` 声明；**服务端策略对 HTTP 头/主题 manifest/DB 主题配置零权重**——协商失败只有"客户端按低级别操作继续"或"被拒绝"，不存在服务端因客户端信号切换明文策略的路径。
- 禁止静默降级（fail-closed 矩阵 NGF-1～8）：明文 body 发往 secure 路由拒绝且零上游调用、HPKE 未接线时 secure 调用路由缺席 404、SDK 探测 `/crypto/key` 失败抛错且**禁止重发 `/auth/login`**（核心负例断言）、重放 409、Redis 断连 503。
- 旧主题兼容：TLS-only 是 P0/P1 阶段**显式运行态**（bootstrap 新增字段为纯增量，旧客户端忽略），不强制断线；灰度阶段 `GATEWAY_ENCRYPTION_PHASE` 单向收紧（`tls-only → hpke-preferred → hpke-required`，只做整条路由缺席、不做路由内换策略，与 `disabledWrite→orderWrite` 唯一提升点同构）；P2 前置 = 明文登录流量归零连续 7 天 + GW-406 独立安全复核。
- 验收矩阵 NGF-1～8（负例）+ NCG-1～10（含 capabilities 快照、阶段切换 validatePolicies、伪造能力头零权重、明文流量归零指标）；错误码只新增一个码位 `ENCRYPTION_REQUIRED`（403，草案待评审）。
- **未验收**：双 kid 轮换（GW-215）、真实联调（GW-216）、独立安全复核（GW-406）仍是 P2 前置；本文档为纯设计变更，未改任何代码/CI/测试。Gateway **346/346** + SDK **32/32** 仍全绿。

## 2026-10-11：GW-504/505 Vue 与 React 独立主题模板（文档交付）

- 新增 [theme-templates.md](./theme-templates.md)：**GW-504（Vue 3 + Vite，首选）与 GW-505（React 19 + Vite）两套独立主题模板的设计契约与完整源码骨架**，均只经 `@txboard/theme-sdk` 与 Gateway 通信（`baseURL: '/gateway/v1'`，同源 Origin 或按 `GATEWAY_ALLOWED_ORIGINS` 精确名单），**任何路径都不直连 Laravel**。
- 两模板共用同一安全语义：bootstrap 加载（`site/theme/security.captcha/capabilities`）、CAPTCHA 缺失时 fail-closed 阻止登录（enabled=true 而 siteKey 缺失时渲染禁用按钮而非静默放行）、登录后 `auth_data` 存 localStorage 并由 `getToken` 注入（SDK 自己不存 token、公开接口零 Bearer）、套餐/用户资料/订阅/订单只读渲染、`orders.create` 仅在 `capabilities.includes('orders.create')` 时出现且携带 `Idempotency-Key`（UUID v4，刷新前持久化）并禁止 UI 自动重试（写请求永不自动重试：`IDEMPOTENCY_IN_PROGRESS` → 以同一键退避后由用户重试、`ORDER_REJECTED` → 映射为 422 业务拒绝展示）、401/429/503/网络错误/超时统一错误 UI、`GatewayApiError` 的 `requestId` 展示给用户便于排查。
- 服务端 URL（背景图/logo/外链）一律经同源的 `safeWebUrl` 卫生函数白名单校验（禁止 `javascript:`/`data:`/凭据/跨源 http），主题配置 `theme.config` 仅作外观渲染不作权限依据；框架差异（Vue `<script setup>` + Composition API vs React hooks）只影响 UI 层，Gateway/SDK 调用与安全语义两侧字节级对齐。
- 两份模板源码骨架位于 `examples/vue-theme/` 与 `examples/react-theme/`（含 `useThemeBootstrap`/`useAuth`/`usePlans`/`useUserData`/`useOrders` composable 与 hook、全链路页面、CSS tokens、README），并配套 `docs/theme-templates.md` 验收矩阵（含 NGF 负例）与 `examples/theme-template-check.mjs` 结构断言脚本（护栏：模板禁止出现 `laravel`/`/api/v1`/`/txapi` 直连、必须引用 theme-sdk）。
- **未验收**：GW-503（SDK 正式发布/SemVer）与 GW-507（运行时版本协商/能力隔离）仍是前置；真实 Gateway 端到端联调（GW-216）与生产发布（GW-508）未做。本交付不改任何代码/CI/测试，Gateway **391/391** + SDK **32/32** 仍全绿。

## 2026-10-11：GW-401 应用层加密威胁模型与协议 ADR（设计，未实施）

- 新增 [adr-layer-encryption.md](./adr-layer-encryption.md)（待评审）：HPKE（RFC 9180 `DHKEM(P-256,HKDF-SHA256)+HKDF-SHA256+AES-256-GCM`，`@hpke/core`）请求体加密的正式威胁模型与密钥生命周期设计，范围为三类凭据提交操作（login/register/email-code），TLS 不可豁免、只加密请求体、响应加密归 GW-407 独立决策。
- 威胁模型 T1–T7（代理/反代明文可见、反代与 CDN 日志泄露、前端注入窃取、算法降级与密文移植、重放、私钥泄露、时钟偏差），逐条给出残余风险；密文可见边界：请求体全密封，`kid/ts/nonce/enc/ct` 为明文信封，元数据（大小/时间/路径）如实声明不可加密。
- TLS-only 对比：核心缺口是 TLS 终止点之后明文凭据穿过不受 Gateway 控制的日志层，属结构性缺口；本方案交换可审计的密钥生命周期与一次密码学审查，opt-in 默认 disabled、不影响旧主题。
- 密钥生命周期：`GET /gateway/v1/crypto/key` 公钥发现（kid = 公钥 SHA-256 前 24 位，绝不回显私钥分量）；私钥 Docker Secret 文件挂载、缺失/非法 fail-hard 且绝不生成临时密钥；双版本轮换窗口（默认 24h，旧密钥窗口内密文仍可解）；kid 吊销移除发现并拒解密，但**不追溯历史密文**（历史密文防御靠 nonce + ±60s 窗口）。
- S1–S12 安全评审清单（AAD 负例、100 并发重放、无算法降级、轮换/吊销/时钟演练、p95 增量 <2ms、独立密码学审查）；**S11 独立密码学审查是 M4 Go/No-Go 阻断项，未做**；否决备选含共享对称密码、路径/参数哈希、自制加密、全流量加密、Signal 双棘轮、客户端长期私钥。纯文档变更，未改任何代码/CI/测试，Gateway 346/346 + SDK 32/32 基线维持。

## 2026-10-11：GW-306 支付沙箱与对账 ADR（设计，未实施）

- 新增 [adr-payment-sandbox.md](./adr-payment-sandbox.md)（待评审）：支付回调处理全管线在 **Laravel**（验签 → 归属校验 → 金额严格相等校验（分，无容差）→ 幂等 → 状态条件 UPDATE `0→1` → 事务后异步交付），Gateway **零改动、零路由**；验收原话"支付回调不走 Gateway"以结构性负例落地——`config/policies.ts` 路由表与 `allowlistedPaths` 中本就不存在回调端点，新增断言要求猜测路径（`/gateway/v1/payment/notify` 等）404/405 且零上游调用，只读订单接口在支付完成前如实返回 `status=0`（适配层禁止乐观推断）。
- 幂等复用 GW-302 `idempotency_keys` 表（operation 扩展 `payment.notify`，不新建机制）；沙箱用例 **PS-1～12**：重复回调（恰 1 单迁移/1 条流水/1 次开通）、10 并发重复回调（唯一约束串行化）、异步晚到（期间保持 pending）、**主动查单恢复**（漏单：定时查单得 paid 后喂同一回调处理器，与迟到真实回调幂等等价）、**超时后支付**（订单已 cancelled 时 100% 不自动开通 + P0 人工队列）、支付失败、错误签名（验签失败零副作用）、金额篡改（少付/元分错位/多付一律拒绝 + 告警）、未知 trade_no（回 200 防重试风暴）、Gateway 边界、对账演练。
- 对账三层（L1 订单态 / L2 `payment_transactions` 流水 / L3 支付商日结，日终命令，不经 Gateway）：分级处置 **A 自动补偿**（漏单/滞后——铁律：补偿永远走同一回调处理器，不建直写旁路）、**B P0 告警**（金额不一致/重复扣款/签名失败——差异 >0 即联动冻结 GW-309 灰度晋级）、**C 人工队列**（超时后支付/归属无法判定）。
- **放量门禁**：PS-1～12 未全绿或对账差异非零时 `GATEWAY_ORDER_WRITE_ENABLED` 不得为 true（GW-309 G0 禁入条件之一）。Gateway **346/346** + SDK **32/32** 仍全绿（本次为纯文档变更，未改任何代码/CI/测试）。

## 2026-10-11：GW-312 交易邮件模板与通知 ADR（设计，未实施）

- 新增 [adr-transaction-notifications.md](./adr-transaction-notifications.md)（待评审）：事件驱动方案，四类交易事件（`order.created`/`order.paid`/`order.cancelled`/`order.refunded`）在 GW-305/306 交易状态机终态迁移与幂等行处产生，复用 TXBoard 既有通知服务与 SMTP 配置。
- 边界保持：Gateway/SDK **零代码零凭据**——不持有 SMTP 账号、不渲染邮件正文、不新增路由/上游 operation；Gateway 只可能透传事件类型标识（本次设计为完全零改动）。模板安全 = 强制 HTML 转义 + 字段白名单 DTO（`TransactionEmailEvent`）+ 明文密码/长效 token 禁令（一次性安全链接替代）+ 禁止远程 HTML URL 模板执行。
- 可靠投递：异步队列（事务提交后才入队，解耦交易）+ 指数退避重试（1s→5s→30s→5min→30min，上限 5 次 + 抖动防风暴）+ DLQ 告警 + `notification_emails.dedupe_key` 唯一约束去重（键由 GW-302 幂等行派生，不新建第二套交易幂等）；**SMTP 故障不回滚合法交易**（副作用隔离）；9 项故障注入 E2E 矩阵（重复回调/100 并发/SMTP 瞬时与持续失败/worker 重启/去重键冲突/模板 XSS 注入/凭据禁令/事务回滚不误发）。
- **未验收**：GW-303（Laravel 事务幂等）、GW-306（支付沙箱）与 GW-309（灰度）仍未做，交易邮件事件依赖其终态语义；本文档为纯设计变更，未改任何代码/CI/测试。Gateway **346/346** + SDK **32/32** 仍全绿。

## 2026-10-11：GW-309 订单写灰度与旧 API 绕过边界验收（设计，未实施）

- 新增 [order-write-rollout.md](./order-write-rollout.md)（待评审）：四级灰度 **1% → 5% → 25% → 100%**，分流在边缘/反代层（DEP 责任，优先按用户会话 cookie 固定两侧），Gateway 内不做百分比分流器；每次调比例为独立部署动作，比例归零等价于关闭 `GATEWAY_ORDER_WRITE_ENABLED`。
- 新旧路径对比：`POST /gateway/v1/orders`（`orderWrite` 策略，默认 `disabledWrite` 405）与 `POST /api/v1/user/order/save`（旧路径，永不关闭、渐进迁移）**权威幂等键同为 Laravel 以认证 user_id 派生**（`SHA-256(user_id‖order.create‖canonical body)` + `idempotency_keys` 唯一约束），Gateway 的 `X-Idempotency-Key-Hint` 仅关联非权威——灰度只改入口不改权威，这是可安全放量的前提。
- 旧 API 绕过边界测试 8 项（BYP-1～8，沙箱直连 curl）：旧路径基线、同 body 两侧交叉重放必须返回**同一 trade_no**、50+50 混合并发恰一单、伪造客户端 user_id 被 strict schema 拒绝、提示头篡改仅记日志不影响判定、TTL 过期按新请求。
- 监控指标：写错误率/p95 增量对比旧路径基线、幂等冲突率 ≈ 0（派生键设计下 `IDEMPOTENCY_CONFLICT` 应不可能出现，出现即实现 bug）、重复成单率零容忍、日终对账零差异。回滚演练判据：止血 ≤ 5 分钟、零数据卷触碰、故障注入变体（Gateway kill / Redis 断连 / Laravel 慢响应）。
- **未验收**：G0 禁入条件（GW-303/305/306 真实沙箱验收 + 回滚演练记录）未解除前 `GATEWAY_ORDER_WRITE_ENABLED` 不得为 true。Gateway **346/346** + SDK **32/32** 仍全绿（本次为纯文档变更）。

## 2026-10-11：GW-310 快速购买一体化 ADR（设计，未实现路由）

- 新增 [adr-quick-purchase.md](./adr-quick-purchase.md)（待评审）：快速购买 = Laravel 编排 + Gateway 透传 + Purchase Ticket（一次性签发的用户本人令牌）。新增候选路由 `POST /gateway/v1/quick-purchase`，默认 `disabledWrite`（405、零上游调用），`GATEWAY_QUICK_PURCHASE_ENABLED` 且 `GATEWAY_ORDER_WRITE_ENABLED=true` 双 flag 才评估开启（未设则路由缺失 = 404）；HPKE sealed 编排载荷（registerSchema ∪ orderCreateSchema 字段并集，strict）+ `Idempotency-Key` 透传 + `X-Idempotency-Key-Hint` 自派生。
- 冒领防线：Laravel 单一事务内按邮箱分支——未注册→现场注册（邀请码/邮箱验证/风控/Laravel CAPTCHA 全开），已注册→`LOGIN_REQUIRED`（409）且**零 token 签发**，老用户走受控 HPKE 登录后用同一 `Idempotency-Key` 对普通 `POST /orders` 下单；两分支响应外壳字节级一致（存在性信号评审确认）。
- 幂等沿用 GW-302 `idempotency_keys` 表（operation 新增 `quickpurchase.checkout`，三态状态机、24h TTL、15min 陈旧 CAS 接管），**不新建第二套幂等机制**；`auth_token` 不落快照明文（token_ref + 重签等价令牌）；支付回调路径不变（GW-306 状态机吸收重复回调，快速购买只是多一条订单创建入口）。
- 边界保持：Gateway 不持有管理 token、不代建用户、不复制业务规则、不做本地去重；限流/CAPTCHA 判定在幂等之前（负例测试要求）。
- **未验收**：GW-303（Laravel 事务幂等）、GW-306（支付沙箱）与 GW-309（灰度）仍未做，快速购买在其后 opt-in；本文档为纯设计变更，未改任何代码/CI/测试。Gateway **346/346** + SDK **32/32** 仍全绿。

## 2026-10-11：GW-305 checkout/cancel 交易状态机 ADR（设计，未实现路由）

- 新增 [adr-checkout-cancel.md](./adr-checkout-cancel.md)（待评审）：复用 GW-302 派生幂等键与 `idempotency_keys` 表（operation 扩展 `order.checkout`/`order.cancel`，不新建幂等机制）；订单业务态状态机 `0 pending → 1 paid（checkout 成功）/ 2 cancelled（cancel，仅 pending 可取消）`，`1/2/3` 为终态锁定。
- 不可重复扣款论证：状态条件 UPDATE（`status=0` 守卫）+ 扣款条件 UPDATE（`balance >= ?` 守卫）+ 优惠券条件 UPDATE（`used < limit` 守卫）+ `idempotency_keys` 唯一约束，四者在同一 MySQL 事务内，任一失败整体回滚；重放同键同参数在幂等层返回首次快照，到不了扣款。
- Gateway 侧**仅设计不实现**：候选 `POST /gateway/v1/orders/:tradeNo/checkout|cancel`（`body:'none'`、无金额字段、默认 disabledWrite 405、独立 flag 评审中）；错误码位冻结增补 `ORDER_STATE_INVALID`(409) / `ORDER_NOT_FOUND`(404，不泄露存在性)，`INSUFFICIENT_BALANCE`/`COUPON_*` 复用 `ORDER_REJECTED`(422)；资金写限流 fail-closed（checkout 5/账号 15 分钟）。未改动任何代码文件。
- **验证（纯文档变更）：** 本次仅新增/修改 docs 下 Markdown，无任何代码与 CI 改动，Gateway 346/346 基线维持（未重跑，无代码变更触发条件）。**未验收**：GW-303（Laravel 事务幂等）、GW-306（支付沙箱）与 GW-309（灰度/回滚，设计文档 order-write-rollout.md 已提交待评审，G0 禁入条件未解除）仍未做；本文档为设计输入，生产放量门禁不变。

## 2026-10-11：GW-304 订单写入适配层（默认关闭）已实现

- 新增 `routes/orders-write.ts`（`orderWrite` 策略，仅 `GATEWAY_ORDER_WRITE_ENABLED=true` 时挂载）与 `middleware/idempotency.ts`；策略表新增 `orderWrite`，`POST /gateway/v1/orders` 默认仍为 `disabledWrite`（405、零上游调用）。
- 边界保持：Gateway 不持有管理 token、不复制业务规则、不做本地去重——透传用户 Bearer、透传客户端 `Idempotency-Key`、自派生 `X-Idempotency-Key-Hint`（仅关联用，Laravel 以认证 user_id 派生权威键）。无幂等键 400 且零上游调用；`IDEMPOTENCY_IN_PROGRESS`/`IDEMPOTENCY_CONFLICT` 透传为 409、业务拒绝映射 `ORDER_REJECTED`（422，不回显上游原文）。
- **未验收**：GW-303（Laravel 事务幂等）、GW-305/306（状态机与支付沙箱）与 GW-309（灰度/回滚）仍未做；本交付只是 Gateway 侧透传适配，生产放量门禁不变。Gateway **346/346** + SDK **32/32** 全绿。

## 2026-10-09：主分支 CI 回归已修复

- Gateway main 基线 `94b3b9e` 的 `verify` 曾失败：`policies.test.ts` 的 “performs zero upstream calls for POST /gateway/v1/orders (disabledWrite)” 期望 **405** 实际 **401**。
- 根因：`app.ts` 的 CORS 凭证检查层在静态 `policyEnforcementMiddleware` 之前，对无 Origin 的 `/gateway/v1/orders` 先返回 401，抢在 `disabledWrite` 的 405 之前。
- **已修复并合并：** [PR #16](https://github.com/ANRCM0/TXBoard-Gateway/pull/16)（main `ba894d6`）让凭证检查层查询冻结策略表，`disabledWrite` 路由交由策略层裁决 405（零上游调用）。修复后 Gateway **202/202** + SDK **9/9** 全绿。

## 一、合并记录与证据

| PR | 合并范围 | 最终代码证据 |
| --- | --- | --- |
| [#4](https://github.com/ANRCM0/TXBoard-Gateway/pull/4) | Laravel 契约初步对齐、验证码公开配置、登录过滤、响应校验 | [CI 37792938219](https://github.com/ANRCM0/TXBoard-Gateway/actions/runs/37792938219) |
| [#5](https://github.com/ANRCM0/TXBoard-Gateway/pull/5) | Node 22/lockfile/npm ci、Chromium 模拟 E2E、Staging 模板 | [CI 37795581303](https://github.com/ANRCM0/TXBoard-Gateway/actions/runs/37795581303) |
| [#6](https://github.com/ANRCM0/TXBoard-Gateway/pull/6) | Docker-first、应用只读 DTO、HPKE 密文登录、私钥隔离 | [CI 37799429771](https://github.com/ANRCM0/TXBoard-Gateway/actions/runs/37799429771) |
| [#7](https://github.com/ANRCM0/TXBoard-Gateway/pull/7) | Redis 原子 nonce、邮箱维度限流、加密注册/邮箱验证码、账户与订单状态 | [CI 37801251835](https://github.com/ANRCM0/TXBoard-Gateway/actions/runs/37801251835) |

合并：#6 → main `de45e3b`；#7 → main `9e5b3c1`。之后文档变更见 Git 历史。主分支最终 CI 状态以 [Actions](https://github.com/ANRCM0/TXBoard-Gateway/actions) 当前记录为准。

## 2026-10-09 合并进度更正（优先于 10/8 历史叙述）

- [PR #10](https://github.com/ANRCM0/TXBoard-Gateway/pull/10)：Routes/Adapters/Services 拆分；
- [PR #11](https://github.com/ANRCM0/TXBoard-Gateway/pull/11)：静态编译期路由策略及 boot-time 验证；
- [PR #12](https://github.com/ANRCM0/TXBoard-Gateway/pull/12)：可信入口、IP/账号双层限流；
- [PR #13](https://github.com/ANRCM0/TXBoard-Gateway/pull/13)：Readiness、Metrics、脱敏观测及熔断/重试等韧性能力。

**真实 Laravel/MySQL/Redis/CAPTCHA 及 1Panel 部署/安全/回退仍未验收。** `/readyz` 当前的 upstream 探针是占位状态，不代表真实 Laravel 探测成功。TXBoard Native 未来路径为 `/txapi/bff/v1/*`，当前 `/gateway/v1/*` 未变。参阅 [双仓集成开发方案](./txapi-integration.md)。

## 二、交付层级：不要混淆三个状态

| 层级 | 当前状态 | 含义 |
| --- | --- | --- |
| **实现 / mock CI** | 已完成首版 | TypeScript/Vitest、Chromium 浏览器、模拟 Laravel HTTP、Redis 7.4 双客户端并发、Docker/HPKE 私钥挂载启动 |
| **真实 TXBoard Staging** | **尚未验证** | Laravel + MySQL + Redis、真正的 Turnstile/reCAPTCHA、账号/邮件/订单状态、代理回滚、错误兼容、负载与数据一致性 |
| **生产安全 / 上线** | **不允许宣称完成** | 独立威胁模型和密码学审查、可信代理与边缘限流、密钥轮换、Redis 失效/切主、安全告警、压测、交易幂等、实际回滚 |

模拟 CI 验证的是 **SDK → 浏览器 → Gateway → 受控模拟 TXBoard**。它不等于真实后端联调或经济交易安全验收。

## 三、工程交付矩阵

| 子系统 | 已实现 | 关闭/局限 |
| --- | --- | --- |
| Gateway v1 | 固定路由、Origin 精确列表、no-store、大小和超时限制、错误映射 | 非 WAF；无正式 OpenAPI 3.1 |
| 主题 SDK | 跨框架 TS 接口、明确 Token 注入、可选密文登录与注册/邮件 API | 尚未发布 npm；未覆盖所有 SSR 真实平台 |
| 用户域 | 套餐、资料、订单列表/详情/状态、订阅用量、支付方式展示、通知、统计 | 当前不创建/取消订单；不操作余额/支付 |
| 账户域 | Laravel 原有登录，HPKE-only 注册、邮件验证码 | 后两项默认关闭，Laravel CAPTCHA 是最终权威 |
| HPKE | RFC 9180 P-256/HKDF-SHA256/AES-256-GCM、AAD 绑定操作、kid、公钥发现、密文请求 | 只加密请求体；无响应加密；无密钥双版本/吊销；TLS 必需 |
| Redis | SET NX PX 原子 nonce（121s）、Lua 计数限流、断连 503；账号键 SHA-256 | Redis 异步故障转移可丢数据；哈希不等于匿名；键 HMAC 化未做 |
| 可信入口 | socket peer 推导客户端 IP、转发头重建、伪造 XFF/Host 在 handler 前 403、Host/Origin 精确校验 | 生产 1Panel/边缘信任链未在实网验证；**GW-213 代码完成（#12），296/296 测试通过** |
| 可观测性 | `requestId` 贯穿、脱敏结构化日志、`/metrics`（带凭证）、13 条 Prometheus 告警规则、`/readyz`（不泄配置） | `/readyz` 的 upstream 探针为占位，未接真实 Laravel |
| 韧性 | 敏感写/登录 fail-closed、只读降级、上游超时/断连受控、防重试风暴 | 生产故障注入与多副本演练未做 |
| Docker | 私网 Compose + 可选 HPKE/Redis overlay；非 root、只读、健康检查、私钥排除镜像上下文 | 尚未接 1Panel 真实网关/生产证书；禁用自动上线 |

当前 Gateway 限流（**PR-C 后为 IP + 账号双层**，见 `middleware/rate-limit.ts`）：

| 操作 | IP 维度 | 账号维度（规范化邮箱 / bearer 派生） | 失效策略 |
| --- | --- | --- | --- |
| 加密登录 `secure/auth/login` | 5 / 5 分钟 | 5 / 15 分钟 | fail-closed |
| 明文登录 `auth/login` | 5 / 5 分钟 | 5 / 15 分钟 | fail-closed |
| 加密注册 `secure/auth/register` | 3 / 10 分钟 | 3 / 30 分钟 | fail-closed |
| 邮箱验证码 `secure/auth/email-code` | 2 / 10 分钟 | 2 / 30 分钟 | fail-closed |
| 用户只读（orders/user/payments） | 60 / 分钟 | 120 / 分钟（bearer） | fail-open |
| 公开访客读（bootstrap 等） | 60 / 分钟 | — | fail-open |

客户端 IP **仅**由 socket peer 地址推导；转发头仅在对端命中可信入口白名单时才采纳，且重建而非追加，伪造转发头在对端不可信时于任何 handler 之前 403 拒绝。规则写在代码中（`ROUTE_POLICIES`），不等同可在线热更新策略；配额为负载测试前的保守初值。

## 2026-10-11 GW-303 上游实现完成

- TXBoard 主仓库 commit `e71dd66` 实现原子订单幂等（100-way MySQL 并发测试）
- Issue #216 已关闭
- Gateway 侧 GW-304 适配层已就绪（纯透传 + 错误映射）
- 待验证：Gateway 与 TXBoard 上游的幂等键格式兼容性

## 2026-10-09 架构决策增补（**代码已交付**）

已选定并**落地** **Hono 模块化单体 + 静态声明式策略 + 路由级安全中间件 + Redis 共享安全存储**。参考 [架构总览](./architecture.md) 和 [中间件实施规范](./middleware-architecture.md)。

- **已实现（PR #10–#13）：** `middleware/` 目录重构（request-context/security/rate-limit/auth/validation/observability/resilience/policy/login-protection）、编译期策略表（`config/policies.ts`）、可信入口与 IP/账号双层限流、`/readyz` + `/metrics`、脱敏结构化观测与熔断/重试韧性。
- **已确定、未实现：** HPKE 密钥双版本轮换/kid 吊销（GW-215）、Luma SDK 迁移（GW-217）、可选公开缓存（GW-218）。
- **继续保留：** 现有 HPKE/Redis 技术预览、基本路由、SDK、Docker CI 的真实实现状态。
- **验收未改变：** 真实 Laravel/MySQL/Redis/CAPTCHA、密钥轮换/Redis 切主、代理回滚、交易幂等和安全审查仍待完成。真实联调虽然允许延期，但生产前不可跳过。

## 四、下一批开发优先级

1. **P0：安全与部署边界。** 可信反代来源/Host/IP、安全头、统一脱敏日志、Redis 429/503 指标、readiness 与故障告警；Laravel 侧沿用原有 CAPTCHA 及限流。Gateway 直连公网不是目标部署形式。
2. **P0：Redis/密钥生命周期。** 验证 Redis AOF、掉电/切主、不一致和多实例共享；实现可验证的双 key 轮换、kid 淘汰和审计，考虑运维误删数据。
3. **P0：恢复真实联调验收。** 用 [Staging 手册](../staging/README.md) 的隔离 Laravel/MySQL/Redis、浏览器 Turnstile/reCAPTCHA 和回滚流程；产品当前允许延期，但**生产之前必须补齐**。
4. **P0：Laravel 交易幂等。** GW-302 ADR 已提交待评审（[订单幂等 ADR](./adr-order-idempotency.md)：派生幂等键 + MySQL 唯一约束 + 三态状态机 + 24h TTL）；随后在 TXBoard 主仓库设计事务表/唯一键与订单状态机，100 并发、失败恢复和支付回调沙箱完成后，才能考虑 Gateway 的 POST orders/checkout/cancel。
5. **P1：正式 API 与主题生态。** OpenAPI 3.1、完整 fixtures、前端适配器 feature flag、SDK 发布和 Vue/React/Next 多主题兼容测试。

## 五、合并与发布规则

- **代码合并**：PR diff + CI 绿 + 变更清单；允许保留禁用的预览功能。
- **功能启用**：Docker 私网 + HTTPS + 配置/Secret 审核 + 实际业务环境验证，敏感账户写默认 off。
- **生产放量**：对外安全审查、真实 Laravel 端到端、Redis 故障注入/回退、关键指标与演练齐全；财务交易需独立幂等/对账验收。
- **回滚**：撤销 `/gateway/v1/*` 反代及前端 feature flag，再停 Gateway；不能触碰 TXBoard 的 MySQL/Redis 生产卷。

相关：[Docker 部署](./docker-deployment.md) · [Gateway v1 契约](../contracts/gateway-v1.md) · [原始任务验收矩阵](./task-backlog.md) · [HPKE/Redis 限制](./app-crypto-preview.md)。
