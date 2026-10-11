# TXBoard

**面向订阅业务与节点管理的模块化 Control Plane。**

TXBoard 管理用户、套餐与订阅、订单与支付、节点/机器以及插件和主题；节点运行时由独立的 [TX-Node](https://github.com/ANRCM0/TX-Node) 负责。TXBoard 不是节点代理内核，也不把服务器任意命令执行能力交给 AI Agent。

[快速部署](#快速部署) · [文档中心](docs/README.md) · [架构说明](docs/architecture/README.md) · [接口契约](contracts/README.md) · [参与贡献](CONTRIBUTING.md)

> **项目状态**：仓库持续开发中。CI、镜像构建和单元测试不等于真实环境生产验收；特别是数据库迁移、支付、节点接入、Agent 操作和第三方扩展，请按 [上线验收清单](docs/operations/release-staging-acceptance.md) 独立验证。不要直接将开发标签用于生产。

## 核心能力

- **用户与订阅**：用户账户、套餐、订阅与流量管理。
- **订单与结算**：订单、充值、支付渠道及关联管理能力。
- **节点管理**：Node / Machine / Group / Route 与 TX-Node 控制平面协作。
- **管理后台与用户端**：独立的 Admin（React）和 User（Vue）应用。
- **扩展体系**：Plugin、Theme、Module Registry / Lifecycle，依据版本化契约运行。
- **Agent Ops / MCP**：受权限、目标范围、审批和审计约束的可选自动化接口。

功能是否在指定版本可用，应以真实运行时、[已注册的接口](contracts/README.md) 及对应发布说明为准；设计文档不等于已交付功能。

## 项目组成

```text
                         TXBoard（Control Plane）
           ┌────────────────────────────────────────────┐
           │ Admin / User UI · Laravel TXAPI · MySQL    │
           │ Module / Plugin / Theme · Agent Ops        │
           │ 可选内置 MCP 适配器 · Redis / WebSocket      │
           └───────────────────┬────────────────────────┘
                               │ 原生 / 兼容协议
                               ▼
                          TX-Node
                    Agent / Data Plane

  TXBoard-Deploy  → 安装、升级和运维 TXBoard 应用镜像
  TXBoard-Gateway → 另一个**可选的用户主题 API BFF**，不是 MCP Gateway
```

| 仓库 | 职责 |
| --- | --- |
| [TXBoard](https://github.com/ANRCM0/TXBoard) | 主应用、控制平面、Admin/User UI 与接口契约 |
| [TXBoard-Deploy](https://github.com/ANRCM0/TXBoard-Deploy) | 公开安装器、升级、备份及服务器运维 |
| [TX-Node](https://github.com/ANRCM0/TX-Node) | Go 节点运行时、代理内核与流量上报 |
| [TXBoard-Gateway](https://github.com/ANRCM0/TXBoard-Gateway) | 可选的用户 API Gateway / Theme SDK；独立于内置 MCP |
| [TXBoard-AccessAudit](https://github.com/ANRCM0/TXBoard-AccessAudit) | 独立审计扩展及兼容集成 |

[进一步了解系统边界 →](docs/architecture/README.md)

## 快速部署

推荐使用独立的 [TXBoard-Deploy 官方部署工具](https://github.com/ANRCM0/TXBoard-Deploy)，而不是从主仓库直接拼接生产 Compose。

**前提**：Linux amd64/arm64、可访问 GitHub/GHCR，具有服务器管理权限。安装器依赖 Docker Engine 与 Docker Compose v2，并可在受支持的交互环境中协助安装 Docker。

```bash
# 建议先打开并审阅官方安装脚本，再在可信主机上执行
curl -fL https://raw.githubusercontent.com/ANRCM0/TXBoard-Deploy/main/install.sh -o install.sh
less install.sh
sudo bash install.sh
```

安装过程按向导配置访问方式、数据库模式及管理员账户。生产使用固定的已验证版本标签或镜像 digest；`latest` 不代表 `main` 的最新开发提交。

- [部署前准备与安装方式](docs/getting-started/installation.md)
- [首次配置检查表](docs/getting-started/first-setup.md)
- [完整部署命令及 XBoard 数据迁移说明](https://github.com/ANRCM0/TXBoard-Deploy#readme)
- [备份、恢复、更新与发布验收](docs/operations/README.md)

**已有 XBoard / TXBoard 用户**：不要将全新安装流程直接指向现有生产数据库。TXBoard 原生 `tx_*` Schema 不会自动转换旧表；先备份并在隔离环境验证迁移。参见 [Schema 边界](docs/operations/native-schema.md)。

## 技术与开发

| 层次 | 当前实现 |
| --- | --- |
| 后端 | PHP 8.2、Laravel 12、Octane / Swoole |
| 管理端 | React、TypeScript、Vite |
| 用户端 | Vue 3、TypeScript、Vite |
| 数据及进程 | MySQL、Redis、Horizon、WebSocket |
| 可选 Agent 接口 | 内置 MCP 协议适配器，委托 Agent Ops API |

开发者从 [本地开发说明](docs/development/local-development.md)、[CONTRIBUTING.md](CONTRIBUTING.md) 与 [AGENTS.md](AGENTS.md) 开始。公开 HTTP/Node/Module 交互以 [contracts/](contracts/README.md) 及当前服务端实现为准。

## 文档入口

- [开始使用](docs/getting-started/introduction.md) · [后台与节点管理](docs/guides/README.md)
- [系统架构](docs/architecture/README.md) · [开发扩展](docs/development/README.md)
- [部署运维](docs/operations/README.md) · [安全基线](docs/security/README.md)
- [跨仓接口和版本化协议](contracts/README.md) · [文档维护规范](docs/DOCUMENTATION_GUIDE.md)

## 安全与许可

- 管理员、用户、Node 和 Agent 分别使用独立鉴权与权限约束。不要将安全路径 `secure_path` 当成身份认证。
- PHP 插件是可信的进程内代码，**不是安全沙箱**；安装第三方包前应核验来源。
- 不要把 Token、数据库备份、支付密钥或包含敏感信息的日志提交到公开仓库。
- 安全约束详见 [安全基线](docs/security/README.md)。

**授权说明**：后端 `api/composer.json` 声明 MIT；整个仓库的统一 LICENSE 和第三方来源/版权清单仍需完成核对。在核实之前，不应推断所有子项目或历史来源均自动适用同一许可证。