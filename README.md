# TXBoard

**TXBoard** 是一套可自托管的模块化网络服务管理平台，提供用户与订阅管理、套餐销售、订单支付、节点控制、流量统计及运维管理能力。项目采用控制面与节点运行时分离的架构，并提供面向第三方主题、插件和外部服务的接口与扩展机制。

## 核心功能

- **用户与订阅**：账号注册和登录、订阅套餐、流量与有效期管理、订阅链接及节点列表。
- **套餐与交易**：套餐定价、订单结算、支付渠道、钱包充值、优惠券、礼品卡和返佣管理。
- **节点与机器**：节点配置、分组与路由、机器管理、在线状态、流量上报和统计。
- **服务与内容**：工单、公告、知识库、通知和用户自助服务。
- **管理后台**：用户、套餐、交易、网络资源、站点设置、扩展及审计的统一管理。
- **主题与插件**：主题包、插件包、模块注册与生命周期管理，支持独立维护的第三方扩展。
- **运维集成**：提供 Agent Ops / MCP 接口，支持经授权的状态查询、诊断和审批式运维操作。

## 技术架构

| 层级 | 技术与职责 |
| --- | --- |
| 用户前台 | Vue、TypeScript |
| 管理后台 | React、TypeScript |
| 核心服务 | Laravel 12、PHP、Octane / Swoole |
| 数据与任务 | MySQL、Redis、Laravel Horizon |
| 实时通信 | WebSocket / Workerman |
| 对外 API | TXAPI（`/txapi/*`） |
| 节点运行时 | 独立的 TX-Node，通过 HTTPS / WebSocket 与 TXBoard 通信 |
| 可选运维组件 | Agent Ops / MCP Gateway |

TXBoard 负责用户、订阅、订单、支付、配置与审计等**控制面**业务；TX-Node 作为独立的**节点运行时**处理节点侧工作。两者通过版本化协议通信，避免将节点运行时与管理面板耦合在一起。

### 项目生态

| 项目 | 说明 |
| --- | --- |
| **TXBoard**（当前仓库） | 核心 API、管理后台、用户前台、扩展运行时及协议契约 |
| [TXBoard-Deploy](https://github.com/ANRCM0/TXBoard-Deploy) | 安装、升级与部署管理 |
| [TX-Node](https://github.com/ANRCM0/TX-Node) | 独立节点 Agent / Data Plane |
| [TXBoard-Gateway](https://github.com/ANRCM0/TXBoard-Gateway) | 可选的独立 API 中间件 / BFF 组件 |

## 安装与部署

推荐通过 [TXBoard-Deploy](https://github.com/ANRCM0/TXBoard-Deploy) 部署。服务器使用 Linux（`amd64` 或 `arm64`）、Docker Engine 与 Docker Compose v2；数据库采用 MySQL，应用使用 Redis 支撑缓存与后台任务。

安装命令：

```bash
curl -fsSL https://raw.githubusercontent.com/ANRCM0/TXBoard-Deploy/main/install.sh | sudo bash
```

安装器负责引导配置与容器部署。执行前建议先审阅安装脚本，并根据部署环境准备域名、HTTPS、数据库、持久化目录和备份策略。

正式发布镜像：

```text
ghcr.io/anrcm0/txboard:latest
```

需要固定版本、选择发布通道或配置外部 MySQL/Redis 时，请参考 [部署项目说明](https://github.com/ANRCM0/TXBoard-Deploy) 与 [镜像发布策略](docs/operations/image-release-channels.md)。

### 升级

使用独立部署工具的升级入口：

```bash
curl -fsSL https://raw.githubusercontent.com/ANRCM0/TXBoard-Deploy/main/update.sh | sudo bash
```

升级前应备份并验证可恢复的数据库、应用配置、上传文件、主题和插件。涉及数据库表结构切换的操作须遵循单独的 [数据库切换手册](docs/operations/native-mysql-table-cutover.md)，不能将更新镜像视为自动执行数据迁移的授权。

## API 与扩展

TXBoard 对外业务 API 使用 `/txapi/*`，节点接口使用 `/txapi/node/v1/*`。管理员、普通用户、节点、机器和 Agent 使用不同的鉴权边界；外部集成应按对应协议申请与使用凭据。

- [外部主题 TXAPI 对接手册](contracts/http/theme-integration-current.md)：注册登录、用户、订阅、订单、充值、工单和请求示例。
- [TX-Node HTTP / WebSocket 协议](contracts/node-protocol/txnode-integration-current.md)：鉴权、握手、配置同步、机器状态与幂等流量上报。
- [插件开发指南](api/docs/en/development/plugin-development-guide.md) 与 [扩展契约](contracts/README.md)：主题、插件及模块包规范。
- [对外路由与权限总览](contracts/http/external-adapter-current.md)：API 命名空间、回调及认证边界。

## 文档

- [文档中心](docs/README.md)
- [模块系统架构](docs/architecture/module-platform-v1.md) 与 [Agent Ops / MCP 架构](docs/architecture/agent-ops.md)
- [发布与验收操作手册](docs/operations/release-staging-acceptance.md)
- [安全基线](docs/security/README.md)
- [代码贡献规范](AGENTS.md)
