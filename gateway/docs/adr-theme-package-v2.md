# ADR：Theme Package v2 独立 Manifest 与 SDK 发布策略（GW-501 / GW-503）

- 状态：**已提出，待评审**
- 日期：2026-10-11
- 责任：TX（TXBoard Laravel：Theme Runtime v2 落地）+ GW（Gateway：SDK 发布与本 ADR）
- 关联任务：[task-backlog.md](./task-backlog.md) GW-501（本文 §1–§7）、GW-503（本文 §8–§10）；下游 GW-502（Runtime 实现）、GW-504～506（框架模板）、GW-507（三维版本协商）、GW-508（总验收）
- 前置依赖：M1（GW-101/102/106，契约与 SDK 兼容性）；GW-405（[加密灰度协商](./adr-encryption-negotiation.md)：manifest 对服务端策略零权重）；[middleware-architecture.md](./middleware-architecture.md)（策略表编译期冻结）

## 1. 背景与问题

- TXBoard 现有主题体系为 **Theme Package v1**：安装入口是 `config.json`（主题元数据）+ `dashboard.blade.php`（Blade 视图），由 Laravel 主题加载器直接处理。M5 需要引入**独立 SPA/SSR 主题**（GW-504～506），这些主题是编译产物、需要声明兼容的 Gateway/SDK 版本区间与能力，v1 装载器**并未**被要求支持 SPA manifest——[implementation-plan.md](./implementation-plan.md) §5.1 明确"不可直接假设 SPA manifest 已被其加载器支持"。
- 同时 `@txboard/theme-sdk`（当前 `0.1.0`，工作区内部包，未发布）将成为第三方主题与模板的公共依赖，需要冻结的公开契约、SemVer 策略与可追溯的发布流程。
- 核心风险（任务验收原话）：
  1. **兼容性误判**：主题声明的能力/版本区间若被当作权限或权威使用，会越权；
  2. **v1 被破坏**：任何 v2 设计不得改动 `config.json`/`dashboard.blade.php` 的既有语义与旧主题行为；
  3. **供应链**：主题包可携带任意编译产物，需完整性校验与 CSP 约束；SDK 发布需 tag/commit/CI/制品可追溯；
  4. **私密字段外泄**：主题公开配置 schema 若不在服务端划分 public/private，会将服务端私密字段经 `theme_config` 带给访客。

## 2. 决策（Decision）

1. **v2 manifest 是新格式、新入口，与 v1 并存互不干扰**。v2 主题包根目录固定携带一个机器可校验文件 `txboard-theme.json`；v1 主题继续以 `config.json` + `dashboard.blade.php` 安装，旧加载器**零改动**。v2 的解析、校验、安装由 **TXBoard Theme Runtime（独立 TXBoard PR，GW-502）** 实现。
2. **Gateway 不参与主题安装**：不解包 ZIP、不访问主题文件系统、不信任主题代码、不解析 `txboard-theme.json`。Gateway 侧新增零路由、零环境变量（纯设计决策）。
3. **manifest 是声明，不是授权**。`gateway.contract`、`capabilities`、`sdk` 版本区间只用于①Runtime 的兼容性判定与②主题 UI 可见性提示；**对 Gateway 服务端策略零权重**——与 [middleware-architecture.md](./middleware-architecture.md) §"强制不可覆盖项"及 GW-405（伪造能力头/manifest 零权重）完全一致。
4. **三维版本独立声明**：Gateway 主版本（HTTP 契约 `meta.version`，当前 `1`）× SDK 包主版本 × 主题 contract 版本，三者各自 SemVer，运行时**拒绝不兼容而非静默降级**（GW-507 落地）。
5. **公开配置的服务端划分**：manifest 的 `config.public` 字段清单是**候选公开集**，最终权威在 Laravel：`GET /gateway/v1/theme/config` 只返回服务端判定为公开的 `theme_config` 字段，manifest 声明不能扩大该集合。
6. **资源完整性 + CSP 是安装前置**：每个静态资源带 `integrity`（sha256，SRI 形态），Theme Runtime 校验；主题承诺不使用远程代码源、不 eval、不持久化 token（详见 §6）。
7. **SDK 按"先稳定后发版"发布**：当前只读稳定集冻结后发 `1.0.0`；此前维持 `0.x`，SemVer 规则按 §9 执行；tag `theme-sdk/vX.Y.Z` 可追溯至 commit/CI/发布记录。

