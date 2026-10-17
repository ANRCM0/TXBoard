# TXBoard Gateway

Gateway 源码位于主仓 `gateway/`，Node.js / Hono / TypeScript，独立依赖锁、镜像和运行权限。它是可选的用户主题 API BFF，并提供 workspace 包 `@txboard/theme-sdk`；不是 Core 业务内核或通用代理。当前 API 为 `/gateway/v1`，外部 envelope 为 `{ok,data,meta}`。

Core 保留认证/CAPTCHA、账户/权限、订单、钱包/账本、退款、权益和 Node 控制权。Gateway 只选择固定操作、校验/投影 DTO，不持业务数据库或共享管理员 Bearer。

[文档入口](docs/README.md) · [当前实现/验证](docs/development-status.md) · [HTTP 契约](contracts/gateway-v1.md) · [安全政策](SECURITY.md)

## 当前能力与限制

- 公开 bootstrap、主题配置、套餐、登录，以及用户 profile/权益摘要、订单/分页/状态、支付方式展示、通知/计数的现有适配器。
- 可选 HPKE 请求加密及 Redis replay；已实现双 key、旧 key 过期/吊销、路由限流和脱敏观测。注册/发码默认关闭，没有 HPKE 失败后的明文回退。
- 服务端 `NativeCoreClient`/CLI 使用独立 User/Admin mTLS；当前仅 login/guestConfig 浏览器适配可选择 User service，不等于管理员浏览器 API 已开放。
- 订单写默认 405、零上游调用；保留的 `plan_id/period` body **不兼容 Native `offer_id` 创建**。打开历史开关不是购买链路验收。
- 匿名支付目录固定 404；无 checkout/cancel、支付回调或管理员浏览器路由。`/txapi/bff/v1`、正式 SDK npm 发布、生产部署和网络 SC-v2 未因这些适配器交付。

只将 `/gateway/v1/*` 分流到 Gateway。Core 管理、支付回调、私密订阅、插件、Node/WebSocket 各走已注册入口及独立鉴权；不恢复退役 Agent/MCP 或 `/api/v1`、`/api/v2`。

## 运行与验收

命令从主仓先 `cd gateway`；部署只操作 Gateway 私网容器，不新增公网 8787/Redis 映射。HPKE 私钥及服务 mTLS/credential 不入镜像、仓库或浏览器。具体现行组合与配置见 [Docker Runbook](docs/docker-deployment.md) 和 [Native 接入](docs/native-core-integration.md)。

本地日常只做必要静态检查；完整构建、数据库/Redis、浏览器与容器验收统一由根 Actions 执行。以下是 CI 的检查入口，不要求文档修改逐一重跑：

```sh
npm ci
npm run check
npm run test:redis
npm run test:browser
```

[合仓运行约定](docs/monorepo-current.md)说明真实工作流、独立镜像和 SDK 发版边界。历史联调/JSON 结果只能证明当时源码及环境，不替代当前 Native DTO、生产 PKI/代理链或资金业务验收。

## SDK 与主题

SDK 不保存会话，公开请求不带 Bearer；SSR client 必须请求级隔离。当前第三方安装包只有 Core Theme Package v2 静态 SPA，不支持旧 Blade 包或执行 SSR 上传代码。新前端开发仍在独立 TX-F，不在本仓新增主题迁移计划。

[SDK 使用](packages/theme-sdk/README.md) · [现有示例指南](docs/developer-guide.md) · [Core 当前主题包](../contracts/theme-package/README.md) · [加密/离线实验边界](docs/app-crypto-preview.md)。
