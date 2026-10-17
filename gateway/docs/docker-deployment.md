# TXBoard Gateway Docker / 1Panel 部署与恢复 Runbook

当前源码位于主仓 `gateway/`，下列命令先 `cd gateway`。根 Actions 独立构建 `ghcr.io/<owner>/txboard-gateway`，统一版本规则见 [合仓运行约定](monorepo-current.md)；合并源码不改变原部署开关。

> 当前代码支持容器化配置，不能据此宣称生产部署已验收。实际环境、最终源码和镜像 digest 必须匹配；完整运行/构建检查在 CI/获授权环境执行。以下只操作 Gateway 自身，不变更 Core、MySQL、Redis 或支付回调。

## 当前 Native 边界

只将当前 `/gateway/v1/*` 分流到私网 Gateway；没有 `/txapi/bff/v1` 或管理员浏览器路由。Core 已注册的 `/txapi/*`、支付回调、订阅和节点入口保持原生鉴权，不恢复退役旧 API。固定 upstream 指向受控 Core origin，不能回到相同公网反代形成循环。服务 mTLS 及配置隔离见 [Native 接入](native-core-integration.md)；以下基础 Compose 不会自动注入完整 service PKI。

## 1. 网络结构

```text
Browser (HTTPS only)
  → 现有 Nginx/OpenResty/Caddy/1Panel HTTPS 入口
       ├─ /gateway/v1/* → gateway:8787 (private Docker network)
       └─ Core 其他已注册路由、订阅、admin、node、plugins、payment webhook → Core
  Gateway → 固定 TXBOARD_UPSTREAM_URL (private trusted origin)
  Gateway → Redis (私网；仅启用 HPKE 时必须)
```

**不提供任何新的对外端口映射。** 原有代理须与 Gateway 共享可信 Docker 网络；配置 `GATEWAY_DOCKER_NETWORK` 为已存在的独立私网。不要把 Gateway 放到公开 Docker network 并直接暴露 8787。

## 2. 公共配置

```sh
cp .env.example .env
chmod 600 .env
# 修改 .env: TXBOARD_UPSTREAM_URL, GATEWAY_ALLOWED_ORIGINS
docker network inspect txboard-gateway-private >/dev/null 2>&1 || docker network create txboard-gateway-private
docker compose -f compose.yaml config --quiet
```

若 TXBoard upstream 是私有 `http://txboard:80`，需 `TXBOARD_ALLOW_PRIVATE_HTTP=true` 且 Gateway 必须能在受控网络解析该服务；不要用任意第三方明文 origin。所有浏览器入口始终走 HTTPS。

## 3. 部署档位

| 档位 | Compose 组合 | HPKE | Redis | 账户写入口 |
| --- | --- | --- | --- | --- |
| 基础只读 + TLS 登录入口 | `-f compose.yaml` | off | 按实际路由安全配置 | off |
| 私网加密预览 | `-f compose.yaml -f compose.crypto.yaml -f compose.redis.yaml` | on | 内置私有容器 | 默认 off |
| 1Panel Redis 预览 | `-f compose.yaml -f compose.crypto.yaml` | on | 自有 `GATEWAY_REDIS_URL` | 默认 off |
| 加密注册/邮件验证码 | 加密预览组合 + `GATEWAY_ACCOUNT_WORKFLOWS_ENABLED=true` | on | 必需 | **显式 opt-in，仅隔离环境** |

### 启用带 Redis 的 HPKE

先生成且仅生成一次私钥，避免重建导致 kid 改变：

```sh
node scripts/generate-hpke-key.mjs
sudo chown 1000:1000 secrets/gateway-hpke.json
sudo chmod 600 secrets/gateway-hpke.json

docker compose -f compose.yaml -f compose.crypto.yaml -f compose.redis.yaml config --quiet
docker compose -f compose.yaml -f compose.crypto.yaml -f compose.redis.yaml up -d --build
docker compose -f compose.yaml -f compose.crypto.yaml -f compose.redis.yaml ps
```

`secrets/` 已进入 `.dockerignore` 与 `.gitignore`，私钥由 Docker 运行时挂载，不应进入镜像、仓库、CI 工件、聊天或日志。Docker Gateway 使用 UID 1000。若是 Linux 绑定挂载的文件 Secret，Compose 的 uid/gid/mode 设置不一定覆盖宿主权限；必须确保 UID 1000 能读且其他非授权用户不可读。

### 连接现有 1Panel Redis

- Redis 在 Gateway 可达的可信 Docker 网络内，优先使用已启用 ACL/密码的服务；非同机可信网段使用 `rediss://`。
- 设置 `GATEWAY_REDIS_URL`（例如私有服务地址，含密码时不要截图、记录在 Issue）。
- **不添加** `compose.redis.yaml`；用基础 + crypto 两份 Compose。
- Redis 断连/初始化失败时，HPKE 登录注册失败而非回退到本地 Map；不可将 503 改造成放行。
- 多个 Gateway 必须共用同一持久私钥与可信 Redis；Redis failover/AOF 的数据持久性尚未完成正式验收。

## 4. 代理接入（手动 opt-in）

在现有 HTTPS 入口，**只**配置：

```nginx
location ^~ /gateway/v1/ {
    proxy_pass http://gateway:8787;
    proxy_set_header Host $host;
    proxy_connect_timeout 3s;
    proxy_read_timeout 15s;
}
```

`gateway` 需为反代所在网络的可解析服务名。不要在 `proxy_pass` 后面添加 URI 尾斜杠，以免改变路径。配置前保留代理文件和回滚版本；Core 其他已注册入口及其鉴权不变。需单独核对可信 peer/转发头、Host 与 Origin；此片段不是可直接套用的生产访问控制模板。

## 5. 校验与回滚

```sh
docker compose -f compose.yaml ps
# 从可访问该服务的受控私网检测 /healthz；
# 经 HTTPS 代理检查 /gateway/v1/bootstrap 和 /gateway/v1/plans
GATEWAY_SMOKE_URL=https://your-staging.example npm run smoke:staging
```

`/healthz` 只证明容器能响应，不表示 Redis、防刷、Laravel、邮件或支付可用。模拟 CI：
`npm run check`、`npm run test:redis`（需 `GATEWAY_TEST_REDIS_URL`）、`npm run test:browser`。

回滚：**先**停止受影响客户端向 Gateway 发新请求/撤销 `/gateway/v1/*` 代理，**再**停止对应 Compose。只恢复受支持的 Core 入口/客户端，不恢复退役 API。不可执行 `down -v`，不可删 Core 数据卷、生产 Redis、幂等或资金记录。未知写结果保留原 key，由 Core 恢复。

## 6. 上线阻断项

密钥双版本、吊销、replay、日志与指标已经有运行实现，不等于完成生产证明。上线须核对匹配版本的代理/PKI、全副本与缓存传播、Redis 持久性/故障恢复、外部 CAPTCHA/邮件、容量及恢复演练。历史隔离 MariaDB 联调不能代替当前 Native 业务或 MySQL 8.4。

订单写默认关闭；显式开启后只接受已发布 Native Offer 的 `offer_id`，Core 仍决定购买及资金。Gateway 不新增结算/支付回调入口。详见 [当前状态](development-status.md)、[加密运行限制](app-crypto-preview.md) 与 [安全政策](../SECURITY.md)。
