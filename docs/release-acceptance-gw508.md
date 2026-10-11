# GW-508：发布 / 监控 / 回滚 / 兼容性总验收方案

- 状态：**设计文档交付（2026-10-11，待评审）**，未实施任何生产放量
- 责任：GW（Gateway 开关、观测与回归）+ DEP（镜像固定、边缘分流、回滚执行）+ TX（Laravel 旧主题与幂等上游）
- 验收原话（task-backlog GW-508）：**canary 报告、旧主题回归、镜像 digest、操作手册、SLO 与 rollback**
- 关联：[adr-theme-package-v2.md](./adr-theme-package-v2.md)（GW-501/503）、[theme-templates.md](./theme-templates.md)（GW-504/505）、[order-write-rollout.md](./order-write-rollout.md)（GW-309 灰度机制，本文取其分级与回滚口径做总验收框架）、[perf-hpke-baseline.md](./perf-hpke-baseline.md)（p95 基线）、[release-checklist.md](./release-checklist.md)（GW-109 台账模板）、[docker-deployment.md](./docker-deployment.md)（部署档位与回滚纪律）、[developer-guide.md](./developer-guide.md)（GW-509，第三方侧文档）

> **硬前置**：GW-503（SDK 正式发布）、GW-507（三维版本协商）未验收前，本文的放量分级全是零。本文设计的是"发布怎么走、坏了怎么停、怎么证明没坏"，**不豁免任何上游验收**。

## 1. 发布对象与三段式总装

GW-508 的总验收覆盖三个可独立回滚的发布对象，各自有不同的载体与回滚面：

| 对象 | 载体 | 责任方 | 回滚面 |
| --- | --- | --- | --- |
| **A. Gateway 容器** | 镜像（digest 固定，§4） | DEP + GW | 反代撤销 `/gateway/v1/*` → `compose down`（无 `-v`） |
| **B. 主题包（v2 manifest）** | ZIP + `txboard-theme.json` | TX（Theme Runtime，GW-502） | 切回 v1 主题或上一版本（无需 Gateway 操作） |
| **C. SDK** | npm `@txboard/theme-sdk` tag | GW | `dist-tag` 指回上一稳定版；tag 不删 |

三者版本独立 SemVer，运行时按 `engines` 区间**拒绝不兼容而非静默降级**（GW-501 §4、GW-507）。总验收按"**先 Gateway、再主题、再 SDK**"顺序走，每一段独立过 §3 的关卡，任何一段失败不影响其他段可回滚。

**发布原则**：每次发布 = 一次独立的、可追踪的部署动作（[release-checklist.md](./release-checklist.md) 台账条目：追踪 ID、镜像 digest、TXBoard commit、Gateway commit、验证结果、回滚步骤）。

## 2. Canary 发布流程（1% → 5% → 25% → 100%）

分流在**边缘/反代层**（1Panel / Caddy / Nginx，DEP 责任），Gateway 内不做百分比分流器（[order-write-rollout.md](./order-write-rollout.md) §3.2 的架构理由：多副本随机分流不可控、无审计面）。

### 2.1 分级表

| 阶段 | 流量 | 最小观测时长 | 晋级条件（全部满足） | 回退条件（任一触发） |
| --- | --- | --- | --- | --- |
| G0 预演 | 0%（仅 staging） | ≥ 1 完整演练日 | §3 全关卡绿；回滚演练 ≤5min 有记录；旧主题回归 §5 通过 | 任一未达标不放行 |
| G1 金丝雀 | **1%** | 24h | SLO 全绿（§6）；5xx ≤ 0.1%；p95 ≤ 30ms；旧主题零回归；幂等冲突率 ≈ 0 | 见 §7 触发表 |
| G2 小流量 | **5%** | 48h（覆盖 ≥2 自然日） | 同 G1，且隔夜 TTL 边界无异常 | 同 G1 |
| G3 中流量 | **25%** | 72h | 同 G2，且沙箱回归复跑全绿 | 同 G2 |
| G4 全量 | **100%** | — | 旧路径调用自然衰减观测启动 | 随时可回退至 0% |

### 2.2 流量分配（DEP 三选一）

1. **按用户会话 cookie（首选）**：边缘写 `tx_gw_rollout=<阶段>`，命中比例决定路由到 `/gateway/v1/*` 或旧路径；同一用户全程稳定同侧，避免幂等观测噪声。
2. **按主题 feature flag**：前端 SDK 开关（GW-108 模式），关闭即全回旧路径。
3. **按权重随机**：仅 G1 前可用（无会话概念时按 IP hash 取模）；G2 起不建议。

