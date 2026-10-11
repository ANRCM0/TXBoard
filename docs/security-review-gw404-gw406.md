# GW-406 独立安全审查清单（S 系列）与故障注入演练记录

> 范围：GW-404 抗重放 + GW-406 性能/故障注入/安全复核的**可自动验证证据**。
> 出处：[adr-replay-protection-and-audit.md](./adr-replay-protection-and-audit.md) §5 D8（S1–S12 冻结范围）与 §5 D7（FI-1～6）。
> 日期：2026-10-11

## 1. 自动化证据（CI 可复现，`npm test -w @txboard/gateway`）

| # | 检查项 | 状态 | 证据（测试文件:用例） |
| --- | --- | --- | --- |
| S4 | AAD 负例：跨操作移植 / kid 篡改 / 时间窗外 / GCM 篡改全拒 | ✅ | `app.test.ts` `roundtrips standard HPKE login and rejects replay, tamper and expiry`（kid/ct/ts 篡改全 400）+ `gw215-hpke-rotation.test.ts` 双窗口负例 |
| S5a | 重放负例：100 并发恰一次（RP-1） | ✅ | `gw404-replay-protection.test.ts` `RP-1`（1×200 / 99×409 / 上游 1 次） |
| S5b | 重放负例：Redis 断连 503（RP-5） | ✅ | `gw404-replay-protection.test.ts` `RP-5`（503 + 零上游 + 无进程内回退 + 恢复后自动放行） |
| S5c | Redis 未就绪 503（RP-6） | ✅ | `gw404-replay-protection.test.ts` `RP-6`（`isReady=false` 路径） |
| S5d | 独立 nonce 并发不误伤（RP-2） | ✅ | `gw404-replay-protection.test.ts` `RP-2`（100×200，键空间互不干扰） |
| S5e | 双 kid 命名空间独立（RP-4） | ✅ | `gw404-replay-protection.test.ts` `RP-4`（同 nonce 跨 kid 各放行一次、各自重放 409） |
| S5f | TTL 与时间窗一致性（RP-9） | ✅ | `gw404-replay-protection.test.ts` `RP-9`（虚拟时钟：TTL 过期后同 nonce 被时间窗先拒，零 Redis 接触） |
| S5g | schema 层负例不进键空间（RP-8） | ✅ | `gw404-replay-protection.test.ts` `RP-8`（非 22 位 nonce / 非安全整数 ts / 缺字段全 400，reserve 调用数 = 0） |
| S5h | 时间窗硬编码不放大（RP-7 / FI-3） | ✅ | `gw404-replay-protection.test.ts` `RP-7`（±61s 400 + 零 Redis 接触）、`FI-3`（±90s/±300s 全 400） |
| S6 | 无算法降级路径 | ✅（沿用 GW-405 矩阵） | GW-405 协商测试（`encryptedLogin=true` 遇 5xx 抛错、明文发往 secure 路由拒绝） |
| S8 | 双窗口轮换演练 FI-1 全绿 | ✅ | `gw404-replay-protection.test.ts` `FI-1`（窗口内 200 / 窗口外 400 / 回滚恢复） |
| S9 | 时钟偏差演练 FI-3 全绿 | ✅ | 同上 `FI-3` |
| S10 | 性能增量实测（D6 口径 ≤30ms） | ✅ | `docs/perf-hpke-baseline.md`（p95 9.09ms）+ `GW-406 性能预算` 测试断言 |
| FI-4 | Redis 断连/恢复演练 | ✅ | `gw404-replay-protection.test.ts` `FI-4`（503 零上游 → 恢复自动放行，无重启） |
| FI-5 | 切主丢键窗口（残余风险如实声明） | ✅ | `gw404-replay-protection.test.ts` `FI-5`（丢键后窗内可重放一次=已知残余风险；出窗一律 400） |

## 2. 人工走查项（不可自动化，需人工留证）

