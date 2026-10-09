# React Admin TXAPI 迁移 Batch 3：工单管理

本批只针对 TXBoard 内部 React Admin，不做 TX-Node/Gateway/第三方服务适配。

- GET /txapi/admin/{admin_path}/tickets：status、reply_status、email 限定字段过滤，页码/每页上限 100，用户关系仅返回 id/email。
- GET /txapi/admin/{admin_path}/tickets/{id}：含按 id 顺序返回的工单消息，过滤用户敏感字段，不直接序列化 Eloquent 模型。
- POST /txapi/admin/{admin_path}/tickets/{id}/reply：复用 TicketService::replyByAdmin，保留工单回复 Hook 和邮件通知；已关闭工单拒绝。
- POST /txapi/admin/{admin_path}/tickets/{id}/close：加锁、事务更新、重复关闭幂等。
- React Admin 工单页面 API 调用改为 TXAPI；保留既有状态标签、分页、搜索和操作视图。
- 旧 /api/v2/{admin_path}/ticket/* 暂不删，待旧消费者、完整回归审计清零后单独退役。已有外部节点、支付和 Gateway 一概不在范围。
- 安全门禁：缺失/普通用户必须拒绝；错误 secure_path 404；message 长度限制；越权与敏感字段检查。
- 完成门禁：Web/SQLite/MySQL/API/P0/镜像 CI。CI 不代表真实部署邮件送达或外部生态的生产级验收。
