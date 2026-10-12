# TX-Node

TX-Node 是 TXBoard 体系中的独立 **Agent / Data Plane Runtime**，同时保留 Xboard 协议兼容。它负责 Control Plane 通信、Machine / Node 编排、Kernel 生命周期、用户与流量策略执行、运行状态上报和有限 typed ops；它不是第二个 Control Plane，也不是通用主机管理 Agent。

TX-Node 支持 `sing-box` / `xray-core` 双内核。安装、升级、回滚与主机部署生命周期继续由公开的 TX-Node-Installer 负责。

运行时职责收口规则见 [`docs/runtime-boundary.md`](docs/runtime-boundary.md)。

下一轮 Core First 演进参考 [主仓 B0 基线](https://github.com/ANRCM0/TXBoard/blob/main/docs/architecture/v2/README.md)、[Node Usage v2](https://github.com/ANRCM0/TXBoard/blob/main/contracts/v2/node-usage/README.md) 与 [Credential Provider v1](https://github.com/ANRCM0/TXBoard/blob/main/contracts/v2/credential-provider/README.md)（Target/Draft）。订阅计费身份、持久接收 ACK、授权租约与撤销版本尚未替代现行 v1；当前 202/queued 仅代表入队。

项目起源于 [cedar2025/Xboard-Node](https://github.com/cedar2025/Xboard-Node)。TX-Node 保留 Xboard 面板 API、认证字段和现有节点配置的协议兼容，但不再以周期性同步上游作为开发模式；上游后续修复会按需审查并选择性移植。独立维护策略见 [`docs/standalone.md`](docs/standalone.md)。

## Control Plane Provider 支持状态

普通节点与 Machine Mode 均通过 `panel.provider` 选择远程控制平面；省略该字段或设置为 `xboard` 时使用现有 Xboard 兼容协议。`txboard` 启用 TXBoard 原生 `/txapi/node/v1` HTTP/WSS 协议（Node 与 Machine Mode）；原生 WebSocket 默认由 TXBoard 服务端关闭，不影响 HTTP 轮询。上线前仍需跨仓库真实环境联调。Standalone 模式使用本地控制平面。

Provider 的构造错误处理、配置继承及原生协议验收边界见 [`docs/control-plane-providers.md`](docs/control-plane-providers.md)。

## 安装与部署

TX-Node 当前为**公开的运行时源码仓库**，负责源码、构建、测试与发布。安装、升级、多面板实例、隔离实例和主机运维脚本则统一由独立的公开安装器仓库维护：

**TX-Node Installer**  
https://github.com/ANRCM0/TX-Node-Installer

推荐安装入口：

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/ANRCM0/TX-Node-Installer/main/deploy.sh)
```

TXBoard 生成的非交互安装命令同样使用公开 Installer。运行时镜像仍由本仓库 CI 构建并发布到：

```text
ghcr.io/anrcm0/tx-node:latest
```

TX-Node 运行时仓库与 TX-Node-Installer（**两者均公开**）的职责边界：

- **TX-Node**：Go 运行时、ControlPlane、Machine、内核适配、审计客户端、配置模型、测试、Docker 镜像与二进制发布。
- **TX-Node-Installer**：安装/升级/卸载、Docker Compose、`txnode` 运维命令、多面板 `instances:`、隔离实例、配置备份与回滚。
- 运行时仓库不再维护 `deploy.sh` / `install.sh` 的副本，避免安装逻辑双轨演进。

开发者需要直接运行二进制时，可以参照 [config.yml.example](config.yml.example) 复制并填写凭据：

```bash
make build
cp config.yml.example config.yml
# 编辑 config.yml 中的 panel.url、token 和 node_id
./tx-node -c ./config.yml
```

常用开发/发布命令：

```bash
make test
make build
make build-all
make docker
```

## 镜像发布策略

TX-Node **只有开发版和稳定版两个发布渠道**，不再发布预览版、RC 或独立的 `:test` 镜像。

| 渠道 | 自动触发条件 | GHCR 镜像 | GitHub Release |
| --- | --- | --- | --- |
| **开发版** | 每次 Push/合并到 `main`，且 Go 测试与运行时稳定性测试通过 | `ghcr.io/anrcm0/tx-node:dev` + 每提交独立的 `:<完整SHA>` | 不创建 |
| **稳定版** | 推送严格匹配 `vX.Y.Z` 的 Git Tag，并通过全部测试 | `ghcr.io/anrcm0/tx-node:vX.Y.Z`、`:latest` + `:<完整SHA>` | 发布 Linux amd64/arm64 二进制与正式说明 |

- Pull Request、`dev` / `master` 分支普通 Push 只运行验证，不发布镜像；`main` 的**每次提交**都有独立的 SHA 构建，旧提交的慢速任务不会把 `:dev` 回退成旧版本。
- `:dev` 指向当前成功构建且仍是 `main` 最新提交的镜像；若最新提交正在构建或构建失败，`:dev` 仍指向上一次成功发布的开发镜像。
- 稳定版只接受 `v<主版本>.<次版本>.<补丁版本>`（例如 `v2.3.0`）。包含连字符的预览/RC Tag 会被校验拒绝，绝不会更新 `:latest`。
- 发布前先更新 `.github/release/VERSION` 与 `.github/release/NOTES.md`，确保内容和 Tag 完全一致。可直接将对应的稳定 Tag 推到 GitHub，或使用 [Publish semantic release](https://github.com/ANRCM0/TX-Node/actions/workflows/publish-release.yml) 从 `main` 创建该 Tag 并调度 Tag CI。
- 开发版不应部署到生产环境。安装器默认使用稳定渠道 `:latest`；更新 GHCR 标签不会自动更新正在运行的容器，实际升级需由 [TX-Node-Installer](https://github.com/ANRCM0/TX-Node-Installer) 执行。
- **发布不等于上线验收**：首次启用原生 TXBoard 模式前，仍需验证 Machine / Node 联调、WS、双内核及异步流量队列结算与持久化重放。

## 访问审计（可选 · 双面板）

**一个 sing-box Reporter、两个协议适配器。** 数据采集、规则匹配、限长队列和失败重试是共享实现；根据 `panel.provider` 选择审计规则/上报传输。默认关闭，不作为流量计费依据。

| Provider | 审计 API | 面板端要求 |
| --- | --- | --- |
| Xboard `xboard` | 保留 `/api/v1/plugin/access-audit/rules` 和 `/report`，原 Xboard 插件认证不变 | 需另外安装并启用兼容的 AccessAudit 服务端插件 |
| TXBoard `txboard` | 原生 `/txapi/node/v1/audit/rules` 与 `/audit/report`，Bearer + Node/Machine 身份请求头 | TXBoard 原生规则/日志管理模块及迁移表 |

TXBoard 后台入口：**节点管理 → 访问审计**，可增删编辑规则、启停规则并查询记录；默认的管理员操作审计日志是另一套功能。TXBoard 原生上报为每个连接事件生成可重试的稳定随机 ID，服务端按节点和事件 ID 去重。API 最多接受 200 条/批，1 MiB 请求体。Xboard 兼容 payload 不变。

示例（两个 Provider 都适用，前提是面板实现了对应审计接收接口）：

```yaml
audit:
  enabled: true
  report_all: false
  batch_max: 50
  flush_interval: 15
  rules_refresh: 5
  queue_cap: 5000
```

- **`report_all: false`（默认）**：只上报规则命中，0 条启用规则 = 0 条事件；`report_all: true` 会采集全部观察到的连接并显著提高隐私与性能成本。
- **仅 sing-box** 的内嵌连接采集已适配；Xray、Standalone 暂不具备同等嵌入式采集能力。
- 审计队列位于内存，拥塞/进程异常时可能丢失日志；与持久化的流量计费待确认批次完全隔离，不保证审计恰好一次或崩溃后重放。
- TXBoard 默认 30 天留存，依赖部署方持续运行 Laravel 调度器（`schedule:run`）。日志可含敏感访问目标和来源 IP，只有授权管理员应有查询权限。
- 上线需先部署原生 TXBoard API/数据表，更新节点镜像，再显式启用 `audit.enabled`。使用旧 TXBoard 版本时请保持关闭。

相关实现：[Reporter](internal/audit/reporter.go)、[双传输适配器](internal/audit/transport.go)、[可选能力](internal/controlplane/capabilities.go)、[原生协议契约](https://github.com/ANRCM0/TXBoard/blob/main/contracts/node-protocol/access-audit-v1.md)。

## Xboard 兼容与项目来源

TX-Node 保留 Xboard 面板协议及兼容配置字段。`xbctl` 和 `xboard-node` 二进制兼容产物已从 TX-Node v2 主线移除。历史 native/systemd 安装仍可由 TX-Node-Installer 识别、迁移和清理；新的 Docker 运维入口统一为 `deploy.sh` / `txnode`。

项目历史来源于 [cedar2025/Xboard-Node](https://github.com/cedar2025/Xboard-Node)。后续 TX-Node 版本独立维护和发布；上游修复仅按需审查、移植，不再整分支同步。详见 [`docs/standalone.md`](docs/standalone.md)。

## License

MPL-2.0。项目保留其历史来源及适用的上游版权与许可证声明。

> **Disclaimer**: This project is for educational and learning purposes only.
