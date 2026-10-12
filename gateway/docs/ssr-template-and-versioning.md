# Next.js SSR 安全模板与运行时版本协商（GW-506 / GW-507）

- 状态：**设计文档交付（2026-10-11）**，附 `examples/next-theme/` 源码骨架与扩展后的结构断言脚本
- 责任：GW（模板与协商设计）；TX（Theme Runtime 对 `entry.type: 'ssr'` 的安装策略，见 [adr-theme-package-v2.md](./adr-theme-package-v2.md) §12 未决问题 1）
- 关联任务：[task-backlog.md](./task-backlog.md) GW-506（本文 §1–§5）、GW-507（本文 §6–§9）；依赖 GW-503（[SDK 发布策略](./adr-theme-package-v2.md) §8）、GW-504/505（[theme-templates.md](./theme-templates.md)）、GW-501（manifest 与零权重原则）、GW-405（协商 fail-closed 语义）

## 1. 背景与问题

[theme-templates.md](./theme-templates.md) 交付了 Vue（GW-504）与 React（GW-505）两套 **CSR** SPA 模板。M5 验收要求"至少 Vue/React 两套 SPA + **SSR 示例**"共存，SSR 带来两个 SPA 没有的结构性风险：

1. **跨请求 session 污染（GW-506 核心验收）**：CSR 模板把 `auth_data` 存 localStorage、由浏览器随请求携带，天然请求隔离；SSR 里如果 SDK client 做成**模块级单例**（很常见的写法 `export const api = createTXBoardClient(...)`，两套 SPA 模板正是这样），`getToken` 闭包会捕获**第一个请求**的 cookie，之后**每个用户**的受保护请求都会重放该 token——A 的响应可能出现在 B 的页面上。这是数据泄露级缺陷，任务验收原话"无跨请求 session 污染"即针对此。
2. **token 落浏览器**：SPA 模板的 localStorage 方案在 SSR 下可以整体消除——登录在服务端 server action 完成，token 只进 httpOnly cookie，浏览器 JS 物理上读不到，XSS 偷 token 的路径被结构性关闭（SPA 模板做不到这一点，属 SSR 的安全增益而非回退）。
3. **版本协商缺失（GW-507）**：三套 SPA 模板只做 capability 门控，**不做版本声明与运行时拒绝**（theme-templates.md §8 已自认此缺口）。manifest 的 `engines`/`gateway.contract` 声明目前只服务于 Runtime 安装判定，主题**运行时**遇到 Gateway 升级到契约 v2 时只会逐调用撞 `VERSION_MISMATCH`，没有"安装前/调用前整体拒绝"的机制，更危险的是容易诱使实现者写出"探测失败就降级到旧调用路径"的代码——GW-405 已冻结禁止静默降级，GW-507 把同一语义搬到版本维度。

## 2. 决策（Decision）

1. **Next.js 14+ App Router，全 server component**（骨架内零 `'use client'`）：所有 Gateway 调用发生在服务端，RSC 载荷里只有渲染所需 DTO，**任何 token 分量都不进序列化载荷**。
2. **请求级 client 工厂，禁止模块单例**：`createClient()` 每次调用新建 SDK client，`getToken` 通过 `next/headers` 的 `cookies()` 读取**当前请求**的 cookie。结构断言强制：模块级 `const x = createTXBoardClient(` 出现即失败。
3. **token 只存 httpOnly cookie**：登录走 server action，`auth_data` 写入 `httpOnly; secure; sameSite=lax` cookie；无 localStorage、无客户端 SDK 实例、无 Bearer 下发浏览器。
4. **版本三维协商 fail-closed（GW-507）**：任何受保护调用前先跑 `negotiateVersion()`——Gateway 契约主版本 × SDK 版本 × 主题 manifest `engines`（构建期注入）三方比对，任一不符**渲染拒绝页并停止**，不存在降级分支。capability 门控保留但明确降格为"UI 可见性提示"，与 manifest capabilities 同为零权重。
5. **公共接口零 Bearer、写请求幂等语义与 SPA 模板逐字节一致**：bootstrap/plans 公开只读；orders 只读列表/详情/状态；`orders.create` 仅 capability 通告时出现入口（且骨架不实现写 UI，只展示门控判定）；错误映射、`requestId` 展示、safe-url 白名单与 Vue/React 模板同一份契约。
6. **strict CSP**：`next.config.mjs` 响应头强制无内联脚本（`script-src 'self'`），对齐 GW-501 §6 与 TP-9 验收口径（SSR 天然无内联脚本，比 SPA 更容易满足）。

