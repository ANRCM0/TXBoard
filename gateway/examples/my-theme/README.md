# my-theme

一个从零创建的完整 TXBoard 主题（按 [docs/developer-guide.md](../../docs/developer-guide.md) §3 流程），
Vue 3 + Vite，通过 `@ANRCM0/theme-sdk` 与 Gateway（`/gateway/v1`）通信——**任何路径都不直连 Laravel**。

## 页面

| 路由 | 页面 | 说明 |
| --- | --- | --- |
| `/` | 首页 | `bootstrap()` + `plans.list()` 公开链路；logo 经 `safeWebUrl` 校验 |
| `/login` | 登录 | CAPTCHA 感知，配置损坏时 fail-closed（按钮禁用 + 告警，零请求） |
| `/dashboard` | 用户资料 | `user.profile()` + `user.subscription()` 只读（Bearer） |
| `/orders` | 订单列表 | `orders.list()/status()` 只读 + 能力门控的 `orders.create` |

## 安全语义

- **唯一数据通道**：所有数据经 `@ANRCM0/theme-sdk` → `/gateway/v1`；源码中不存在 `/api/v1`、`/txapi` 或上游 host 字面量（结构断言强制）。
- **不持管理员 token**：用户 token 由本主题存 localStorage 并经 SDK `getToken` 注入；SDK 零存储。
- **CAPTCHA fail-closed**：`bootstrap.security.captcha.enabled=true` 但 `type`/`siteKey` 缺失 → 登录按钮禁用并提示联系管理员，绝不静默跳过。
- **`orders.create` 幂等键**：仅当 `bootstrap.capabilities` 通告 `orders.create` 时渲染创建入口；幂等键按 `plan_id + period` 生成一次并持久化到 sessionStorage，页面刷新复用同一键，杜绝静默重复下单；SDK 永不自动重试写请求。
- **错误 UI**：`GatewayApiError` → 可恢复提示（401 清 token 跳登录、429 尊重退避、503 暂不可用、未知码提示确认状态），并展示服务端 `requestId`。
- **URL 卫生**：服务端下发的 logo/外链经 `safeWebUrl` 白名单校验（禁 `javascript:`/`data:`/凭据/跨源 http）。
- **主题配置即外观**：`theme.config` 只驱动 CSS tokens，从不作为权限或业务依据；manifest 声明零权重。

## 运行

```bash
npm install --workspace @ANRCM0/theme-sdk
cd examples/my-theme && npm install
VITE_GATEWAY_PROXY_TARGET=http://127.0.0.1:8080 npm run dev
```

Gateway 默认 `http://127.0.0.1:8080`；dev-server 把 `/gateway/v1` 同源代理过去，Origin 始终一致。
跨域部署时把站点精确 origin 加入 `GATEWAY_ALLOWED_ORIGINS` 并使用 HTTPS `baseURL`。

## 结构断言

```bash
node examples/theme-template-check.mjs
```

TPL-1～5 覆盖本主题（禁上游直连 / 必须引用 SDK / 无密钥字面量 / 负例验证）。

## 打包（v2 主题包）

```bash
npm run build
# dist/ + assets/ + txboard-theme.json → my-theme.zip
```

`txboard-theme.json` 中 `assets[].integrity` 用 `sha256(base64)` 计算实际构建产物。
安装/切换由 TXBoard 管理员在后台完成（GW-502 Theme Runtime），不属于开发流程。
