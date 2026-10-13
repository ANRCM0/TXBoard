# 管理身份隔离联调

只验证[本地管理身份读取](../../../contracts/v2/core-internal-api/local-admin-identity-v1.md)。使用固定源码、锁定 vendor、PHP8.2+、Node22、Python3.12+、OpenSSL 及独占合成 `txboard_admin_identity_test` 数据库；普通运行不 fresh、删库或重置既有数据，非空 seed 拒绝。

```bash
DB_DATABASE=txboard_admin_identity_test DB_HOST=127.0.0.1 DB_PORT=13306 \
DB_USERNAME=fixture-user DB_PASSWORD=fixture-password \
bash scripts/tests/internal-admin-identity/run.sh /path/to/isolated/api
```

`run-local-baseline.py` 仅为已存在的 TXBoard-Baseline 实验室创建固定新数据库及只对该库授权的用户，不是生产安装器。`127.0.0.1:19081/19443` 须空闲；TLS 固定真实 CA、Admin/User SAN、独立服务签名 key，沿用已审阅的 loopback boundary，不能以 caller 头声明服务角色。

本地修正复验另用固定 `txboard_admin_identity_retry_test`，保留首次失败的数据库；两个字面名称构成封闭 allowlist，非空库仍拒绝 seed，不隐式重置。

12项真实验证覆盖闭合 DTO/无缓存、服务/actor 交叉拒绝、staff/过期/Agent拒绝、路径/未知与重复字段、当前封禁/降权/路径轮换、直接无proof拒绝、SQL防重放、真实审计INSERT失败503及token撤销。结果只保留非秘密案例和计数；退出终止本次进程组并删除临时凭据、私钥和信任配置。PHP专项另测严格default-off、生产禁止、失效nonce存储与User行不变。

这不启动 Admin Gateway、SC-v2 或新管理前端，也不是生产 PKI、细粒度 RBAC 或全B5退出证据。
