# TXBoard

TXBoard 是一个模块化的网络服务 **Control Plane（控制面板）**，用于管理用户、订阅与套餐、订单与支付、节点与机器、流量、工单、主题和插件，并提供可选的 Agent Ops / MCP 运维接口。

- **前端**：React + TypeScript（管理后台）、Vue + TypeScript（用户端）。
- **后端**：Laravel 12 + Octane / Swoole，使用 MySQL、Redis 和 Horizon。
- **运行时**：单个 TXBoard 应用镜像包含前后端、Caddy、Redis、WebSocket 与默认关闭的 MCP Gateway；MySQL 与备份服务单独运行。
- **节点**：节点运行时由独立的 [TX-Node](https://github.com/ANRCM0/TX-Node) 提供，通过版本化 HTTP / WebSocket 契约与 TXBoard 通信。
- **扩展**：内置 Theme / Plugin Runtime、Module Platform v1；可选插件示例见 [TXBoard-AccessAudit](https://github.com/ANRCM0/TXBoard-AccessAudit)。

项目源代码、生产安装器与节点运行时分别维护：

| 仓库 | 用途 |
| --- | --- |
| **TXBoard**（本仓库） | 控制面源代码、前后端、镜像构建及协议契约 |
| [TXBoard-Deploy](https://github.com/ANRCM0/TXBoard-Deploy) | 面向用户的安装、升级和部署管理 |
| [TX-Node](https://github.com/ANRCM0/TX-Node) | 独立节点 Agent / Data Plane |

## 安装

**推荐使用 [TXBoard-Deploy](https://github.com/ANRCM0/TXBoard-Deploy)** 安装生产环境。服务器需要 Linux（amd64 / arm64）、Docker Engine 和 Docker Compose v2；安装器可以按提示协助安装 Docker。

```bash
curl -fsSL https://raw.githubusercontent.com/ANRCM0/TXBoard-Deploy/main/install.sh | sudo bash
```

按照提示设置域名或 HTTP 模式、管理员邮箱、MySQL、镜像标签及安装目录。安装器生成配置并拉取镜像，不需要在服务器上克隆本仓库，也不需要本地 PHP / Node.js 构建环境。

默认镜像：

```text
ghcr.io/anrcm0/txboard:latest
```

安装参数、外部 MySQL、反向代理、HTTPS、备份和管理命令详见 [TXBoard-Deploy 文档](https://github.com/ANRCM0/TXBoard-Deploy#readme)。

## 内置支付插件

TXBoard Core 目前保留 **EPay（易支付）** 和 **AlipayF2F（支付宝当面付）** 作为内置支付插件。BTCPay、Coinbase、CoinPayments 与 MGate 已从 `api/plugins-core/` 移除，不再默认安装或随 Core 镜像发布；未来按需通过独立插件包提供。

从旧版升级时，数据库迁移将禁用这四个旧支付插件及其支付渠道配置，**不会删除配置、历史订单或交易记录**。它们不会继续出现在用户可用支付方式中；在重新安装可信的独立插件包之前，无法通过后台重新启用这些渠道。

## 升级

生产部署使用独立更新器。升级前请确保已有可恢复的数据库、配置与上传文件备份；更新器默认先执行一次备份，再拉取目标镜像并检查应用健康状态。

```bash
curl -fsSL https://raw.githubusercontent.com/ANRCM0/TXBoard-Deploy/main/update.sh | sudo bash
```

需要指定已发布的镜像标签或更改安装目录时，参阅 [TXBoard-Deploy](https://github.com/ANRCM0/TXBoard-Deploy) 的更新参数说明。生产环境不通过容器内 `git pull`、`composer install` 更新应用代码。

## 开发

### 环境与目录

- `api/`：Laravel API、内置插件和主题。
- `web/admin/`：React 管理端；`web/user/`：Vue 用户端；`web/shared/`：共用代码。
- `mcp/`：可选 MCP Gateway。
- `contracts/`：HTTP、TX-Node、模块、插件和主题的版本化协议契约。
- `docs/architecture/`：架构说明与开发指南。

### 前端

在仓库根目录安装依赖并执行验证：

```bash
npm ci
npm run verify:web
```

本地单独启动：

```bash
npm run dev --workspace @txboard/admin
npm run dev --workspace @txboard/user
```

### 后端

需要 PHP、Composer 及对应数据库环境；先配置 `api/.env`：

```bash
cd api
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan test
```

### Docker 源码部署（开发 / 维护）

本仓库的 Compose 适用于源码构建与维护，不代替面向生产用户的 TXBoard-Deploy。按需填写环境变量后运行：

```bash
cp .env.example .env
cp api/.env.example api/.env
docker compose up -d --build --remove-orphans --wait
docker compose exec -it txboard php artisan txboard:install
```

验证入口：`npm run verify:web`、`composer test --working-dir=api`，或 `make verify`。镜像构建与运行时冒烟测试由 GitHub Actions 的 `txboard-image` 工作流执行。

## 文档与协议

- [架构与开发指南](docs/architecture/README.md)
- [跨组件协议契约](contracts/README.md)
- [插件开发指南](api/docs/en/development/plugin-development-guide.md)
- [MCP Gateway](mcp/README.md)
- [贡献与编码约束](AGENTS.md)

许可证与第三方来源说明见 [api/LICENSE](api/LICENSE) 和 [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)。
