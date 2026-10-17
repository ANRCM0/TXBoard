# Gateway Security Policy

Gateway 是固定 API 适配器，不是 WAF、匿名化服务或端到端通信加密系统。公网必须经有效证书的 HTTPS。漏洞通过仓库私有 Security Advisory 提交；公开 Issue、日志、工件或聊天不得包含凭据。

## 当前控制与归属

- 静态上游/路由策略、严格请求 DTO 与 Native 响应投影、精确 Origin/Host、受限大小/超时、no-store 和安全头；不提供任意 host/path 代理。
- 普通用户 Bearer 只转发给需要的操作；管理员原生入口和支付/Node/订阅路由独立。服务端 Native User/Admin 客户端使用各自 mTLS、service credential/epoch，Core 仍复验 actor/RBAC；这些秘密不进入 Theme SDK。
- HPKE v1 只加密请求体，已含当前/旧 key、过期与吊销、固定 AAD、毫秒 ±60 秒窗口。Redis 原子 replay TTL 121 秒；重放 409，故障 503，无本地 replay 回退或自动明文降级。
- Redis 原子 IP/账号双维滑动窗口，敏感路径 fail-closed；公开/只读按策略降级并观测。账户预算独立计数，真实 Core 自身 CAPTCHA/限流/授权不可取消。
- 受限结构化日志/指标；禁止 Authorization、密码、完整邮箱、CAPTCHA、正文、私钥、订阅私密地址和上游内部异常。依赖锁、SBOM/审计脚本及独立镜像上下文不因合仓合并权限。

## 不可省略的限制

HPKE base mode 不认证发送者，不防主题 XSS，也不保证长期私钥泄露后历史密文保密；响应/Bearer 仍依赖 TLS。吊销不能销毁已泄露的 key。轮换窗口从进程加载时算起，发现响应当前可短缓存，不能承诺全副本和缓存瞬时吊销。

Redis 正常期间的 `SET NX PX` 不代表故障切主、旧快照恢复或丢键后仍强一致。replay 不是业务幂等，Gateway 不持资金/订单账本；未知写结果不能自动换 key 或重复扣款。Native 购买只接受 Core 已发布 `offer_id`，订单写开关保持默认关闭。

`/healthz` 仅 liveness，默认 upstream readiness 是占位。源码中的可信入口、指标和密钥生命周期不替代生产 PKI/代理链、Redis 恢复、容量及密码学审查证据。第三方主题/插件需信任作者，不能将同源 JS 或可信同进程 PHP 描述为沙箱。

三项 SDK SC-v2 experimental 子路径及共享 owner 工程夹具仅限离线/测试；当前没有正式网络 SC-v2 入口。共享 owner 校验与 WebCrypto 加密不是原子 fence，盐候选也不证明 replay/恢复或 actor 权限。

## 部署与恢复

Gateway/Redis 不直接开放公网；私钥和 mTLS 文件只读、最小权限注入，不进 Git/镜像/日志。只能分流 `/gateway/v1/*`；停用 Gateway 不得删除 Core MySQL/Redis/财务记录或恢复退役 API。安全事件先收紧受影响入口，保存脱敏证据并核对实际已提交业务，不伪造成功/失败结果。

当前细节：[加密与实验限制](docs/app-crypto-preview.md) · [架构](docs/architecture.md) · [Native 接入](docs/native-core-integration.md) · [部署恢复](docs/docker-deployment.md) · [版本与验证](docs/development-status.md)。
