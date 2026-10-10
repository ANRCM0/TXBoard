# TXBoard 发布前真实环境验收 Runbook

> **状态：需要操作员执行，CI 不能代替。** 适用 TXBoard 自有 Compose，或 1Panel + 外部 MySQL 8.4 / Redis + 反代（OpenResty/Caddy）部署。只在隔离预发环境操作支付、Token 轮换、恢复和故障注入。没有真实验收证据时不得宣称已通过生产发布验收。

## 0. 验收前准备与红线

1. 准备独立预发域名、HTTPS、隔离的 MySQL 库、Redis、测试用管理员/普通用户、受控的测试套餐、礼品卡、测试插件和测试节点。不要连接真实付款方生产商户；优先商户 sandbox。公开互联网访问须限制管理员来源。
2. 记录 Git 提交 SHA、**镜像 digest**（不可只记 `latest`）、PHP/MySQL/Redis 版本、OpenResty/1Panel 版本、应用时区及数据库时区；保存脱敏的路由清单和环境配置差异。检查 `APP_ENV=production`、`APP_DEBUG=false`、`APP_URL`、真实 TLS、Redis 连接与受信代理 CIDR。
3. 正式回归前停止或隔离定时 Worker/Horizon、结算消息和外部支付回调，确保备份时没有跨库/文件系统的并发修改。**禁止在真实生产账户上测试重复付款、删除、财务故障注入、Token 轮换或数据库恢复。**
4. 确认至少有一份之前已验证可以恢复的离线/异机备份。CI 的合成数据备份不包含你的历史交易数据，不能作为这一项的证据。

## 1. 完整备份：数据库 + APP_KEY + 用户上传 + 主题 + 插件

项目备份脚本是根目录 `backup.sh`；它会通过 MySQL 8.4 的 `mysqldump --single-transaction` 导出所有表，并打包 `.env`（含 APP_KEY）、`storage/app`、`storage/theme`（若存在）和 `plugins`（若存在）。缺少 APP_KEY 或打包失败会报错并移除不完整目录；成功后生成 `MANIFEST` 与 `CHECKSUMS.sha256`。

**自带 Compose**：在保存有 `compose.yaml` 和真实挂载目录的主机上执行：

```bash
# 一次性运行，禁用定时等待；不要把备份目录提交 Git
TXBOARD_BACKUP_INTERVAL=0 docker compose run --rm -e BACKUP_INTERVAL=0 backup
```

**1Panel 外部数据库**：切勿直接套用自带 Compose（它会创建另一套 MySQL/Redis）。使用 1Panel 提供的应用编排或临时的 MySQL 8.4 客户端容器，在与外部数据库相同的 Docker Network 内运行备份脚本；用 1Panel 的私密环境变量/临时 `--env-file` 提供 `DB_HOST`、`DB_PORT`、`DB_DATABASE`、`DB_USERNAME`、`DB_PASSWORD`，并挂载**运行中真实使用**的 `api/.env`、`storage` 和 `plugins`：

```bash
# 仅示例：在隔离的备份维护窗口，使用已受限制的环境变量文件。
# 将 <network>、路径和凭据文件替换为真实配置；不在命令行明文传密码。
docker run --rm --network <network> --env-file ./backup-private.env \
  -e BACKUP_INTERVAL=0 -e BACKUP_SOURCE_DIR=/backup-source/api \
  -e BACKUP_DIR=/backups \
  -v "$PWD/backup.sh:/usr/local/bin/txboard-backup.sh:ro" \
  -v "$PWD/api:/backup-source/api:ro" \
  -v "$PWD/backups:/backups" \
  --entrypoint /bin/sh mysql:8.4.11 /usr/local/bin/txboard-backup.sh
```

私密环境文件须仅管理员可读（`chmod 600`），使用后安全处理；备份里有 APP_KEY、密码密文和用户数据，必须加密存储并限制下载。**不要把 SQL dump、env、Secret、Token 或完整日志上传到 GitHub Actions Artifact/Issue。**

选择最新备份目录 `<archive-dir>`：

```bash
cd <archive-dir>
sha256sum -c CHECKSUMS.sha256
gzip -t db.sql.gz
tar -tzf storage-app.tar.gz >/dev/null   # 如存在
tar -tzf storage-theme.tar.gz >/dev/null # 如存在
tar -tzf plugins.tar.gz >/dev/null       # 如存在
```

