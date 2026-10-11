# 独立主题模板（GW-504 Vue / GW-505 React）

> 状态：**设计文档交付（2026-10-11）**。本文定义 Vue 3（GW-504，首选）与 React 19（GW-505）两套独立主题模板的契约、安全语义与验收矩阵，并附完整源码骨架（`examples/vue-theme/`、`examples/react-theme/`）。
> **前置未验收**：GW-503（SDK 正式发布与 SemVer 策略）、GW-507（运行时版本协商与能力隔离）。模板以 SDK 的 **workspace 当前契约** 为基线，与正式发布版本对齐属于 GW-503 范围。真实 Gateway 端到端联调（GW-216）与生产发布（GW-508）仍待做。

## 1. 定位与不变量

两套模板是 **M5"多框架独立主题"** 的参考实现：

| 不变量 | 含义 |
| --- | --- |
| **唯一数据通道 = Gateway** | 所有请求经 `@txboard/theme-sdk` 打到 `/gateway/v1`。模板代码禁止出现 `/api/v1`、`/txapi`、任何上游 host 字面量（结构断言见 §7）。 |
| **主题无直连 Laravel 路径** | 不复制 Laravel 业务规则、不持管理员 token、不碰支付回调/订阅原文 token；权限判定始终以 Laravel 经 Gateway 的裁决为准。 |
| **框架只影响 UI 层** | Vue 与 React 的差异只在渲染（`<script setup>`/hooks）；SDK 调用序列、capability 门控、错误映射、幂等语义**逐字节一致**。 |
| **主题配置即外观** | `bootstrap.theme.config` 只驱动 CSS tokens（颜色/背景/logo），**永远不作为权限或业务依据**。 |
| **服务端 URL 卫生** | bootstrap/主题配置下发的 URL 一律经 `safeWebUrl` 白名单（HTTPS-only、禁 `javascript:`/`data:`/凭据、同源相对路径、回环 http 仅开发）。 |

部署形态：静态托管 SPA（Vue 用 hash 路由、React 用 BrowserRouter，生产建议把 SPA fallback 配到同一静态服务）。Gateway 必须可达于同源 `/gateway/v1`，或站点精确 origin 在 `GATEWAY_ALLOWED_ORIGINS` 名单内且 baseURL 为 HTTPS。开发期由 Vite dev-server 同源代理（`vite.config.ts` 的 `proxy`），保证 Origin 始终一致。

## 2. 全链路地图（两模板共有）

| 链路 | SDK 调用 | 认证 | 模板行为 |
| --- | --- | --- | --- |
| 主题初始化 | `bootstrap()` | 公开 | 读取 `site/theme/security.captcha/capabilities`，应用外观 CSS 变量、标题、logo（经 safe-url）；失败不致命，页面各自呈现错误态。 |
| 主题配置 | `theme.config()` | 公开 | 仅当需要实时重取外观时使用；bootstrap 已含同一数据。 |
| 套餐 | `plans.list()` | 公开 | 渲染 `Plan[]`；金额为分单位，展示层 `formatMoney` 换算（SDK 不做二次转换）。 |
| 登录 | `auth.login({email,password,…})` | 公开+CAPTCHA | 从 bootstrap 读取 captcha 元数据渲染对应 widget；`enabled=true` 而 type/siteKey 缺失 → **登录按钮禁用 + 告警**（fail-closed）。可选 `encryptedLogin: true`（需 Gateway HPKE+Redis）；SDK 对密码操作绝不降级明文。 |
| 注册/邮箱码 | `auth.register()` / `auth.sendEmailCode()` | 仅密文 | 默认不可用（`GATEWAY_ACCOUNT_WORKFLOWS_ENABLED=false` 时路由 404）；capability 未通告则入口不渲染。 |
| 用户 | `user.profile()` / `user.subscription()` / `dashboard.stats()` | Bearer | token 由模板持有（localStorage `auth_data`），经 `getToken` 注入；SDK 自身零存储。 |
| 订单只读 | `orders.list()/status()/detail()` | Bearer | 状态刷新走轻量 `status` 投影；金额/状态展示。 |
| 订单创建 | `orders.create(payload,{idempotencyKey})` | Bearer + 幂等键 | **仅当 `capabilities.includes('orders.create')`**（Gateway 默认 405 时不通告）；键 = UUID v4 且刷新前持久化（见 §4）。 |
| 支付方式展示 | `payments.list()` | Bearer | 只读展示，非交易入口。 |
| 通知 | `notices.list({current,pageSize})` | Bearer | 分页 ≤100。 |
| 公钥发现 | `/crypto/key`（SDK 内部） | 公开 | 仅 `encryptedLogin` 或注册/发码路径触发；SDK 每次操作前发现、不缓存。 |