### 2.3 晋级动作（每次调比例）

1. 当前档观测窗口结束、指标全绿；
2. §3 关卡逐项签署（证据链接：CI URL / 沙箱报告 / 演练记录）；
3. DEP 经 PR 修改边缘配置比例到下一档（**独立部署动作**，Gateway 代码里永不放百分比）；
4. 台账追加一条：比例、时间、触发人、当前指标快照；
5. 进入下一观测窗口。

### 2.4 Canary 报告模板

每次晋级生成一份 canary 报告（附录进 [release-checklist.md](./release-checklist.md) 台账）：

```markdown
## Canary 报告 <tracking-id>-G<n>

- 阶段流量：n% | 观测窗口：<起>-<止> | 覆盖自然日：k
- 分流机制：cookie / feature flag / 权重（按 §2.2 方案）
- 镜像 digest：sha256:<...>（对应 §4 固定项）
- 指标快照：5xx=<x>%；p50/p95/p99=<ms>；幂等冲突率=<x>%；旧主题回归=<通过/用例>
- 旧主题验证：v1 Blade 主题全链路 <通过>（§5 回归矩阵编号）
- 结论：晋级 / 保持 / 回退（附触发指标与操作记录）
```

## 3. 总验收关卡（发布前必须逐项签署）

| # | 关卡 | 证据 |
| --- | --- | --- |
| GA-1 | 全测试绿：`npm test`（Gateway 391/391 + SDK 32/32 基线）、typecheck、build、npm audit 无新增高危 | CI 运行链接 |
| GA-2 | [adr-theme-package-v2.md](./adr-theme-package-v2.md) TP-1～9 全绿（主题包校验、零权重、CSP、公开配置） | 沙箱/结构断言输出 |
| GA-3 | GW-507 三维协商：不兼容版本被拒绝（`VERSION_MISMATCH` / Runtime 拒绝安装），非静默降级 | 沙箱用例输出 |
| GA-4 | §5 旧主题回归矩阵全绿 | 回归报告 |
| GA-5 | §8 回滚演练：staging 实测 ≤ 5 分钟止血、零数据卷触碰、可重放 | 演练 JSON 记录 |
| GA-6 | 镜像按 digest 固定并验证（§4） | `docker inspect` / Registry 输出 |
| GA-7 | SLO 在 staging 同构容器实测达标（§6） | 压测报告 |
| GA-8 | 告警已配置并演练触达（§7） | 告警测试记录 |

## 4. 镜像 digest 固定（防 tag 漂移）

**现状与风险**：`Dockerfile` 基础镜像为 `node:22.23.2-alpine`（tag，非 digest）；`compose.yaml` 服务镜像为 `${GATEWAY_IMAGE:-txboard-gateway:local}`（本地构建 tag）。tag 会漂移：同一 tag 在不同时间指向不同层，破坏"发布的镜像 = 验收的镜像"的可追溯性，也让回滚到的"上一版本"不确定。

**验收口径**（DEP 落地，本文仅定义标准）：

1. **基础镜像 pin digest**：`FROM node:22.23.2-alpine@sha256:<digest>`（对齐 [security-audit-gw207.md](./security-audit-gw207.md) 建议）。升级基础镜像时 digest 变更走 PR + 全量回归，不接受"顺手改 tag"。
2. **发布镜像记 digest**：每次构建 `txboard-gateway:<semver>` 同时记录其 digest（`docker inspect --format='{{index .RepoDigests 0}}'`），digest 写入 [release-checklist.md](./release-checklist.md) 台账"Gateway 镜像 Digest"字段；部署清单按 `image: <repo>@sha256:<digest>` 引用而非 tag。
3. **签名对象是 digest**：cosign 签/验对象为 `ghcr.io/<org>/txboard-gateway@sha256:<digest>`（[security-audit-gw207.md](./security-audit-gw207.md) §供应链），`cosign.key` 走密钥管理，不进仓库/镜像/CI 日志。
4. **回滚锚点**：台账里每条记录的 digest 即回滚目标——"回滚到 release-YYYYMMDD-XXX" = 部署该条记录的 digest，语义确定无歧义。
5. 本文档**不改 Dockerfile/compose**（与既有纪律一致：总验收方案先行，DEP 在真实部署 PR 落地 pin）。

## 5. 旧主题（v1）回归矩阵

"旧主题不受影响"是 GW-501/508 的共同验收硬约束。v1 = `config.json` + `dashboard.blade.php` + `/api/v1/*` 路径。回滚目标也是它。