**非目标（明确不做）**：本 ADR 不实现 Theme Runtime 代码（GW-502）；不改 Gateway 任何代码/路由/CI（纯文档变更）；不将 manifest 用作权限或功能开关；不迁移 v1 主题（v1 与默认 Blade 主题同仓并存，按 [implementation-plan.md](./implementation-plan.md) §5.3 共存）；不在主题包内携带管理员凭据、SMTP 密钥、订阅原始 token 等任何服务端私密信息。

## 3. v2 主题包结构

```
my-theme.zip
├── txboard-theme.json      # v2 manifest（唯一安装声明；必需，缺则拒绝安装）
├── dist/                   # 编译产物（仅 bundle 输出，不放源码/密钥/私密配置）
│   ├── index.js
│   └── index.<hash>.css
├── assets/                 # 静态资源（图片/字体等，全部带 integrity）
└── README.md               # 可选
```

铁律：ZIP 内**不得**出现 v1 安装文件 `config.json` / `dashboard.blade.php` 之外的任意执行入口伪装；不得包含 `.env`、私钥、可执行脚本（`.sh/.exe/.php`）、符号链接或超出 `dist/`+`assets/` 的路径穿越条目。这些由 Theme Runtime（GW-502）的 ZIP 安全检查实施（路径穿越/软链/压缩炸弹/资源完整性），Gateway 不重复实现。

## 4. Manifest 字段规范（冻结草案）

JSON 文件，UTF-8，≤ 64 KiB，严格模式（未知字段拒绝）。

```jsonc
{
  "format": 2,                              // 固定 2；非 2 拒绝（防止 v1 误装）
  "name": "luma-minimal",                   // slug：^[a-z0-9-]{2,64}$，全站唯一
  "title": "Luma Minimal",                  // 展示名
  "version": "1.0.0",                       // 语义版本（SemVer）
  "entry": {
    "type": "spa",                          // spa | ssr（ssr 预留，模板见 GW-506）
    "module": "dist/index.js"               // 相对包根的 ESM 入口（单一）
  },
  "engines": {
    "txboard": ">=3.6",                     // 支持 v2 的 TXBoard 版本区间
    "gateway": ">=1 <2",                    // Gateway HTTP 契约主版本 1（当前唯一）
    "contract": "1.x"                       // gateway.contract 版本（见 §5）
  },
  "gateway": {
    "contract": "1.x",
    "sdk": {
      "package": "@txboard/theme-sdk",      // 固定值（本仓库唯一公开包）
      "version": ">=1.0.0 <2.0.0"           // 主题声明可用的 SDK 区间
    },
    "capabilities": [                       // UI 可见性提示清单（仅提示，见 §5）
      "bootstrap", "plans", "theme.config", "auth.login"
    ]
  },
  "build": {
    "tool": "vite@7",                       // 记录产物来源
    "node": ">=22"
  },
  "assets": [                               // 静态资源完整性（运行时校验）
    { "path": "dist/index.<hash>.css", "integrity": "sha256-<base64>" }
  ],
  "config": {                               // 主题配置（见 §5）
    "schema": "theme.config.schema.json",   // JSON Schema（draft 2020-12），严格模式
    "defaults": { "primaryColor": "#3b82f6" },
    "public": ["primaryColor", "siteBanner"]// 候选公开字段（服务端为最终权威）
  },
  "dependencies": { "runtime": [] },        // 运行时 npm 依赖必须全部打包进 dist；禁止运行时远程拉取
  "security": { "csp": "strict", "remoteCode": false },
  "author": { "name": "…", "url": "…", "license": "MIT" }
}
```

字段要点：

