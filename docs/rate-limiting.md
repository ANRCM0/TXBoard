# TXBoard Gateway 限流策略与调参指南

> GW-208 交付物 1/2 — 固化限流默认策略、调参指南与误伤恢复流程入口。
> 更新时间：2026-10-10。本文档与 `apps/gateway/src/middleware/rate-limit.ts` 中的 `ROUTE_POLICIES` 是同一事实的两种表述；代码是唯一可执行事实源，本文档供运维/调参参考。

---

## 1. 当前限流策略表

Gateway 采用 **Redis 原子滑动窗口** 双维度限流（IP + 账号/bearer），所有路由策略在编译期由 `ROUTE_POLICIES` 声明，运行时不可通过请求参数篡改。

| 操作 | 路由 | IP 维度（配额 / 窗口） | 账号维度（配额 / 窗口） | 账号派生 | 失效策略 |
| --- | --- | --- | --- | --- | --- |
| 加密登录 `secure/auth/login` | `POST /gateway/v1/secure/auth/login` | 5 / 5 min | 5 / 15 min | email | fail-closed |
| 明文登录 `auth/login` | `POST /gateway/v1/auth/login` | 5 / 5 min | 5 / 15 min | email | fail-closed |
| 加密注册 `secure/auth/register` | `POST /gateway/v1/secure/auth/register` | 3 / 10 min | 3 / 30 min | email | fail-closed |
| 邮箱验证码 `secure/auth/email-code` | `POST /gateway/v1/secure/auth/email-code` | 2 / 10 min | 2 / 30 min | email | fail-closed |
| 用户只读 orders | `GET /gateway/v1/orders*` | 60 / 1 min | 120 / 1 min | bearer | fail-open |
| 用户只读 user | `GET /gateway/v1/user*` | 60 / 1 min | 120 / 1 min | bearer | fail-open |
| 支付方式展示 | `GET /gateway/v1/payments` | 60 / 1 min | 120 / 1 min | bearer | fail-open |
| 公开访客读（bootstrap / theme / plans / crypto/key） | `GET /gateway/v1/{bootstrap,theme,plans,crypto/key}` | 60 / 1 min | — | — | fail-open |
| 默认兜底（未来新增 `/gateway/v1/*` 路由） | 任意未匹配路由 | 120 / 1 min | — | — | fail-open |

**关键设计不变量：**

- **fail-closed（凭证类路由）**：Redis 不可用时返回 503，绝不静默放行。
- **fail-open（只读路由）**：Redis 不可用时降级放行，响应头 `X-Gateway-Rate-Limit: degraded` 标记，可被监控告警。
- **账号维度优先**：账号维度配额始终 ≤ IP 维度配额的合理倍数，防止撞库攻击从多 IP 分散。
- **键隐私**：Redis 键使用 keyed SHA-256 摘要（`policyId + identity`），绝不存原始 email / IP / bearer。
- **Retry-After 语义**：基于窗口内最老成员的真实过期时间，而非整个窗口长度，给客户端真实等待提示。
- **可信入口**：客户端 IP 仅从 socket peer 推导；转发头仅在对端命中可信入口白名单时采纳，伪造 XFF 在 handler 之前 403。
- **双层互补**：`redis-security.ts` 中的登录/注册/邮箱验证码预算（`AccountLimiter`）作为第二道防线，与路由级限流独立计数、独立失效策略。

---

## 2. 调参指南

### 2.1 当前值的设计依据

默认配额是**负载测试前的保守初值**（参见 `implementation-plan.md §2.2`），并非经过压测的最优值。在真实联调（GW-216）和压测（GW-109）完成前，不应据此宣称"已调优"。

| 参数类别 | 当前取值逻辑 | 上调场景 | 下调场景 |
| --- | --- | --- | --- |
| 凭证类 IP 配额 | 5/5min、3/10min、2/10min | 正常用户偶发 429 且攻击可控 | 撞库攻击激增，需快速止血 |
| 凭证类账号配额 | 5/15min、3/30min、2/30min | 同上（账号维度） | 同上 |
| 只读路由 IP/账号配额 | 60/1min、120/1min | 正常用户浏览频繁触发 429 | 扫描/爬取行为需压制 |
| 默认兜底配额 | 120/1min | 新路由上线初期正常流量较高 | 新路由被滥用 |

