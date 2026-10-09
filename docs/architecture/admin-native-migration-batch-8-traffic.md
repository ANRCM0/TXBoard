# TXBoard native Admin Batch 8 — 流量重置与收尾安全

本批仅迁移 TXBoard 主仓流量重置管理，保留已有 V2 路由供兼容消费者使用；不修改 TX-Node/Gateway 客户端协议。

## 内部交付

- `GET /txapi/admin/{admin_path}/traffic-resets`：按用户、重置类型、来源、日期筛选，最大每页 100 条；仅返回前台必须字段与用户 id/email。
- `GET /txapi/admin/{admin_path}/traffic-resets/stats`：有界统计区间（1–365 天）。
- `GET /txapi/admin/{admin_path}/traffic-resets/users/{id}`：单用户最近 1–50 条记录。
- `POST /txapi/admin/{admin_path}/traffic-resets/users/{id}/reset`：管理员手动重置，严格验证 reason 并记录 admin_id、reason。使用既有 `TrafficResetService`，不复制流量/缓存/Hook 状态机。
- React Admin `traffic-reset.ts` 完整迁移到上述原生接口，保留列表分页/筛选/统计/历史/手动重置视图数据结构。

## 修复的重要一致性风险

以前 `TrafficResetService::performReset` 使用先前获取的 User 模型，在收到最新 TX-Node 流量上报后可能把过时的流量数据作为重置前数值，且 `manualReset` 丢弃 reason/admin 元信息。

现在重置与日志插入使用同一事务，先对 user 行加锁并重新读取最新 u/d，再计算并重置；自动/定时触发的逻辑在锁内再次检查是否仍应重置，避免重复执行。手动重置的元数据传到实际记录，并对用户有效性重新验证。日志 metadata 在管理员 DTO 返回时使用审计脱敏器处理。

## 验收与余项

- PHP：未登录、普通用户、错误动态管理路径、无套餐用户、人工 reason、旧对象数据竞争、cron stale-due、分页和边界验证。
- Web：路径、Bearer、响应分页契约、统计、重置 POST、格式错误时失败显式提示。
- 发布尚需：真实节点同时上报时的 MySQL 锁等待观测、Horizon/Octane/Caddy/Redis 和真实 1Panel 回滚备份恢复演练；这些不能仅凭 CI 宣称完成。