- **标识与版本**：`format` 防 v1 误装；`name` slug 唯一；`version` 与 `engines` 区间均 SemVer，Runtime 按区间判定兼容（GW-507 引入 Gateway/SDK 双维比对）。
- **入口与产物**：`entry.type` 区分 `spa`（编译 SPA bundle）与 `ssr`（本 ADR 冻结字段但安装策略留待 GW-506 模板验证后放开）；`assets[].integrity` 为每个部署文件提供 sha256 SRI，破坏任一即拒绝启用。
- **作者/依赖/CSP**：`author` 为归属与审计线索；`dependencies.runtime` 必须为空（依赖全部离线打包），从结构上杜绝供应链远程执行；`security` 为安装前置声明。

## 5. 能力清单与公开配置：声明 ≠ 授权

- **`gateway.capabilities` 是纯 UI 提示**。主题只能用它决定"这个按钮显示/隐藏"（例如缺少 `orders.create` 时隐藏下单入口），**不能**用来调用任何路由、也不能被 Runtime/Gateway 当作功能开关。真正的能力权威永远是 Gateway 的 `bootstrap.capabilities`（activeRouteDefinitions 投影，见 [adr-encryption-negotiation.md](./adr-encryption-negotiation.md) §D2——manifest 对策略零权重）。两者不一致时，**以 Gateway 为准**；主题对不支持的功能显式禁用，而不是 fallback 到不安全写入（implementation-plan §5.2）。
- **`gateway.contract: 1.x`**：声明主题针对 Gateway HTTP 契约 v1 编写。v2 契约出现时（`meta.version: '2'`），SDK 层 `VERSION_MISMATCH` 拒绝，主题运行时按 `engines.gateway` 区间拒绝安装——**拒绝而非降级**。
- **公开配置的服务端划分**：`config.public` 是主题**建议**的公开字段候选集；`GET /gateway/v1/theme/config` 返回的 `theme_config` 只含 **Laravel 判定为公开**的字段（[txboard-v1-source-contract.md](./txboard-v1-source-contract.md)：`ThemeService::getPublicConfig` 过滤）。manifest 声明**不能扩大**公开集——这堵住"主题自己声明就能拿到私密配置"的越权路径。`config.schema`/`defaults` 仅供主题自身渲染与 Runtime 表单校验。
- **私密字段禁令**：manifest 及包内任何文件不得携带服务端密钥、管理员 token、邮件/SMTP 凭据、支付商密钥或订阅原始 token（与 [airbuddy-design-reference.md](./airbuddy-design-reference.md) 的审查红线一致）。

## 6. 安全策略要求（安装前置）

| 项 | 要求 | 违反后果 |
| --- | --- | --- |
| 资源完整性 | 每个静态资源 `integrity` sha256；运行时校验 | 拒绝启用 |
| 远程代码 | `security.remoteCode=false` 强制；禁止远程 script/worker/WASM URL、禁止 `eval`/`new Function` | 拒绝安装（Runtime 静态扫描） |
| CSP | 主题在 `strict` CSP（无内联脚本、仅同源资源）下必须可用 | 验收用例（GW-508） |
| token 存储 | 用户 Bearer 必须由应用自行管理、按需通过 SDK `getToken` 回调注入；**禁止 localStorage 长期存储、禁止打包进产物**（与 [SDK README](../packages/theme-sdk/README.md) 会话模型一致） | 安全评审阻断 |
| 依赖 | 运行时依赖全部离线打包，`dependencies.runtime` 恒为空 | 拒绝安装 |
| 信任边界 | "受 Gateway 保护"不等于恶意主题安全保证；第三方主题需独立审查（implementation-plan §5.3） | 文档红线 |

## 7. v1/v2 共存与回滚

- v1 主题（`config.json` + `dashboard.blade.php`）与默认用户端**完全不受影响**：本 ADR 与 GW-502 不修改 v1 加载器、不改 Blade 入口、不改 `/api/v1/*`、`/api/v2/*`、`/s/*`。
- v2 主题 **opt-in**：由管理员在 TXBoard 后台显式启用；回滚 = 切回 v1 主题或上一个 v2 版本（`txboard-theme.json` 版本可追溯），无需 Gateway 侧任何操作，也不需要触碰数据库用户/订单数据。
- Gateway 演进只增不改：现有 `/gateway/v1/*` 契约字节级兼容（bootstrap 新增字段为纯增量，旧客户端忽略——GW-405 已验证该性质）。

