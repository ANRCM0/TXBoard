# TXBoard 镜像发布通道（Phase 4）

GHCR 仓库：`ghcr.io/anrcm0/txboard`，支持 `linux/amd64` / `linux/arm64`。

| 场景 | Git 触发条件 | 推送的 GHCR 标签 | 是否覆盖正式版 |
| --- | --- | --- | --- |
| 开发版 | 每次 `main` push（含文档提交） | `dev`, `dev-sha-<12 位提交 SHA>` | 否 |
| 预览版 | 推送 `v1.2.3-rc.1`（也接受 `beta.N`、`preview.N`） | `preview`, `v1.2.3-rc.1` | 否 |
| 正式版 | 推送 `v1.2.3` | `latest`, `v1.2.3` | 是（更新 latest） |
| Pull Request | 向 main 发起 PR | 无，仅测试 | 否 |

所有通道在推送前必须通过完整 API/Web/MCP 回归检查、Docker 容器构建与运行时冒烟检查。
手动 `workflow_dispatch` 不再触发镜像发布；只有 main 的自动提交事件或符合命名规范的版本 Git 标签可以发布。
发布 Git 标签所指向的提交必须已在 `main` 中，避免绕过 PR 验证。错误的 `v*` 标签会被拒绝。
构建成功后可在 Actions 摘要中查看通道及实际推送的镜像标签。

## 如何发布

### 开发版（自动）

每次合并或直接推送至 `main`，自动构建
`ghcr.io/anrcm0/txboard:dev`，并同时推送独立提交标签（如
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

**不要随意重建/强推现有正式版本 Git 标签。** 正式部署建议固定版本
`v1.2.3` 或镜像 digest，预发布固定 `v1.2.3-rc.1`；开发环境可
`sudo txboard update dev`。正式版默认镜像保留 `latest`，
但 `latest` 不再代表 main 最新提交。

若 Docker buildx 跨架构环境遇到 esbuild 的 ETXTBSY，Web 静态文件阶段已
改为使用 BuildKit `BUILDPLATFORM` 原生构建并对 npm 缓存加锁，避免让
跨架构 QEMU 参与 Vite/esbuild 的安装/构建；MCP 仍依目标架构保留依赖。

源代码 Compose 用于开发；真实服务器应通过 TXBoard-Deploy 更新镜像标签。
