# Gateway 真实 TXBoard Staging 联调入口（GW-104 / GW-105 / GW-216）

合仓后先 `cd gateway`；下列路径均相对此 workspace。完整 staging Compose 的默认 HPKE 文件为 `gateway/secrets/gateway-hpke.json`，相对其文件所在目录写作 `../secrets/gateway-hpke.json`。

> **不是生产部署手册，也不是已完成的真实端到端联调证明。**  
> 本目录仅为已有隔离 TXBoard 环境提供 opt-in Gateway 接入，  
> 与 CI 中基于伪造 Laravel HTTP 服务的 Chromium 测试不同。

## 运行前置

1. 在另一台/独立网络环境先运行固定版本 TXBoard（Laravel + MySQL + Redis）。  
   可以使用官方 TXBoard / TXBoard-Deploy 的独立测试安装，不要复用生产数据库、账号、邮件密钥、订单或管理入口。
2. 确定 TXBoard staging Docker 网络名称，例如 `txboard-staging_default`。  
   确认 Gateway 容器可以从该网络访问到运行中的 `http://txboard`（Caddy/Laravel）。  
   如果服务名不同，以 `TXBOARD_STAGING_UPSTREAM_URL` 指向明确的内网 HTTP origin。
3. 只在真实 staging 的可信 HTTPS 入口配置 `/gateway/v1/*` 反代。  
   其余 /api/*、/s/*、管理员、支付回调和节点路由仍完全指向 TXBoard。

## 快速开始

### 方式一：一键启动（推荐）

```bash
# 1. 复制环境模板
cp staging/.env.example .env
# 编辑 .env 配置上游和 Origin

# 2. 一键启动（含构建和详细日志）
scripts/staging-up.sh --build --verbose

# 3. 验证
docker compose -f staging/docker-compose.staging.yml ps
```

**staging-up.sh 选项：**

| 选项 | 说明 |
|------|------|
| `--build` | 强制重新构建镜像 |
| `--verbose` | 显示详细日志 |
| `--network-only` | 仅创建网络，不启动服务 |
| `--skip-verify` | 跳过启动后验证（仅启动服务） |

### 方式二：手动启动

```sh
export TXBOARD_STAGING_NETWORK=txboard-staging_default
export TXBOARD_STAGING_UPSTREAM_URL=http://txboard
export GATEWAY_STAGING_ALLOWED_ORIGINS=https://staging.example.test
docker compose -f staging/compose.gateway.yaml config
docker compose -f staging/compose.gateway.yaml up -d --build
docker compose -f staging/compose.gateway.yaml ps
```

### 方式三：完整自包含环境（含 Redis + HPKE）

```bash
cp staging/.env.example .env
docker compose -f staging/docker-compose.staging.yml up -d --build
```

Compose 仅 `expose: 8787` 到 Docker 私网，无 `ports:` 公网映射。  
如果独立的前置 Nginx 与 Gateway 在同一个私有网络，可以使用：

```nginx
location ^~ /gateway/v1/ {
    proxy_pass http://txboard-gateway:8787;
    proxy_set_header Host $host;
    proxy_set_header X-Request-Id $request_id;
    proxy_connect_timeout 3s;
    proxy_read_timeout 15s;
}
```

`proxy_pass` **不要追加 URI 尾斜杠**，确保固定 `/gateway/v1/` 路径保留。  
部署网络中 `txboard-gateway` 必须是可解析的服务别名；外部代理不在同网时需使用安全的独立网络方式对接。公网只公开既有 HTTPS 入口，不直通 :8787。

## HPKE 配置

默认 HPKE 模式为 `disabled`。启用 HPKE 需要：

```bash
# 1. 生成 HPKE 密钥对
node scripts/generate-hpke-key.mjs

# 2. 设置权限（容器以 uid=1000 运行）
sudo chown 1000:1000 secrets/gateway-hpke.json
sudo chmod 600 secrets/gateway-hpke.json

# 3. 在 .env 中启用
# GATEWAY_HPKE_MODE=enabled

# 4. 启动（staging-up.sh 会自动检查密钥文件）
scripts/staging-up.sh --build
```

## 只读冒烟与真实验收

```sh
GATEWAY_SMOKE_URL=https://staging.example.test npm run smoke:staging
```

可选：使用**隔离测试用户** 的短期 Bearer，加入  
`GATEWAY_SMOKE_USER_BEARER` 以检查 profile/orders。不要输入管理员 token，  
更不要在 shell history、聊天、日志和 Issue 中公开真实令牌。

真实浏览器手工/自动化验收表（每项提供脱敏记录、实际环境版本和 CI 链接）：

- [ ] 真实 TXBoard guest 配置、套餐、主题（多语言/资源字段如适用）。
- [ ] 真实浏览器分别验证关闭 CAPTCHA、Turnstile、reCAPTCHA v2/v3（仅测试 Site Key，服务端仍要实际验证）。
- [ ] 正确登录→令牌过期→401→重新登录；兼容浏览器 Origin/OPTIONS 和异常网络状态。
- [ ] 账户信息、订单数组和金额分数单位与原生接口一致；旧主题仍可工作。
- [ ] staging 反代开启、停止 Gateway、恢复旧入口的回滚演练。
- [ ] 记录隔离后端的 Laravel commit、Gateway commit、Docker 镜像 digest、压测/时延和脱敏错误样本。

## 回滚与切换

### 快速回滚

```bash
# 1. 关闭前端 Gateway feature flag
# 2. 在代理层移除 /gateway/v1/* 映射
# 3. 重启代理
# 4. 验证旧 API 可用
curl -sf https://staging.example.test/api/v1/bootstrap

# 5. 停止 Gateway（可选）
docker compose -f staging/docker-compose.staging.yml stop gateway

# 6. 或完全清理（不删除数据卷）
docker compose -f staging/docker-compose.staging.yml down
```

### 回滚演练

```bash
# 自动演练（不交互，自动执行所有步骤）
scripts/rollback-drill.sh --auto --verbose

# 手动演练（交互式）
scripts/rollback-drill.sh --verbose

# 跳过恢复（演练后保持 Gateway 停止状态）
scripts/rollback-drill.sh --auto --skip-restore

# 仅模拟，不实际执行
scripts/rollback-drill.sh --dry-run
```

**rollback-drill.sh 选项：**

| 选项 | 说明 |
|------|------|
| `--auto` | 自动模式（不交互） |
| `--verbose` | 显示详细日志 |
| `--skip-restore` | 不恢复 Gateway（演练后保持停止状态） |
| `--dry-run` | 仅打印将要执行的操作，不实际执行 |

### 切换流程文档

详细的 Gateway/旧 API 切换流程请参阅 [gateway-legacy-switchover.md](./gateway-legacy-switchover.md)。

**回滚原则：**  
- 先关前端 Gateway feature flag / 撤销代理 `/gateway/v1/*`，再停 Gateway  
- 不要对 TXBoard 的 MySQL/Redis 数据卷执行 `down -v`  
- 已有旧 `/api/*` 和管理端、节点、支付路由均不依赖 Gateway  

## 故障场景

### Gateway 容器崩溃

```bash
# 查看日志
docker compose -f staging/docker-compose.staging.yml logs gateway

# 重启
docker compose -f staging/docker-compose.staging.yml restart gateway

# 如果持续崩溃，回滚到旧 API（参考上方"快速回滚"）
```

### Redis 不可达

```bash
# 检查 Redis 容器
docker compose -f staging/docker-compose.staging.yml ps gateway-redis

# 恢复 Redis
docker compose -f staging/docker-compose.staging.yml restart gateway-redis
```

Gateway 在 Redis 不可达时会 **fail-closed**（返回 503），不会回退到明文登录。

### 上游 TXBoard 不可达

```bash
# 检查上游
curl -sf http://txboard/api/v1/bootstrap

# Gateway 会返回 502/503
# 如果 TXBoard 挂了，回滚到旧 API 无意义（旧 API 也不可用）
# 需要恢复 TXBoard
```

### 代理配置错误

```bash
# 检查代理配置
nginx -t  # 或 caddy validate

# 回滚代理配置
# 恢复旧代理文件
```

## 压测与故障演练

### 压测脚本

```bash
# 完整压测（所有场景）
scripts/benchmark.sh --base-url http://127.0.0.1:8787 --duration 30 --concurrency 10

# 指定场景
scripts/benchmark.sh --scenarios normal,peak

# 输出 JSON 报告
scripts/benchmark.sh --output benchmark-report.json
```

**覆盖场景：**
- 正常负载 — 持续只读请求，验证基线性能
- 峰值负载 — 高并发突发，验证限流和稳定性
- 网络超时 — 上游慢响应，验证超时处理
- 进程退出 — Gateway 容器重启，验证恢复
- DNS 错误 — 上游 DNS 不可达，验证错误处理

### 故障演练脚本

```bash
# 完整演练（所有场景）
scripts/fault-drill.sh --auto --verbose

# 指定场景
scripts/fault-drill.sh --scenarios gateway-down,redis-failure

# 输出 JSON 报告
scripts/fault-drill.sh --output fault-drill-report.json
```

**覆盖场景：**
- Gateway 宕机 — 停止 Gateway 容器，验证旧 API 仍可用
- Redis 故障 — 停止 Redis，验证 Gateway fail-closed
- 上游超时 — 模拟上游 TXBoard 不可达，验证超时处理

### 回滚演练脚本

```bash
# 自动演练
scripts/rollback-drill.sh --auto --verbose

# 输出 JSON 报告（可追踪）
scripts/rollback-drill.sh --output rollback-drill-report.json
```

**追踪功能：**
- 每次演练生成唯一追踪 ID
- 记录 Gateway 版本、镜像 digest、时间戳
- 所有操作记录到日志文件

## 文件清单

| 文件 | 说明 |
|------|------|
| `staging/compose.gateway.yaml` | 轻量 Gateway overlay（依赖外部 Redis/HPKE） |
| `staging/docker-compose.staging.yml` | 完整自包含 staging 环境（Gateway + Redis + HPKE） |
| `staging/.env.example` | 环境变量模板 |
| `staging/gateway-legacy-switchover.md` | Gateway/旧 API 切换流程文档 |
| `scripts/staging-up.sh` | 一键启动脚本 |
| `scripts/rollback-drill.sh` | 回滚演练脚本（含追踪 ID） |
| `scripts/benchmark.sh` | 压测脚本 |
| `scripts/fault-drill.sh` | 故障演练脚本 |

## 一键启动/回滚命令汇总

```bash
# 一键启动
scripts/staging-up.sh --build --verbose

# 一键回滚（停止 Gateway + 移除代理路由后执行）
docker compose -f staging/docker-compose.staging.yml stop gateway

# 一键清理（不删除数据卷）
docker compose -f staging/docker-compose.staging.yml down

# 一键演练
scripts/rollback-drill.sh --auto --verbose
```

## 已知限制

- 本仓库没有从生产服务器获取数据库/Token，也不会使用真实管理员凭证执行测试。
- CI 的 Chromium mock E2E 证明 SDK→HTTP Gateway→受控模拟上游能工作，  
  **不能证明**独立 TXBoard Laravel+MySQL/Redis 的真实联调或第三方验证码服务可用。
- 完整 GW-104/105/216 验收须运行本手册中的隔离真实环境流程后才能勾选。