## 8. SDK 发布策略（GW-503）

**现状**：`packages/theme-sdk`，版本 `0.1.0`，`type: module`，`engines.node >=22`，单依赖 `@hpke/core@1.9.0`（HPKE 加密路径按需动态 import），构建 `tsc` 产出 `dist/index.js` + `dist/index.d.ts`，`exports` 仅 `"."`（types/import 双条件），尚未发布 npm。

### 8.1 打包与多运行时兼容（验收口径）

| 验收 | 方式 | 现状 |
| --- | --- | --- |
| npm pack | `npm pack --workspace @txboard/theme-sdk` dry-run 检查 `files`（dist + README）与导出映射 | 已有（GW-103 基础） |
| npm 安装 | 临时目录 `npm i <tarball>` + Node 侧 `import` 冒烟 | 待发版前执行 |
| 浏览器 | ESM 直接 import（同源 `/gateway/v1`）经打包器/原生模块；Playwright mock-gateway 浏览器用例 | 已有（GW-105 基线） |
| SSR | Node + 每请求独立 client 实例，`getToken` 请求级隔离测试（无跨请求泄漏） | 已有（GW-106 基线） |
| 生成声明 | `tsc` 产出 `dist/index.d.ts`，消费方无需 `skipLibCheck` 特例 | 已有 |

铁律：**公共接口调用不附带 bearer**；SDK 不保存任何 token/凭据；敏感数据不进永久缓存；SSR 必须请求级隔离 `getToken` 与 client（跨请求复用全局 token 是已知否决项）。

### 8.2 SemVer 规则（冻结）

包名 `@txboard/theme-sdk`。契约对齐三方：Gateway HTTP 契约主版本（`meta.version`，当前 1）× SDK 主版本 × 主题 contract。

- **major（X.0.0）**：破坏性变更——移除/改名任何导出、改 `GatewayEnvelope` 线格式、移除错误码、改 `createTXBoardClient` 选项语义、提 `engines.node` 下限、放弃对 Gateway 契约旧主版本支持。major 前必先发预发布（`X.0.0-beta.N`）。
- **minor（X.Y.0）**：向后兼容新增——新方法/路由封装、新可选选项、新**附加**字段（如 bootstrap 增量字段）、新错误码（附加不删除）、新类型导出。
- **patch（X.Y.Z）**：无契约影响——bug 修复、文档、types 修正、内部重构。
- **0.x 特殊规则**（当前所处阶段）：minor 可含破坏性变更（`0.2.0` 起可 breaking）；`1.0.0` 的门槛 = **只读稳定集冻结**（M3 或"当前只读稳定集"决议：bootstrap/plans/theme-config/user.*/dashboard/orders 只读/payments 展示/notices + 登录）。`orders.create` 等写路径因 GW-303/305/306 未验收，不纳入 1.0 承诺集，以 minor 附加演进。
- **错误码纪律**：`KNOWN_ERROR_CODES` 集合（UNAUTHORIZED/…/ORDER_REJECTED）只增不删；删除码位必须 major。新码位进 minor 并在 release notes 明示。
- **支持窗口**：Gateway 契约主版本切换（1→2）时，SDK 旧 major 支持到过渡窗口结束（建议 ≥ 90 天公告，implementation-plan §7.2），旧 SDK/Gateway 主版本在窗口内仍可用。

### 8.3 发版与追溯流程

1. **冻结**：确定版本区间与只读稳定集；跑全门禁（typecheck、vitest、build、pack dry-run、browser/SSR 用例、npm audit）。
2. **CHANGELOG**：条目按 §8.2 规则归类，附行为变化与迁移说明。
3. **tag**：`theme-sdk/vX.Y.Z`（与 Gateway 应用版本 tag 区分），指向已合并 main 的 commit。
4. **release**：GitHub Release 记录 tag↔commit↔CI 运行链接↔`npm publish`（含 provenance）↔npm 制品校验和；镜像/制品 digest 记入 [release-checklist.md](./release-checklist.md) 台账。
5. **npm 发布**：`npm publish --access public --provenance`（@scope 公开包）；发布后验证 `npm view @txboard/theme-sdk` 与 tarball 安装冒烟。
6. **追溯**：任何主题包 `engines`/`gateway.sdk.version` 声明的区间必须能解析到具体 tag；GW-507 的三维协商即基于此。
7. **回滚**：npm `dist-tag` 指回上一稳定版；仓库 tag 不删（历史制品可复现）。

