# TXBoard Xboard 遗留清单与施工工作包

> 2026-10-09 / 仅首批已核对的代码审计。**不得将此表视为全仓相似度测量或最终删除清单。**

## 处理分类

- **KEEP/OPTIMIZE**：有业务价值且已成熟的实现，优化性能/权限/质量。
- **REFACTOR**：保留外在语义，拆分控制器、服务和领域规则。
- **ADAPTER → RETIRE**：迁移窗口内仍被消费的旧协议，确认无调用后下线。
- **REVIEW → DELETE**：经实际引用、运行日志、回归、合规检查后删除。

| 领域 | 已确认文件或事实 | 策略 | 阻断条件 |
|---|---|---|---|
| 路由 | §api/app/Providers/RouteServiceProvider.php§、§api/app/Http/Routes/V1/§、§V2/§ | ADAPTER → RETIRE | route:list/外部消费者 |
| 用户/认证 | §V1/UserRoute.php§、§V1/PassportRoute.php§ | REFACTOR | 登录/身份/会话测试 |
| 管理后台 | §V2/AdminRoute.php§、§web/admin/src/api/client.ts§ | REFACTOR | secure_path 轮换、权限审计 |
| 用户端 | §web/user/src/api/client.ts§ | REFACTOR | xboard_auth_data 一次迁移 |
| 订单页面 | §web/user/src/api/order.ts§、§V1/User/OrderController.php§ | OPTIMIZE | 服务端分页/响应契约 |
| 计费/付款 | §api/app/Services/OrderService.php§、§PaymentService.php§ | REFACTOR + ADAPTER | 资金对账/回调窗口 |
| 套餐 | §api/app/Models/Plan.php§、PlanResource | REFACTOR | 周期/价格单位一致 |
| 用户/数据库 | §api/app/Models/User.php§、§v2_*§ 历史表 | KEEP，再选择性迁移 | MySQL 实际数据/恢复 |
| 流量账本 | §api/database/migrations/2026_10_08_000001_create_traffic_batch_ledger.php§ | KEEP/OPTIMIZE | 去重/并发/回放 |
| 节点协议 | §api/app/Http/Routes/V2/ServerRoute.php§ | ADAPTER → RETIRE | TX-Node 双边更新 |
| 模块平台 | §api/app/Services/Module/*§ | KEEP/OPTIMIZE | 维持 Module Runtime v1 |
| 插件/主题 | §PluginManager§、§ThemeService§ | KEEP/OPTIMIZE | Package v1 兼容 |
| Agent/MCP | §api/app/Services/AgentOps/*§、§mcp/§ | KEEP + 协议适配 | Scope、审批、安全 |
| 历史文档 | 原 §txboard-api-compatibility-audit.md§ | 文档 RETIRE | 新 CURRENT/TARGET 入口 |

抽样与 cedar2025/Xboard master 比较，V1 UserRoute、PassportRoute、V2 ServerRoute 与 User Model 相同，OrderService 不完全相同。此样本无法用于计算整个 TXBoard 的 Xboard 占比。

## 跨仓库及真实消费者（P0 逐项确认）

| 消费方 | 当前接口/依赖 | 目标 |
|---|---|---|
| React Admin | §/api/v2/{secure_path}§ | §/txapi/admin/{secure_path}§ |
| Vue User | §/api/v1§ | §/txapi/me§、§/txapi/orders§ 等 |
| TX-Node | §/api/v2/server/*§、UniProxy 兼容 | §/txapi/node/v1/*§ |
| TXBoard-AccessAudit | §/api/v1/plugin/access-audit/*§ | §/txapi/extensions/...§ |
| TXBoard-Gateway | 调用路径待实证盘点 | 显式版本化管理/控制接口 |
| TXBoard-Deploy | 当前镜像健康探针/升级管理 | 更新 image 后才改探针 |
| MCP / 外部 Agent | §/api/v2/agent/*§ | §/txapi/agent/v1/*§ |
| 支付提供商 | 注册的旧回调 URL | 新旧回调收敛同一结算服务 |
| 用户订阅客户端 | 独立的订阅地址 | 逐客户端验证，不随意硬切 |
| 第三方插件/主题 | Module/Plugin/Theme Package v1 | 显式兼容窗口/版本声明 |

## 完整盘点记录格式

§§§text
domain | file | route/method | owner | source/provenance | caller
current contract | target contract | security context | data/schema impact
keep/refactor/adapter/delete | baseline metrics | tests | rollout | rollback | signoff
§§§

每个 PR 必须记录调用者、业务边界、可复现测试、性能/安全受益及回退方法。删除候选只有在代码搜索与运行观测均证实无受支持调用方、数据可恢复且许可合规时才能移除。
