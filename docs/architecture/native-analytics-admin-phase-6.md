# Native Analytics Administration · Phase 6

> 开发阶段。统计看板、订单图表、节点/用户流量排行与历史统计已迁到原生 TXAPI 管理接口。此文档是代码验收依据，非生产发布证明。

## 读路径

统一使用动态管理员路径 `/txapi/admin/{admin_path}`、管理员鉴权和审计。统计接口只接受 GET：

| 路径 | 行为 / 边界 |
|---|---|
| analytics/dashboard | 原有仪表盘指标口径，15 秒缓存的聚合快照 |
| analytics/overview | 复用同一聚合快照，避免重复扫描用户/订单 |
| analytics/orders/chart | 按天订单金额、佣金及汇总；日期包含端点，最多 366 天 |
| analytics/traffic/rank | node 或 user，按指定时间窗口聚合，最多 10 条，最长 366 天 |
| analytics/rankings | server_traffic_rank/user_consumption_rank/invite_rank，默认 20、上限 100，最长 366 天 |
| analytics/users/{id}/traffic | 用户流量历史；按记录时间/ID 稳定排序，页大小 1–100 |
| analytics/records | 单一每日聚合指标，按时间排序、页大小 1–100，日期最长 366 天 |
| analytics/nodes/rank/{period} | period 为 today/yesterday，使用现有统计服务按天排行 |

收入和佣金金额继续使用**分为单位**，React Admin 展示时才转换为元；总数、流量字节、增长率均保持历史计算口径。缓存键使用新的 `txapi.admin.analytics.*` 命名空间，不会读取 V2 旧缓存。

## 本批实现

- `AdminAnalyticsReadService` 从旧管理控制器抽出已验证的 Dashboard 聚合、订单趋势和 Top10 排行公式；原 V2 控制器及旧 `AnalyticsRoute` 注册全部移除，V2 下的 `/stat/queue/*` 也退役（队列管理早已独立原生化）。
- React Admin 的 `statistics.ts` 仪表盘、趋势及排行调用已指向 TXAPI，并加入统计模块回退审计；`DashboardPeriodPicker` 限制自定义日期最长 366 天，与服务端一致。
- 空结果使用 `data: []`，真实数据带 `request_id`；分页接口有 `meta.page/per_page/total/last_page`，异常请求返回标准 `error.code` 和字段名。
- 旧的 V2 Stat 只负责管理员报表。**用户自有的** `/txapi/me/dashboard-stats` 仍保持独立，不被此批删除。
- 不重写金融订单状态判定、不改数据库迁移，不扩展 Agent/Node 的数据面协议。缓存与聚合性能、MySQL 数据一致性、时区边界、历史图表大数据量仍必须在正式发布前做代表性部署验证。

## Release gate

CI 通过只表示代码与模拟数据库检查通过。正式发布前应验证账单金额/佣金与历史报表一致、时区跨日跨月、MySQL 大表索引及执行计划、用户/节点流量聚合、备份恢复与负载回归。详见 Issue #168。
