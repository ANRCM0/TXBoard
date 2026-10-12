# Internal 只读服务身份联调

这是 Linux 本机 testing fixture，验证 [Current slice](../../../contracts/v2/core-internal-api/current-readonly-slice-v1.md)。只使用 Node 22、PHP 8.2+、项目锁定 vendor、OpenSSL、Python 3.12+ 和一个独占空 MariaDB/MySQL 测试数据库。它不安装生产服务，不修改 Gateway，不进入发布门禁。

先创建 committed Core snapshot 并只叠加本切片，目标目录必须不存在：

```bash
python3 scripts/tests/internal-readonly/prepare-snapshot.py /path/to/TXBoard /tmp/internal-core-snapshot /path/to/existing/vendor
cd /tmp/internal-core-snapshot/api
php vendor/bin/phpunit tests/Feature/InternalReadOnly --no-progress
```

snapshot 的 internal-snapshot.json 保存 HEAD 和每个覆盖文件的 SHA-256。vendor 复制到独立 application，使 Composer 相对 classmap 指向该 snapshot。snapshot 不包含其他人的未提交变更；routes 只插入本 slice group。

prepare-snapshot.py 可附第四个参数指定已提交的完整 SHA，便于在其他阶段已提交后仍精确重现本轮 Core 基线；缺省为 committed HEAD。

准备空的 `txboard_internal_test` 数据库和只对该库有权限的账号，指定凭据通过进程环境：

```bash
DB_DATABASE=txboard_internal_test DB_HOST=127.0.0.1 DB_PORT=13306 \
DB_USERNAME=fixture-user DB_PASSWORD=fixture-password \
bash /tmp/internal-core-snapshot/scripts/tests/internal-readonly/run.sh /tmp/internal-core-snapshot/api
```

独占占用 `127.0.0.1:19081`（PHP）和 `127.0.0.1:19443`（Node TLS）。run.sh 拒绝其他数据库名，migrate 不使用 fresh；seed 拒绝存在用户的数据库，不自动删除/重置既有数据。多 worker PHP 服务用于真实并发 nonce 竞争；重启整个进程组验证 durable replay。

已有专用 TXBoard-Baseline 实验环境可用 `run-local-baseline.py /opt/txboard-baseline/internal-<snapshot>`。它仅通过固定实验室 MariaDB socket 创建上述测试库/fixture 用户并授予该库权限，不删除表或账号，不处理生产环境。

明确重跑可单独执行 `reset-local-fixture.py`：它先精确核对本 fixture 的三个用户邮箱及唯一订单号，只清空固定 `txboard_internal_test` 数据库。这是实验管理员的显式隔离库重置，区别于正常 nonce retention；正常运行不会删除未过期 nonce。非此 fixture 数据会拒绝重置。

generate-pki.py 创建真实 CA、服务端、User/Admin 客户端、未知 CA、错误/多 SAN、过期、CRL 已吊销和短有效期证书；proof 使用另外三把 RSA key 验证角色与 epoch 轮换。短证书约 60 秒有效，测试保持同一 TLS 连接至到期，再确认拒绝；keepalive fingerprint 撤销也验证连接相同。客户端没有关闭服务器 hostname/CA 验证。

integration.mjs 检查三个正向只读操作、历史订单 status4、owner/admin-path/服务与 actor 交叉拒绝、真实 Sanctum expired/revoked/banned/admin-demoted、header spoof、proof strict/binding/clock/key/epoch、8 个并发同 nonce 和 Core 重启 replay、数据库不可用等。sqlite tests 另外覆盖严格 duplicate key、字段/大小/deadline、rate/retention、生产 default-off。

`node scripts/tests/internal-readonly/timeout-boundary.mjs /path/to/isolated/api` 另用真实 mTLS 和 deliberately slow loopback upstream（19082/19444）验证连续小 chunk、不发送 end、超大响应、错误及客户端断开。连续数据不能延长固定 5 秒 upstream walltime；测试检查 upstream 已被关闭并在最后确认正常响应仍通过。Core 同步 deadline 只在 handler 返回时检查，不声称取消 SQL。边界信任配置在每个 request 开始读取，Core 在验签时读取当前 trust，已开始的请求没有跨进程即时中断保证。

测试输出只含固定 case 名，不显示 actor token、body、proof、证书或私钥。结束时终止 loopback 服务，删除临时 key、credentials、trusted config 和 CA 状态，保留公开 fingerprint、route catalogs、sanitized logs/result.json。未达成的测试不会生成成功 result。数据库保留为隔离证据；重跑需要使用实验室管理员明确清理此专用库后重新准备，脚本不会自动 destructive reset。

当前本机证据不是生产 B5 验收：数据库实际 MariaDB 11.4，MySQL 8.4、生产 PKI/网络、Redis/集群、写入操作及 Gateway 接入仍需独立阶段。
