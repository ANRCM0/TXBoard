# Gateway 当前架构与信任边界

本文描述当前源码，不维护历史开发顺序。功能与验收范围见 [当前状态](development-status.md)，HTTP 字段见 [Gateway v1 契约](../contracts/gateway-v1.md)。

## 运行与业务归属

Gateway 是 Node.js / Hono / TypeScript 模块化单体，公开前缀为 `/gateway/v1`。它提供用户主题的固定 API 适配，不是通用反向代理、业务数据库或交易后台。`/txapi/bff/v1` 和统一管理员浏览器入口没有注册，不能当作当前接口。

```text
Browser / Theme SDK
  -> HTTPS ingress
      -> /gateway/v1/* -> Gateway -> 固定 Core operation
                         |-> Redis: replay / rate limit
      -> Core 其他已注册入口（独立鉴权）
```

- Core 持有身份、权限、订单、钱包/账本、退款、权益和 Node 状态；Gateway 不计算价格、不结算、不存储业务真相，不持有共享管理员 Bearer。
- Core 管理入口、支付回调、私密订阅、插件与 Node/WebSocket 不因部署 Gateway 改道。已退役的 Agent/MCP 与 `/api/v1`、`/api/v2` 不恢复。
- `services/upstream.ts` 固定选择 Core `/txapi/*` 路径，禁止客户端提供上游 host/path。匿名支付目录没有 Core 对应项，固定 404、零上游调用。
- `services/native-internal.ts` 是独立服务端 mTLS 客户端。User/Admin 服务配置隔离；当前浏览器适配只在配置 User service 后将 `login`、`guestConfig` 选入该通道。客户端存在不等于管理员浏览器 API 已开放，详见 [Native 接入](native-core-integration.md)。
- 浏览器目录和创建使用 Native 已发布 Offer 的 `offer_id`；Core 继续持有购买与金融权威，Gateway 写入仍默认关闭。

## 请求管线与静态策略

`apps/gateway/src/app.ts` 组合中间件；`config/policies.ts` 是路由方法、路径及安全形态的静态事实源。未知方法/路径不能继承已知路径的宽松策略；非法组合在启动时拒绝。

| 层 | 当前职责 | 不可越过的边界 |
| --- | --- | --- |
| HTTPS 入口 | TLS、私网分流、基础防刷、可信 peer | Gateway/Redis 不直接暴露公网 |
| 全局基线 | request ID、安全头、Origin/Host、大小与超时、脱敏日志 | Origin 不是身份认证；客户端转发头不可信 |
| 路由策略 | Bearer 形态、HPKE envelope、固定 limiter、禁止写入口 | 客户端 header、capability、主题 manifest 不可放宽策略 |
| 适配与投影 | 严格请求 DTO、Native envelope/字段检查、固定上游、最小字段 | Core 独立复核身份、归属和业务规则 |
| 运行支撑 | Redis、防重放、限流、指标、readiness | 不持订单/资金账本，不记录秘密 |

`publicRead`、`userRead`、`login`、`secureLogin`、`secureRegister`、`secureEmailCode`、`captchaChallenge`、`disabledWrite` 和 `orderWrite` 由服务端固定绑定。先低成本拒绝，再做昂贵解密/上游调用；业务副作用之前必须经过对应安全闸门。

- `POST /orders` 默认 `disabledWrite`，405、零上游调用。显式写开关只更换 Gateway 策略，不证明上游 DTO 或业务链路兼容。
- HPKE 路由没有明文回退；注册/发码还受默认关闭的账户工作流开关约束。准确密钥、AAD 和 replay 规则见 [加密与实验边界](app-crypto-preview.md)。
- 可信入口根据 socket peer 与允许列表解析转发头，不直接信任任意 `X-Forwarded-For`。部署时仍须核对真正的代理链和 Host。
- 路由级限流为 Redis 原子双维滑动窗口，敏感路由 fail-closed；账户预算另行计数。配置文件热重载不能由 HTTP 请求触发，见 [限流](rate-limiting.md)。

## 缓存、观测和故障

- 只有 `public-short` 策略的成功 GET 可进进程内 TTL 缓存；默认 60 秒，键含方法、路径和查询。用户数据/凭据写入不得共享缓存。该缓存不提供跨 worker 的即时失效；公钥轮换/吊销要同时考虑缓存与全副本配置传播。
- 结构化日志只接受受限字段；禁止 Authorization、密码、完整邮箱、CAPTCHA 票据、请求/响应正文、订阅私密地址和上游内部异常。指标标签使用受限路由模板，不用用户输入。
- `/healthz` 是 liveness；`/readyz` 只汇报依赖状态，其中默认 upstream 探针是占位，不证明真实 Core 可用。`/metrics` 需要单独凭据。
- 写入/凭证操作不得因超时、网络失败或安全存储故障自动放行/重试；未知写结果保留原幂等键，由 Core 恢复。只读重试和熔断受运行时边界限制，不允许重试风暴。
- Gateway 不持 SMTP/支付商密钥。通知投递、支付验签、重复回调吸收、金额/归属和资金退款由 Core 及其专用插件接口负责。

## 代码入口

- 路由/安全形态：`apps/gateway/src/config/policies.ts`、`apps/gateway/src/routes/`。
- 上游与 DTO：`apps/gateway/src/services/upstream.ts`、`apps/gateway/src/adapters/txapi-native.ts`、`apps/gateway/src/contracts/schemas.ts`。
- 安全/运行状态：`apps/gateway/src/middleware/`、`apps/gateway/src/services/crypto.ts`、`apps/gateway/src/services/redis-security.ts`。
- 主题 SDK 与示例：[开发者指南](developer-guide.md)；包安装/活动主题由 [Core Theme Runtime](../../contracts/theme-package/README.md) 管理，不由 Gateway 解包执行。

源码合并、相同源码 CI、隔离业务联调和正式发布是不同结论。当前文档不设旧包/旧 API 过渡窗口，不能以历史 ADR、旧测试数或演示模板替代当前验收。
