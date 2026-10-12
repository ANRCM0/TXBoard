# TXBoard 镜像发布通道（CURRENT）

运行时统一在本仓发布，支持 `linux/amd64` / `linux/arm64`：Core 为 `ghcr.io/anrcm0/txboard-core`（保留 `txboard` 兼容别名），Gateway 为 `ghcr.io/anrcm0/txboard-gateway`，Node 为新包 `ghcr.io/anrcm0/txboard-node`。Node 旧 `ghcr.io/anrcm0/tx-node` 包不迁移、转绑或覆盖；CLI 和二进制名仍为 `tx-node`。目录和历史来源见 [双仓布局](../development/repository-layout.md)。

| 场景 | Git 触发条件 | 推送的 GHCR 标签 | 是否覆盖正式版 |
| --- | --- | --- | --- |
| 开发版 | `main` 中应用、构建或发布脚本变更 | `dev`, `dev-sha-<12 位提交 SHA>` | 否 |
| 预览版 | 推送 `v1.2.3-rc.1`（也接受 `beta.N`、`preview.N`） | `preview`, `v1.2.3-rc.1` | 否 |
| 正式版 | 推送 `v1.2.3` | `latest`, `v1.2.3` | 是（更新 latest） |
| Pull Request | 向 main 发起 PR | 无，仅测试 | 否 |

Core 镜像发布前必须通过完整 API/Web/MCP 回归、隔离 MySQL 备份恢复、Docker 容器构建与运行时冒烟检查。Gateway 经过类型检查、Gateway/SDK 测试、Redis、浏览器和容器检查；Node 经过 Go 测试、运行时稳定性与容器配置路径检查。PR 执行相关验证，不推送镜像。
文档或无关文件的 main 提交不重复发布开发镜像。Core/Gateway 不通过手动 `workflow_dispatch` 发布；Node 允许对已经在 main 的版本标签重新验证与发布。正常发布由匹配构建路径的 main push 或符合命名规范的版本标签 push 触发。
发布 Git 标签所指向的提交必须已在 `main` 中，避免绕过 PR 验证。错误的 `v*` 标签会被拒绝。
构建成功后可在 Actions 摘要中查看通道及实际推送的镜像标签。

## 如何发布

### 开发版（自动）

每次合并或推送涉及 API、Web、MCP、容器或发布脚本的变更至 `main`，自动构建
`ghcr.io/anrcm0/txboard-core:dev` 及旧 `txboard:dev` 别名，并同时推送独立提交标签（如
`dev-sha-0123456789ab`）。**不会移动 `latest`。**

### 预览版（主动打标签）

先将待发布修改合并到 main：

```bash
git checkout main
git pull --ff-only
git tag -a v1.2.3-rc.1 -m "TXBoard v1.2.3 release candidate 1"
git push origin v1.2.3-rc.1
```

GHCR 标签：`preview` 和 `v1.2.3-rc.1`。

### 正式版（主动打标签）

```bash
git checkout main
git pull --ff-only
git tag -a v1.2.3 -m "TXBoard v1.2.3"
git push origin v1.2.3
```

GHCR 标签：`latest` 和 `v1.2.3`。

同一版本标签触发三个组件独立验证与发布，部署前核对全部目标 digest。新镜像包在第一次正式版本发布前只有开发通道；安装测试需显式选择 `dev`，不能假设 `latest` 已存在。

**不要随意重建/强推现有正式版本 Git 标签。** 正式部署建议固定版本
`v1.2.3` 或镜像 digest，预发布固定 `v1.2.3-rc.1`；开发环境可
`sudo txboard update dev`。正式版默认镜像保留 `latest`，
但 `latest` 不再代表 main 最新提交。

若 Docker buildx 跨架构环境遇到 esbuild 的 ETXTBSY，Web 静态文件阶段已
改为使用 BuildKit `BUILDPLATFORM` 原生构建并对 npm 缓存加锁，避免让
跨架构 QEMU 参与 Vite/esbuild 的安装/构建；MCP 仍依目标架构保留依赖。

源代码 Compose 用于开发；真实服务器应通过 TXBoard-Deploy 更新镜像标签。发布镜像不代表真实数据库恢复、支付沙盒或 TXNode 跨仓联调已通过。