## 3. 安全语义（两侧一致的契约）

1. **CAPTCHA fail-closed**：`enabled=true` 且（`!type || !siteKey`）时禁止任何无验证码提交——渲染禁用按钮与"配置不完整"告警，**绝不静默放行**（契约原话：前端必须阻止，而不是静默禁用）。
2. **Token 边界**：SDK 不保存 token/cookie/会话；模板存储 `auth_data`，公开接口零 Bearer；**任何路径不出现管理员 token**（结构断言）。401 时清 token 并跳登录，不做自动重登。
3. **能力门控（capability gating）**：`bootstrap.capabilities` 是部署实际能力的投影（如 `orders.create` 仅在 `GATEWAY_ORDER_WRITE_ENABLED=true` 时通告、`auth.login.encrypted` 仅在 HPKE 启用时通告）。UI 元素只在能力存在时出现；**客户端猜测能力零权重**（服务端策略不认头/manifest 权重，与 GW-405 协商语义一致）。
4. **错误映射**（`GatewayApiError` → 可恢复 UI）：
   - `UNAUTHORIZED` → 提示重新登录并清 token；
   - `RATE_LIMITED`(429) → 提示稍后重试（GET 的 SDK 自动重试已尊重 `Retry-After` 封顶 60s，UI 不叠加）；
   - `UPSTREAM_UNAVAILABLE`/`GATEWAY_ERROR`(503) → 服务暂不可用；
   - `NETWORK_ERROR`/超时 → 检查网络；
   - `VERSION_MISMATCH` → 版本不兼容提示联系管理员；
   - 未知错误码 → 提示"结果不确定，请确认订单状态再操作"（`needsStateConfirmation`），展示服务端 `requestId` 便于日志关联。
5. **写请求永不自动重试**（SDK 内建）：`IDEMPOTENCY_IN_PROGRESS`(409) → 退避后**以同一键**由用户/页面重试；`IDEMPOTENCY_CONFLICT`(409) → 草稿被复用，要求重新选择；`ORDER_REJECTED`(422) → 展示业务拒绝信息（不回显上游原文）。UI 层禁止自动重发写请求。
6. **金额与格式**：所有金额为分单位整数字段，模板只做展示换算；日期为 epoch 秒或 ISO（以各 DTO 为准），模板格式化。

## 4. 幂等键生命周期（模板职责）

`orders.create` 要求 `Idempotency-Key`（1–128 位 URL 安全字符，缺失/非法 → 400 零上游调用）。模板策略：

- 每个"逻辑订单"（用户选中 plan+period+coupon 的草稿）生成一次 UUID v4；
- 草稿持久化于 sessionStorage/localStorage（键含 plan_id/period），**刷新/重进页面复用同一键**；
- 收到 `IDEMPOTENCY_IN_PROGRESS` 后，页面提示"处理中"并可轮询 `orders.status(tradeNo)`；重试时**必须带同一键**；
- 用户主动改变草稿内容 → 生成新键（不同 body 同键 = CONFLICT，属正确拒绝）；
- Laravel 以认证 user_id 派生权威键（`X-Idempotency-Key-Hint` 仅关联），Gateway 不做本地去重。

## 5. 框架差异对照（仅 UI 层）

| 维度 | Vue（GW-504） | React（GW-505） |
| --- | --- | --- |
| 运行时 | Vue 3.5 + vue-router 4（hash） | React 19 + react-router-dom 7（BrowserRouter） |
| 状态 | `<script setup>` + composables（`useAuth` 等） | hooks（`useAuth`/`useBootstrap`/`useOrders`…） |
| 构建 | Vite + @vitejs/plugin-vue，vue-tsc | Vite + @vitejs/plugin-react，tsc |
| CAPTCHA 组件 | `CaptchaField.vue`（props captcha/tokens） | `CaptchaField.tsx`（props captcha） |
| 错误组件 | `ErrorState.vue`（事件 retry） | `ErrorState.tsx`（回调 onRetry） |
| SDK/安全语义 | **完全相同**（同一调用序列与映射） | **完全相同** |