**非目标**：不实现 CAPTCHA widget 渲染细节（bootstrap 已下发 provider/siteKey，widget 接线同 SPA 模板，留待真实联调）；不实现 `orders.create` 的完整写 UI（默认部署不通告该能力）；不接入 CI（不改 `.github/workflows/`）；不做 `entry.type: 'ssr'` 的 Runtime 安装策略（GW-502 + ADR 未决问题 1）；不 git commit。

## 3. SSR 模板契约（GW-506）

### 3.1 文件结构与职责

```
examples/next-theme/
  package.json                  # next 14 + react 18 + @txboard/theme-sdk@0.1.0（workspace）
  tsconfig.json next.config.mjs # strict CSP / 安全响应头；paths @/* → src/*
  txboard-theme.json            # v2 manifest：entry.type='ssr'、engines、capabilities（UI 提示）
  src/lib/server.ts             # 'server-only'：createClient()（请求级）、CONTRACT_VERSION、hasSession()
  src/lib/version.ts            # GW-507：negotiateVersion() 三维协商 + hasCapability()
  src/lib/safe-url.ts           # 与 SPA 模板字节级一致的 URL 卫生
  src/lib/format.ts             # 分/字节/日期展示格式化
  src/app/layout.tsx            # 品牌/导航壳（无 token 逻辑）
  src/app/page.tsx              # 首页：bootstrap + plans（公开）+ 版本门
  src/app/login/actions.ts      # 'use server'：登录 → httpOnly cookie；logout → 删 cookie
  src/app/login/page.tsx        # 纯 form action 登录页（浏览器零 SDK）
  src/app/dashboard/page.tsx    # 受保护只读：profile/subscription/stats
  src/app/orders/page.tsx       # 受保护只读：orders.list + capability 门控展示
  src/app/version-incompatible/page.tsx  # GW-507 拒绝页
  src/components/               # ErrorState / PlanCard（无客户端 token 逻辑）
  src/styles/                   # tokens.css / base.css（同 SPA 模板）
```

### 3.2 会话模型：为什么 SSR 比 SPA 更安全

| 维度 | SPA（Vue/React 模板） | SSR（本模板） |
| --- | --- | --- |
| token 存放 | localStorage（JS 可读，XSS 可窃） | httpOnly+secure cookie（JS 不可读，XSS 窃不到） |
| token 进入浏览器的形式 | `auth_data` 明文经登录响应下发 | 永不出现（server action 内消费，只回写 Set-Cookie） |
| SDK client 生命周期 | 模块级单例（每浏览器一个，天然隔离） | **必须每请求新建**（单例会跨请求污染） |
| 请求隔离依赖 | 浏览器 cookie 随请求自动携带 | server 端 `cookies()` 按请求读取 |
| XSS 偷 token | 可能（localStorage 可读） | 结构性不可能（httpOnly） |
| CSRF | 同源 + Bearer 头（cookie 不用） | cookie sameSite=lax + server action POST 同源校验 |

SPA 的"模块单例"在 CSR 是正确的（单用户浏览器）；同样写法搬到 SSR 是缺陷。这是两套模板**唯一的安全语义分叉**，其余（fail-closed CAPTCHA、capability 门控、幂等、错误映射、safe-url）完全一致。

### 3.3 核心代码形态（请求级隔离）

