# TXBoard Native — 独立化重构与优化开发方案

> 日期：2026-10-09 · 方案 1.0 · 状态：**TARGET / 规划中，尚未实施**
>
> **唯一正式 API 根入口：`/txapi`。** 原有 `/api/v1`、`/api/v2` 只作为迁移兼容入口，确认所有支持的消费端升级后退役。
>
> 本文件只定义施工方案；本次文档提交**不修改**当前 PHP/TypeScript 运行时代码、路由、数据库或部署镜像。当前 API 以代码及 CURRENT contracts 为准。

## 1. 改造目标、原则与非目标

目标是将 TXBoard 从依赖 Xboard 历史接口和源码结构的衍生实现，发展为独立维护的模块化网络服务控制面，同时优化安全、性能、可测试性与维护成本。

- **架构**：继续 Laravel 12 + Octane/Swoole + MySQL + Redis/Horizon，React Admin + Vue User；采用模块化单体，暂不拆为微服务。
- **业务**：用户、订阅、套餐、资金、订单、支付、节点、流量、工单、通知、插件、主题、Agent Ops 保持完整。
- **边界**：TXBoard 是 Control Plane；TX-Node 是独立 Data Plane；Gateway/MCP 是受限 API 适配器，不成为第二控制面。
- **价值优先**：优先消除旧契约依赖、重复逻辑、不安全行为和可测量性能瓶颈，而非以重命名目录、相似率下降或行数减少衡量成功。
- **渐进迁移**：contract → baseline/tests → domain implementation → legacy adapter → caller migration → observation → retirement。每个业务域独立 PR、回滚和验收。
- **严禁**：一次性删除旧支付回调、Node/Agent/插件协议、全部老路由；同批重命名所有 v2_* 表；将新业务继续接到旧 V1/V2 控制器上。
- **许可证**：保留 api/LICENSE 与 THIRD_PARTY_NOTICES.md 的来源和版权要求，去技术依赖不等于消除第三方许可。

最终完成定义：官方调用者全部迁往 `/txapi/*`、无需 Xboard 运行时接口和旧响应适配、资产及资金/流量账本对账一致、跨仓库协议/生产回滚/CI 验证齐全。

## 2. 已核查源码与 Xboard 耦合点