通过标准：所有预期文件存在、校验全部 PASS、APP_KEY 与当前实例相符、上传/主题/插件标记文件可读取，并保留异地副本。不满足则**暂停升级**。

## 2. 先在独立数据库执行真实恢复、再试迁移

1. 暂停预发 Worker 和写操作；创建**全新的空 MySQL 数据库** `txboard_restore_check`，不要覆盖现有或生产数据库。使用权限受限但可导入的 MySQL 账号，从已验证压缩包恢复：
   ```bash
   set -o pipefail
   gzip -dc <archive-dir>/db.sql.gz | mysql -h <db-host> -u <restore-user> -p txboard_restore_check
   ```
   这条命令会提示输入密码；勿将密码写入 shell history。目标数据库须预先创建。
2. 将 `env` 恢复为**该隔离实例**的 `.env`，仅将数据库地址、Redis、域名等改成隔离目标；**APP_KEY 保持原样**，不可在恢复后重新执行 `key:generate`。在独立挂载目录解压 `storage-app.tar.gz` → `storage/app`、`storage-theme.tar.gz` → `storage/theme`、`plugins.tar.gz` → `plugins`，核验文件所有权与受控插件包。绝不覆盖正在运行的真实目录。
3. 使用本次待发布的**不可变镜像 digest**启动隔离实例并执行：
   ```bash
   php artisan migrate:status
   php artisan migrate --force --no-interaction
   php artisan migrate:status
   php scripts/p0-schema-inventory.php --output=artifacts/staging-schema.json
   php scripts/p3-billing-audit.php --output=artifacts/staging-billing.json
   ```
   命令在容器的 `/www` 应用目录执行。不得用 `migrate:fresh`、`db:wipe` 或对生产库执行 `migrate:rollback` 来“验证恢复”。
4. 再执行一次 `migrate --force`，应无待应用迁移且不会改变账户余额/订单数据。对照恢复前的**脱敏聚合数据**：用户数、订单数、订单状态与金额分布、钱包/返佣余额总和、充值流水数、流量结算批次数、礼品卡历史数、插件/主题文件数及配置关键值。
5. 若发现数据差异、钱包被重复入账、关键索引缺失或迁移失败：**停止新版本接入、保留故障镜像与日志，恢复旧镜像及匹配的完整旧备份（含 APP_KEY、上传、主题和插件）**，不要只切换旧代码继续使用已经被新迁移修改的数据库。

通过标准：完整恢复可启动、无迁移错误、所有资金和流量不变量通过、重要数据计数/金额与备份时快照一致、文件与主题插件可读取。记录耗时及 RTO/RPO 实测，不能拿 CI 合成恢复时间充当生产 RTO。

## 3. 真实 HTTP + 管理端登录

```bash
bash scripts/staging-readonly-smoke.sh https://<staging-domain>
```

脚本仅执行**不带凭据的 GET**：`/api/health`、`/txapi/health`、`/txapi/public/site-config`、错误的管理员安全路径拒绝访问，以及已退役 V2 管理入口不得成功。

再用浏览器人工测试：管理员登录成功并跳转正确动态安全路径；错误密码、普通用户、过期/禁用账号被拒绝；开启 Turnstile/hCaptcha/Recaptcha 时必须通过真实验证码；验证码服务不可用时页面拒绝提交；管理员退出后旧 Token 不可复用；普通用户不应读到管理员信息。查看响应与应用/反代日志，确保未记录密码、验证码、APP_KEY、Bearer、订阅密钥。

## 4. 资金与业务 E2E（只用测试商户/测试用户）

按顺序实测并保留脱敏前后快照：

| 操作 | 通过标准 |
|---|---|
| 注册/登录 → 套餐 → 订单创建 | 数据库金额单位为分、订单初始 pending、未支付不授予套餐 |
| 付款方 sandbox 成功通知 | 签名与金额严格相符，只结算一次；订单及账户权益正确 |
| 对同一合法通知再发送 2 次 | 不新增订单履约、钱包入账或佣金流水 |
| 伪造签名、错误金额、错误订单号 | 拒绝，账户余额/佣金/套餐不变 |
| 钱包充值 pending → sandbox 通知 | 只有成功且匹配的通知入账一次，流水保留 |
| 优惠券与礼品卡创建/发放/兑换 | 适用限制有效，同一礼品码双并发兑换只能成功一次 |
| 工单/邮件通知、配置/Telegram webhook | 真实送达和错误返回符合预期；日志脱敏 |
| 看板与排行榜 | 金额、佣金、流量与 SQL 聚合一致；验证时区跨日/月边界与长周期查询 |