```ts
// src/lib/server.ts（'server-only'）
export function createClient(): TXBoardClient {
  return createTXBoardClient({
    baseURL: process.env.GATEWAY_BASE_URL || '/gateway/v1',
    getToken: () => cookies().get(SESSION_COOKIE)?.value,  // 每次调用解析当前请求
    timeoutMs: 10000,
    fetchImpl: globalThis.fetch,
  })
}
```

```ts
// src/app/login/actions.ts（'use server'）
export async function loginAction(formData: FormData) {
  const api = createClient()
  const result = await api.auth.login({ email, password })
  cookies().set(SESSION_COOKIE, result.auth_data, {
    httpOnly: true, secure: production, sameSite: 'lax', path: '/', maxAge: 7d,
  })
  redirect('/dashboard')
}
```

结构断言（`examples/theme-template-check.mjs`，已实现并通过）：
- `server.ts` 必须含 `'server-only'` 导入；
- 必须经 `cookies().get(SESSION_COOKIE)` 读 token；
- 禁止模块级 `const x = createTXBoardClient(`；
- 登录 action 必须 `httpOnly: true` 且不含 `localStorage` 字样（注释也不行）。

## 4. 验收矩阵（GW-506）

| # | 断言 | 验证方式 | 状态 |
| --- | --- | --- | --- |
| SSR-1 | 无跨请求 session 污染：两个并发请求携带不同 cookie，各自只收到自己的 Bearer | 运行时脚本（见 §5），交错执行两个 client 的全部受保护调用，断言 bearer 集合互不相交 | ✅ 已通过 |
| SSR-2 | 模块级 SDK 单例被结构断言拒绝 | 负例注入 `const api = createTXBoardClient(` 于 server.ts，脚本非零退出 | ✅ 已通过（断言在） |
| SSR-3 | token 不进客户端 bundle / RSC 载荷 | `server.ts` 标记 `'server-only'`（Next 构建期强制，客户端 import 即编译错误）；无 `'use client'` 页面 | 结构 ✅；构建期负例待真实 `next build` |
| SSR-4 | token 只存 httpOnly cookie；login action 不含 localStorage | 结构断言 + 负例注入 | ✅ 已通过 |
| SSR-5 | 模板禁止直连 Laravel（`/api/vN`、`/txapi`、上游 host） | 负例注入 `fetch('/api/v1/user')` 于 server.ts，脚本非零退出 | ✅ 已通过（已验证） |
| SSR-6 | 模板必须引用 `@txboard/theme-sdk` | 结构断言（SDK 入口文件存在性 + `createTXBoardClient`） | ✅ 已通过 |
| SSR-7 | strict CSP 无内联脚本 | `next.config.mjs` `script-src 'self'` | 声明 ✅；真实浏览器验收待 GW-508 |
| SSR-8 | 与 SPA 模板安全语义一致（CAPTCHA fail-closed、幂等键、错误映射、safe-url） | 代码走查（safe-url/format 字节级复用；CAPTCHA/widget 留真实联调） | 部分（骨架范围） |
| SSR-9 | `entry.type: 'ssr'` manifest 可被 Runtime 识别安装 | 属 GW-502 + ADR 未决问题 1 | 未做（设计预留） |

**双用户隔离测试（SSR-1 核心负例的口径）**：两个模拟请求 A/B 携带不同 `auth_data` cookie，交错调用 `orders.list`/`user.profile`/`user.subscription`，断言 A 的 client 历史里**从不出现** B 的 Bearer（反之亦然）。若实现退化为模块单例，A 的 `getToken` 闭包固化第一个值，该断言必失败——这就是验收要防的缺陷。

## 5. 版本协商机制（GW-507）

### 5.1 三维版本平面

