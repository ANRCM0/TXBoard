# 隔离管理会话 v1

> 状态：Current，仅默认关闭的隔离 local/testing 切片；见[验收报告](../../../docs/architecture/v2/internal-admin-sessions-verification.json)。

B5.21 加入 `admin.session.list`、`admin.session.revoke` 和 `admin.session.logout`，version `1`。独立 `CORE_INTERNAL_ADMIN_SESSIONS_ENABLED` 默认关闭，同时要求全局 Internal、local/testing、隔离副本和合成 `@example.test` 管理账户。只能由独立admin-gateway证书和实际当前Sanctum Bearer调用。当前封禁、AdminPath/Admin每次核对，普通User/staff/Node及退役Agent凭据不得执行管理操作；请求不能指定owner。

list/logout输入闭集为 `{admin_path}`；revoke为 `{admin_path,session_id}`，session_id是1..18位规范正十进制字符串。委托原生 `AuthController::sessions/revoke/logout`，会话数据继续属于原Sanctum。list按当前owner、id降序、limit101查询，超过100返回409 `SESSION_LIST_TOO_LARGE`，不删除或截断记录；成功 `data={items}`，item保留字符串id、name、abilities和可空ISO时间last_used_at/created_at/updated_at/expires_at。不得返回Token hash、明文凭据、账户订阅秘密或其他owner的会话。

revoke只删除当前owner的指定会话，外人/丢失/重复为原生404；允许撤销当前会话，随后使用该凭据的请求返回401。logout只删除当前Token，保留原生模型拒绝delete时仍返回成功的语义。成功 `data={ok:true}`，`meta={current_only:true,slice:internal-isolated-admin-sessions/1}`，private/no-store。没有新会话、全owner删除或业务幂等表。

SQL故障使用固定无秘密500；nonce/审计故障拒绝。审计/deadline/丢响应可能隐藏已提交撤销，客户端不补偿、不自动重试或Native回退；用另一有效凭据观察当前会话。已在撤销前通过认证的并发请求保持原生进行中语义，不称为SQL授权fence。元数据name/abilities为现有Token的owner可见内容，客户端闭集、大小和日期验证，不新增权限。

Gateway管理客户端固定消费这三个Operation，验证list唯一降序id、闭集DTO与exact meta。正式浏览器入口、SC-v2、独立管理前端、细粒度RBAC和生产PKI/私网继续独立验收；新用户前端延期。