| 当前实现 | 核查发现 | 工作 |
|---|---|---|
| `api/app/Providers/RouteServiceProvider.php` | 注册 /api/v1、/api/v2 | 新路由注册、旧路由隔离 |
| `api/app/Http/Routes/V1/*`、`V2/*` | 多数还按 Xboard 层级组织，V2 复用 V1 Controller | 领域 API、控制器解耦 |
| `web/user/src/api/client.ts` | /api/v1、xboard_auth_data 与旧 envelope | 新 API client、登录态安全迁移 |
| `web/admin/src/api/client.ts` | /api/v2/{secure_path} | /txapi/admin/{secure_path}、动态轮换 |
| `api/app/Services/PaymentService.php` | 支付通知 URL 硬编码 /api/v1 | 命名路由、老单通知兼容 |
| `api/app/Http/Controllers/V1/User/OrderController.php` | 全量查询订单列表 | 服务端分页、筛选、稳定排序 |
| `web/user/src/api/order.ts` | 浏览器端过滤/分页 | 标准分页与类型化 DTO |
| `api/app/Models/User.php`、`Plan.php`、`Order.php` | v2_* 表名和部分历史字段 | 隔离领域规则，DB 最后迁移 |
| `api/app/Services/OrderService.php` | 订单、计价、返佣及结算逻辑集中 | 业务服务拆分、并发/幂等 |
| `contracts/node-protocol/README.md` | /api/v2/server/* 是当前正式 Node 线协议 | 双边版本化协议 |
| `mcp/README.md`、Agent contracts | 仍使用 /api/v2/agent | 独立 Agent API，维持授权/审批 |
| Plugin Package v1 | 旧 host API base 和插件端点 | 扩展路由版本化，保留迁移窗口 |

抽样对照上游 cedar2025/Xboard master：V1 UserRoute、V1 PassportRoute、V2 ServerRoute、User Model 与对应上游内容一致，OrderService 存在差异。**这不能推算整个项目的 Xboard 代码占比**；完整 provenance/依赖盘点属于 P0。

## 3. TXAPI 根入口与目标接口

| 领域 | 目标路径 | 安全要求 |
|---|---|---|
| 轻量健康检查 | GET `/txapi/health` | 不依赖数据库/Redis 的 liveness，readiness 独立 |
| 公共配置 | GET `/txapi/public/config` | 匿名，公开字段白名单 |
| 注册/登录 | POST `/txapi/auth/register`、`/txapi/auth/login` | CAPTCHA、速率限制、反枚举 |
| 用户资料 | GET/PATCH `/txapi/me` | User token |
| 套餐 | GET `/txapi/plans` | 可售/可见策略 |
| 订单列表 | GET `/txapi/orders?page=1&per_page=20` | 服务端分页和用户隔离 |
| 订单详情 | GET `/txapi/orders/{tradeNo}` | 强制所有权验证 |
| 取消订单 | POST `/txapi/orders/{tradeNo}/cancel` | 状态/幂等检查 |
| 管理后台 | `/txapi/admin/{secure_path}/*` | Admin token + RBAC + 路径轮换 + 审计 |
| TX-Node | `/txapi/node/v1/*` | Node 身份和版本化协议 |
| Agent Ops | `/txapi/agent/v1/*` | Agent 专用 abilities、target scope、审批 |
| 插件/扩展 | `/txapi/extensions/{code}/v1/*` | host 权限 + 启停防护 |
| 支付回调 | `/txapi/payment/webhooks/*` | 验签、重放检测和唯一结算 |
 
用户/Admin REST 不强制路径版本号；跨仓库 Node、Agent、Extension 协议必须有版本。任何新 HTTP 功能禁止注册在 /api/v1 或 /api/v2。不能把 secure_path 当成管理员鉴权；用户/管理员/节点/Agent token 不可互相提权。

### 3.1 响应契约（目标建议，待 P1 冻结）

成功示例：

```json
{"data":[{"id":1}],"meta":{"page":1,"per_page":20,"total":42,"last_page":3},"request_id":"trace-id"}
```

错误示例：

```json
{"error":{"code":"ORDER_NOT_FOUND","message":"订单不存在"},"request_id":"trace-id"}
```

- HTTP 2xx 代表成功；错误使用正确 4xx/5xx，不再返回 Xboard 风格的 status success/fail 兼容 envelope。
- 分页参数为正整数、设上限（建议 per_page<=100）、排序/过滤字段白名单且使用稳定次排序键。
- 金额使用整数最小货币单位与明确 currency；流量使用非负 64 位整数字节；时间使用 UTC RFC3339；敏感字段显式输出白名单。
- Mutation 校验、所有权、限流、request_id、审计与错误码有统一规则；支付可重试动作有幂等键和数据库唯一性保障。
- 详细候选规范见 [TXAPI Target](../../contracts/http/txapi-target-v1.md)。目标文档不是当前已上线协议。

### Gateway 双仓联合方案（ADR-006）

`/txapi` 是统一对外 API 根路径，**并不意味着所有请求都通过 Gateway**。独立 Hono Gateway 只处理未来 `/txapi/bff/v1/*` 的可选主题/用户 BFF；其余 native API、Admin、Node、Agent、Webhook、插件直接进入 Laravel。Edge 要先分流 BFF 子树，Gateway 只通过私有 TXBoard origin 的固定 named operations 调用 native API。BFF v1 保留现有 SDK `{ok,data,meta}` envelope，与 Native `{data,meta,request_id}` 不同。现有 `/gateway/v1/*` 和固定 `/api/v1/*` 仍有效。联合 G0–G5 工作包、可信代理风险和双轨/回退请见 [Gateway Integration ADR](gateway-integration.md) 与 [BFF Target Contract](../../contracts/http/txapi-bff-target-v1.md)。

## 4. 建议的模块化单体代码结构

```text
api/app/
├── Core/
│   ├── Auth/             # 认证、授权、能力/资源策略
│   ├── Http/             # 请求验证、响应与错误、Trace
│   └── Contracts/        # 值对象与共享接口
├── Domains/
│   ├── Identity/         # User/Session
│   ├── Subscription/     # Plan/Subscription/Pricing
│   ├── Billing/          # Order/Payment/Balance/Coupon/Commission
│   ├── Network/          # Node/Machine/Group/Route
│   ├── Traffic/          # Usage/Settlement/Reset/Stats
│   ├── Support/          # Ticket/Knowledge/Notice
│   └── Notification/     # Mail/Telegram/Provider
├── Services/             # 旧服务迁移缓冲区
└── Http/                 # 领域路由、请求/资源、legacy adapters
```

Controller 不承担复杂交易，只完成鉴权、验证和调用服务。域服务承担业务规则和事务；Repository/DTO 按复杂度使用，不制造为了“漂亮架构”而产生的层层转发。legacy adapter 只转换旧请求/响应，新原生领域绝不输出 Xboard 专用字段。

保留现有 Module Platform v1、PluginManager、ThemeService、Agent Ops 权威行为；Module Registry 只做库存/健康/能力投影，Lifecycle 继续委托既有适配器；MCP 不直接连 MySQL/Redis/Node。Octane 服务必须定义 request scope 和缓存失效；避免常驻单例泄漏用户状态。

## 5. 业务领域优化方案

### 5.1 Identity / Auth
- 账户、管理员 RBAC、Agent/Node tokens 与会话分别建立边界；后端负责撤销令牌。
- xboard_auth_data → txboard_auth_data 只做一次安全迁移，过渡时允许既有登录态正常使用，失败时明确重新认证。
- 登录、邮件链接、密码重置、邀请码与验证码有测试、限流和敏感值脱敏。
- secure_path 轮换立即生效，旧动态管理路径不可被缓存穿透。

### 5.2 Subscription / Plans
- 套餐、周期、价格、按量计费、流量重置和订阅升级补差统一定义与测试。
- 不在新 API 返回 month_price、quarter_price 等老字段；旧字段映射仅留适配器。
- 计划/价格缓存必须按权限和配置变动失效，绝不能错用跨用户缓存。

### 5.3 Billing / Orders / Payments
- 分拆订单生成、报价/优惠、账户余额、支付、返佣、订单状态和订阅生效；状态变化合法性集中验证。
- 金额全部以整数计价；余额变更和订单状态在事务中原子执行，必要时行锁/唯一约束防双写。
- Webhook 验证签名和来源，按 provider event ID / trade_no 幂等持久化；重复/乱序/并发回调至多一次结算。
- 长耗时通知、邮件与事件在 DB commit 后排队；提供失败对账和补偿机制。
- **旧通知地址必须覆盖尚未完成订单的最大回调窗口**，新旧入口最终调用同一个结算服务，不能直接 404。
- 列表改 DB 分页，减少全量拉取、N+1 查询与大响应；以真实 SQL EXPLAIN 和采样负载确认收益。

### 5.4 Network / Traffic
- TX-Node 通过版本化 handshake、节点/机器鉴权、capability negotiation、配置同步、批次上报和 WSS 加速；HTTP 保底。
- 持久化 traffic batch ledger 是权威；同一批次重复上报不得重复扣费，ID 碰撞、乱序、溢出要正确拒绝或补偿。
- Redis 用于队列/缓存，不是财务或流量结算唯一真相；MySQL 故障与重试必须避免局部结算。
- 优化统计索引、批量聚合、归档、Redis 命中、队列积压与节点心跳，先测 p95/CPU/内存/锁等待再决定重写。

### 5.5 Frontend / Plugin / Theme / Agent
- Vue/React 使用标准 API 客户端管理 Token、异常、分页、请求取消和类型；只有幂等读自动重试。
- 不在前端猜测后端端点或用假成功掩盖缺失接口；动态功能开关以服务器契约为准。
- Plugin Package v1、Theme Package v1、Admin Bridge 和模块生命周期保持兼容窗口，新增插件显式声明版本化 route/capability。
- PHP 插件不具有隔离沙箱；安装升级需校验包完整性、权限和 Worker 状态；停用后 stale route 不能执行。
- Agent Ops 的 abilities、target scope、审批、审计不得因为改 API prefix 失效。

## 6. 数据库演进：Expand → Backfill → Verify → Contract

1. P0 全面调查 v2_* 表、migration、索引、唯一性、字段语义、真实 MySQL 数据量和事务/统计 SQL。
2. 新字段/索引优先 additive migration；大表分批回填，有 checkpoint、限速、错误恢复、对账。
3. 数据源单一权威；能用 mapping/adapter 则避免长期双写。不得未经设计引入新旧表双向同步。
4. 通过影子读取/灰度验证新旧数据对齐，核对用户、订单、余额、佣金、套餐、结算 ledger 与历史流量统计。
5. 有经过验证的 MySQL + 文件/插件/主题/APP_KEY 备份恢复、增量迁移回滚和业务补偿，才进入破坏性 schema 清理。
6. v2_* 表名改不改应看维护成本和风险，**不把重命名当成去 Xboard 的 KPI**。历史迁移脚本不得为了整洁擅自删除。

## 7. 旧路由迁移流程与停用门槛

- **发现**：Laravel route:list 与代码搜索列出每个 path/method/middleware，找出 React/Vue、TX-Node、TXBoard-Gateway、AccessAudit、Deploy、MCP、支付平台和第三方订阅客户端消费者。
- **冻结**：旧 V1/V2 接口仅保留适配职责；新功能和原生响应只进 TXAPI。
- **双轨**：按领域开关分批开启新协议；新旧接口共享真正的授权、业务服务与幂等账本。
- **迁移**：先低风险公共/用户/管理端，再资金支付和 Node/Agent/插件，具体顺序依依赖图调整。
- **观测**：按匿名化 consumer 类型统计旧 API 请求量、成功率、延迟和异常，日志不保存 Bearer、订阅 token、签名或支付密钥。
- **退役**：所有受支持调用方已完成升级，交易回调有效窗口结束，Node/Agent 双端验证成功，最长调用周期内旧请求为 0，回滚预案完整且 Owner 批准。

API 兼容策略不是永久双轨；但在证据不足时宁可保留短期 adapter 也不要丢单/中断节点。

## 当前执行决议（2026-10-09）：内核优先，外部适配后置

维护者明确要求先完成 TXBoard 本体：**真实 TX-Node 版本互通、独立 Gateway/Hono 联调、支付提供方沙箱、第三方插件主题端到端升级和线上负载/恢复演练不作为 P1–P6 代码开发的前置阻塞**。TXBoard 只承诺已测试的 Laravel 内部合同与向后兼容；这些外部工程应在后续独立适配 TXBoard 已冻结的协议。

此安排**不代表**这些验收已通过：P0-A 外部消费者所有权和 provenance 仍待人工核对；P0-B 真实环境的性能、备份恢复、第三方回调及跨仓库联调仍记为 deferred。P4-B、P5、P7 里相应的真实联调/安全/发布退出条件依然存在，不能据此删除旧 /api/v1、/api/v2 和真实 webhook，也不能对上线稳定性作已验证的承诺。

执行顺序转为：P0 合成业务/数据基线已入库 → P1-A 原生 HTTP（首批纯只读）→ P1-B 客户端无损迁移 → P2–P6 分域实现与测试 → 外部消费者分别适配 → P7 经外部证据完成后再退役旧路由。每一阶段可以在不破坏旧接口的前提下并行推进。

## 8. 实施阶段与退出条件

| 阶段 | 具体工作包 | 退出条件 |
|---|---|---|
| P0-A | 完整 provenance、依赖矩阵、路由/消费者/数据库字典 | 每条旧接口有 Owner/消费者/风险 |
| P0-B | 登录→下单→支付→生效→节点→流量 E2E 与性能基线 | 基准可重现，关键回归可执行 |
| P1-A | TXAPI 注册、响应/error/pagination、权限审计 | 新旧路径隔离，旧业务不受影响 |
| P1-B | User/Admin API client、登录态迁移与 secure_path | 前后端和页面刷新完整验证 |
| P2 | 账户、认证、套餐、知识库、公告、工单 | 逐域功能与数据一致 |
| P3-A | 订单/钱包/返佣/优惠与支付状态机 | 幂等/并发/金额对账通过 |
| P3-B | 支付 webhook 双路径迁移 | 支付沙箱/真实回调无漏单 |
| P4-A | 流量 ledger、统计、Redis/MySQL 效率 | 重放/故障测试、负载不退化 |
| P4-B | TX-Node HTTP/WSS 版本化互通 | 双端协议测试/升级/降级成功 |
| P5 | Agent Ops、插件、主题、Gateway 接入 | 权限边界及生态兼容验证 |
| P6 | DB 选择性 schema 优化、历史实现清理 | 数据恢复/对账，删除候选确认 |
| P7 | 旧 API 退役、发布、观测与最终验收 | 无受支持旧消费者，完整回归 |

每个 PR 只解决一个架构主题；Billing、Traffic、DB 切换不得混在一次发布中。P0 完成前不对性能提升做无依据的百分比承诺。

## 9. 可量化验收与测试矩阵

- **Unit**：金额和流量单位、订阅周期、权限、订单状态流转、优惠、价格、响应错误码。
- **Integration**：SQLite 快速测试与 MySQL 8.x、Redis、Horizon、真实锁/唯一约束语义测试。
- **Contract**：OpenAPI/DTO/客户端、Node、Agent/MCP、插件、Admin Bridge、跨仓库消费者。
- **Adversarial**：两次同时下单/取消/回调、重复与乱序支付、重放流量、Token 跨角色、secure_path 轮换、恶意 ZIP、Octane 共享状态污染。
- **E2E**：注册、套餐、购买、支付、订阅生效、节点拉取、流量入账、到期/重置、工单与回滚。
- **性能**：记录 p50/p95/p99、SQL 次数/EXPLAIN、Redis ops、队列 lag、DB lock、Node batch QPS、CPU/内存及 error rate；在同样环境/负载下验证无明显回退。
- **财务与流量**：回调幂等、账户余额、真实订单状态、流量 batch 累计与历史总量对账差额必须为 0。
- **发布**：npm ci + npm run verify:web；cd api 后 composer install + php artisan test；按 CI 执行 MySQL、Docker/Octane 与 MCP smoke 和生产恢复演练。

CI 通过不等于实际支付回调联调或真实生产备份已验证。

## 10. 发布/灰度/回退与合规

每个工作包有 Feature Flag、指标看板、回滚操作者与命令、数据变更清单、失败补偿和正式发布说明。PR 经过审查和自动测试后以不可变 SHA 镜像部署，先内部/小流量、后放量。保留上一版本镜像与可靠备份。

路由/前端可回退，但**已支付订单和破坏性 DDL 无法仅靠 git revert 恢复**：必须有数据库快照/对账/补偿方案。生产变更时监控支付对账、流量去重、DB/Redis/队列、Node 连接与错误率。

旧 Phase N 临时文档已汇总成 [Operations](../operations/README.md)、[Security](../security/README.md)、[Extension Runtime](extension-runtime-policy.md)；完整代码/来源变迁仍可通过 Git history 查找。当前有效协议见 [contracts](../../contracts/README.md)，初始审计见 [legacy inventory](legacy-inventory-and-work-packages.md)。

## 11. P0 立即执行清单

1. 自动导出 route:list、API/client 代码引用图及所有 /api/v1、/api/v2 硬编码 URL。
2. 查明支付回调、健康探针、部署脚本、Node、Gateway、AccessAudit、MCP 与主题/插件真实路径依赖。
3. 为 Controller/Service/Model/Migration 标注：来源、领域、所有调用者、保留优化/重构/暂存适配/删除、Owner。
4. 补齐金额/重复回调/跨角色、批量流量去重和 DB 迁移的回归与失败注入。
5. 测定当前关键 API/SQL/负载基线；明确逐模块性能改进目标、上线指标及回滚窗口。
6. 冻结 TXAPI v1 细节，先实现非破坏性新入口和低风险领域，再按 P1–P7 拆分开发。
