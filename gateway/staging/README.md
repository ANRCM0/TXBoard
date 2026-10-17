# Gateway 隔离 Staging 与恢复

这里只说明现有资源和安全约束，不是正式部署证明。命令从主仓 `gateway/` 执行；数据库、进程、Redis、浏览器和容器联调由 CI/获授权环境集中执行，不为文档更新自行启动本地环境。

## 环境与入口

- 使用固定版本、专用账号和独立数据库/Redis 的 Core 测试环境；禁止复用生产数据、邮件密钥、订单或管理员凭据。
- Gateway 固定上游指向受控私网 origin，不回到相同公网反代形成循环。仅将 HTTPS `/gateway/v1/*` 送往 Gateway；其余 Core 已注册入口保持原生鉴权，不恢复 `/api/v1`、`/api/v2` 或 Agent/MCP。
- 反代与 Gateway 共用受控私网，Gateway 8787/Redis 不直通公网。精确 Host/Origin、可信 socket peer 和转发头规则需按实际网络核对；示例 Nginx 片段不是完整生产访问控制配置。
- 原生服务配置使用独立 User/Admin mTLS、service credential/epoch，不能从主题传入。操作格式见 [Native 接入](../docs/native-core-integration.md)。

## 现有资源

| 路径 | 作用与限制 |
| --- | --- |
| `staging/compose.gateway.yaml` | Gateway overlay，依赖外部 Core 网络及所需安全资源 |
| `staging/docker-compose.staging.yml` | Gateway + Redis/Secret 模板，不包含 Core/MySQL，不是完整业务测试环境 |
| `staging/.env.example` | 环境模板，使用前与实际 runtime/Compose 配置逐项核对 |
| `scripts/staging-up.sh` | 既有模板启动帮助，不证明当前 Native/mTLS 部署已验收 |
| `scripts/smoke-gateway.mjs` | 公开/可选普通用户只读 HTTP 冒烟 |
| `scripts/benchmark.sh`、`fault-drill.sh`、`rollback-drill.sh` | 既有演练帮助；历史旧 API 探针不能充当当前 Core 成功判据 |

Docker 基础/crypto/Redis 的当前部署说明集中在 [部署 Runbook](../docs/docker-deployment.md)。HPKE 合法模式是 `disabled` / `optional`，没有 `enabled`；optional 必须有实际传入容器的绝对 `GATEWAY_HPKE_KEY_FILE`、可读 P-256 私钥和 Redis，不能只挂载文件或改 `.env` 就假设配置已生效。模板没有自动完成全副本轮换或 service mTLS 注入。

在已审查的隔离环境可执行只读冒烟：

```sh
GATEWAY_SMOKE_URL=https://staging.example.test npm run smoke:staging
```

可选 `GATEWAY_SMOKE_USER_BEARER` 只能用隔离普通用户短期凭据，经受控秘密环境传入，不进命令历史/Issue/工件。`/healthz` 仅 liveness，默认 upstream readiness 是占位，不能替代实际授权调用。

## 当前业务判断

应记录实际 Core/Gateway commit、镜像 digest、PHP/DB/Redis 版本和脱敏结果；mock 浏览器测试与真实业务环境分开。CAPTCHA、邮件、Token 撤销、两用户/他人订单隔离和异常网络使用真正目标接口验证。

Gateway displays current Native published Offers and, only when the explicit write gate is enabled, sends the selected `offer_id` to Core. Default disabled behavior remains 405 with zero upstream calls. The gate does not authorize payment or fulfillment claims; Provider/callback and financial authority remain in Core.

HPKE 需要核对新旧 key、过期/吊销、毫秒时间窗、replay/限流及 Redis 故障时敏感操作 503。replay TTL 121 秒不提供 Redis failover 强一致；生产还需全副本/缓存传播及恢复证据，见 [加密说明](../docs/app-crypto-preview.md)。

## 停用与恢复

1. 停止受影响前端向 Gateway 发新请求，关闭相应 Gateway 功能或撤销 `/gateway/v1/*` 代理映射。
2. 按实际部署恢复受支持的 Core 入口/客户端，**不**恢复退役旧 API；独立检查 Core 当前公开配置或有授权业务请求。
3. 再停止对应 Gateway Compose 服务；不触碰 Core 数据库/Redis、财务幂等或业务历史，不执行 `down -v`。
4. 保存脱敏 request ID、实际版本/digest、未知写结果和恢复记录；未知结果不能通过换 key 自动重发来“修复”。

Redis 不可达：恢复受控安全存储，敏感写/HPKE 按策略 fail-closed，不能降级成明文绕过。Core 不可达：先恢复 Core，停 Gateway 不会修复 Core 故障。代理错误：用部署端校验工具核对配置和路由，不扩大全局反代匹配范围。

完整记录使用 [发布记录模板](../docs/release-checklist.md)，历史集成结果和不适用范围见 [当前状态](../docs/development-status.md)。
