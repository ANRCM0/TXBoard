# TXBoard 安全收尾：管理员审计日志双向脱敏

**代码边界**：仅 TXBoard 主仓 RequestLog / Native Audit / V2 System Audit；不变更原订单、支付、节点和外部组件协议。

- 新增统一 `AdminAuditSanitizer`：写入数据库前以及读取历史日志时，都会递归清理 password/token/secret/key/authorization 等嵌套字段。旧非 JSON 请求体不以原文显示。
- URI 仅保留路径，不保留 `?query` 或 `#fragment`，杜绝历史查询参数中 Token、Key 和回调签名的直接展示。
- 新/旧后台动态访问路径一律使用 `{admin_path}` 占位，避免日志成为安全路径恢复侧信道。此前写入的动态 path 也通过查询时投影规范化；旧 action 如含动态路径则从 URI 安全地重建动作名。
- Native `/txapi/admin/{admin_path}/audit-logs` 和 V2 `/api/v2/{secure_path}/system/getAuditLog` 同步应用读取清理；用户不需要先全量修改日志表才获得防护，保持历史审计原表的证据完整性。
- 新增原生写入与历史日志两个接口的安全测试，SQLite/MySQL、Web、P0、镜像测试是合并门槛。

**剩余上线检查**：老日志原始数据库数据不回写删除，数据库管理员仍可能看到历史原文；发布时应做审计数据的保留期/访问权限/离线安全导出审查。真实 1Panel/OpenResty 代理/备份恢复与生产数据脱敏演练不在本批模拟。
