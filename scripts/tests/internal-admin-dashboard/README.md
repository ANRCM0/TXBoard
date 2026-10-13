# 隔离管理仪表盘联调

只用于 `txboard_admin_dashboard_test` 空白合成库，不清空既有数据库。`run.sh <隔离 Core api>` 使用环境中的专用 MySQL 凭据，生成短期测试 PKI，启动127.0.0.1 PHP Core 和真实 TLS1.3 mTLS 边界；正式安装器不启用此功能。

12项检查涵盖原生仪表盘闭集 DTO、超过53位的字节字符串、User业务记录读取前后不变、User服务/普通账户/staff/过期与退役Agent拒绝、路径与角色当前检查、SQL防重放、真实审计INSERT故障，以及Token撤销。脚本最后删除凭据、密钥与私有配置，只保留脱敏 `result.json` 和不含请求数据的进程日志。

`run-local-baseline.py` 仅在 `/opt/txboard-baseline/internal-admin-dashboard-*` 固定快照创建预先列举的三种专用库之一，账户只获对应库权限，不重置已有数据。重试库用于保留前次快照证据。GitHub CI 使用独立 Oracle MySQL8.4。SQLite功能回归另由 `InternalAdminDashboardTest` 覆盖原生缓存漂移、SQL失败和授权；并不证明生产私网、细粒度权限、SC-v2或全阶段完成。