| # | 检查项 | 状态 | 说明 |
| --- | --- | --- | --- |
| S1 | 威胁模型 T1–T7 与残余风险显式接受 | 📋 待走查 | 依据 GW-401 §3；T5 重放残余风险（D5）已由 FI-5 量化声明 |
| S2 | 密文可见边界与日志脱敏一致 | 📋 待走查 | 信封字段（kid/ts/nonce/enc/ct）不落日志：GW-205/214 断言 + 反代配置（DEP 侧） |
| S3 | RFC 9180 标准套件 + `@hpke/core`，无自制密码学 | ✅ 代码事实 | `crypto.ts`：`DHKEM(P-256,HKDF-SHA256)+HKDF-SHA256+AES-256-GCM`，无自制算法 |
| S7 | 私钥不出镜像/git/日志 | 📋 待走查 | `.dockerignore` 含 `secrets/`；fail-hard（无临时密钥）；日志/`/metrics`/`/readyz` 断言见 `app.test.ts` |
| S11 | **独立密码学审查**（AAD 设计、nonce 空间、时间窗取值、fail-closed 语义） | ✅ **已通过（GO）** | **书面报告：[security-review-s11-crypto.md](./security-review-s11-crypto.md)**（2026-10-11，独立内部安全组）。四项焦点+密钥生命周期+篡改检测全绿，无阻断缺陷；两处非阻断修正（nonce 熵 128bit 文档精度、旧密钥窗口锚定进程启动的运维观察）。M4 Go/No-Go 阻断项解除 |
| S12 | 依赖审计：`@hpke/core` 版本锁定、无高危 CVE、SBOM 可复现 | ✅（沿用 GW-207） | `@hpke/core@1.9.0` 锁定；`npm audit --audit-level=high` 0 漏洞；`npm run sbom` CycloneDX 1.6（见 security-audit-gw207.md） |

## 3. S11 独立审查输入包（供审查方使用）

审查方需要判断的四个焦点，全部有代码/测试落点：

1. **AAD 设计**（六段冻结格式 `txboard-gateway:v1\n{method}\n{path}\n{kid}\n{ts}\n{nonce}\n{session}`）：
   - 实现：`apps/gateway/src/services/crypto.ts` `buildAad()`（Gateway）与 `packages/theme-sdk/src/crypto.ts` `encryptForOperation()`（SDK），两侧字节级一致由往返测试锁定。
   - 判据：跨操作移植 / 跨方法 / kid 篡改 / 时间窗外 / GCM 篡改是否全部 400（测试 S4）。
2. **nonce 空间**（132 bit + kid 命名空间）：
   - 键 `txbgw:v1:replay:<kid>:<nonce>`，`SET NX PX` 原子占用，TTL 121s。
   - 判据：碰撞概率可忽略 + kid 命名空间隔离（测试 RP-4）+ schema 层 22 位 base64url 强制（RP-8）。
3. **时间窗取值**（60s 窗口 / 121s TTL 推导）：
   - 窗口硬编码不可配置放大（RP-7/FI-3）；TTL = 窗口×2 + 1000，依据见 ADR §2 D1。
   - 判据：TTL 过期后同 nonce 必被时间窗先拒（RP-9 虚拟时钟验证）。
4. **fail-closed 语义**（D4）：
   - `reserve()` 每条失败路径（未就绪 / 异常 / 非 OK）→ 503；零进程内回退（多副本下回退 = N 副本各放行一次）。
   - 判据：RP-5（异常）、RP-6（未就绪）、FI-4（断连/恢复）。

## 4. 未自动化项（FI-2 / FI-6，演练环境依赖）

- **FI-2（kid 吊销）**：代码路径 `GATEWAY_HPKE_REVOKED_KIDS`（`crypto.ts` `parseRevokedKids` + `visibleVersion` + `open()` 吊销检查先于私钥尝试）已实现并有单元覆盖（`gw215-hpke-rotation.test.ts` 吊销组）；"不静默降级明文"的零重发断言沿用 GW-405 NCG-4 判据。
- **FI-6（多副本滚动部署不一致）**：需多副本演练环境；未生效副本拒新 kid 密文 400 是**正确 fail 方向**（密钥不匹配 → 认证失败），与 FI-1 联动，回滚即恢复。

## 5. 结论

- 自动化可证项（S4–S6、S8–S10、FI-1/3/4/5）：**全绿**。
- 人工走查项（S1/S2/S7）：已列出清单与证据位置，待人工签字。
- **S11 独立密码学审查：✅ 已通过（GO）**——[security-review-s11-crypto.md](./security-review-s11-crypto.md) 首轮书面结论解除 M4 Go/No-Go 阻断项；两处非阻断修正（nonce 熵 128bit、旧密钥窗口锚定进程启动）建议 M4 收尾处理。
