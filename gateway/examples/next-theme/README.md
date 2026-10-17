# Next.js SSR 独立主题模板（GW-506）

Next.js 14+ App Router 独立主题模板。**所有数据都经 `@txboard/theme-sdk` 服务端访问 Gateway（`/gateway/v1`），任何路径都不直连 Laravel。** 骨架源码见 `src/`；设计契约与验收矩阵见 [当前开发者指南](../../docs/developer-guide.md)。

## 运行

```bash
npm install --workspace @txboard/theme-sdk
# Gateway 默认同源 /gateway/v1；跨源时设 GATEWAY_BASE_URL=https://gateway.example.com/gateway/v1
GATEWAY_BASE_URL=/gateway/v1 npm run dev --workspace next-theme
```

生产部署需在反代把 `/gateway/v1` 同源代理到 Gateway，或将站点精确 origin 加入 `GATEWAY_ALLOWED_ORIGINS` 并设 HTTPS `GATEWAY_BASE_URL`。

## 结构

```
src/
  lib/server.ts            # 'server-only'：请求级 createClient()（无模块单例）、CONTRACT_VERSION、hasSession
  lib/version.ts           # GW-507：三维版本协商 negotiateVersion() + hasCapability 门控
  lib/safe-url.ts          # URL 卫生白名单（与 Vue/React 模板一致）
  lib/format.ts            # 分/字节/日期格式化
  app/
    layout.tsx page.tsx            # 首页（bootstrap + plans，公开）
    login/actions.ts page.tsx      # 登录 server action（httpOnly cookie）+ 登录页
    dashboard/page.tsx             # 用户中心（受保护只读）
    orders/page.tsx                # 订单列表（受保护只读 + 能力门控）
    version-incompatible/page.tsx  # GW-507 版本不兼容拒绝页
  components/              # ErrorState / PlanCard（无客户端 token 逻辑）
  styles/                  # tokens.css / base.css
next.config.mjs            # strict CSP + 安全响应头
tsconfig.json package.json
```

## 安全语义（与 Vue/React 模板一致的契约，加 SSR 特有隔离）

- **不直连 Laravel**：模板代码不出现 `/api/v1`、`/txapi`、任何上游 host；结构断言见 `examples/theme-template-check.mjs`。
- **服务器 token 隔离（SSR 特有）**：登录 server action 在服务端完成，`auth_data` **只**写入 httpOnly+secure+sameSite=lax cookie；浏览器 JS 永远读不到 token（不代表可防止 XSS 借用户身份发起请求），token 从不进 localStorage、不进 RSC 序列化载荷、不进客户端 bundle。
- **无跨请求 session 污染**：`createClient()` 必须每请求调用一次，`getToken` 从**该请求**的 cookie 读取；**禁止模块级 SDK 单例**（会捕获第一个请求的 token 并重放给所有后续用户——本任务的核心否决项）。
- **CAPTCHA fail-closed**：`bootstrap.security.captcha.enabled=true` 但 type/siteKey 缺失时登录不可用（server action 侧应加同样守卫；骨架省略 widget 渲染细节）。
- **写能力门控（GW-507）**：`orders.create` 仅当 `bootstrap.capabilities` 包含该能力才出现——这是 UI 可见性提示，**不是授权**；未通告时直接调用仍被 Gateway 405 拒绝。
- **版本协商（GW-507）**：任何受保护调用前先 `negotiateVersion()` 三维比对（Gateway contract × SDK × 主题 manifest engines），不兼容即渲染 `/version-incompatible` 拒绝页，**绝不静默降级**。
- **错误 UI**：`GatewayApiError` → 可恢复文案，展示 `requestId`；`VERSION_MISMATCH` → 版本不兼容提示。
- **URL 卫生**：服务端下发的 logo/外链经 `safeWebUrl` 白名单校验（禁 `javascript:`/`data:`/凭据/跨源 http）。
- **主题配置即外观**：`theme.config` 只驱动 CSS tokens，从不作为权限或业务依据。
- **strict CSP**：`next.config.mjs` 强制无内联脚本（GW-501 §6 / TP-9 验收口径）。

## 当前限制

本目录是已有示例，不是正式发布或生产验收。当前订单创建仍用历史
`plan_id/period`，不兼容 Native Core `offer_id`；能力通告/开启写入开关
不能视作 Native 购买已可用。Core 仅安装静态 SPA 主题包，不执行 SSR 上传代码。
新前端开发仍归独立 TX-F，已有示例构建/浏览器验收由 CI 集中执行。