## 6. 源码骨架位置

```
examples/vue-theme/      # GW-504：Vue 3 + Vite（首选）
  src/api.ts             # SDK 单实例、token 存储、newIdempotencyKey
  src/router.ts main.ts App.vue
  src/pages/             # Home / Login / User / Orders
  src/components/        # CaptchaField / ErrorState / PlanCard
  src/composables/       # useAuth
  src/lib/               # safe-url / format
  src/styles/            # tokens.css / base.css
examples/react-theme/    # GW-505：React 19 + Vite
  src/api.ts
  src/App.tsx main.tsx
  src/pages/             # Home / Login / Dashboard / Orders
  src/components/        # CaptchaField / ErrorState / PlanCard
  src/hooks/             # useAuth / useBootstrap / usePlans / useUserData / useOrders
  src/lib/               # safe-url / format
  src/styles/            # tokens.css / base.css
```

骨架是**可编译交付的最小全链路**：模板自含 `package.json`（依赖 `@txboard/theme-sdk@0.1.0` workspace 版）、`vite.config.ts`（同源代理）、`tsconfig.json`、README。骨架**不接入** CI（不修改 `.github/workflows/`，见 §8）。

## 7. 验收矩阵（模板自检 + 人工）

结构断言（`examples/theme-template-check.mjs`，已实现并通过）：

| # | 断言 | 结果 |
| --- | --- | --- |
| TPL-1 | 模板源码不含 `/api/vN`、`/txapi` 路径字面量 | ✅ 通过 |
| TPL-2 | 模板源码不含 Laravel host/URL 字面量 | ✅ 通过 |
| TPL-3 | 模板必须引用 `@txboard/theme-sdk` | ✅ 通过 |
| TPL-4 | 模板不含 admin token / 密钥字面量 | ✅ 通过 |
| TPL-5 | 负例验证：注入 `fetch('/api/v1/user')` 断言脚本必须以非零退出 | ✅ 通过（已验证） |

人工/环境验收（需真实 Gateway，待 GW-216 联调）：

| # | 场景 | 期望 |
| --- | --- | --- |
| E2E-1 | 空态 bootstrap（无 CAPTCHA）登录→仪表盘→套餐→订单 | 全链路绿；token 注入正确 |
| E2E-2 | CAPTCHA 启用但配置损坏（enabled=true, siteKey 缺失） | 登录按钮禁用 + 告警；无任何请求发出 |
| E2E-3 | 登录 401 / 429 / 503 / 网络中断 | 对应错误 UI + requestId；GET 429 尊重 Retry-After |
| E2E-4 | `orders.create` 能力未通告（默认部署） | 创建入口不出现；直接调用得 405 |
| E2E-5 | 启用 `GATEWAY_ORDER_WRITE_ENABLED` 后同键重放 | 同一 trade_no；`IDEMPOTENCY_IN_PROGRESS` 时退避重试同键 |
| E2E-6 | 跨域部署（origin 未列入白名单） | 403 ORIGIN_DENIED，模板呈现错误态而非无限重试 |
| E2E-7 | 主题配置含恶意 URL（`javascript:`/跨源 http） | safe-url 拒绝，不注入 DOM |
| E2E-8 | React/Vue 双侧同脚本回放 | 请求序列与错误映射逐字节一致（同一 fetch 记录） |

## 8. 边界与未验收

- **未改任何代码/CI**：本交付为 `examples/` 骨架 + 文档；不触碰 `.github/workflows/`、不接入 `npm test`、不 git commit。
- **SDK 未正式发布**：模板依赖 workspace 版本；GW-503 发布/SemVer 后需 bump 并重验。
- **GW-507 版本协商未做**：模板目前不做客户端版本声明与运行时拒绝，能力门控仅用 capabilities 通告。
- **SSR（GW-506）**：本文模板为纯 CSR；React 版迁移到 SSR 时须保持"每请求隔离 client 实例与 getToken"（模板 hook 结构天然支持，但未实现/未测试）。
- **旧 Blade 主题共存**：独立模板与 `config.json`/`dashboard.blade.php` 旧主题并存（不改旧主题），切换/回滚属 GW-502 Runtime 范围。