### 2.2 调整步骤（安全变更流程）

1. **确认是否需要调参**：查看 Prometheus 指标（见下文 §3）和 `/metrics` 输出，确认是误伤还是真实攻击。
2. **单维度优先**：先调账号维度（对撞库/脚本攻击更有效），再调 IP 维度（对单 IP 压测更有效）。
3. **渐进式调整**：每次调整幅度不超过 2 倍，观察 24h 后再决定是否继续。
4. **变更审计**：在 PR 描述中记录调整原因、时间窗、预期影响和回滚条件。
5. **回滚准备**：保留上一版 `ROUTE_POLICIES` 的 Git 历史，异常时可快速 revert。

### 2.3 环境变量热重载（GW-208 新增）

Gateway 支持通过 `GATEWAY_RATE_LIMIT_CONFIG` 环境变量传入 JSON 配置文件路径，实现**不重启进程**的限流策略热重载。详见 [热重载机制](#4-热重载机制)。

### 2.4 不应做的操作

- **不要在生产直接修改代码中的 `ROUTE_POLICIES`**：每次变更都需要重新构建和部署，且容易遗漏审计。
- **不要为了"解决"429 而简单翻倍配额**：应先确认是否为误伤（见 §5 误伤恢复流程）。
- **不要关闭 fail-closed**：Redis 故障时返回 503 是安全设计，不是缺陷。
- **不要单独调整 IP 维度而不考虑账号维度**：两者应保持合理比例，防止攻击者通过轮换 IP 绕过账号维度。

---

## 3. Prometheus 限流指标

GW-208 为每个限流维度添加了 Prometheus 指标，通过 `/metrics` 端点暴露（受凭证保护，见 `middleware/observability.ts`）。

| 指标名 | 类型 | 标签 | 说明 |
| --- | --- | --- | --- |
| `txboard_gateway_rate_limit_current` | Gauge | `policy`, `dimension` | 当前滑动窗口内某维度计数（0 = 未触发） |
| `txboard_gateway_rate_limit_threshold` | Gauge | `policy`, `dimension` | 当前策略的配额阈值 |
| `txboard_gateway_rate_limit_rejections_total` | Counter | `policy`, `dimension`, `reason` | 被限流拒绝的请求总数，`reason` ∈ `ip`, `subject`, `missing_subject` |
| `txboard_gateway_rate_limit_degraded_total` | Counter | `policy` | Redis 故障导致 fail-open 降级的次数 |
| `txboard_gateway_rate_limit_missing_subject_total` | Counter | `policy` | 账号维度无法执行而拒绝的请求数 |

**告警规则建议**（已部署于 `.github/alert-rules.yml` 或由运维侧配置）：

```yaml
# 限流拒绝率突增（可能是攻击或误伤）
- alert: GatewayRateLimitRejectionSpike
  expr: rate(txboard_gateway_rate_limit_rejections_total[5m]) > 50
  for: 2m
  labels: { severity: warning }
  annotations:
    summary: "Gateway 限流拒绝率突增，请检查是否为误伤"

# Redis 故障导致限流降级
- alert: GatewayRateLimitDegraded
  expr: rate(txboard_gateway_rate_limit_degraded_total[5m]) > 0
  for: 1m
  labels: { severity: critical }
  annotations:
    summary: "Gateway 限流正在降级（fail-open），需立即检查 Redis"
```

---

## 4. 热重载机制

### 4.1 配置文件路径

通过环境变量指定 JSON 配置文件路径：

```bash
export GATEWAY_RATE_LIMIT_CONFIG=/etc/txboard/gateway/rate-limit.json
```

### 4.2 配置文件格式

```json
{
  "version": 1,
  "policies": [
    {
      "id": "auth-login",
      "match": "/gateway/v1/secure/auth/login",
      "method": "POST",
      "ipLimit": 5,
      "ipWindowMs": 300000,
      "subjectLimit": 5,
      "subjectWindowMs": 900000,
      "subjectFrom": "email",
      "failClosed": true
    }
  ]
}
```

### 4.3 热重载行为

- 配置文件每 **30 秒** 由 `index.ts` 中的 watcher 检查一次（基于 `fs.watch`）。
- 文件内容变更时，watcher 原子替换 `ROUTE_POLICIES` 的运行时副本（`runtimePolicies`）。
- 正在处理中的请求使用旧快照，新请求使用新策略，避免中间态。
- 解析失败时保留旧策略并记录 `rate_limit.config.reload_failed` 指标。
- 热重载不改变 `resolveRoutePolicy` 的函数签名，所有调用方透明。

### 4.4 与安全边界的交互

- **热重载仅影响配额数值和窗口大小**，不改变路由匹配、方法过滤、subjectFrom 派生或 fail-closed 策略（这些是编译期安全不变量）。
- 配置文件权限应限制为 `0640 gateway:gateway`，避免非授权用户读取或篡改。
- 配置文件变更后，审计日志记录 `old_version` → `new_version` 和变更时间戳。

---

## 5. 误伤恢复流程

误伤（false positive）指正常用户被限流拒绝。完整 runbook 见 [runbook-rate-limit-recovery.md](./runbook-rate-limit-recovery.md)。

**快速判断清单：**

1. **确认 429 来源**：检查响应体 `error.code` 是否为 `RATE_LIMITED`，响应头 `Retry-After` 值。
2. **查看维度**：通过 `txboard_gateway_rate_limit_rejections_total{policy="...", dimension="..."}` 确认是 IP 维度还是账号维度。
3. **区分误伤与攻击**：
   - **误伤特征**：单 IP/账号偶发，用户投诉集中，拒绝率低。
   - **攻击特征**：多 IP/账号高频，拒绝率突增，可能伴随撞库日志。
4. **临时缓解**（误伤时）：
   - 短期：通过热重载临时上调对应策略的配额（2 倍以内）。
   - 长期：确认是否为 NAT/代理 IP 被误判，考虑将可信入口 CIDR 加入 `GATEWAY_TRUSTED_INGRESS`。
5. **攻击止血**（攻击时）：
   - 短期：下调配额或启用 Cloudflare/WAF 边缘限流。
   - 长期：分析攻击模式，调整策略并考虑封禁 IP 段。

---

## 6. 与其他安全层的关系

| 层次 | 位置 | 职责 | 配额 |
| --- | --- | --- | --- |
| 边缘 WAF/CDN | Cloudflare / 1Panel | IP 级粗粒度限流、地理封禁、DDoS 清洗 | 由运维配置 |
| Gateway 路由限流 | `middleware/rate-limit.ts` | 双维度（IP + 账号）滑动窗口，路由级精细化 | 本文档表格 |
| Gateway 登录防护 | `middleware/login-protection.ts` | 结果驱动（仅失败计数），CAPTCHA/锁定升级 | 独立计数 |
| Laravel CAPTCHA | TXBoard 上游 | 最终验证码权威，独立限流 | Laravel 配置 |

**纵深防御原则**：任一层失效不会导致整体崩溃。边缘限流阻止大规模扫描，Gateway 路由限流阻止账号级滥用，登录防护阻止撞库，Laravel CAPTCHA 兜底。

---

## 7. 代码索引

| 关注点 | 文件 |
| --- | --- |
| 策略表与 Lua 脚本 | `apps/gateway/src/middleware/rate-limit.ts` |
| Redis 安全存储（nonce + 账号预算） | `apps/gateway/src/services/redis-security.ts` |
| 登录防护（CAPTCHA/锁定） | `apps/gateway/src/middleware/login-protection.ts` |
| 可信入口 IP 推导 | `apps/gateway/src/middleware/security.ts` |
| Prometheus 指标实现 | `apps/gateway/src/services/metrics.ts` |
| 指标暴露与凭证保护 | `apps/gateway/src/middleware/observability.ts` |
| 限流集成测试 | `apps/gateway/test/rate-limiter.test.ts` |

---

## 8. 变更记录

| 日期 | 变更 | 原因 |
| --- | --- | --- |
| 2026-10-09 | 初始策略表（PR-C 合入后基线） | GW-213 交付 |
| 2026-10-10 | 添加 Prometheus 指标、热重载机制、调参指南、误伤恢复流程 | GW-208 交付 |
