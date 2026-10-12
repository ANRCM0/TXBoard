# 误伤恢复 Runbook：Gateway 限流

> GW-208 交付物 2/2 — 限流误伤（false positive）的诊断与恢复流程。
> 适用场景：正常用户被 Gateway 限流拒绝（HTTP 429），需要快速判断是误伤还是攻击，并执行恢复操作。

---

## 0. 前置条件

- 已登录跳板机或运维终端，可访问 Gateway 日志和 Prometheus。
- 已确认 Gateway 版本和部署环境（Docker / 裸机 / K8s）。
- 已确认是否有正在进行的变更（部署、配置修改、热重载）。

---

## 1. 快速判断：误伤还是攻击？

### 1.1 收集基本信息

```bash
# 查看最近 5 分钟的限流拒绝指标
curl -s http://localhost:8787/metrics | grep 'txboard_gateway_rate_limit_rejections_total'

# 查看限流降级指标（Redis 故障）
curl -s http://localhost:8787/metrics | grep 'txboard_gateway_rate_limit_degraded_total'

# 查看当前限流计数和阈值
curl -s http://localhost:8787/metrics | grep 'txboard_gateway_rate_limit_current\|txboard_gateway_rate_limit_threshold'
```

### 1.2 判断矩阵

| 特征 | 误伤（false positive） | 攻击（true positive） |
| --- | --- | --- |
| 拒绝率 | < 5%，单 IP/账号偶发 | > 20%，多 IP/账号高频 |
| 时间分布 | 集中在某段时间（如整点） | 持续或周期性 |
| IP 分布 | 单一或少量 IP（如 NAT 出口） | 大量分散 IP |
| 账号分布 | 单一或少量账号 | 大量不同账号 |
| 用户投诉 | 有明确投诉 | 无投诉 |
| 上游日志 | 无异常 | 可能有撞库日志 |

### 1.3 快速决策

- **误伤** → 进入 §2 临时缓解。
- **攻击** → 进入 §3 攻击止血。
- **不确定** → 先执行 §2 临时缓解，同时继续分析。

---

## 2. 误伤临时缓解

### 2.1 确认误伤范围

```bash
# 查看哪些策略触发了拒绝
curl -s http://localhost:8787/metrics | grep 'txboard_gateway_rate_limit_rejections_total{' | grep -v ' 0$'

# 查看是 IP 维度还是账号维度
curl -s http://localhost:8787/metrics | grep 'txboard_gateway_rate_limit_rejections_total{' | grep 'dimension="ip"'
curl -s http://localhost:8787/metrics | grep 'txboard_gateway_rate_limit_rejections_total{' | grep 'dimension="subject"'
```

### 2.2 热重载临时上调配额（推荐）

1. 编辑限流配置文件（路径由 `GATEWAY_RATE_LIMIT_CONFIG` 环境变量指定）：

```bash
# 查看当前配置
cat /etc/txboard/gateway/rate-limit.json

# 备份当前配置
cp /etc/txboard/gateway/rate-limit.json /etc/txboard/gateway/rate-limit.json.bak

# 编辑配置，临时上调对应策略的配额（建议 2 倍以内）
vi /etc/txboard/gateway/rate-limit.json
```

2. 示例：将 `auth-login` 的 IP 配额从 5 上调到 10：

```json
{
  "version": 1,
  "policies": [
    {
      "id": "auth-login",
      "match": "/gateway/v1/secure/auth/login",
      "method": "POST",
      "ipLimit": 10,
      "ipWindowMs": 300000,
      "subjectLimit": 5,
      "subjectWindowMs": 900000,
      "subjectFrom": "email",
      "failClosed": true
    }
  ]
}
```

3. 保存后，watcher 会在 30 秒内自动重载，无需重启进程。

4. 验证重载成功：

```bash
# 查看重载指标
curl -s http://localhost:8787/metrics | grep 'txboard_gateway_rate_limit_config_reloads_total'

# 查看当前阈值是否已更新
curl -s http://localhost:8787/metrics | grep 'txboard_gateway_rate_limit_threshold'
```

### 2.3 如果热重载不可用

如果 `GATEWAY_RATE_LIMIT_CONFIG` 未配置或 watcher 未生效，需要重启进程：

```bash
# Docker 部署
docker compose restart gateway

# 裸机部署（systemd）
systemctl restart txboard-gateway

# K8s 部署
kubectl rollout restart deployment/txboard-gateway
```

### 2.4 长期修复

误伤缓解后，需要分析根因并制定长期修复方案：

| 根因 | 修复方案 |
| --- | --- |
| NAT/代理 IP 被误判 | 将可信入口 CIDR 加入 `GATEWAY_TRUSTED_INGRESS` |
| 正常用户行为触发限流 | 调整配额或窗口大小（通过热重载） |
| 移动端网络切换导致 IP 变化 | 考虑使用账号维度为主、IP 维度为辅 |
| 定时任务/爬虫触发 | 与业务方协调，加入白名单或调整策略 |

---

## 3. 攻击止血

### 3.1 确认攻击类型

```bash
# 查看拒绝率突增
curl -s http://localhost:8787/metrics | grep 'txboard_gateway_rate_limit_rejections_total'

# 查看是否有大量不同 IP
curl -s http://localhost:8787/metrics | grep 'txboard_gateway_rate_limit_current{' | grep 'dimension="ip"' | wc -l

# 查看是否有大量不同账号
curl -s http://localhost:8787/metrics | grep 'txboard_gateway_rate_limit_current{' | grep 'dimension="subject"' | wc -l
```

