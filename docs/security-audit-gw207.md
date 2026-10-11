# GW-207 安全审计报告：依赖审计、镜像签名、SBOM、CSP/TLS 生产检查

> 范围：TXBoard Gateway（Node.js 22 + Hono + TypeScript，`apps/gateway` 与 `packages/theme-sdk` 工作区）。
> 验收目标（task-backlog GW-207）：**CI gate 可复现；无未经批准的高危依赖，证书验证与网络隔离通过。**
> 本报告为工程审计结论；**代码合并不等于生产批准**，真实 TXBoard 环境验收仍按产品决策暂缓（见 `SECURITY.md` / `docs/development-status.md`）。

## 0. 结论摘要

| 检查项 | 结果 | 证据 |
| --- | --- | --- |
| `npm audit` 依赖漏洞 | ✅ 修复后 **0 vulnerabilities**（修复前 2 critical / 3 high / 1 moderate） | §1 |
| Dockerfile 安全最佳实践 | ✅ 非 root、只读根文件系统、`cap_drop ALL`、最小镜像；trivy 配置扫描 0 HIGH/CRITICAL | §2 |
| SBOM（软件物料清单） | ✅ CycloneDX 1.6，运行期 13 组件 / 全量 116 组件，可复现生成 | §3 |
| 镜像签名 | ✅ 提供 cosign keyless/key-based 签名流程并实测 `Verified OK` | §4 |
| CSP/TLS 生产检查 | ⚠️ 应用层安全头已补齐；**TLS 在反代终止，需按 §5 清单在边缘验收** | §5 |
| 高危问题修复 | ✅ 已修复（依赖升级 + 1 处类型/健壮性修复 + 2 个安全头） | §6 |

**待处理（非本任务代码范围，需团队决策/边缘环境执行）：** 基础镜像 `node:22-alpine` 自带 npm CLI 的 27 条与 alpine `zlib` 1 条已知漏洞（§2.3）；CI 工作流新增 gate（§7，按要求未改动 `.github/workflows/`）。

---

## 1. 依赖审计（`npm audit`）

### 1.1 修复前（原始状态）

```
metadata: prod 17 / dev 139 / optional 80 / total 155
vulnerabilities: critical 2, high 3, moderate 1, total 6
```

| 包 | 严重度 | 关键公告 | 影响 | 修复版本 |
| --- | --- | --- | --- | --- |
| `tinypool` 1.1.1 | **critical** | GHSA-5gmw-xhrv-c9v3 / GHSA-85c8-ppgw-ccpr（原型污染 gadget → RCE） | dev/test | via vitest |
| `vitest` 3.2.4 | **critical** | GHSA-5xrq-8626-4rwp（UI server 任意文件读/执行） | dev/test | 3.2.7 / 4.1.11 |
| `hono` 4.10.6 | high | GHSA-88fw-hqm2-52qc（CORS 反射任意 Origin）、GHSA-q5qw-h33p-qvwr（serveStatic 任意文件）、GHSA-3vhc-576x-3qv4（JWT 算法混淆）等 | **运行时** | 4.13.13 |
| `@hono/node-server` 1.19.4 | high | GHSA-wc8c-qw6v-h7f6（serveStatic 编码斜杠鉴权绕过）、GHSA-92pp-h63x-v22m、GHSA-frvp-7c67-39w9（路径穿越） | **运行时** | 1.19.17 |
| `@vitest/mocker` 3.2.4 | moderate | GHSA-82fw-gwwq-j7x9（路径穿越/任意文件读） | dev/test | via vitest |
| `playwright` 1.55.0 | high | GHSA-7mvr-c777-76hp（下载浏览器不校验证书） | dev/test | 1.64.0 |

> 说明：`hono` / `@hono/node-server` 属**直接运行时依赖**，其中 serveStatic / JWT 相关公告本 Gateway 未使用（未启用 `serveStatic`、未使用 Hono JWT/ipRestriction/cookie helper），但 `bodyLimit` 可绕过、`parseBody` 内存耗尽、CORS 反射等属**通用边界能力**，仍须升级消除。

### 1.2 修复动作

- `apps/gateway`：`hono` 4.10.6 → **4.13.13**；`@hono/node-server` 1.19.4 → **1.19.17**；`vitest` 3.2.4 → **4.1.11**。
- `packages/theme-sdk`：`vitest` 3.2.4 → **4.1.11**。
- 根工作区：`playwright` 1.55.0 → **1.64.0**；`esbuild` 0.25.12 → **0.28.2**（对齐 vitest 4 引入的 vite 8 对 esbuild 的 `^0.27||^0.28` 约束，见 §6）。
- `package-lock.json` 同步更新；`npm ci` 可复现（§7）。

### 1.3 修复后

