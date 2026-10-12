# 第三方主题开发者指南与迁移手册（GW-509）

- 状态：**设计文档交付（2026-10-11，待评审）**
- 面向：第三方主题开发者（社区），**不使用任何管理员凭据**，只经 Gateway 公开/用户接口
- 验收原话（task-backlog GW-509）：**一套从零创建主题的可复现演示，不用管理员凭据**
- 关联：[adr-theme-package-v2.md](./adr-theme-package-v2.md)（manifest 契约 GW-501）、[theme-templates.md](./theme-templates.md)（Vue/React 模板 GW-504/505）、[developer guide §3](#3-从零创建主题可复现演示) 是本手册核心交付、[release-acceptance-gw508.md](./release-acceptance-gw508.md)（发布侧总验收，GW-508）

> **你可以做什么、不可以做什么**：本文档的所有流程只用**普通用户凭据**（你自己的 TXBoard 账号 token）与**公开接口**。任何需要"管理员 token / 管理员后台 / 直连 Laravel `/api/v1` / 直连数据库"的路径都不在本文范围内——Gateway 的设计前提就是**主题不持管理员凭据、不复制业务规则、权限以 Laravel 经 Gateway 的裁决为准**。

## 1. 心智模型（先读这个）

```
你的主题（浏览器 SPA/SSR）
  └─ @txboard/theme-sdk（唯一依赖，唯一数据通道）
       └─ /gateway/v1/*（同源或白名单 origin，HTTPS）
            └─ TXBoard Laravel（业务规则、权限、幂等的唯一权威）
```

四条铁律（违反即过不了验收）：

1. **所有数据经 SDK 打 `/gateway/v1`**。你的代码里**禁止**出现 `/api/v1`、`/txapi`、任何上游 host 字面量（结构断言会拦）。
2. **不持管理员 token**。用户 token 由你的应用持有（`getToken` 注入），SDK 零存储。权限永远以 Laravel 裁决为准。
3. **manifest/能力声明不是授权**。`txboard-theme.json` 的 `capabilities` 只影响你的 UI 显示/隐藏；真正的能力权威是 Gateway 下发的 `bootstrap.capabilities`。服务端策略对客户端声明零权重。
4. **主题配置即外观**。`theme.config` 只驱动 CSS 颜色/logo/背景，**永远不作为权限或业务依据**；服务端下发的 URL 必须经 `safeWebUrl` 白名单校验（禁 `javascript:`/`data:`/凭据/跨源 http）。

## 2. 五分钟上手（用现成模板，推荐起步）

```bash
# 1. 克隆仓库并安装 SDK（workspace 内）
cd /path/to/main-repository/gateway
npm install

# 2. 起本地 Gateway（见 docker-deployment.md，私网 + 同源代理）
#    Gateway 默认 127.0.0.1:8080，模板 dev-server 把 /gateway/v1 同源代理过去

# 3. 跑模板
VITE_GATEWAY_PROXY_TARGET=http://127.0.0.1:8080 npm run dev --workspace vue-theme
# 或 --workspace react-theme
```

模板已在 `examples/vue-theme/`（Vue 3，首选）与 `examples/react-theme/`（React 19），包含登录（CAPTCHA fail-closed）、套餐、用户资料、订单只读、错误 UI 全链路。改样式 → 改 `src/styles/tokens.css`；改数据调用 → 改 `src/api.ts`。契约与安全语义见 [theme-templates.md](./theme-templates.md)。

## 3. 从零创建主题（可复现演示，不用管理员凭据）

这是本手册的核心交付：一个**从空白目录到可运行主题**的完整流程，每一步只用普通用户凭据与公开接口。

### 3.1 前置（唯一一次）

- Node ≥ 22；一个能访问 Gateway 的本地/测试环境（Gateway 部署方式见 [docker-deployment.md](./docker-deployment.md)，社区测试环境由你自建，不需要 TXBoard 管理员权限）；
- 一个**普通测试账号**（你自己的 TXBoard 账号）用于验证登录后只读接口。管理员后台、Laravel 数据库、支付回调**全程不接触**。

### 3.2 Step 1 — 初始化项目

```bash
mkdir my-theme && cd my-theme
npm init -y && npm i vue@3 vue-router@4 @txboard/theme-sdk@0.1.0
npm i -D vite @vitejs/plugin-vue typescript vue-tsc
# 本演示用 Vue；React 版把插件换成 @vitejs/plugin-react、依赖换 react/react-dom
```

`vite.config.ts` 关键是**同源代理**（保证 Origin 与 Gateway 白名单一致，且主题从不直连上游）：

```ts
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  server: {
    proxy: {
      '/gateway/v1': {
        target: process.env.VITE_GATEWAY_PROXY_TARGET || 'http://127.0.0.1:8080',
        changeOrigin: false, // Origin 保持与站点一致
      },
    },
  },
})
```

### 3.3 Step 2 — SDK 单实例（唯一数据通道）

```ts
// src/api.ts
import { createTXBoardClient } from '@txboard/theme-sdk'

export const api = createTXBoardClient({
  baseURL: '/gateway/v1',          // 同源；跨域时用 HTTPS 且 origin 必须在白名单
  getToken: () => localStorage.getItem('auth_data'), // token 生命周期归你的应用
  encryptedLogin: true,            // 可选：仅当 Gateway 启用 HPKE+Redis
})
```

### 3.4 Step 3 — bootstrap 与能力门控

```ts
// 公开调用：零 Bearer
const { site, theme, security, capabilities } = await api.bootstrap()

// 能力门控：只有服务端通告的能力才渲染入口；客户端猜测零权重
const canCreateOrder = capabilities.includes('orders.create') // 默认部署不含此能力
```

`security.captcha.enabled=true` 但 `type`/`siteKey` 缺失 → **登录按钮禁用 + 告警，绝不静默放行**（fail-closed，契约原话）。

### 3.5 Step 4 — 页面与只读链路

```ts
const plans = await api.plans.list()                    // 公开
const profile = await api.user.profile()                // Bearer（登录后）
const orders = await api.orders.list({ status: 0 })     // Bearer，只读
const detail = await api.orders.detail(tradeNo)
```

登录（CAPTCHA 感知）：

```ts
const login = await api.auth.login({
  email, password,
  turnstile_token,          // 从 bootstrap 的 captcha 元数据渲染对应 widget 后取得
})
localStorage.setItem('auth_data', login.auth_data) // 你的应用存 token，SDK 不存
```

错误 UI：`GatewayApiError` → 可恢复提示（401 清 token 跳登录；429 尊重 `Retry-After`；503 服务暂不可用；未知码提示"结果不确定请确认"），并展示服务端 `requestId` 便于排查。写请求 **SDK 永不自动重试**（详见 §5）。

### 3.6 Step 5 — 安全语义自查（结构断言）

仓库提供 `examples/theme-template-check.mjs`，把你的主题源码放进 `examples/` 同级后运行，断言：

| 断言 | 含义 |
| --- | --- |
| TPL-1 | 源码不含 `/api/vN`、`/txapi` 字面量 |
| TPL-2 | 源码不含 Laravel host/URL 字面量 |
| TPL-3 | 必须引用 `@txboard/theme-sdk` |
| TPL-4 | 不含 admin token / 密钥字面量 |
| TPL-5 | 负例：注入 `fetch('/api/v1/user')` 断言脚本非零退出 |

### 3.7 Step 6 — manifest 与打包（v2 主题包）

根目录放 `txboard-theme.json`（`format:2` 防 v1 误装），完整字段规范见 [adr-theme-package-v2.md](./adr-theme-package-v2.md) §4。最小可用：

```jsonc
{
  "format": 2,
  "name": "my-theme",                 // slug ^[a-z0-9-]{2,64}$ 全站唯一
  "version": "1.0.0",
  "entry": { "type": "spa", "module": "dist/index.js" },
  "engines": { "txboard": ">=3.6", "gateway": ">=1 <2", "contract": "1.x" },
  "gateway": {
    "contract": "1.x",
    "sdk": { "package": "@txboard/theme-sdk", "version": ">=1.0.0 <2.0.0" },
    "capabilities": ["bootstrap", "plans", "theme.config", "auth.login"] // 仅 UI 提示
  },
  "build": { "tool": "vite@7", "node": ">=22" },
  "assets": [{ "path": "dist/index.<hash>.css", "integrity": "sha256-<base64>" }],
  "config": { "schema": "theme.config.schema.json", "defaults": {}, "public": [] },
  "dependencies": { "runtime": [] },  // 恒空：依赖全部离线打包
  "security": { "csp": "strict", "remoteCode": false },
  "author": { "name": "you", "license": "MIT" }
}
```

打包纪律：ZIP 内**不得**含 `config.json`/`dashboard.blade.php`（那是 v1 安装文件）、`.env`、私钥、可执行脚本、符号链接或路径穿越条目；每个静态资源带 sha256 SRI；strict CSP 下必须可用；**token 不入包**。

```bash
npm run build
# dist/ + assets/ + txboard-theme.json → zip 为 my-theme.zip
# assets[].integrity 用 sha256(base64) 计算
```

> 安装/切换由 TXBoard 后台（管理员操作，GW-502 Theme Runtime）完成——**这不属于你的开发流程**。你交付的是合规的 ZIP；管理员安装后，你的主题按 `engines` 区间被 Runtime 校验兼容性。

### 3.8 Step 7 — 验证清单（自检即验收）

- [ ] 结构断言 TPL-1～5 通过（§3.6）
- [ ] 空态 bootstrap 登录 → 套餐 → 订单只读全链路绿
- [ ] CAPTCHA enabled 但配置损坏时按钮禁用 + 告警、零请求发出
- [ ] 401/429/503/断网有对应错误 UI + `requestId`
- [ ] `orders.create` 未通告时入口不出现（默认部署）
- [ ] 主题配置含恶意 URL（`javascript:`/跨源 http）被 `safeWebUrl` 拒绝
- [ ] strict CSP 下功能无退化
- [ ] 全程未出现管理员 token / 直连 Laravel

## 4. SDK 契约速查

- 公开（零 Bearer）：`bootstrap()`、`theme.config()`、`plans.list()`、`auth.login()`
- 用户（Bearer，由你的 `getToken` 注入）：`user.profile()`、`user.subscription()`、`dashboard.stats()`、`orders.list/status/detail()`、`payments.list()`（只读展示）、`notices.list()`
- 账户写（默认关闭，需 HPKE+Redis 与开关）：`auth.register()`、`auth.sendEmailCode()`
- 订单写（默认 405，仅能力通告后出现）：`orders.create(payload, { idempotencyKey })`
- 完整线格式与错误码：[gateway-v1.md](../contracts/gateway-v1.md) + SDK README
- 错误码只增不删；`VERSION_MISMATCH` = 契约/版本不兼容，联系部署管理员（**拒绝而非降级**）

## 5. 写请求与幂等（orders.create）

`orders.create` 要求 `Idempotency-Key`（UUID v4，1–128 位 URL 安全字符；缺失/非法 → 400 零上游调用）。你的职责：

- 每个"逻辑订单草稿"生成一次键，**刷新前持久化**（sessionStorage/localStorage，键含 plan_id/period）——刷新/重进页面复用同一键；
- `IDEMPOTENCY_IN_PROGRESS`(409) → 提示"处理中"，退避后**以同一键**由用户重试（可轮询 `orders.status(tradeNo)`）；
- `IDEMPOTENCY_CONFLICT`(409) → 草稿被复用，要求重新选择（不同 body 同键是正确拒绝）；
- `ORDER_REJECTED`(422) → 展示业务拒绝信息（不回显上游原文）；
- **UI 禁止自动重发写请求**。权威幂等键由 Laravel 以认证 user_id 派生——你传的键是关联与去重提示，不是权威。

## 6. v1 → v2 迁移指南

v1 主题（`config.json` + `dashboard.blade.php`）与 v2（`txboard-theme.json` + 编译产物）**并存、互不影响**；v1 加载器零改动，迁移是**自愿且单向**的（可从 v2 切回 v1）。

### 6.1 何时需要迁移

- 你要做**独立 SPA/SSR 主题**（编译产物、声明 Gateway/SDK 版本区间与能力）；
- v1 装载器不要求支持 SPA manifest——SPA 主题**只能**走 v2。

### 6.2 迁移映射

| v1（现状） | v2（迁移后） | 说明 |
| --- | --- | --- |
| `config.json` 主题元数据 | `txboard-theme.json`（`format:2`） | 新文件、新入口；**不覆盖** v1，两文件不会共存于同一包（含 v1 文件即拒绝安装） |
| Blade 视图 `dashboard.blade.php` | `dist/index.js`（`entry.module`） | 编译 SPA/SSR bundle；SPA 用 hash 路由或配 fallback，SSR 预留（GW-506） |
| 隐式版本/无 engines | `engines` 三维区间（txboard/gateway/contract） | 不兼容 → 拒绝而非降级 |
| 主题配置直接来自服务端 | `config.schema/defaults/public` + 服务端权威过滤 | manifest 的 `public` 是**候选**公开集，不能扩大 Laravel 下发的公开字段 |
| 无能力声明 | `gateway.capabilities`（仅 UI 提示） | 真能力以 `bootstrap.capabilities` 为准 |
| 数据直连 `/api/v1` 或 Blade 内联查询 | `@txboard/theme-sdk` → `/gateway/v1` | **唯一数据通道**；结构断言强制 |
| 无资源完整性 | `assets[].integrity` sha256 SRI | 破坏任一即拒绝启用 |

### 6.3 迁移步骤

1. **评估**：你的主题是否需要独立 SPA/SSR（v2 的唯一动机）；纯 Blade 增强留在 v1，无需迁移。
2. **选模板**：`examples/vue-theme/`（首选）或 `examples/react-theme/`，按 §3 流程从零改造你的 UI 到模板骨架。
3. **声明 manifest**：按 §3.7 填写 `txboard-theme.json`；`gateway.sdk.version` 区间必须能解析到已发布 SDK tag。
4. **迁移数据访问**：把所有 `/api/v1`/内联查询调用替换为 SDK 调用（TPL-1/2 断言保证无遗漏）；权限判定移除，交给 Gateway bootstrap capabilities + Laravel。
5. **迁移配置**：外观配置迁到 `config.schema/defaults`；确认 `public` 只列真正该公开的字段（服务端会再过滤）。
6. **打包与完整性**：build → 计算 `assets[].integrity` → zip（遵守 §3.7 打包纪律）。
7. **验证**：§3.8 清单 + 结构断言 + v1/v2 并存切换测试（RT-2 口径）。
8. **发布/回滚**：ZIP 交管理员安装（GW-502 Runtime 校验 + 安装）；回滚 = 切回 v1 或上一 v2 版本，无需 Gateway 侧操作、不碰数据。

### 6.4 迁移红线

- 迁移**不改** v1 主题行为——v1 与其默认 Blade 用户端完全不受影响；
- manifest 声明**不能**变成权限/功能开关（TP-4/TP-5 负例）；
- 不得把管理员凭据、SMTP 密钥、支付密钥、订阅原始 token 打进包；
- 运行时依赖恒空（全部离线打包），禁远程代码（`remoteCode:false`、禁 `eval`/`new Function`）。

## 7. 常见问题

| 问题 | 答案 |
| --- | --- |
| 403 ORIGIN_DENIED | 站点精确 origin 不在 `GATEWAY_ALLOWED_ORIGINS`；跨域必须 HTTPS 且被列入白名单（联系部署管理员，非你的代码问题） |
| 405 on `POST /gateway/v1/orders` | 写能力默认关闭；等 `bootstrap.capabilities` 通告 `orders.create` 再渲染入口 |
| 404 on register/email-code | 账户工作流默认关闭；`GATEWAY_ACCOUNT_WORKFLOWS_ENABLED=false` 时路由缺席 |
| `VERSION_MISMATCH` | SDK/Gateway/主题 contract 版本不兼容；按 `engines` 升级 SDK 或联系管理员 |
| 登录 401 | 清 token 跳登录，不做自动重登 |
| 我能用管理员 token 调更多接口吗 | **不能**。Gateway 透传语义下管理员 token 会被 Laravel 拒绝（401）；权限以 Laravel 为准 |

## 8. 边界与未验收

- **本手册为文档交付**：未改任何代码/CI（`.github/workflows/` 零改动）、未 git commit。SDK 当前 `0.1.0` 为 workspace 版，正式发布（GW-503）后本指南的版本区间以发布 tag 为准。
- **Theme Runtime（GW-502）未实现**：§3.7 的安装/切换由管理员在 TXBoard 后台完成，本文只定义交付物标准；真实安装验收待 GW-502。
- **SSR 模板（GW-506）未做**：本演示为纯 CSR；SSR 迁移须保持"每请求隔离 client 实例与 `getToken`"。
- **版本协商（GW-507）未落地**：manifest 的 `engines` 判定由 Runtime 实现，当前模板仅用 capabilities 门控 UI。
- 第三方主题的**独立安全审查**是安装前提（"受 Gateway 保护 ≠ 恶意主题安全"，[adr-theme-package-v2.md](./adr-theme-package-v2.md) S5）。