| 平面 | 版本来源 | 当前值 | 检查时机 |
| --- | --- | --- | --- |
| Gateway HTTP 契约 | 每个响应 `meta.version` | `'1'` | SDK 每次响应校验（已有，`VERSION_MISMATCH`） |
| SDK 包 | 模板 `package.json` 锁定 + manifest `gateway.sdk.version` 区间 | `0.1.0`（区间 `>=0.1.0 <1.0.0`） | 构建期 manifest 校验 + 运行时区间比对 |
| 主题 manifest | `txboard-theme.json` `engines.gateway`/`gateway.contract` | `>=1 <2` / `1.x` | 构建期注入模板；运行时与 live 契约比对 |

三方各自 SemVer（[adr-theme-package-v2.md](./adr-theme-package-v2.md) §4/§8 冻结）：Gateway 契约主版本切换（1→2）时，SDK 旧 major 有 ≥90 天过渡窗口（ADR §8.2 支持窗口），窗口内旧 SDK/旧主题仍可用——**窗口是时间维度，不是降级维度**：窗口内运行时若检测到契约主版本不符，仍然拒绝，不会"将就着用"。

### 5.2 协商语义（fail-closed）

```
negotiateVersion(liveContractMajor, manifestEngines) →
  live !== THEME_CONTRACT_MAJOR            → { ok:false, plane:'gateway' }   拒绝
  SDK ∉ manifest.sdk 区间                   → { ok:false, plane:'sdk' }      拒绝
  live ∉ manifest.engines.gateway 区间      → { ok:false, plane:'theme' }    拒绝
  manifest.contract 主版本 ≠ live           → { ok:false, plane:'theme' }    拒绝
  否则                                      → { ok:true }
```

- **拒绝而非降级**：任何 `ok:false` 都渲染 `/version-incompatible`（或页面内拒绝块），**没有任何分支继续发起降级调用**。这与 GW-405"禁止静默降级"同构：那里禁止"加密协商失败改发明文"，这里禁止"版本不符改用旧契约调用"。
- **协商先于受保护调用**：页面在第一个 `protected` 调用前先 `bootstrap()`（公开、零 Bearer）+ `negotiateVersion()`，版本不符时**连受保护调用都不发起**（零上游写风险）。
- **SDK 层双保险**：即使模板协商被绕过，SDK 对 `meta.version !== '1'` 的响应一律抛 `VERSION_MISMATCH`（现有行为，GW-503 错误码纪律只增不删）。
- **manifest 声明零权重（沿 GW-501/GW-405）**：manifest 的 `engines`/`capabilities` **不能授权任何事**。`negotiateVersion` 里 manifest 只作为"主题自述的兼容区间"参与**主题侧**判定；Gateway 服务端策略对它零权重（伪造 manifest 声明不能改变 Gateway 行为，TP-4/TP-5 已覆盖）。真正的授权永远是 Gateway 路由策略 + `bootstrap.capabilities` 投影。

### 5.3 capability ≠ 授权

`bootstrap.capabilities`（如 `orders.create`、`auth.login.encrypted`）是**部署实际能力的投影**（[adr-encryption-negotiation.md](./adr-encryption-negotiation.md) D2：activeRouteDefinitions 投影）。模板侧 `hasCapability()` 只决定 UI 元素显隐：

- 能力未通告 → 入口不渲染（UX）；
- 能力已通告 → 入口渲染，但**调用仍可能被 Gateway 405/403**（部署中途改 flag、flag 与投影不一致等）；
- 客户端**猜测**能力（伪造 capabilities 数组）→ Gateway 零变化（路由策略是唯一权威，TP-4）。

即：capability 通告 = "可以试着显示"，Gateway 路由策略 = "能不能做"。两者不一致时以 Gateway 为准，主题显式禁用而非 fallback 到不安全写入。

### 5.4 验收矩阵（GW-507）