> 本 ADR 不修改 `.github/workflows/` 任何文件；发版门禁与 npm 令牌/认证细节以既有 [release-checklist.md](./release-checklist.md) 为准，落地为 GW-508 前的 PR。

## 9. 验收矩阵（待评审冻结后执行）

| 编号 | 断言 |
| --- | --- |
| TP-1 | 缺 `txboard-theme.json` 或 `format≠2` 的包被拒绝安装（GW-502） |
| TP-2 | 包内含 `config.json`/`dashboard.blade.php` 即拒绝；v1 主题安装路径零回归 |
| TP-3 | `assets` 完整性被破坏 → 拒绝启用；`dependencies.runtime` 非空 → 拒绝 |
| TP-4 | `capabilities` 伪造成包含未实现能力 → Gateway 行为零变化（对策略零权重） |
| TP-5 | `config.public` 声明私密字段名 → `theme/config` 仍不下发（服务端权威） |
| TP-6 | `engines.gateway` 区间不覆盖当前 Gateway 主版本 → Runtime 拒绝安装 |
| TP-7 | SDK `1.0.0` 前只读稳定集字段级冻结；tag/CHANGELOG/release 三方一致可追溯 |
| TP-8 | pack + 干净安装 + 浏览器 + SSR（双用户 token 隔离）四环境冒烟全绿 |
| TP-9 | strict CSP（无内联脚本）下主题功能无退化（GW-508 汇总） |

## 10. 安全评审要点（S 清单）

- S1：manifest 对 Gateway/Laravel 权限零权重（结构 + 负例 TP-4/TP-5）。
- S2：ZIP 路径穿越/软链/压缩炸弹由 Runtime 拦截，Gateway 不重复解包（责任边界）。
- S3：token 不入包、不入 localStorage、不跨请求复用（SDK 铁律 + 模板约束）。
- S4：公开/私密配置划分的服务端权威性（`ThemeService::getPublicConfig` 过滤不可被 manifest 扩大）。
- S5：第三方主题审查与"Gateway 保护 ≠ 恶意主题安全"的文档红线明确。

## 11. 备选方案与否决理由

| 备选 | 否决理由 |
| --- | --- |
| 直接扩展 v1 `config.json` 支持 SPA 字段 | 会改动既有 v1 加载器语义，违背"不改变旧 config.json/dashboard.blade.php"的验收硬约束；v1/v2 混淆增加迁移风险 |
| manifest 字段携带权限/capability 作为授权 | 与策略表编译期冻结（middleware-architecture）和 GW-405 零权重原则冲突；能力必须服务端权威 |
| Gateway 直接解析/校验主题包 | Gateway 不访问主题文件系统、不信任主题代码；责任归 TXBoard Runtime，缩小 Gateway 攻击面 |
| 先发 SDK 1.0.0 再冻结只读集 | 1.0 承诺的是冻结契约；当前写路径（orders.create 等）未验收，1.0 只能承诺只读稳定集 |
| manifest 携带远程依赖声明允许运行时拉取 | 供应链远程代码执行风险；一律离线打包（`dependencies.runtime` 恒空） |

## 12. 未决问题（待评审）

1. `entry.type: 'ssr'` 的安装策略是否随 GW-506 模板验证后再放开（本 ADR 冻结字段、留策略）。
2. manifest 是否引入**签名**（作者私钥）以防供应链替换——integrity 防篡改、签名防冒充，是否一期即引入。
3. SDK `@hpke/core` 是否改可选 peer（加密路径动态 import 已隔离，但当前为直接依赖）。
4. SDK 旧 major 支持窗口具体天数（建议 90 天，需产品确认）。

**回滚措施**：本文档为纯设计变更，未改任何代码/CI/测试；若评审否决，删除本文档即恢复原状，不影响 v1 主题与 Gateway 现有契约（Gateway 391/391 + SDK 32/32 基线维持）。
