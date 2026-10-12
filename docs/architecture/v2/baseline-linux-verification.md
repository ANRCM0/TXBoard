# B0 Linux 运行验证

> 2026-10-12；隔离本地验证。B0 的 HTTP/API/Linux 基线退出完成，后续生产切换、真实匿名化站点数据与密码协议审查仍按各阶段门禁执行。

## 来源与环境

使用 `git archive` 导出固定源码，避免后续 B1 工作区改动污染 B0 结果：Core `03ce4260f60a582ecb05ff57dad5835aed9ced9f`、Gateway `5eefb6317a6032f6f4ddc2a1e641fd9255335aea`、Node `52506a22b4dc6a7320ba149d86684e844cc77823`、Installer `303fd425d9eb0853d4abe9b79b1c26c42c9ac241`。Installer 已获取只读源码；无业务改动。

测试环境为 WSL2 / Alpine 3.22.6、Linux 6.6.87.2、PHP 8.4.16、Composer 2.10.3、Node 22.23.2 / npm 11.6.4、Go 1.26.3 linux/amd64、MariaDB 11.4.12、Redis 8.0.4。数据库和 Redis 是任务专用实例，不连接生产。Composer 安装锁定的 155 项依赖，未更新 lock；现有 composer.json 与 lock 的哈希差异警告保留，不以本轮依赖安装掩盖。

## 实测结果

| 范围 | 执行 | 结果 |
| --- | --- | --- |
| Core API 全量 | `php84 artisan test`，SQLite 内存、array cache、隔离 Redis | 520 项：518 passed、1 warning、1 skipped；4035 assertions |
| 订单真实并发 | `php84 artisan test --filter=OrderIdempotencyMysqlConcurrencyTest`，MariaDB + pcntl | 100 个独立连接并发重放，只产生 1 个订单；1 passed / 106 assertions；补跑上一行 skipped |
| HTTP registry | `php84 artisan route:list --json` + `export-route-catalog.mjs --check` | 287 路由声明展开为 288 HTTP method/path；250 条 TXAPI 声明；0 条 `/api/v1`、`/api/v2`；健康路由及 Admin middleware 门禁通过 |
| Gateway + SDK | `npm ci`；`npm run check` | 类型检查、Gateway 391/391、SDK 32/32、构建全部通过 |
| Gateway Redis | `npm run test:redis`，真实 Redis | 4/4，包括重放、TTL、共享限流、故障拒绝 |
| Node | `go test ./internal/controlplane ./internal/reporting` | 两包通过；重启重放测试保留文件/目录 fsync |
| Node Installer | 逐个 `bash tests/*_test.sh` | 5 套通过；Docker/systemd 边界由测试替身模拟，不是镜像部署 |
| Core 数据库 | 全 54 migrations；seed 大流量、nullable expiry、钱包 fixture；dump → 新库 restore → 再 dump | 42 张表；两份规范化 dump 的 SHA-256 都为 `b1cb866cd093989456fd5c08798e2d24df0649316ca43cc17dde3f7d99a890d9`；差异为空 |

Core warning 来自 PluginPackageTest 清理 fixture 时遗漏 `admin` 父目录。本次补上父目录清理并单独复跑该测试；这是测试夹具修复。Windows 两项失败的 Linux 复跑均通过，原始 Windows 记录不改写。

真实注册表保存为 [JSON](baseline-http-registry.json) 和 [可读清单](baseline-http-registry.md)。HTTP 注册表不证明 Workerman WSS、插件动态路由或反向代理已经运行。上述 MariaDB 数据为合成 fixture，不是生产历史数据；也不证明 Oracle MySQL 8、跨主机部署或财务迁移通过。

原始测试日志/JUnit、源码来源和备份比对保留在本机任务工具目录 `C:/Users/mn139/.codex/toolchains/txboard-linux/evidence/`。仓库只保存不含环境秘密的结果和路由清单。后续各阶段另记录其代码版本及实测结果。