| # | 断言 | 验证方式 | 状态 |
| --- | --- | --- | --- |
| NEG-1 | 三维全兼容 → `ok:true`，正常渲染 | 运行时脚本真值表 | ✅ 已通过 |
| NEG-2 | Gateway 契约主版本不符 → `plane:'gateway'` 拒绝 | 运行时脚本（live='2'） | ✅ 已通过 |
| NEG-3 | SDK 版本出 manifest 区间 → `plane:'sdk'` 拒绝 | 运行时脚本（manifest sdk '>=1.0.0'） | ✅ 已通过 |
| NEG-4 | manifest `engines.gateway` 不含 live 契约 → `plane:'theme'` 拒绝 | 运行时脚本（engines '>=2 <3'） | ✅ 已通过 |
| NEG-5 | manifest `contract` 字段主版本不符 → 拒绝 | 运行时脚本（contract '2.x'） | ✅ 已通过 |
| NEG-6 | **不存在静默降级路径**：任何拒绝 verdict 都不携带"继续调用"动作 | 运行时脚本（拒绝分支断言） | ✅ 已通过 |
| NEG-7 | capability 缺席 → UI 门控隐藏；伪造能力 → Gateway 行为零变化（TP-4 延伸） | 运行时脚本（门控真值）+ Gateway 侧 TP-4 | 脚本 ✅；Gateway 侧随 GW-502 |
| NEG-8 | SDK `VERSION_MISMATCH` 作为第二道闸仍然生效 | SDK 现有行为（`meta.version!=='1'` 抛错） | ✅ 既有 |
| NEG-9 | 真实 Gateway 升级契约主版本后，SSR 主题渲染拒绝页而非部分功能异常 | 需真实 Gateway（GW-216 联调） | 未做 |

## 6. 与既有决策的一致性

- **零权重原则**（middleware-architecture、GW-405、GW-501）：本文所有版本声明/capability 对 Gateway 服务端策略零权重；Gateway 零代码零路由改动（纯 `examples/` + 文档交付）。
- **禁止静默降级**（GW-405 NGF 矩阵）：版本维度复用同一 fail-closed 语义，拒绝=停止，无降级分支。
- **token 铁律**（GW-501 §6、SDK README）：token 不入包、不入 localStorage（SSR 模板连浏览器存储都不用）、不跨请求复用。
- **SemVer 与支持窗口**（GW-503 §8.2）：三维平面各自的版本演进规则不变；本模板锁定 SDK `0.1.0` workspace 版，随 GW-503 正式发布对齐。
- **v1 主题共存**（GW-501 §7）：SSR 模板是 v2 新入口，与 Blade 旧主题并存，切换/回滚属 GW-502 Runtime。

## 7. 边界与未验收

- **纯交付物**：`examples/next-theme/` 骨架（可读的最小全链路）+ `examples/theme-template-check.mjs` 扩展（TPL 断言 + SSR 专属断言）+ 本文档。未改任何 Gateway/SDK 代码、未改 CI（`.github/workflows/` 零改动）、未 git commit、未接入 `npm test`。Gateway **391/391** + SDK **32/32** 基线维持（已重跑确认）。
- **未真实构建**：骨架未在本环境执行 `next build`/`next dev`（工作区未安装 next/react；两套 SPA 模板同样只到结构断言级）。类型检查的 JSX 报错均为环境缺依赖所致，与 vue/react 模板同等状态。
- **GW-507 运行时部分依赖真实 Gateway**：NEG-9（真实契约升级演练）与 SSR-7（真实浏览器 strict CSP 行为）待 GW-216 联调/GW-508 总验收。
- **`entry.type: 'ssr'` 安装策略**：ADR §12 未决问题 1——本模板验证了 ssr 模板的可行形态，但 Runtime 是否放开 ssr 安装仍待 TX 评审（GW-502）。
- **manifest 签名**：ADR §12 未决问题 2 未决，本文不引入。
- **CAPTCHA 在 SSR 的形态**：bootstrap 已下发 provider/siteKey；SSR 下 widget 仍需客户端组件渲染（骨架省略接线），fail-closed 语义（enabled 而缺 siteKey → 禁止提交）在真实实现中必须保持。
