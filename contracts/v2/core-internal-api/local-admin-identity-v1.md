# 本地管理身份读取 v1

> 已退役（2026-10-16）：本文保留旧隔离切片的历史协议和验收说明。对应 Core/Gateway 运行入口、配置、SDK 原型消费者、夹具及专属 CI 已移除；下文 Current/local-testing 状态仅描述当时实现。正式 Internal API 仍属 C4 待交付，不提供旧路径或旧版本兼容。见 [当前清理边界](../../../docs/development/core-cleanup.md)。

B5.15 增量 Current：`admin.identity.get` version `1` 仅用于隔离副本的管理端准备，默认关闭。必须同时启用 `CORE_INTERNAL_READONLY_ENABLED`、`CORE_INTERNAL_ADMIN_IDENTITY_ENABLED` 与 `CORE_SHADOW_ISOLATED_COPY`，且环境为 local/testing。生产配置不能启用；未开放 Gateway 的 Admin 路由或新前端。

沿用 `POST /txapi/internal/v1/operations/{id}` 的真实 mTLS 边界和绑定方法、路径、原始 body、Authorization 的服务 proof，仅接受 `admin-gateway` 身份。Core 从当前 Sanctum Bearer 获取 actor，验证过期/撤销、封禁，再调用原生 `AdminPath` 和 `Admin`。普通 User、仅 staff、退役 Agent 凭据、User Gateway 或伪造 actor/role 头均不能进入。当前 Core 的管理权限是原生 `is_admin`，本片不虚构细粒度 RBAC。

`input` 恰好为 `{ "admin_path": "<当前管理路径>" }`。路径是有界 ASCII 字符串，按当前配置核对，不允许 caller 提供 actor ID、role 或额外字段。错误路径保持原生404；合格路径下非管理员为403。停用开关返回404且不消耗 nonce。proof 经验证后沿用 SQL nonce 防重放、服务限流和有界审计；每次新请求重新核对当前会话、角色和路径。

成功使用原 TXAPI envelope，`data` 恰好包含十进制字符串 `id`、当前 `email`、`is_admin: true`，`meta.current_only=true`、`meta.slice=internal-admin-identity/1`。响应为 `private, no-store`。不输出管理路径、会话 token/hash、UUID、订阅秘密、余额、Provider 或 Node 配置。调用不签发或撤销会话，不更改 User/业务表；Sanctum 的原生 `last_used_at`、proof nonce、服务计数和技术审计仍可写入。

准入和事后角色/路径更新并非一个全局锁事务，响应只是本次当前检查的结果，不可缓存为后续业务授权。同步 deadline 仍只在 handler 返回后检查，不声称取消 SQL。数据库/审计故障沿用固定503，不泄露原始请求、路径、proof 或凭据。正式 Admin 登录、其他管理 Operation、细粒度权限设计、SC-v2 与生产 PKI/私网验收仍在后续阶段。
