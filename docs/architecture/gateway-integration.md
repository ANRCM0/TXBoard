# TXBoard ↔ TXBoard-Gateway 联合架构与实施计划

> ADR-006 | 2026-10-09 | **ACCEPTED TARGET / NOT LIVE**
>
> 两仓同步目标：[TXBoard-Gateway 集成开发文档](https://github.com/ANRCM0/TXBoard-Gateway/blob/main/docs/txapi-integration.md)。本 ADR 不修改任何现有路由或部署。
>
> **统一对外 API 根路径 `/txapi`；独立 Gateway 未来只负责 `/txapi/bff/v1/*` 子树。** 其他 TXAPI 由 Laravel 负责。

## 1. 决策、职责与边界

采取 **可选的 API BFF + Laravel 单一业务事实来源**。Gateway 是独立 Node.js 22/Hono/TypeScript 容器、同进程声明式中间件，不是强制经过的“主网关”。不开辟微服务式多层串联，也不在 Gateway 复制 Laravel 的领域实现。

| 组件 | 唯一责任 | 绝对不做 |
|---|---|---|
| HTTPS Edge (OpenResty/Caddy/1Panel) | TLS、Host/body 边界、入口限流、精准路径分流、可信代理链 | 不改变权限、不向 Gateway 开放全部 TXAPI |
| TXBoard-Gateway (Hono) | BFF、主题 SDK、静态 operation allowlist、策略/限流、DTO、有限公开数据聚合、可选 HPKE/Redis nonce、指标/熔断 | 不直连 MySQL/支付/Node、无 Admin token、不接收动态上游 Host/任意 JS 中间件 |
| TXBoard Laravel | 用户登录/验证码、所有权与 RBAC、订单/钱包/支付/佣金/流量账本、节点、插件与主题生命周期 | 不信任 BFF 的用户鉴权替代服务端检查 |
| TX-Node | 独立 Data Plane；版本化 HTTP/WSS 到 TXBoard | 不通过主题 BFF |
| MCP Gateway（TXBoard `mcp/`） | Agent Ops → MCP Tool 协议适配 | 与主题 Gateway 不共用 Admin/Agent 用户上下文 |

业务授权、持久化幂等、订单事务和资金对账必须在 Laravel。Gateway 的 Redis nonce/限流属于前置增强，不能等价为交易安全。BFF 仅应转发**当前用户的** Bearer 到其获准的 Laravel 用户操作，不接受管理员/节点/Agent 凭据。

## 2. 当前与未来路由拓扑

```text
CURRENT (already in source)
Browser → HTTPS ingress
 ├─ /gateway/v1/* → Hono Gateway → fixed /api/v1/* Laravel operations
 ├─ /api/v1/* /api/v2/* → TXBoard
 └─ /s/* /plugin/* /ws /payment callbacks /node → TXBoard

TARGET (not implemented)
Browser → HTTPS ingress
 ├─ /txapi/bff/v1/*    → optional Hono Gateway → fixed private TXBoard /txapi/* operations
 ├─ /txapi/*           → TXBoard Laravel native services
 ├─ /s/* /ws /static   → existing TXBoard routes
 └─ legacy prefixes    → old handlers until approved retirement
```

**路径优先级**：Edge 先匹配 `/txapi/bff/v1/*`，再匹配其他 `/txapi/*`，两个目的地不同；匹配时保留正确原始 URI。Gateway 上游 URL 必须是固定私网 TXBoard origin，不可指向会把 BFF 再反代回来的同一个公网路由，以防死循环。

### Target path ownership

| 目标 | 服务所有权 | Authorization |
|---|---|---|
| `GET /txapi/public/config`, `/txapi/plans` | Laravel | anonymous + policy |
| `POST /txapi/auth/login`、`GET /txapi/me`、`GET /txapi/orders` | Laravel | Laravel CAPTCHA / User Bearer、ownership |
| `GET /txapi/bff/v1/bootstrap`、`/plans`、`/theme/config` | Gateway | publicRead |
| `POST /txapi/bff/v1/auth/login` | Gateway→Laravel | login policy，Laravel 最终认证 |
| `GET /txapi/bff/v1/user/profile`、`/orders` | Gateway→Laravel | userRead，无共享用户缓存 |
| `POST /txapi/bff/v1/orders` | Gateway | **405 disabledWrite**（直到 Laravel 幂等/安全独立验收且另有版本化设计） |
| `/txapi/admin/{secure_path}/*` | Laravel | Admin token/RBAC/audit，直连 |
| `/txapi/node/v1/*` | Laravel | Node identity，直连 |
| `/txapi/agent/v1/*` | Laravel | Agent ability/target/approval，直连 |
| `/txapi/payment/webhooks/*` | Laravel | Provider 验签/幂等，直连 |
| `/txapi/extensions/*`、订阅链接、WebSocket | Laravel 或既有处理器 | 对应独立协议，直连 |

这是一份**候选映射**，并未发明已存在的 Laravel 实现。原 Gateway 还有 notices、payment method display、subscription summary、dashboard stats、order status 和 secure auth 等操作，须逐一建 native upstream 契约后才能迁移，不允许猜测路径。

## 3. 必须区分 Native 与 BFF 的 JSON 协议

- **Laravel 原生 TXAPI**（目标）：`{data, meta?, request_id?}` / `{error, request_id?}`，定义于 [TXAPI Target](../../contracts/http/txapi-target-v1.md)。
- **Hono Gateway BFF v1**（延续现有 SDK）：`{ok:true,data,meta:{version:"1",requestId}}` / `{ok:false,error,meta:{version:"1",requestId}}`。由 Gateway 的 typed adapter 将 Native schema 转为已冻结的 BFF v1 schema。
- 迁移 URL 不等于可破坏 v1 envelope。需要修改字段/错误语义时必须另立 BFF v2 契约和 SDK major。原 `/gateway/v1` 在兼容窗口内继续有效。
- Gateway 禁止原样透传 upstream exception、secure_path、完整订阅 token/URL、支付密钥和用户不应看见的内部字段；request ID 贯穿并由可信组件重建/验证。
- 账号/金额/流量领域数据与权限始终由 Laravel 验证，DTO 不改变整数分单位、bytes 等真实语义。

详见 [跨仓 TXAPI BFF 目标协议](../../contracts/http/txapi-bff-target-v1.md)。

## 4. 安全策略与中间件组合

| Route policy | 请求处理顺序 | 容错和数据边界 |
|---|---|---|
| publicRead | requestId → Host/Origin/可信代理 → 入站限流 → schema → allowlisted operation → public DTO | 只有确认全部公开的字段才可短缓存；故障限额可观测 |
| userRead | global baseline → Bearer 格式/请求 schema → Laravel Bearer+所有权 → DTO | no-store、禁止共享缓存、禁止原文订阅 secret |
| login | ingress/IP → schema/CAPTCHA 外形 → account limiter → Laravel 真正校验 | 失败按约定处理，敏感流量不得无限 retry |
| secureAccount | 低成本入口保护 → HPKE kid/nonce → Redis 防重放 → account guard → Laravel | 默认 opt-in；Redis 故障 fail-closed，不自动降级明文 |
| disabledWrite | 静态 route/policy 立即 405 | 零上游资金副作用 |

请求头/主题 manifest/客户端参数不得选择中间件策略、绕过全局基线或改变 upstream operation；只读公开、可安全重试的操作才允许有界自动重试。管理员、Node、Agent、Webhook 不允许进入主题 BFF。

**当前待审安全问题**：TXBoard 的 `api/.docker/caddy/Caddyfile` 包含 `trusted_proxies static 0.0.0.0/0 ::/0`，过于宽泛。需要在单独的部署/安全 PR 中按真实网络划定代理信任 CIDR、剔除伪造 Forwarded 头，测试直接访问和双代理链。不能因为 Gateway 有安全中间件就忽视 Caddy 入口风险。本次仅记录、**不改配置**。

## 5. 部署、可选性和回滚

- Gateway 单独镜像/Compose、私有 Docker 网络，8787、Redis、/metrics 不映射公网；Edge 采用精确 allowlisted BFF path 分流。
- Gateway 应独立发布和扩容，不依赖它来启动 TXBoard；Gateway 关闭时 Admin、Node、支付回调、订阅、TXAPI native 及旧 Web SPA 仍可用。
- `GET /healthz` 为 Gateway liveness，`/readyz` 为 Gateway 依赖能力状态；实际当前 readiness 未主动连接 Laravel 则不得报告“上游已探测健康”，应使用真实 staging 请求验证 upstream。
- TLS、受信 Proxy、CORS Origin allowlist、Docker 固定服务地址与响应大小/超时等必须配置成可审计规则，严禁任意用户可控 upstream target。
- 生产支持灰度：先 TXBoard 发布 Native APIs → Gateway 新 adapter/双协议测试 → SDK/主题 feature flag → 受控 Edge 改路由 → 观察 → 旧 URL 退役。
- 回滚必须优先关闭主题 BFF feature flag/撤销 BFF 路由，并切回已经验证的直接 Laravel 路径；不能对真实支付和数据库写入做假回滚。

## 6. 联合工作包：G0–G5

| 阶段 | 负责人 | 交付及阻断条件 |
|---|---|---|
| G0 设计与契约 | TXBoard + Gateway | 两边 ADR、BFF target、固定 operation mapping、consumer 列表 |
| G1 真实旧链路验收 | Gateway + Deploy | 在隔离 Laravel/MySQL/Redis/CAPTCHA 上测试现有 /gateway/v1，代理/回滚/异常实证 |
| G2 Native 实现 | TXBoard | /txapi/public、auth、me、plans、orders，权限/DB 分页/测试，旧路径保留 |
| G3 Gateway 双栈适配 | Gateway | /txapi/bff/v1、native adapter + v1 SDK/旧路径 golden fixtures，认证错误一致 |
| G4 部署与主题切换 | Gateway + Web + Deploy | 主题 opt-in、HTTPS/私网/可信代理、性能和故障灰度、可一键撤回 |
| G5 退役/维护 | 双仓 | 真实用户/外部调用已迁移，旧调用观测为零，支付/Node 不受影响 |

依赖关系：G2 依赖 TXBoard Native P1/P2；G3 依赖 Native 契约稳定；G4 不得早于 G1 的真实验收；G5 不得早于 Native P7 对旧消费者的整体评审。支付/订单写入另有独立业务幂等安全闸门，不在第一批 BFF 切流中。

## 7. P0 测试和完成标准

- [ ] 双仓 route/method/auth/DTO/error golden fixtures，所有上游操作均映射唯一已验证 Native endpoint
- [ ] 现行 /gateway/v1、/api/v1 双轨无回归；当前不可用的 POST orders 仍 405
- [ ] Laravel 用户所有权、CAPTCHA、管理员/Node/Agent token 混用负向测试
- [ ] 真实 Laravel/MySQL/Redis、真实验证服务、代理和浏览器联调
- [ ] 可信来源及 XFF/Host/Origin 伪造/跨域/无限上游重定向/代理环路拦截
- [ ] HPKE Redis nonce、多实例限流、密钥轮换、故障/超时/熔断和日志零敏感值
- [ ] 请求 p95、Gateway 附加延迟、Laravel 上游耗时、错误率分层观测，证明不明显退化
- [ ] Edge/Deploy/SDK/Theme feature flag 恢复演练，交易资金无侧作用
- [ ] 所有 CURRENT / TARGET 文档和跨仓库 PR 的版本、配置/兼容窗口明确

**文档提交 ≠ 代码交付 ≠ 真实 staging ≠ 生产批准。**
