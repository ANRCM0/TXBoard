# Core First v2 契约目录

> 更新：2026-10-16。C1、C2 已实现并合入 main，其余协议按各自状态阅读；本目录不注册路由，也不代表整体已正式发布。

项目仍处开发阶段，尚未接入正式测试，以当前 native 为开发基线，不承担旧版本过渡兼容。允许生产者、真实消费者与契约同步变更；不为历史协议新增双轨适配。旧迁移/隔离切片的历史证据保留；已退役源码与命令不再可调用，清理范围见 [Core 清理](../../docs/development/core-cleanup.md)，开发规则见[开发基线](../../docs/development/native-development-baseline.md)。

TXBoard 是业务与数据权威。Gateway、Deploy、TX-Node 与可选插件按本目录的版本化契约协作，不能分别定义订单、授权、流量与升级的业务真相。`v2` 是平台演进目录；其中每项协议有自己的版本，不代表全部协议同时升到第二版。

2026-10-13 [当前范围](../../docs/architecture/v2/admin-only-scope.md)：管理前端与用户前端均暂缓，后续集中到独立 `TX-F` 项目，只本地提交、不推送；本仓推进 Core、Gateway、Node，安装器独立成库；Agent/MCP 已退役。当前 Admin/User UI/Theme 保留兼容；Admin 迁移仍要求独立服务身份、Core 当前授权与通道验收。

| 契约 | 负责边界 | 实施批次 |
| --- | --- | --- |
| [Core Commerce v1](core-commerce-v1.md) | 正式订单购买快照与履约输入；不等于订阅/账务权威切换 | Core C1 |
| [Core Subscription v1](core-subscription-v1.md) | 安装准备、正式目录及订阅聚合基础；生产启用条件由 Native C2 Runtime v1 补充 | Core C2 基础 |
| [Native C2 Runtime v1](native-c2-runtime-v1.md) | 原生就绪转换、免费购买、Provider 轮换与接收者渲染、签名授权及原周期结算；精确提交验收见 PR #251 | Core C2 收尾 |
| [Core Billing v1](core-billing-v1.md) | C3-A 真实双分录与支付尝试/收款事实基础；渠道接入、退款及对账完成前不开放付费 | Core C3 实现中 |
| [Core Internal API v1](core-internal-api/README.md) | 正式服务身份、调用者授权与 Operation 待交付；旧隔离切片已退役 | C4 / B5 历史 |
| [Credential Provider v1](credential-provider/README.md) | 秘密归属、发行、租约、撤销、生命周期 | B4 |
| [Node Usage v2](node-usage/README.md) | 计费主体、持久接收、结算、重放 | B3 |
| [Secure Channel v2](secure-channel/README.md) | User/Admin 安全域、双向加密、密钥与防重放 | B6 |
| [Upgrade Manifest v1](upgrade-manifest/README.md) | 兼容矩阵、迁移阶段、检查点与恢复 | B2 |

## 当前接口仍然有效

当前 HTTP 权威是 [TXAPI 接口](../http/external-adapter-current.md) 和 Laravel 注册路由；当前节点协议是 [Node Protocol v1](../node-protocol/README.md)。当前扩展继续遵守 [Plugin Package v1](../plugin-package/README.md)、[Module Lifecycle v1](../module-lifecycle/README.md) 和 Admin Bridge v1。

不得为了符合目标目录而重新注册已退役的 `/api/v1`、`/api/v2`，也不得将目标 Internal API、升级 CLI 或加密通道写成已实现。协议落地需同时更新生产者、实际消费者、直接相关负向检查和能力声明；不要求维护历史协议兼容窗口。

## 共同规则

- 64 位数据库 ID、bytes 与金额最小单位在当前 JSON DTO 中用十进制字符串；时间为 UTC Unix 秒。格式变更同时更新真实消费者，不额外保留旧解码层。
- 权限、财务和流量归属由 Core 验证；声明 capability 或通过 mTLS 不等于最终用户授权。
- 修改已实现的对外行为时，更新对应当前契约和真实消费者；本目录不能作为绕过现有约束的依据。
- 示例是非秘密的目标格式；没有执行迁移或开放接口。

[B0 入口与状态](../../docs/architecture/v2/README.md) · [实施顺序](../../docs/architecture/v2/implementation-plan.md)

[Identity SQL writers v5](authority-identity-writers-v5.md) and [Support-ticket SQL writers v6](authority-ticket-writers-v6.md) are retired historical protocols. Their isolated migration runtime, CLI and dedicated CI have been removed. Current identity/session and support transactions use their actual domain owners and installation admission; the retained verification reports describe earlier evidence only.
