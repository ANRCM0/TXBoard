# Gateway / 旧 API 切换流程（GW-216）

> 本文档描述 staging 环境中 Gateway 与旧 TXBoard API 的切换流程、回滚步骤和验收标准。
> 适用于隔离 staging 环境，不适用于生产。

## 1. 架构概述

```text
                    ┌─────────────────────────────────────┐
                    │         HTTPS 入口 (Nginx/Caddy)     │
                    │                                     │
                    │  /gateway/v1/* ──→ Gateway:8787     │
                    │  /api/*, /s/*  ──→ TXBoard (直连)    │
                    │  管理/节点/支付  ──→ TXBoard (直连)    │
                    └──────────────┬──────────────────────┘
                                   │
                    ┌──────────────▼──────────────────────┐
                    │     TXBoard Staging 私网             │
                    │                                     │
                    │  Gateway ──→ TXBoard (Laravel)      │
                    │  Gateway ──→ Redis (HPKE/限流)      │
                    │  TXBoard ──→ MySQL / Redis          │
                    └─────────────────────────────────────┘
```

**核心原则：**
- Gateway 是**协议/展示适配层**，不是业务替代品
- 旧 `/api/*`、`/s/*`、管理、节点、支付回调**始终直连 TXBoard**
- 回滚 = 撤销 `/gateway/v1/*` 反代 + 停用 Gateway 主题适配开关
- **不触碰** TXBoard 的 MySQL/Redis 数据卷

## 2. 部署模式

### 2.1 轻量 Overlay（仅 Gateway）

适用于已有 TXBoard staging 网络 + 外部 Redis/HPKE 的场景。

```bash
export TXBOARD_STAGING_NETWORK=txboard-staging_default
export TXBOARD_STAGING_UPSTREAM_URL=http://txboard
export GATEWAY_STAGING_ALLOWED_ORIGINS=https://staging.example.test

docker compose -f staging/compose.gateway.yaml up -d --build
```

### 2.2 完整 Staging 环境（自包含）

包含 Gateway + Redis + HPKE，适合一键启动完整隔离环境。

```bash
cp staging/.env.example .env
# 编辑 .env 配置上游和 Origin

docker compose -f staging/docker-compose.staging.yml up -d --build
```

### 2.3 代理配置

在 HTTPS 入口（Nginx/Caddy）添加：

```nginx
location ^~ /gateway/v1/ {
    proxy_pass http://txboard-gateway:8787;
    proxy_set_header Host $host;
    proxy_set_header X-Request-Id $request_id;
    proxy_connect_timeout 3s;
    proxy_read_timeout 15s;
}
```

**注意：**
- `proxy_pass` 不要追加 URI 尾斜杠
- `txboard-gateway` 必须是可解析的服务别名
- 公网只公开既有 HTTPS 入口，不直通 :8787

## 3. 切换流程

### 3.1 启用 Gateway（旧 API → Gateway）

```bash
# 1. 确认 TXBoard staging 网络存在
docker network inspect txboard-staging_default

# 2. 启动 Gateway
docker compose -f staging/docker-compose.staging.yml up -d --build

# 3. 验证 Gateway 健康
docker compose -f staging/docker-compose.staging.yml ps
curl -sf http://127.0.0.1:8787/healthz

# 4. 在代理层添加 /gateway/v1/* 映射
# 5. 重启代理
# 6. 验证 Gateway 路由
GATEWAY_SMOKE_URL=https://staging.example.test npm run smoke:staging

# 7. 开启前端主题适配 feature flag
```

### 3.2 回滚（Gateway → 旧 API）

```bash
# 1. 关闭前端 Gateway feature flag
# 2. 在代理层移除 /gateway/v1/* 映射
# 3. 重启代理
# 4. 验证旧 API 可用
curl -sf https://staging.example.test/api/v1/bootstrap

# 5. 停止 Gateway（可选，保留容器便于快速恢复）
docker compose -f staging/docker-compose.staging.yml stop gateway

# 6. 或完全清理（不删除数据卷）
docker compose -f staging/docker-compose.staging.yml down
```

**关键：** 不要执行 `down -v`，不要删除 TXBoard 数据卷。

### 3.3 快速恢复（回滚后重新启用）

