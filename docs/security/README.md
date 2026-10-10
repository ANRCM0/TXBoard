# TXBoard 安全基线

本文件记录持续适用的安全约束；实际授权由运行时代码及版本化契约决定。

- 用户、管理员、节点和 Agent 使用独立凭据/最小权限；动态 secure_path 不是授权策略。
- Agent token 使用细粒度 agent:* abilities 和 target scopes，拒绝未知能力、越权节点、未经审批动作；审计不可绕过。
- MCP 是 Agent Ops 适配器，不允许直接连接 MySQL/Redis/Node，也不提供任意 shell、Docker socket 或通用文件访问。
- PHP 插件没有进程级安全沙箱，必须可信；ZIP 拒绝 traversal、symlink、重复/超限路径，插件禁用后 stale HTTP route 不可执行。
- 所有 TXAPI 入口必须复用真实业务授权与审计；任何未授权的旁路、插件动态路由或回调均不得绕过 RBAC、幂等及签名校验。
- 令牌、支付签名、密钥、动态管理路径、订阅 secret 不得被记录到不受保护日志，调试输出需脱敏。
- 对认证、Webhook、Node、Agent、插件管理加限流/重放保护；使用负向测试覆盖跨用户资源、管理员与普通 Token 混用、旧路由旁路、并发结算、ZIP 攻击。
- PHP/Composer、JS/npm 依赖安全更新需测试与发布审批；发现高风险权限/资金问题应阻断 release。

## 反向代理信任边界（Caddy）

Caddy 的可信代理默认仅允许 `127.0.0.1/8 ::1/128`，不再无条件信任 `0.0.0.0/0 ::/0`。使用 Docker Compose 时通过 `.env` 中 `TXBOARD_TRUSTED_PROXY_CIDRS` 配置上游可信 OpenResty/1Panel 代理的确切 IP/CIDR（多个以空格分隔）。不要直接填写全网或笼统地信任整个公网。修改后重建或重启 TXBoard 容器加载 Caddy 配置。

上线验收：从可信反代与直连入口分别测试 X-Forwarded-For/Forwarded 伪造、不可信 IP 下的限流身份、双代理链与 IPv6；记录实际网段、回退操作和反代跳数。默认收紧可能改变经过外部 Docker 反代的真实客户端 IP 识别，因此务必按部署拓扑显式设置，不应为“修复”访问日志而恢复全网信任。

## 插件 / 主题运行时边界

- 包安装必须验证归档目录穿越、绝对路径、符号链接、重复或大小写冲突路径、容量/数量上限、manifest 与入口文件；这些检测**不等于**证明插件本身可信。
- 依赖必须按包名和版本约束校验；缺失或未启用的依赖禁止激活，仍有已启用依赖者时禁止移除或禁用提供者。
- 升级使用隔离 staging、备份与受控重命名。第三方任意 PHP 清理、SQL 迁移及外部副作用不能保证原地回滚；必须准备可恢复的文件与数据库快照。
- Octane/Swoole 可能持续持有已加载类与路由；插件停用、卸载后仍应由 enabled guard 拒绝旧请求，必要时重启 Octane、queue 和 scheduler worker。
- 检验坏 ZIP、依赖冲突、错误 manifest、升级失败恢复、禁用后 stale route、Worker 缓存与可信参考插件的真实联调。

参照 [Agent Ops 架构](../architecture/agent-ops.md)、[Module Platform](../architecture/module-platform-v1.md)、[Plugin Package](../../contracts/plugin-package/README.md) 和 [Agent Contract](../../contracts/http/agent-ops-v1.md)。
