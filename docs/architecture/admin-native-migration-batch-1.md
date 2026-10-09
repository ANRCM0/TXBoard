# React Admin Native TXAPI — 第一批审计日志只读迁移

> CURRENT on this branch. TXBoard 主仓实现；不修改 Gateway、TX-Node、第三方组件或外部服务。

- 原生 GET /txapi/admin/{admin_path}/audit-logs：管理员动态 secure_path 校验、Sanctum 管理员权限验证、分页与筛选、最小 DTO。
- 管理日志查询仅限管理员；未登录/普通用户不可查询，旧路径猜测失败时返回 404。动态路径轮换必须立即生效。
- 新读端对历史 request_data 二次递归敏感字段脱敏，并对无法按对象解析的旧文本不回传原文；不引入新的审计写入口，保留已有 V2 日志写入中间件。
- React Admin 的 AuditLogPage 使用同一页面数据适配器转到 Native API，保留分页、搜索和详情展示；其他后台领域继续走既有 V2，避免一次性切断后台。
- V2 /api/v2/{admin_path}/system/getAuditLog 此批仍保留，直到旧路径回归测试/正式迁移门禁一起改造；其他管理员接口后续逐域完成。
- 必须检查 API/SQLite/MySQL/Web/镜像 CI；并发、生产审计日志敏感字段和真实反向代理链仍需独立验证。

下一批按配置、内容、订单、用户、设备、插件模块等逐领域切换 Admin，服务实现复用 Domain/Service，原生权限及审计边界独立验证后再删 V2 路由。