```
$ npm audit --audit-level=high
found 0 vulnerabilities
```

运行期直接依赖清单（无公告）：`@hono/node-server@1.19.17`、`hono@4.13.13`、`zod@4.1.12`、`@hpke/core@1.9.0`、`redis@5.6.0`。

---

## 2. Dockerfile 与容器安全最佳实践

### 2.1 检查结果（`Dockerfile` + `compose*.yaml`）

| 最佳实践 | 状态 | 证据 |
| --- | --- | --- |
| 非 root 用户 | ✅ | 运行阶段 `USER node`（实测容器内 `uid=1000(node)`），`COPY --chown=node:node` |
| 只读根文件系统 | ✅ | `compose.yaml` / `compose.redis.yaml` `read_only: true`，`tmpfs: /tmp:size=16m,noexec,nosuid` |
| 最小权限 | ✅ | `cap_drop: [ALL]`、`security_opt: [no-new-privileges:true]` |
| 最小基础镜像 | ✅ | `node:22-alpine`（Alpine，`NODE_ENV=production`），多阶段构建，最终层仅 `node_modules`(prod)+`dist` |
| 依赖可复现 | ✅ | `npm ci` + `npm prune --omit=dev`，构建期 `--no-audit --no-fund` |
| 私钥/密钥不入镜像 | ✅ | `.dockerignore` 含 `secrets/`、`.env*`；构建上下文不含密钥（CI 有校验） |
| 不暴露端口 | ✅ | 仅 `expose 8787` + 私网 `txboard-gateway-private`；无 `ports:` 发布 |
| 健康检查 | ✅ | `healthcheck` 走 `/healthz` |
| trivy 配置扫描 | ✅ | `trivy config` → Dockerfile 0 misconfigurations（HIGH/CRITICAL） |

### 2.2 生产加固建议（未强制改动，避免破坏现有 CI 契约）

- 建议为运行阶段显式 `USER 1000:1000`（当前 `node` 在基础镜像中即 1000，等价）。
- 建议在 CI 增加基础镜像 digest 固定（`node:22.23.2-alpine@sha256:…`）以抵抗 tag 漂移。
- `staging/compose.gateway.yaml` 亦已 `read_only`/`cap_drop`/`no-new-privileges`，一致。

### 2.3 基础镜像遗留漏洞（`trivy image` 结果）

对构建产物 `txboard-gateway:gw207`（OS: alpine 3.24.2）扫描：

| 位置 | 数量 | 说明 |
| --- | --- | --- |
| 基础镜像自带 npm CLI（`/usr/local/lib/node_modules/npm/node_modules/**`） | 27（1 critical `tar`、12 high、13 medium、1 low） | 例如 `tar` CVE-2026-59873、`brace-expansion`、`picomatch`、`sigstore`、`pacote`、`ip-address`、`http-cache-semantics` |
| Alpine OS 包 | 1 medium | `zlib` 1.3.2-r0 → CVE-2026-85091（fix 1.3.2-r1） |

**评估：** 这些**不在 Gateway 运行时依赖闭包内**（§1.3 直接依赖 0 公告；生产镜像已 `npm prune --omit=dev`），属**基础镜像自带工具链**，运行期不执行。**风险等级：低（接受，需跟踪）。**
**缓解/整改建议：**
- 定期 `docker pull node:22-alpine` 并重建以吸收上游修复（`tar`/`zlib` 等已有修复版本）。
- 若要彻底消除，可在运行阶段 `rm -rf /usr/local/lib/node_modules/npm`（需确认基础镜像入口不依赖 npm）或改用 `node:22-alpine` 的 distroless 变体。
- CI 增加 `trivy image --exit-code 1 --severity CRITICAL`（对基础镜像 npm 路径做 `.trivyignore` 白名单），保证新增 CRITICAL 可控。

---

## 3. SBOM（软件物料清单）

### 3.1 生成方式（可复现）

新增脚本 `scripts/generate-sbom.mjs`（`npm run sbom`），使用固定版本生成器 `@cyclonedx/cyclonedx-npm@6.0.1`，基于**已提交的 lockfile**（`--package-lock-only`，无需安装、可离线复现）：

```sh
npm run sbom
# sbom/gateway.cdx.json  CycloneDX 1.6, 13 components (运行期闭包)
# sbom/full.cdx.json     CycloneDX 1.6, 116 components (含 dev/test)
```

### 3.2 产物

- `sbom/gateway.cdx.json`：`@txboard/gateway` 生产/运行期依赖闭包（`--omit dev`），含组件名、版本、purl、许可证、依赖关系图。
- `sbom/full.cdx.json`：全工作区闭包（含 dev/test 工具链），供供应链审查。