```bash
# 1. 启动 Gateway
docker compose -f staging/docker-compose.staging.yml start gateway

# 2. 在代理层恢复 /gateway/v1/* 映射
# 3. 重启代理
# 4. 验证
GATEWAY_SMOKE_URL=https://staging.example.test npm run smoke:staging
```

## 4. 回滚演练

### 4.1 自动演练

```bash
scripts/rollback-drill.sh --auto --verbose
```

### 4.2 手动演练

```bash
scripts/rollback-drill.sh --verbose
```

### 4.3 演练检查清单

- [ ] Gateway 当前健康
- [ ] 模拟 Gateway 故障（停止容器或移除代理路由）
- [ ] 旧 API 仍可用（/api/v1/*, /s/*, 管理, 支付）
- [ ] Gateway 不再接收流量
- [ ] 恢复 Gateway
- [ ] 最终验证（Gateway + 旧 API 均可用）
- [ ] 数据卷未被删除

## 5. 验收标准

### 5.1 功能验收

| 项目 | 标准 | 验证方式 |
|------|------|----------|
| Gateway 健康 | /healthz 返回 200 | curl |
| 只读冒烟 | bootstrap/plans 正常 | npm run smoke:staging |
| 旧 API 可用 | /api/v1/* 正常 | curl |
| 代理切换 | /gateway/v1/* 正确转发 | 浏览器/curl |
| 回滚 | 移除代理后旧 API 可用 | 演练脚本 |

### 5.2 安全验收

| 项目 | 标准 | 验证方式 |
|------|------|----------|
| Origin 校验 | 非白名单 Origin 被拒绝 | curl -H "Origin: https://evil.com" |
| 无敏感日志 | 日志不含密码/Token/email | 检查日志 |
| 容器隔离 | Gateway 无公网端口 | docker ps |
| 私钥安全 | HPKE 私钥不进入镜像 | docker history |

### 5.3 数据验收

| 项目 | 标准 | 验证方式 |
|------|------|----------|
| 数据卷保留 | 回滚后 Redis 数据卷存在 | docker volume ls |
| TXBoard 数据 | MySQL/Redis 数据未变 | 对比回滚前后 |
| 订单一致 | 订单数据与原生接口一致 | 对比测试 |

## 6. 故障场景与处理

### 6.1 Gateway 容器崩溃

```bash
# 查看日志
docker compose -f staging/docker-compose.staging.yml logs gateway

# 重启
docker compose -f staging/docker-compose.staging.yml restart gateway

# 如果持续崩溃，回滚到旧 API
# 参考 3.2 回滚流程
```

### 6.2 Redis 不可达

```bash
# 检查 Redis 容器
docker compose -f staging/docker-compose.staging.yml ps gateway-redis

# 如果 Redis 挂了，HPKE 登录会 fail-closed (503)
# 这是预期行为，不会回退到明文

# 恢复 Redis
docker compose -f staging/docker-compose.staging.yml restart gateway-redis
```

### 6.3 上游 TXBoard 不可达

```bash
# Gateway 会返回 502/503
# 检查上游
curl -sf http://txboard/api/v1/bootstrap

# 如果 TXBoard 挂了，回滚到旧 API 无意义（旧 API 也不可用）
# 需要恢复 TXBoard
```

### 6.4 代理配置错误

```bash
# 检查代理配置
nginx -t  # 或 caddy validate

# 回滚代理配置
# 恢复旧代理文件
```

## 7. 监控与告警

### 7.1 关键指标

- Gateway 请求率、错误率、延迟
- Redis 连接状态
- 上游 TXBoard 可用性

### 7.2 告警阈值

| 指标 | 阈值 | 动作 |
|------|------|------|
| Gateway 5xx 率 | > 5% | 检查日志，考虑回滚 |
| Gateway 延迟 p95 | > 2s | 检查上游 |
| Redis 连接失败 | > 0 | 检查 Redis 容器 |
| 上游不可用 | 持续 1 分钟 | 回滚到旧 API |

## 8. 相关文档

- [Staging README](./README.md)
- [Docker 部署](../docs/docker-deployment.md)
- [开发状态](../docs/development-status.md)
- [任务清单](../docs/task-backlog.md)
- [架构总览](../docs/architecture.md)
