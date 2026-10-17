# TX Core（原 TXBoard 主仓）

**面向订阅业务与节点管理的模块化 Control Plane。**

本仓集中维护 TXBoard Core、[Gateway 与 Theme SDK](gateway/README.md)、[TX-Node](node/README.md)，后续仓库名为 `tx-core`。TXBoard Core 管理用户、套餐与订阅、订单与支付、节点/机器以及插件和主题；TX-Node 独立运行节点代理内核。三个组件分别构建、发布镜像，业务与凭据边界保持独立。

[快速部署](#快速部署) · [文档中心](docs/README.md) · [架构说明](docs/architecture/README.md) · [接口契约](contracts/README.md) · [参与贡献](CONTRIBUTING.md)

> **项目状态**：仓库持续开发中。CI、镜像构建和单元测试不等于真实环境生产验收；特别是数据库迁移、支付、节点接入和第三方扩展，请按 [上线验收清单](docs/operations/release-staging-acceptance.md) 独立验证。不要直接将开发标签用于生产。

## 当前实现

当前主线已包含原生订阅/用量、CNY 支付结算、钱包与退款、升级折抵、统一插件框架及正式服务接口子集。退款仅到钱包，不反转权益或履约 work；不兼容旧插件。具体支持范围与未验收边界统一见[当前实现基线](docs/architecture/v2/current-progress.md)，不以合并或 CI 通过宣称完整内核成熟、生产可用或已发布。

项目尚未接入正式测试部署；只维护当前 native 契约及真实消费者，不承担旧版本过渡兼容。新增前端延期至 TX-F，安装器由 TXBoard-Deploy 维护。开发与数据安全规则见[Native 开发基线](docs/development/native-development-baseline.md)。

## 核心能力

- **用户与订阅**：用户账户、套餐、订阅与流量管理。
- **订单与结算**：订单、充值、支付渠道及关联管理能力。
- **节点管理**：Node / Machine / Group / Route 与 TX-Node 控制平面协作。
- **既有管理后台与用户端**：保留当前Admin（React）和User（Vue）；新前端开发延期，后续统一由独立TX-F承接。
- **扩展体系**：Plugin、Theme、Module Registry / Lifecycle，依据版本化契约运行。

功能是否在指定版本可用，应以真实运行时、[已注册的接口](contracts/README.md) 及对应发布说明为准；设计文档不等于已交付功能。

## 项目组成

```text
                         TXBoard（Control Plane）
           ┌────────────────────────────────────────────┐
           │ Admin / User UI · Laravel TXAPI · MySQL    │
           │ Module / Plugin / Theme        │
           │ Redis / WebSocket      │
           └───────────────────┬────────────────────────┘
                               │ 当前版本节点协议
                               ▼
                          TX-Node
                    Agent / Data Plane

  TXBoard-Deploy  → 统一安装、升级和运维 Core / Gateway / Node
  gateway/       → 可选的用户主题 API BFF
```

| 位置 | 职责 |
| --- | --- |
| 本仓根目录 `api/`、`web/` | Core、Admin/User UI 与权威接口契约 |
| [gateway/](gateway/README.md) | 可选用户 API Gateway / Theme SDK；独立依赖和 Docker 上下文 |
| [node/](node/README.md) | Go 节点运行时、代理内核与流量上报；独立 Go module |
| [TXBoard-Deploy](https://github.com/ANRCM0/TXBoard-Deploy) | 三组件唯一安装器、升级、备份及服务器运维 |
| [TXBoard-AccessAudit](https://github.com/ANRCM0/TXBoard-AccessAudit) | 独立审计扩展及兼容集成 |

[进一步了解系统边界 →](docs/architecture/README.md)

目录、历史来源、镜像标签与 CI 规则见 [双仓布局与发布](docs/development/repository-layout.md)。旧独立仓保留为历史来源，后续代码修改与发布在本仓进行。

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

开发者从 [本地开发说明](docs/development/local-development.md)、[CONTRIBUTING.md](CONTRIBUTING.md) 与 [AGENTS.md](AGENTS.md) 开始。公开 HTTP/Node/Module 交互以 [contracts/](contracts/README.md) 及当前服务端实现为准。

## 文档入口

- [开始使用](docs/getting-started/introduction.md) · [后台与节点管理](docs/guides/README.md)
- [系统架构](docs/architecture/README.md) · [开发扩展](docs/development/README.md)
- [部署运维](docs/operations/README.md) · [安全基线](docs/security/README.md)
- [跨仓接口和版本化协议](contracts/README.md) · [文档维护规范](docs/DOCUMENTATION_GUIDE.md)

## 安全与许可

- 管理员、用户、Node/Machine 和服务客户端分别使用独立鉴权与权限约束，旧 Agent 凭据拒绝。不要将安全路径 `secure_path` 当成身份认证。
- PHP 插件是可信的进程内代码，**不是安全沙箱**；安装第三方包前应核验来源。
- 不要把 Token、数据库备份、支付密钥或包含敏感信息的日志提交到公开仓库。
- 安全约束详见 [安全基线](docs/security/README.md)。

**授权说明**：后端 `api/composer.json` 声明 MIT；整个仓库的统一 LICENSE 和第三方来源/版权清单仍需完成核对。在核实之前，不应推断所有子项目或历史来源均自动适用同一许可证。