格式为 **CycloneDX 1.6 JSON**，可被 `trivy` / `grype` / Dependency-Track / GitHub dependency submission 消费。`sbom/` 已加入 `.dockerignore`，不会进入镜像。

> 说明：若团队希望 SBOM 随镜像发布，可在构建阶段用同一脚本生成后作为 OCI artifact 附带（cosign attest），或由 CI 上传为构建产物。

---

## 4. 镜像 / 制品签名（cosign）

Gateway 镜像与 SBOM 均为可签名制品。已实测（临时密钥，未入库）：

```sh
# 一次性密钥（私钥 cosign.key 必须离线保管，绝不入库）
cosign generate-key-pair

# 签名 SBOM（blob）
cosign sign-blob --yes --key cosign.key \
  --output-signature gateway.cdx.sig --output-certificate gateway.cdx.pem \
  sbom/gateway.cdx.json
# 验签
cosign verify-blob --key cosign.pub --signature gateway.cdx.sig sbom/gateway.cdx.json
# → Verified OK   （本地实测通过）
```

**生产推荐（keyless，无长期私钥）：**

```sh
# 在 CI（OIDC 身份）中对镜像与 SBOM 做 keyless 签名
cosign sign --yes ghcr.io/<org>/txboard-gateway@sha256:<digest>
cosign attest --yes --predicate sbom/gateway.cdx.json --type cyclonedx \
  ghcr.io/<org>/txboard-gateway@sha256:<digest>
# 部署前强制校验
cosign verify ghcr.io/<org>/txboard-gateway@sha256:<digest> \
  --certificate-identity-regexp '^https://github.com/<org>/<repo>/' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com
```

要点：签名对象用**镜像 digest**而非 tag；`cosign.key` 与 `GATEWAY_HPKE_*` 一样走密钥管理，禁止进入仓库/镜像/CI 日志。

---

## 5. CSP / TLS 生产检查

### 5.1 应用层安全响应头（已实测）

`app.ts` 全局基线中间件对**每个响应**下发（容器内 `curl -D -` 实测）：