### 3.2 临时止血措施

1. **下调配额**（通过热重载）：

```json
{
  "version": 1,
  "policies": [
    {
      "id": "auth-login",
      "match": "/gateway/v1/secure/auth/login",
      "method": "POST",
      "ipLimit": 2,
      "ipWindowMs": 300000,
      "subjectLimit": 2,
      "subjectWindowMs": 900000,
      "subjectFrom": "email",
      "failClosed": true
    }
  ]
}
```

2. **启用边缘限流**（如果尚未启用）：
   - Cloudflare：启用 Rate Limiting 规则。
   - 1Panel：配置 Nginx `limit_req` 模块。

3. **封禁 IP 段**（极端情况）：
   - 通过防火墙或边缘 WAF 封禁攻击 IP 段。

### 3.3 攻击后分析

1. 收集攻击期间的日志和指标。
2. 分析攻击模式（IP 分布、账号分布、时间分布）。
3. 评估现有策略的有效性。
4. 制定长期防御策略（如引入 CAPTCHA、调整限流算法等）。

---

## 4. Redis 故障导致限流降级

### 4.1 确认降级

```bash
# 查看降级指标
curl -s http://localhost:8787/metrics | grep 'txboard_gateway_rate_limit_degraded_total'

# 查看 Redis 健康状态
curl -s http://localhost:8787/readyz
```

### 4.2 恢复 Redis

```bash
# Docker 部署
docker compose restart redis

# 裸机部署
systemctl restart redis

# 验证 Redis 恢复
curl -s http://localhost:8787/readyz
```

### 4.3 降级期间注意事项

- **fail-open 路由**（只读路由）：Redis 故障时会降级放行，响应头 `X-Gateway-Rate-Limit: degraded` 标记。
- **fail-closed 路由**（凭证类路由）：Redis 故障时返回 503，不会降级放行。
- **降级期间监控**：密切关注 `txboard_gateway_rate_limit_degraded_total` 指标，确保降级不会持续过久。

---

## 5. 常见误伤场景与解决方案

### 5.1 场景一：NAT 出口 IP 被限流

**现象**：多个用户共享同一 NAT 出口 IP，其中一人触发限流，其他人也被拒绝。

**解决方案**：
1. 将 NAT 出口 IP 加入 `GATEWAY_TRUSTED_INGRESS`（如果该 IP 是可信入口）。
2. 调整该 IP 的限流配额（通过热重载）。
3. 考虑使用账号维度为主、IP 维度为辅的策略。

### 5.2 场景二：移动端网络切换

**现象**：用户在移动网络下频繁切换 IP，导致 IP 维度计数分散，但账号维度计数正常。

**解决方案**：
1. 确认账号维度配额是否合理。
2. 如果账号维度配额足够，可以适当上调 IP 维度配额。

### 5.3 场景三：定时任务/爬虫

**现象**：业务方的定时任务或爬虫触发限流。

**解决方案**：
1. 与业务方协调，将爬虫 IP 加入白名单。
2. 为定时任务单独配置更高的配额（通过热重载添加专用策略）。

### 5.4 场景四：整点突发流量

**现象**：每天整点（如 9:00、18:00）出现限流拒绝。

**解决方案**：
1. 分析整点流量的来源和模式。
2. 如果是正常业务高峰，临时上调配额（通过热重载）。
3. 如果是异常流量，考虑启用边缘限流。

---

## 6. 回滚与恢复

### 6.1 回滚热重载配置

```bash
# 恢复备份的配置
cp /etc/txboard/gateway/rate-limit.json.bak /etc/txboard/gateway/rate-limit.json

# 等待 watcher 自动重载（30 秒内）
```

### 6.2 回滚代码变更

如果误伤是由代码变更引起的：

```bash
# 回滚到上一个版本
git revert <commit-hash>

# 重新构建和部署
docker compose up -d --build gateway
```

### 6.3 恢复默认策略

如果需要完全恢复默认策略：

```bash
# 删除或重命名配置文件
mv /etc/txboard/gateway/rate-limit.json /etc/txboard/gateway/rate-limit.json.disabled

# 重启进程（watcher 会检测到文件删除，恢复默认策略）
docker compose restart gateway
```

---

## 7. 监控与告警

### 7.1 关键指标

| 指标 | 说明 | 告警阈值 |
| --- | --- | --- |
| `txboard_gateway_rate_limit_rejections_total` | 限流拒绝总数 | 5 分钟内 > 50 |
| `txboard_gateway_rate_limit_degraded_total` | 限流降级总数 | 5 分钟内 > 0 |
| `txboard_gateway_rate_limit_missing_subject_total` | 账号维度缺失拒绝总数 | 5 分钟内 > 10 |
| `txboard_gateway_rate_limit_config_reloads_total{outcome="parse_failed"}` | 配置重载失败 | 1 分钟内 > 0 |

### 7.2 告警响应

- **warning**：限流拒绝率突增，检查是否为误伤。
- **critical**：限流降级（Redis 故障），立即检查 Redis 状态。

---

## 8. 联系与升级

- **P1 误伤**（影响核心业务）：立即执行 §2 临时缓解，同时通知业务方。
- **P0 攻击**（大规模撞库/DDoS）：立即执行 §3 攻击止血，同时通知安全团队。
- **持续降级**（Redis 故障超过 5 分钟）：通知运维团队恢复 Redis。

---

## 9. 变更记录

| 日期 | 变更 | 原因 |
| --- | --- | --- |
| 2026-10-10 | 初始版本 | GW-208 交付 |
