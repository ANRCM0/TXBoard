# Core First v2 契约目录

> 状态：Target / Draft。2026-10-12 开始 B0；本目录不注册路由、不改变当前权限、不代表部署完成。

TXBoard 是业务与数据权威。Gateway、Deploy、TX-Node 与可选插件按本目录的版本化契约协作，不能分别定义订单、授权、流量与升级的业务真相。`v2` 是平台演进目录；其中每项协议有自己的版本，不代表全部协议同时升到第二版。

2026-10-13 [当前范围](../../docs/architecture/v2/admin-only-scope.md)：独立前端只推进管理端，新用户前端延期，Agent/MCP 已退役。当前 User UI/Theme 保留兼容；Admin 迁移仍要求独立服务身份、Core 当前授权与通道验收。

| 契约 | 负责边界 | 实施批次 |
| --- | --- | --- |
| [Core Internal API v1](core-internal-api/README.md) | 服务身份、最终用户授权、Operation、DTO、幂等 | B5 |
| [Credential Provider v1](credential-provider/README.md) | 秘密归属、发行、租约、撤销、生命周期 | B4 |
| [Node Usage v2](node-usage/README.md) | 计费主体、持久接收、结算、重放 | B3 |
| [Secure Channel v2](secure-channel/README.md) | User/Admin 安全域、双向加密、密钥与防重放 | B6 |
| [Upgrade Manifest v1](upgrade-manifest/README.md) | 兼容矩阵、迁移阶段、检查点与恢复 | B2 |

## 当前接口仍然有效

当前 HTTP 权威是 [TXAPI 接口](../http/external-adapter-current.md) 和 Laravel 注册路由；当前节点协议是 [Node Protocol v1](../node-protocol/README.md)。当前扩展继续遵守 [Plugin Package v1](../plugin-package/README.md)、[Module Lifecycle v1](../module-lifecycle/README.md) 和 Admin Bridge v1。

不得为了符合目标目录而重新注册已退役的 `/api/v1`、`/api/v2`，也不得将目标 Internal API、升级 CLI 或加密通道写成已实现。协议落地需同时实现生产者、消费者、失败测试、兼容检测和退出条件。

## 共同规则

- 64 位数据库 ID、bytes 与金额最小单位在新 JSON DTO 中用十进制字符串；时间为 UTC Unix 秒。旧协议独立解码，不就地改变字段类型。
- 权限、财务和流量归属由 Core 验证；声明 capability 或通过 mTLS 不等于最终用户授权。
- 修改已实现的对外行为时，更新对应当前契约和真实消费者；本目录不能作为绕过现有约束的依据。
- 示例是非秘密的目标格式；没有执行迁移或开放接口。

[B0 入口与状态](../../docs/architecture/v2/README.md) · [实施顺序](../../docs/architecture/v2/implementation-plan.md)
