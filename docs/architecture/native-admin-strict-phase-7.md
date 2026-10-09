# Native Admin Strict Cutover — Phase 7

> 2026-10-10 开发阶段实施记录。本批目标是第一方 React Admin 的直接 V2 调用数归零，删除本批已经退役的管理路由，并把 `--strict` 纳入 P0 CI；这**不是**宣告 TXBoard 全局 V1/V2 数据平面或所有历史管理员路由已经清零，更不代表生产发布完成。

## 核查与直接调用归零

对 `web/admin/src/api/*.ts`（排除测试和 `client.ts`）逐文件盘点后，原先仅剩 4 个直接 `apiClient` 调用：

| 官方前端原调用 | TXAPI 新合同 | 业务边界 |
|---|---|---|
| POST `/order/assign` | POST `orders/assign` | 单用户行锁、待支付订单重复保护、金额以**分**传输、创建待支付订单不直接结算 |
| POST `/order/update` | POST `orders/{tradeNo}/commission-review` | 只允许 0/1/3 管理状态，拒绝无佣金、尚未完成或已记账的订单；不改变钱包和已发放流水 |
| POST `/user/sendMail` | POST `users/mail` | selected/filtered/all，过滤字段白名单，一次最多 500 个用户，100 行一组入队，取消旧无限 PHP 内存做法 |
| GET `/module` | GET `modules` | `ModuleRegistry` 原业务快照；原生详情、可执行操作列表和生命周期操作另走 `modules/{id}` |

全新的接口全部置于 `/txapi/admin/{admin_path}` 下，继续走原有管理员角色、动态安全路径和管理操作审计；不创建旧接口代理。被清理的 V2 具体路由包括 `order/assign`、`order/update`、`user/sendMail` 和整个 `module/*` 管理路由族，连同对应控制器中的已退役方法。

## CI 严格审计

`node scripts/native-admin-release-audit.mjs --strict --guard-native --output artifacts/release/admin-native-gap.json`：

- 官方 React Admin 对 `apiClient.get/post/put/patch/delete/request` **直接调用**数必须为零；任一已完成模块恢复直连 V2 会失败。
- CI 附件保留每次扫描的审计 JSON，`strict_release_ready` 是 *React Admin 直接调用面的静态结论*，不等同于真实所有路由和插件动态调用面的完整证明。
- 仍需持续运行 `scripts/p0-api-audit.mjs`（真实 Laravel 路由及安全合同），PHP Feature Tests、TS 契约测试、MySQL 及镜像流水线。

## 旧端点边界清单：保留/后续拆除

- **活跃外部业务入口，未移除**：订阅配置 URL、签名支付商回调、Telegram Webhook、Node 机器/Agent 的运行时数据面协议。即使处于开发阶段，必须先定义 TXBoard 新原生 Wire 合同并显式迁移生产者；不为新管理接口写兼容层。
- **仍注册的 V2 Admin 历史管理模块**：`SystemRoute`（配置/邮件模板/系统状态/流量重置）、`CommerceRoute`（套餐/订单余下操作/优惠券/支付渠道）、`UserRoute`（用户余下操作/工单）、`ContentRoute`（公告/知识库）。第一方管理前端对应页面大多已原生化，但这些路由可能仍被历史 PHP 合同测试或独立扩展调用；应在下一批逐项删除并迁移测试，勿以此次静态扫描的零调用当成全仓无引用证据。
- **第一方以外调用**：插件自己的管理页面、其声明的自定义 CRUD URL、WebSocket/消息队列/回调 SDK 均不属于静态 `apiClient` 归零作用域。为关闭隐藏在动态客户端分支中的最后一处 V2 调用，本批把插件自定义 CRUD 和组件读取改为只允许明确的 `/plugin/{code}/*` 插件业务 API 或 `/plugins/{code}/*` 资产，拒绝任意 V2 Admin 路径与相对管理员路径；不创建兼容层。
- **不做无意义破坏性改表名**：`v2_*` 数据库表名称仍是现有持久化合同，不为消除“V2”字样而重建历史账本。
- **未完成的真实验收**：MySQL 真实并发下的订单指派幂等、群发 500 用户负载与异常恢复、模块安装升级回滚、旧端口路由退役前后端到端、正式部署备份/恢复、网关反代与资金/流量对账。见 Issue #168。

建议下一批将剩余 V2 Admin RouteModules 按“先第一方调用图、再业务能力替换、再旧路由删除及回归”逐组清退，并单独验收运行时 Node/Agent 协议。
