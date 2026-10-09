# TXBoard 安全基线（CURRENT）及 TXAPI 迁移门禁

取代旧 Phase 3 安全阶段记录。本文件总结长期约束；实际授权仍以实现和 CURRENT contracts 为准。

- 用户、管理员、节点和 Agent 使用独立凭据/最小权限；动态 secure_path 不是授权策略。
- Agent token 使用细粒度 agent:* abilities 和 target scopes，拒绝未知能力、越权节点、未经审批动作；审计不可绕过。
- MCP 是 Agent Ops 适配器，不允许直接连接 MySQL/Redis/Node，也不提供任意 shell、Docker socket 或通用文件访问。
- PHP 插件没有进程级安全沙箱，必须可信；ZIP 拒绝 traversal、symlink、重复/超限路径，插件禁用后 stale HTTP route 不可执行。
- 新/旧 API 双轨必须调用同一真实权限与业务层；历史兼容接口不允许越过 RBAC、idempotency 或审计。
- 令牌、支付签名、密钥、动态管理路径、订阅 secret 不得被记录到不受保护日志，调试输出需脱敏。
- 对认证、Webhook、Node、Agent、插件管理加限流/重放保护；使用负向测试覆盖跨用户资源、管理员与普通 Token 混用、旧路由旁路、并发结算、ZIP 攻击。
- PHP/Composer、JS/npm 依赖安全更新需测试与发布审批；发现高风险权限/资金问题应阻断 release。

参照 [Agent Ops](../architecture/agent-ops.md)、[Extension Runtime](../architecture/extension-runtime-policy.md)、[现行 Agent Contract](../../contracts/http/agent-ops-v1.md)。
