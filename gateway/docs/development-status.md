# Gateway 当前实现与验证范围

更新时间：2026-10-17。只记录当前能力、限制和证据边界；不保留逐日开发计划。源码合并、匹配源码的 CI、业务联调和正式发布分别判断。

## 当前实现

| 范围 | 当前源码事实 | 不能由此推断 |
| --- | --- | --- |
| 用户 BFF | 固定 `/gateway/v1`；外部 `{ok,data,meta}`，上游 Native envelope/DTO 校验 | 没有 `/txapi/bff/v1` 或统一管理员浏览器路由 |
| 上游调用 | 固定 Core `/txapi/*`；独立 `NativeCoreClient`/CLI 支持服务端 mTLS operation | 开启服务配置不等于所有旧浏览器 DTO 已改为 native |
| 身份隔离 | User/Admin mTLS 文件、service credential/epoch 分离；Core 重新验证 actor，Admin 再验安全路径/RBAC | 不能将服务凭据或 Admin Bearer 传给浏览器 SDK |
| 普通读取 | 套餐、profile、订单/分页、权益摘要、支付展示、通知、计数的现有适配器 | 旧展示模型与当前 Core 对应 DTO 的兼容须逐项验证 |
| 敏感账户 | 可选 HPKE 登录；注册/发码需 Redis/HPKE 及默认关闭的账户开关 | 不能以无 CAPTCHA/模拟邮件当作真实外部服务通过 |
| 安全机制 | 静态路由策略、可信入口、双维限流及热重载、脱敏日志/指标、受限缓存 | 运行代码存在不等于生产代理链/容量/恢复已验收 |
| HPKE v1 | 当前/旧 key、过期/吊销、AAD、毫秒 ±60 秒、Redis 121 秒 replay | 无响应加密；Redis failover 不具强一致 replay 保证 |
| 订单写适配 | 默认 405；打开开关后校验/透传历史 `plan_id/period` 及幂等头 | **不兼容当前 Native `offer_id` 创建，不是可用 native 购买链路** |
| SDK/示例 | `@txboard/theme-sdk` workspace、现有 Vue/React/Next 示例；三项离线 SC-v2 子路径 | npm 发布、SSR 主题安装或网络 SC-v2 均未因此开放 |
| 健康探针 | liveness、readiness 状态、凭据保护 metrics | 默认 upstream readiness 为占位，不代表 Core 真正连通 |

当前 mTLS 是已存在的服务消费者，不再沿用早期“全部 Internal 原型已删除”的叙述；也不因它存在就恢复已退役的浏览器密码恢复、会话管理、取消订单方法。真实入口和开关以源码及 [Native 接入](native-core-integration.md) 为准。

## 保留的历史证据

[native-core-baseline.json](../tests/integration/evidence/native-core-baseline.json) 是原样保留的旧快照，只证明文件记录的源码、环境及检查；不能覆盖当前 C3/C4、Native Offer、服务注册/mTLS 或后续业务改动，也不将 mock、日志邮件、MariaDB 等同真实生产浏览器、外部投递或 MySQL 8.4。

SC-v2 实验的 JSON verification/evidence 原样保留，作用和未解决 fence/密钥信任边界见 [加密说明](app-crypto-preview.md)；历史安全 review 的结论不替代现版独立审查。

## 验收与发布边界

- 本地只做语法/格式/差异与必要静态检查；数据库、进程、Redis、浏览器、完整构建和镜像验证在根 Actions 集中执行。文档精简不重跑重型测试。
- 相关运行时改动需要匹配最终源码的 Gateway/SDK、直接受影响业务及安全负例验收；已有通过记录没有新变更时不重复全量执行。
- Native Offer 购买消费者、生产 mTLS/PKI/代理链、外部 CAPTCHA/邮件、支付商/回调、多副本密钥传播/Redis 恢复和目标生产环境不能以旧基线代替。
- npm 发版与镜像发布是独立动作；没有默认开启交易入口或自动上线的许可。正式发布使用实际 digest、依赖审查、秘密检查和恢复证据，见 [发布记录](release-checklist.md)。

当前接口：[Gateway v1](../contracts/gateway-v1.md) · [Native 服务](native-core-integration.md) · [架构](architecture.md) · [主仓当前进展](../../docs/architecture/v2/current-progress.md)。
