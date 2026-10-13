# 本地管理仪表盘 v1

B5.17 增量 Current：`admin.dashboard.get` version `1` 使用现有 Internal mTLS、服务 proof、SQL nonce 和技术审计，仅接受 `admin-gateway`。必须同时严格启用 `CORE_INTERNAL_READONLY_ENABLED`、`CORE_INTERNAL_ADMIN_DASHBOARD_ENABLED`、`CORE_SHADOW_ISOLATED_COPY`，且环境为 local/testing。默认关闭，不能启用生产管理 Gateway。

`input` 恰好为 `{ "admin_path": "<当前管理路径>" }`，沿用有界 ASCII 路径规则。每次请求通过当前 Sanctum 会话、封禁、原生 `AdminPath` 与 `Admin` 检查；服务身份不提供业务权限。错误路径404，普通用户或仅 staff 为403；撤销、过期和退役 Agent 凭据拒绝。身份读取和仪表盘共用同一原生授权适配器。权限仍是当前 `is_admin`，不虚构细粒度 RBAC。

Core 委托现有 `AdminAnalyticsReadService::getStats()`，保持相同15秒缓存、统计公式、时间窗口和历史数据来源，不另建 Gateway 业务数据或改变旧仪表盘。响应不是即时对账、节点健康检查或财务账本；资格检查后的角色改变没有全局事务锁。原生统计整数范围和数据库聚合限制仍适用。

成功 envelope 的 `data` 是闭集：

- 十进制字符串金额（原生最小币值单位）：`todayIncome`、`currentMonthIncome`、`lastMonthIncome`、`currentMonthCommissionPayout`、`lastMonthCommissionPayout`。
- 原生整数计数：`commissionPendingTotal`、`currentMonthNewUsers`、`totalUsers`、`activeUsers`、`onlineUsers`、`onlineDevices`、`ticketPendingTotal`、`onlineNodes`。
- 原生有限数值增长百分比：`dayIncomeGrowth`、`monthIncomeGrowth`、`lastMonthIncomeGrowth`、`commissionGrowth`、`userGrowth`。
- `todayTraffic`、`monthTraffic`、`totalTraffic` 各恰好含十进制字符串 `upload`、`download`、`total`（字节）。字符串避免 JavaScript 的53位整数精度丢失；不补造已在原生聚合阶段丢失的精度。

`meta` 为 `{ "current_only": true, "slice": "internal-admin-dashboard/1" }`，响应 `private, no-store`。不包含账户列表、节点配置、Token、订阅秘密、回调引用或管理路径。未知原生字段不透传；缺失或类型异常返回固定503。读取不改业务表；Sanctum 使用时间、nonce、服务计数、原生缓存和技术审计保留已有写入。审计写入失败隐藏读取结果，proof 重放不重新读取或追加审计；同步 deadline 不保证取消 SQL。

该片为独立管理前端提供只读准备，正式 Admin 网络入口、SC-v2、生产私网与细粒度权限仍须各自完成门禁。
