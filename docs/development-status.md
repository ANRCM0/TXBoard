# TXBoard Gateway 开发状态台账

> 更新时间：**2026-10-11**；旧阶段记录为历史快照，以下新增最新 main 代码核查。此文档记录代码和 CI，**不是生产就绪证明**。默认分支 main。当前不接入真实 TXBoard 环境，真实联调的验收暂按产品决策**延期**。

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