| # | 用例 | 预期 |
| --- | --- | --- |
| RT-1 | v1 Blade 主题登录 → 仪表盘 → 套餐 → 订单全链路（不经过 Gateway） | 全绿；`/api/v1/*` 字节级不变 |
| RT-2 | v1 主题与 v2 独立主题并存安装/切换 | 切换无互相污染；manifest 对 v1 零影响（TP-2） |
| RT-3 | 旧登录（明文 `/api/v1` 路径）不受 Gateway 加密协商影响 | 旧客户端忽略 bootstrap 增量字段，行为不变（GW-405 已验证增量性） |
| RT-4 | 支付回调 `/payment/notify` 等旧路径 | 不经 Gateway，零改动（GW-306 边界） |
| RT-5 | 回滚后（Gateway 停 / 反代撤销）v1 主题全链路 | 与发布前逐字节一致（回滚验收核心：**回滚态 = 发布前态**） |
| RT-6 | 旧路径订单创建（`POST /api/v1/user/order/save`） | 与 Gateway 新路径同一 Laravel 派生幂等权威（[order-write-rollout.md](./order-write-rollout.md) §5.1，放量前提） |

## 6. SLO 指标与监控

### 6.1 SLO 表（staging 同构容器实测口径）

| SLO | 阈值 | 基线依据 | 测量点 |
| --- | --- | --- | --- |
| Gateway 端到端 **p95** | **≤ 30ms**（附加预算口径） | [perf-hpke-baseline.md](./perf-hpke-baseline.md) D6：实测 9.09ms，余量 3.3× | staging 同构容器复测（明文基线 vs HPKE，并发 10/50/100） |
| 写路径 p95 增量 | ≤ 50ms（vs 旧路径基线） | [order-write-rollout.md](./order-write-rollout.md) §4.1 | 灰度期两侧对比 |
| 5xx 错误率 | ≤ 0.1%（金丝雀期）；> 0.5% 持续 15min → 回退 | [order-write-rollout.md](./order-write-rollout.md) §4.1 | `/metrics` + 边缘日志 |
| 幂等冲突率（`IDEMPOTENCY_CONFLICT`） | ≈ 0；**任何出现即回退**（派生键下应为不可能，出现=实现 bug） | 同上 §5.2 | Gateway 日志错误码计数 |
| 重复成单率 | **0，零容忍**（任何 1 起即刻全量回退 + 事故复盘） | 同上 §4.1 | 对账 |
| 可用性 | Gateway 故障回旧路径 ≤ 5min（回滚 SLO） | §8 | 演练实测 |

> p95 ≤ 30ms 是**端到端附加预算**（含 HPKE 解封装、GCM、nonce 占用、Hono 管线），与 GW-401 "<2ms 加密分量"不矛盾（[perf-hpke-baseline.md](./perf-hpke-baseline.md) §2）。超预算处置按 D6：定位分量 → Redis RTT 优先查连接复用 → **不通过调大预算"达标"**。

### 6.2 监控指标（发布后持续）

- 请求率 / 5xx 率 / 延迟 P50/P95/P99（Gateway `/metrics` + 边缘，口径与 GW-214 一致，无敏感字段、带 `requestId`）；
- Redis 连接状态（断连 = fail-closed 503，不是放行）；
- 上游可用性；幂等冲突计数；旧主题 vs 新主题调用量对比；
- 发布后可观测性清单对齐 [release-checklist.md](./release-checklist.md) §发布后监控。

### 6.3 监控纪律

- 只监控计数与状态码，**不监控 body/金额明细**（GW-205 脱敏）；
- 不以"新路径零错误"单独晋级——必须与旧路径基线对比。

## 7. 告警与回滚触发

**告警配置**（对齐 [release-checklist.md](./release-checklist.md)）：5xx 率 > 0.5%（页级）、p95 > 30ms 持续 15min、`IDEMPOTENCY_CONFLICT` 出现即页、Redis 断连、上游不可用 > 1min。告警触达路径必须在 GA-8 演练过（发过测试告警并确认接收）。

**回退触发（任一 → §8.2 T+0 止血，止血不等待诊断）**：

| 优先级 | 触发 |
| --- | --- |
| P0 即刻 | 任何用户可见重复成单 / 资金对账差异；安全事件 |
| P1 | `IDEMPOTENCY_CONFLICT` 出现；幂等冲突率 > 0.5% |
| P1 | 5xx > 0.5% 持续 15min |
| P2 | p95 增量 > 150ms 持续 15min；两侧一致性矩阵任一项失败 |
| P2 | 上游 Laravel 幂等实现回滚（GW-303 迁移回滚） |