**支付回调不能手工向真实商户“模拟成功”。** 应使用供应商沙盒或完整独立的测试商户；如供应商没有沙盒，该项标记为阻塞并单独做受控小额验收及财务审计，不能伪称 CI 已完成。

## 5. Node / Agent / Machine、主题与插件

- **Node/Machine**：安装器连至预发地址 → 鉴权 → 下发配置 → WebSocket 上线 → 流量上报两次使用同一 batch ID → 只结算一次 → 重绑节点 → 更新运行时并能回滚；管理员轮换机器 Token 后旧 WebSocket 会话须按新安全约定失效。如果独立 TX-Node 尚未适配此版本 TXBoard 合同，将此项标记为 BLOCKED，不得算通过。
- **Agent**：管理员签发最小权限 Token → 限定节点作用域 → 审批一项测试运维操作 → 核对执行/拒绝/审计 → 撤销 Token → 新请求拒绝；验证未授权节点无法执行、并发审批与重放不会二次执行。客服 Agent 回复需人工核对送达与敏感字段脱敏。
- **插件/主题**：安装可信 ZIP → 启用并访问静态资源 → 升级 → 禁用 → 从完整备份恢复旧版本。检验恶意路径穿越、超大 ZIP、重复包被拒绝。若插件升级有不可逆 SQL 迁移，必须用**隔离实例完整 DB + 文件恢复**证明回滚；不能仅回滚 ZIP。
- **运行时**：1Panel 代理信任 CIDR 与 TLS、WebSocket 透传、Redis/Horizon 队列失败处理、计划任务、Octane 进程重启及回滚。进行一段稳定的测试期，记录延迟（p50/p95/p99）、MySQL 慢查询、锁等待、队列积压、CPU/内存，和原环境相同数据规模比较；没有测量则不填写“性能提升”。

## 6. 验收证据与 Go/No-Go

每个步骤填写 `PASS / FAIL / BLOCKED / NOT_RUN`；至少记录：

| 字段 | 需要的证据 |
|---|---|
| 版本 | Git SHA、镜像 digest、部署时间、镜像标签 |
| 升级恢复 | 迁移状态、校验结果、脱敏前后聚合比对、RTO/RPO |
| 资金与流量 | 测试交易匿名 ID、订单/充值/佣金/流量前后总量，重放次数 |
| 身份与运维 | 权限拒绝截图（脱敏）、Token 撤销/Node 重连证明 |
| 运行时 | Caddy/OpenResty、Redis/Horizon、MySQL、真实 CPU/内存/延迟 |
| 回滚 | 旧镜像 digest、对应备份时间点、已实际验证的恢复步骤 |
| 责任人 | 执行人、复核人、日期、未通过缺陷 Issue |

**Go 规则：** 不得存在 P0/P1 安全/财务/数据丢失缺陷；所有涉及真实环境的发布阻塞项必须有 PASS 证据或明确的产品范围调整。未验证支付商户、机器 Token 失效、真实备份恢复或生产数据对账时，维持 **NO-GO**。Release/Tag 不得因 CI 合成测试全部绿灯而自动授权。

## 日常运行监控与故障判定

- 应用探针 `GET /txapi/health`、`GET /api/health` 只验证基本存活，不能代替 MySQL、Redis/Horizon、支付商、TXNode 的端到端健康检查。
- 观察 API p95、5xx、MySQL 死锁和慢查询、失败任务与队列 backlog、Node/Machine 连接、Agent 审批、支付/钱包/佣金对账；结合 `traffic_queue_unavailable`、`traffic_queue_backlog_high` 告警。
- 可用运行时中执行 `php artisan traffic:health --json`，检查持久流量批次 ledger 与队列；HTTP 202 或 WS `traffic.ack` 的 `queued` 是接收确认，不代表 SQL 最终结算。
- 同 `traffic_batch_id` 重试不能重复计费，同 ID 但不同计数必须拒绝；故障后以持久账本核对，并确认失败任务可恢复。回退镜像不会自动撤销 DDL 或资金交易，必须按备份与对账计划处理。
- 按 [镜像发布通道](image-release-channels.md) 使用不可变 digest，数据库表名切换必须依照 [独立切换 Runbook](native-mysql-table-cutover.md) 在维护窗口执行。
