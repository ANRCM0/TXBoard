# 管理登录隔离联调

验证[本地管理登录](../../../contracts/v2/core-internal-api/local-admin-login-v1.md)，使用 PHP8.2+、Node22、Python3.12+、OpenSSL 和锁定 vendor。仅合成独占 `txboard_admin_login_test`；非空 seed 拒绝，不 fresh/重置/删库。

```bash
DB_DATABASE=txboard_admin_login_test DB_HOST=127.0.0.1 DB_PORT=13306 \
DB_USERNAME=fixture-user DB_PASSWORD=fixture-password \
bash scripts/tests/internal-admin-login/run.sh /path/to/isolated/api
```

12项真实 Core/MariaDB或MySQL/TLS1.3 验证登录→当前身份、服务与User/staff隔离、guest禁止Authorization、密码/封禁、未知字段/合成范围、nonce重放、降权、CAPTCHA拒绝、审计失败隐藏已提交会话及撤销。原生10/min预算不修改；就绪请求只探测受保护身份，不消耗登录预算。PHP专项另测旧hash、密码空格、共享密码预算、请求绑定恢复、严格停用和生产门禁。

本地 `run-local-baseline.py` 只为专用实验室创建上述固定库与该库账号，不接触站点数据。loopback19081/19443须空闲；使用真实Admin/User SAN与服务proof。退出清理本次进程/凭据/私钥/配置，成功结果仅案例名、版本与计数；不得保留成功response含Bearer/路径。未开启 Admin Gateway、SC-v2 或生产登录。
