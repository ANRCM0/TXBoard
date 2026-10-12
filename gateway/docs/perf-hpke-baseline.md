# HPKE 加密性能基线报告（GW-406 / D6）

> 口径：[adr-replay-protection-and-audit.md](./adr-replay-protection-and-audit.md) §5 D6
> 测试：`apps/gateway/test/gw404-replay-protection.test.ts`（`GW-406 性能预算` 组）
> 环境：Node 22（本机，CI 容器规格近似）、`@hpke/core@1.9.0`、P-256/HKDF-SHA256/AES-256-GCM
> 日期：2026-10-11

## 1. 实测结果

| 指标 | 预算 | 实测（p50 / p95 / p99） | 结论 |
| --- | --- | --- | --- |
| HPKE `open()` 全链路（P-256 ECDH 解封装 + AES-256-GCM + nonce 原子占用，经 `POST /gateway/v1/secure/auth/login` 端到端） | p95 **≤ 30ms** | **2.66 / 9.09 / 12.41 ms**（N=300） | ✅ 达标，余量 ~3.3× |
| Redis `SET NX PX` 单项（内存替身模拟，不含真实网络 RTT） | p95 **≤ 2ms** | **0.0058 ms**（N=300） | ✅ 达标 |

样例输出（`vitest --reporter=verbose`）：

```
[GW-406 PERF] N=300 p50=2.66ms p95=9.09ms p99=12.41ms
[GW-406 PERF] reserve N=300 p95=0.0058ms
```

## 2. 口径说明（D6 与 GW-401 §5 的区别）

- **本报告的 30ms 是端到端附加预算**：含 HPKE 解封装、GCM 验证、nonce 原子占用与 Hono 请求管线开销。
- GW-401 §5 的 "<2ms" 是**加密运算本身**的分量估计。两者不矛盾：分量远小于预算，实测印证了这一点。
- Redis 往返在真实部署中另计（本报告用内存替身测量了命令语义部分）。压测机上的真实 Redis RTT 按 D6 表格第 4 行在 staging 同构容器复测（并发梯度 10/50/100）。

## 3. 分量归因（基于实测与代码结构）

| 分量 | 量级 | 依据 |
| --- | --- | --- |
| P-256 ECDH 解封装（WebCrypto 硬件加速） | 亚毫秒级 | @hpke/core 单次 DHKEM |
| AES-256-GCM 解封 + AAD 拼接 | 亚毫秒级 | 请求体 ≤20KB（schema 上限） |
| nonce 原子占用 | 微秒级（本地） | 上表第 2 行 |
| 请求管线（JSON 解析、schema 校验、中间件） | 毫秒级主体 | p50 与 p95 的主要构成 |

## 4. 超预算处置（D6 冻结）

若 staging 复测 p95 超 30ms：定位分量（HPKE 运算 / Redis RTT / JSON 解析）→ Redis RTT 超支优先查连接复用与副本拓扑 → 仍超则上报评审，**不通过调大预算"达标"**。

## 5. 待办（不阻塞本报告）

- staging 同构容器压测（明文基线 vs HPKE，并发 10/50/100）与绝对值差归档。
- 客户端 SDK 密封开销 p95 ≤ 10ms（浏览器 + Node 各一组）。
- `/crypto/key` p95 ≤ 5ms（序列化预计算，零密码学运算）。