## 8. 回滚（≤5 分钟止血）

### 8.1 三层回滚面

| 层 | 回滚动作 | 时效目标 |
| --- | --- | --- |
| 边缘分流 | 比例 → 0%（cookie/flag 热更新） | 秒级（首选第一动作） |
| Gateway | `GATEWAY_ORDER_WRITE_ENABLED=false` 重启（幂等，405 零上游调用）；或反代撤销 `/gateway/v1/*` 后 `compose down`（**无 `-v`**） | ≤ 5min |
| 主题/SDK | 切回 v1 主题 / `dist-tag` 回指 | 分钟级 |

**铁律**：回滚**不触碰** TXBoard 生产 MySQL/Redis 数据卷（development-status §5）；在途 `in_progress` 陈旧后 CAS 接管不产生悬挂单；`idempotency_keys` 自然 GC（ADR §10）。

### 8.2 T+0 止血步骤（按序，先止血后诊断）

1. **比例 → 0**（边缘热更新）或 **flag → false**（全局硬开关）；
2. 验证：`POST /gateway/v1/orders` → 405、零上游调用（CI 有回归用例）；
3. 观察 15 分钟：旧路径错误率回基线、无悬挂 `in_progress` 增长；
4. 记录：触发原因、操作人、恢复时间 → 台账 + 事故记录（若资金差异）。

### 8.3 演练（GA-5 证据）

- staging 执行 `scripts/rollback-drill.sh`（已有实现，GW-109/216）：`--auto --output report.json`，全程 ≤5min、PASS 项全覆盖；
- 故障注入变体：Gateway kill、Redis 断连（fail-closed）、Laravel 慢响应——验证回滚路径不依赖上游健康；
- 判据（对齐 [order-write-rollout.md](./order-write-rollout.md) §8.4）：时效 ≤5min、零数据卷触碰、旧路径错误率 15min 内回基线、演练可重放（脚本化非手工记忆）。

### 8.4 操作手册定位

本文 §2/§7/§8 即发布/回滚操作手册核心；与 [docker-deployment.md](./docker-deployment.md) §5（部署校验与回滚命令）、[runbook-rate-limit-recovery.md](./runbook-rate-limit-recovery.md)（限流误伤恢复，GW-208）互补。发布/回滚 SOP 摘要：

```
发布：GA-1..8 签署 → 按 digest 部署镜像 → 边缘比例 1% → 观测 24h → 按 §2.3 晋级
回滚：比例→0 / flag→false → 验 405 零上游 → 观察 15min → 记录台账
```

## 9. 兼容性总验收（三方契约）

| 矩阵 | 断言 |
| --- | --- |
| CMP-1 | Gateway 契约 v1 字节级兼容：bootstrap 新增字段纯增量，旧 SDK/主题忽略无退化 |
| CMP-2 | SDK SemVer 纪律：破坏性变更只在 major（[adr-theme-package-v2.md](./adr-theme-package-v2.md) §8.2）；`KNOWN_ERROR_CODES` 只增不删 |
| CMP-3 | 主题 `engines` 三维区间不覆盖当前版本 → 拒绝安装/运行（GW-507），非降级 |
| CMP-4 | v1 主题（RT-1～6）回归零退化 |
| CMP-5 | v2 manifest 对 Gateway 服务端策略零权重（TP-4/TP-5 负例） |
| CMP-6 | 发布物三方可追溯：镜像 digest ↔ Gateway commit ↔ SDK tag ↔ 主题 manifest 版本（release-checklist 台账） |

## 10. 未决与边界

- **未实施**：本文为方案文档，未改任何代码/CI（`.github/workflows/` 零改动）、未 git commit、未执行任何生产放量；所有百分比与放量动作待 GA 关卡签署后由 DEP 在真实部署 PR 落地。
- **前置未验收**：GW-503、GW-507、GW-216（真实联调）未完成前本文分级不生效；GW-502（Theme Runtime）未实现则主题段发布无从谈起。
- **可继承**：canary 分级/回滚口径复用 [order-write-rollout.md](./order-write-rollout.md)（GW-309）的验证机制，总验收将其扩展到 Gateway 容器 / 主题 / SDK 三对象；checkout/cancel 等未来资金写按同构独立 flag + 独立比例档扩展。
- 基础镜像 digest pin 与发布镜像 digest 记录属 DEP 部署 PR 范围，本文只定义验收口径（§4）。