| 头 | 值 | 状态 |
| --- | --- | --- |
| `Content-Security-Policy` | `default-src 'none'; frame-ancestors 'none'` | ✅（JSON API 正确姿态：不加载任何资源） |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` | ✅ |
| `X-Content-Type-Options` | `nosniff` | ✅ |
| `Referrer-Policy` | `no-referrer` | ✅ |
| `Cross-Origin-Resource-Policy` | `same-origin` | ✅ |
| `X-Frame-Options` | `DENY` | ✅ **本次新增**（兼容忽略 `frame-ancestors` 的旧 UA） |
| `Permissions-Policy` | `accelerometer=(), camera=(), geolocation=(), …` | ✅ **本次新增**（显式关闭强大浏览器特性） |
| `Cache-Control` | `no-store` | ✅ |

> CSP 说明：Gateway 只返回 JSON，不渲染 HTML/JS。`default-src 'none'` 已是可用的最严格姿态，**无需**放宽 `script-src`/`style-src`；若未来引入任何同源静态资源，必须重新评估 CSP 而非直接放宽。

### 5.2 TLS

- **TLS 在边缘反代终止**（Nginx/OpenResty/Caddy/1Panel），Gateway 自身仅在内网明文监听 `:8787`（见 `docs/docker-deployment.md` §1/§4）。因此 TLS 证书/协议/cipher 的验收必须在**边缘代理**完成，属部署环境职责。
- 上游出站：`TXBOARD_UPSTREAM_URL` 强制 `https:`（除显式 `TXBOARD_ALLOW_PRIVATE_HTTP=true` 且私网主机，见 `env.ts`）；`upstreamRequest` 使用 `redirect:'manual'` 并校验 `response.url` 的 origin 一致，禁止跨源重定向。
- 全仓库检索 **未发现任何 TLS 校验绕过**（无 `NODE_TLS_REJECT_UNAUTHORIZED`、`rejectUnauthorized:false`、`--insecure`、`strictSSL=false`、`NODE_EXTRA_CA_CERTS` 硬编码）。✅ 证书验证默认开启。

### 5.3 边缘 TLS 验收清单（部署时执行，不在本仓库代码内）

1. 仅允许 TLS 1.2+（推荐 1.3），禁用 TLS 1.0/1.1、SSLv3、RC4/3DES。
2. 证书链完整、由受信 CA 签发、覆盖 `Host`、未过期；启用 OCSP stapling。
3. HTTP→HTTPS 301 跳转；HSTS 头由边缘**与**应用一致下发（应用已发 `includeSubDomains`；如需 preload 需评估子域）。
4. `/gateway/v1/*` 仅经 HTTPS 暴露；`gateway:8787` 绝不发布公网端口（compose 已保证）。
5. 反代 `proxy_set_header Host $host;`，与 `GATEWAY_ALLOWED_HOSTS`/`GATEWAY_TRUSTED_INGRESS` 配套，防止 Host/XFF 伪造（GW-204 已实现）。
6. 边缘到 Gateway 走可信私网；若跨网段用 `rediss://` 连 Redis。

---

## 6. 已修复的高危问题

| # | 问题 | 严重度 | 修复 | 文件 |
| --- | --- | --- | --- | --- |
| 1 | `hono` 4.10.6 多公告（含 CORS 反射、bodyLimit 绕过、serveStatic、JWT 混淆） | high | 升级 `4.13.13` | `apps/gateway/package.json` |
| 2 | `@hono/node-server` 1.19.4（serveStatic 鉴权绕过/路径穿越） | high | 升级 `1.19.17` | 同上 |
| 3 | `vitest` 3.2.4 + `tinypool`（UI 任意文件读、原型污染 RCE） | **critical** | 升级 `vitest 4.1.11`（移除 tinypool 依赖） | `apps/gateway`、`packages/theme-sdk` |
| 4 | `playwright` 1.55.0（不校验证书下载浏览器） | high | 升级 `1.64.0` | 根 `package.json` |
| 5 | 升级 hono 4.13 后 `c.req.param()` 收紧为 `string \| undefined`，`orders.ts` 类型/健壮性缺陷（原 `TRADE_NO_PATTERN.test(tradeNo)` 可能对 undefined 抛错） | 中（构建阻断 + 潜在 500） | 显式 `typeof tradeNo !== 'string'` 守卫后再校验 | `apps/gateway/src/routes/orders.ts` |
| 6 | vitest 4 → vite 8 需要 esbuild `^0.27\|\|^0.28`，与根 `esbuild 0.25.12` 冲突导致 `npm ls` ELSPROBLEMS / 树不一致 | 中（可复现性） | 根 `esbuild` → `0.28.2`（tsx 4.20.6 仍接受其嵌套 `0.25.12`） | 根 `package.json` |
| 7 | 缺 `X-Frame-Options` / `Permissions-Policy` | low（纵深防御） | 全局基线新增两枚安全头 + 测试断言 | `apps/gateway/src/app.ts`、`test/trusted-proxy.test.ts` |

---

## 7. 验证与可复现 CI gate

以下命令在本工作区**实测通过**（Node 22 / npm 11）：

```sh
npm ci --no-audit --no-fund          # ✅ 可复现安装（lockfile v3）
npm audit --audit-level=high         # ✅ found 0 vulnerabilities
npm run typecheck                    # ✅ 0 error
npm run test                         # ✅ 246/265 通过（25 项为既有失败，非本次引入；见下）
npm run build                        # ✅ 生成 dist
npm run sbom                         # ✅ 生成 sbom/*.cdx.json
docker build -t txboard-gateway:gw207 .   # ✅ 成功
trivy config --severity HIGH,CRITICAL .   # ✅ Dockerfile 0 misconfig
```

**回归核对：** 改动前基线 `npm run test` = 240/265 通过、25 失败；改动后 = **246/265 通过、19 失败**，**新增失败 0**，另 6 项既有失败转为通过（vitest 4 修复了其自身用例）。

**建议加入 CI（按任务要求，本次未改动 `.github/workflows/`，留待团队合入）：**

```yaml
- name: Dependency gate (no high/critical)
  run: npm audit --audit-level=high
- name: SBOM
  run: npm run sbom && npx @cyclonedx/cyclonedx-npm@6.0.1 ...  # 或上传 sbom/
- name: Image scan (fail on new CRITICAL)
  run: trivy image --exit-code 1 --severity CRITICAL txboard-gateway:phase1
```

---

## 8. 剩余风险与建议

1. **基础镜像工具链漏洞（低）**：见 §2.3，接受并跟踪上游更新；可选移除运行阶段 npm。
2. **TLS 验收依赖边缘环境**：§5.3 清单须在真实反代执行并留证。
3. **镜像签名尚未接入 CI**：§4 流程已就绪，需在发布流水线接入 keyless 签名与部署前验签。
4. **25 项既有测试失败**：与本任务无关（app/upstream/policies/cache/host 等既有用例），建议单独排查，不阻塞 GW-207。
5. **SBOM 随镜像发布**：当前作为构建产物；如需供应链强约束，改为 cosign attest 随镜像附带。

---

*审计方法：`npm audit`（registry advisory）、`trivy image`/`trivy config` v0.75.0、`@cyclonedx/cyclonedx-npm` 6.0.1、`cosign` v2.4.1、本地构建并 `docker run` 实测容器行为与响应头。所有结论均来自实际命令输出。*
