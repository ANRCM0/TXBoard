# React 独立主题模板（GW-505）

React 19 + Vite 独立主题模板。**所有数据都经 `@txboard/theme-sdk` 访问 Gateway（`/gateway/v1`），任何路径都不直连 Laravel。** 骨架源码见 `src/`；设计契约与验收矩阵见 [docs/theme-templates.md](../../docs/theme-templates.md)。

## 运行

```bash
# 在 TXBoard-Gateway 工作区根（或安装本包后）：
npm install --workspace @txboard/theme-sdk
VITE_GATEWAY_PROXY_TARGET=http://127.0.0.1:8080 npm run dev --workspace react-theme
```

Gateway 默认运行在 `http://127.0.0.1:8080`（Docker Compose 内映射），本模板开发服务器把 `/gateway/v1` 同源代理过去，因此 Origin 始终一致；跨域部署时把站点精确 origin 加入 `GATEWAY_ALLOWED_ORIGINS` 并改用 HTTPS `baseURL`。

## 结构

```
src/
  api.ts                 # SDK 单实例（baseURL/getToken/encryptedLogin）、CAPTCHA 守卫、幂等键
  App.tsx                # 品牌/导航壳 + 路由
  main.tsx               # bootstrap 外观初始化 + mount（StrictMode）
  pages/
    HomePage.tsx         # bootstrap + 套餐全链路
    LoginPage.tsx        # CAPTCHA 感知登录（fail-closed）
    DashboardPage.tsx    # 资料/订阅/统计只读
    OrdersPage.tsx       # 订单列表/状态/创建（能力门控 + 幂等键）
  components/
    CaptchaField.tsx     # turnstile / recaptcha / recaptcha-v3
    ErrorState.tsx       # GatewayApiError → 可恢复 UI（含 requestId）
    PlanCard.tsx         # 套餐卡片（分单位金额仅展示转换）
  hooks/
    useAuth.ts           # 登录/注册/登出，token 生命周期归模板所有
    useBootstrap.ts      # bootstrap 单次加载 + 重试
    usePlans.ts          # 公开套餐列表
    useUserData.ts       # profile + subscription + dashboard.stats
    useOrders.ts         # 订单列表/状态/创建（写语义与 Vue 模板一致）
  lib/safe-url.ts        # 同 Luma 主题的 URL 卫生白名单
  lib/format.ts          # 分/字节/日期展示格式化（SDK 不做二次转换）
  styles/                # design tokens（bootstrap 外观只改 CSS 变量）
```

## 安全语义（与 Vue 模板字节级一致）

- **不直连 Laravel**：模板代码不出现 `/api/v1`、`/txapi`、任何上游 host；结构断言见 `examples/theme-template-check.mjs`。
- **SDK 不存 token**：`auth_data` 存 localStorage，`getToken` 注入；公开接口零 Bearer；不带任何管理员 token。
- **CAPTCHA fail-closed**：`bootstrap.security.captcha.enabled=true` 但 type/siteKey 缺失时登录按钮禁用并提示联系管理员，绝不静默跳过验证码。
- **写能力门控**：`orders.create` 仅当 `bootstrap.capabilities` 包含 `orders.create` 才出现；提交携带 `Idempotency-Key`（UUID v4，刷新前持久化）；SDK 永不自动重试写请求；`IDEMPOTENCY_IN_PROGRESS` → 提示“处理中，稍后重试”，`IDEMPOTENCY_CONFLICT` → 提示重新选择套餐，`ORDER_REJECTED` → 展示业务拒绝信息。
- **错误 UI**：401→重新登录、429→稍后重试、503→服务暂不可用、网络/超时→检查网络，全部展示 Gateway `requestId`；未知错误码提示确认远程状态。
- **URL 卫生**：服务端下发的 background/logo/外链经 `safeWebUrl` 白名单校验（禁 `javascript:`/`data:`/凭据/跨源 http）。
- **主题配置即外观**：`theme.config` 只驱动 CSS tokens，从不作为权限或业务依据。
