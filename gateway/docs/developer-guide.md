# Gateway SDK 与现有主题示例指南

新管理端/用户端开发归独立 TX-F，当前仓库只维护实际存在的 SDK/示例及其接口，不新增前端计划。Gateway 当前是 `/gateway/v1` 用户 BFF，不是任意 Core API 代理，也没有管理员浏览器接口。

## 工作区与数据通道

SDK 位于 `gateway/packages/theme-sdk`，包名 `@txboard/theme-sdk`，当前版本 `0.1.0`、尚未发布 npm；不要把示例依赖写成已经可从 npm 安装的稳定发行版。依赖锁与 Node 下限见 [合仓约定](monorepo-current.md)。安装、完整构建/浏览器与运行验收在 CI 执行，本地日常只做必要静态检查。

```ts
import { createTXBoardClient } from '@txboard/theme-sdk'

const api = createTXBoardClient({
  baseURL: '/gateway/v1',
  getToken: () => currentUserToken,
  encryptedLogin: true, // 仅部署端已配置 HPKE + Redis 时使用
})
const bootstrap = await api.bootstrap()
const profile = await api.user.profile()
const page = await api.orders.page({ current: 1, page_size: 20 })
```

- 示例数据只经 SDK；不要在主题内嵌 Core host、管理安全路径、数据库查询或自行构造任意上游 URL。
- 只注入当前普通用户凭据。SDK 不保存会话、密码、Bearer 或 cookie，公开请求不附 Bearer；存储和令牌生命周期由应用负责。
- SSR 必须每请求创建 client 并隔离 `getToken`，禁止携带用户状态的模块单例。现有 Next 示例使用 server-only 代码与 httpOnly/secure/sameSite cookie，不等于 Core 能执行 SSR 主题包。
- 精确 Origin 名单与 HTTPS 由部署端配置；Origin 不替代身份认证。不可因 403 而关闭检查。

## 现有示例的范围

`examples/vue-theme`、`examples/react-theme` 和 `examples/next-theme` 是已有示例，不是正式发布或部署证明。源码、各自 README 与 `examples/theme-template-check.mjs` 是当前维护入口。

- bootstrap 提供站点/主题公开配置、CAPTCHA 元数据和 capabilities；capability 仅门控 UI，不能授予服务端权限。主题 manifest 同样无授权权重。
- CAPTCHA enabled 但类型/siteKey 缺失时阻止提交并显示错误，不能静默按无需 CAPTCHA 登录。Core 仍完成最终校验。
- logo、背景、外链经过 `safeWebUrl` 等现有白名单，禁止 `javascript:`、`data:`、带凭据 URL 和不可信明文来源。外观配置不是权限或业务数据。
- 401、429、503、超时与断网有明确 UI；错误只显示安全信息及 request ID，不公开上游异常或用户秘密。
- 不兼容版本明确拒绝，不通过猜测契约、恢复退役入口或降低加密等级继续调用。

## 当前 SDK 操作与精度

公开操作为 `bootstrap`、`theme.config`、`plans.list` 和 `auth.login`；用户读取含 profile、subscription、dashboard、orders、payments 展示和 notices。精确方法/字段见 [SDK README](../packages/theme-sdk/README.md) 与 [HTTP 契约](../contracts/gateway-v1.md)。

`orders.list` 默认仅第 1 页最多 100 条；读取完整历史使用 `orders.page` 及 `lastPage`，状态 4（已折抵）不能映射成取消/完成。金额保持 Core 分单位。`user.subscription` 是 `core-entitlement/v1` 判别联合：先检查 `authorized`，字节数、标识、限制与 Unix 秒保持十进制字符串，不转换为有损 JS Number，不输出订阅原始 URL。

注册/发码始终走 HPKE 路径，默认关闭；`encryptedLogin` 只影响登录，发现/加密失败不回退明文。SDK 没有当前服务未提供的密码恢复、会话管理、订单取消、checkout、支付回调或管理员方法。

## 订单写入与未知结果

`plans.list()` returns published `NativeOffer[]` values with exact decimal-string IDs, prices and quotas. Use the returned `offer_id` verbatim; do not infer it from a mutable Plan or recompute price. `orders.create({offer_id}, {idempotencyKey})` accepts no legacy `plan_id/period` conversion. The route remains disabled by default; its bootstrap capability only reflects the explicit server gate and does not mean payment or fulfillment succeeded. See [Native integration](native-core-integration.md).

保留适配器的行为是：必填 1..128 位 URL 安全 `Idempotency-Key`，缺失/非法 400 且零上游调用；Gateway 原样透传用户 key，自派生 Hint 仅作关联，不持业务去重状态。相同逻辑意图保留原 key；参数改变不能复用 key。`IDEMPOTENCY_IN_PROGRESS` 和 `IDEMPOTENCY_CONFLICT` 保持不同 409，业务拒绝为安全的 422。超时/断网不证明失败，禁止自动换 key 或重发写入，先查询/按原 key 恢复；交易真相与幂等由 Core 管理。

## 当前第三方主题包

Core 只接受 [Theme Package v2 静态 SPA 包](../../contracts/theme-package/README.md)。旧 Blade 包和共存迁移窗口已经退役；不要按旧文档交付 `config.json + dashboard.blade.php`。以当前 Schema/样例和 runtime 验证为准，不复制一份容易漂移的 manifest 到此文档。

安装、资源摘要校验、升级/失败补偿、激活/回滚由 Core Theme Runtime 管理；Gateway 不解包、不执行上传代码。`frontend_theme` 是唯一活动状态。公开配置须同时满足 manifest 候选集和服务端 allowlist，不能让主题扩大公开字段。包内禁止 `.env`、服务端密钥、管理员/支付/SMTP/订阅凭据；保持路径/大小/符号链接保护与离线静态资源纪律。可信作者和浏览器脚本审查仍必要：同源主题 JS 无进程沙箱，SRI 不是作者签名。

当前示例的 SSR 和版本帮助代码不扩展 Core 安装器能力，不保证当前版本真实 Next 构建/生产 CSP 已验收。验证范围与历史证据见 [当前状态](development-status.md)。
