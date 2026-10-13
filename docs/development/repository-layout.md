# 双仓布局与镜像发布

2026-10-13 起，运行时代码集中到当前 TXBoard 主仓，后续仓库名为 `tx-core`；安装和主机运维统一到 TXBoard-Deploy。仓库合并不改变 Core 的业务权威、Gateway 的用户 BFF 边界或 Node 的独立进程和凭据。

2026-10-13 最新范围：当前主仓只开发 Core、Gateway、Node。管理前端和用户前端均暂缓，后续集中到独立 `TX-F` 项目，只本地提交、不推送；安装器保持独立仓库。现有 `web/`、Theme 及其 CI/镜像构建依赖保留兼容，不代表在本仓开始新前端项目。

## 运行时仓

| 组件 | 源码与依赖 | Docker 上下文 | 镜像 |
| --- | --- | --- | --- |
| TXBoard Core | 根目录 `api/`、`web/`；保留现有 Composer/npm locks | 根目录；排除 `gateway/`、`node/` | `ghcr.io/anrcm0/txboard-core` |
| Gateway | `gateway/`，独立 npm workspaces 和 lock | `gateway/` | `ghcr.io/anrcm0/txboard-gateway` |
| Node | `node/`，独立 `go.mod`、`go.sum` | `node/` | `ghcr.io/anrcm0/txboard-node` |

Core 继续发布 `ghcr.io/anrcm0/txboard` 别名供已有安装使用；新安装使用 `txboard-core`。Go module `github.com/ANRCM0/TX-Node` 与现有内部 import 暂不改名；从统一仓克隆后进入 `node/` 构建即可。SDK 包名与接口也保持现行版本，合仓不自动发布新的 npm 包。

```bash
# Core
npm ci --no-audit --no-fund
npm run verify:web
cd api && composer install --no-interaction --prefer-dist && php artisan test

# 独立组件（从统一仓根目录执行）
cd gateway && npm ci --no-audit --no-fund && npm run check
cd ../node && go test ./...
```

## CI 与发布

所有活动工作流都在根目录 `.github/workflows/`。API、MySQL、Web 保留现有专项；Core 镜像保留全量回归、恢复演练和容器健康/PDO/Octane 门禁。Gateway 和 Node 在各自工作目录验证后发布，镜像上下文不能互换。

| Git ref | 各组件镜像标签 |
| --- | --- |
| `main` | `dev`、`dev-sha-<统一提交前12位>` |
| `vX.Y.Z` | `latest`、`vX.Y.Z` |
| `vX.Y.Z-rc.N` / `-beta.N` / `-preview.N` | `preview`、完整预发布标签 |

同一个 `v*` 标签触发三个组件各自的验证及发布；它必须指向已在 `main` 的提交。组件镜像独立发布，没有跨 GHCR 包的原子提交，部署前需确认三个目标 digest 和全部相关 CI。GitHub Release 的附件为 Node 二进制；正式部署仍使用验证过的固定版本或 digest。文档更新不发布开发镜像，组件工作流仍可能运行相应验证。

Gateway 的不可变镜像构建可并行，`dev` 提升按组件串行；Node 的整个 publish job 按通道串行。两者提升前都核对当前 main 的运行时代码，防止旧构建覆盖新镜像。文档或测试变化不会取消正在进行的运行时发布。Core 保留现有分通道并发策略；包写权限只授予镜像 publish job 与 Gateway 的 promote-dev job。

工作流通过 `github.repository` 生成 OCI 来源标签，通过仓库 owner 生成小写镜像路径，不依赖主仓名称。后续重命名 GitHub 仓库不会改变三个镜像名。主仓新建 `txboard-node` 包，保留旧 `tx-node` 包且不向它写入标签；无需将旧包转绑到主仓。

## 唯一安装仓

[TXBoard-Deploy](https://github.com/ANRCM0/TXBoard-Deploy) 管理 Core、Gateway、Node 的安装与生命周期。原节点安装器在该仓 `node/` 下保留；现有 `txnode` 命令、配置位置和机器更新桥保持兼容。Node 新镜像包的选择和已有容器升级由安装仓管理，运行时发布不会自动替换已运行容器。运行时仓内的 Compose 用于开发与构建验证，生产入口以安装仓文档为准。

当前整合保留运行时仓与安装仓；未来新前端在独立 `TX-F` 项目开发，只本地提交、不推送。原 TX-Node、TXBoard-Gateway、TX-Node-Installer 是迁移过渡仓，后续将删除。三组件活动 CI、Docker 上下文和管理员生成的 Node 安装命令均使用这两个主仓。Node 的稳定 Go module 名是本地模块标识，完整 checkout 中的构建不需要拉取旧 TX-Node 仓的自身源码。

主仓只发布新 `ghcr.io/anrcm0/txboard-node` 包，旧 `ghcr.io/anrcm0/tx-node` 包不迁移、转绑或覆盖；旧包的可用性不由新 CI 保证。已安装的旧 `txnode` 脚本须先从安装仓 `node/deploy.sh` 刷新持久入口。原安装仓只在过渡期间提供兼容转发。

## 历史来源

通过不 squash 的 Git subtree merge 导入；源仓提交仍是主仓祖先，可以核查完整历史。原仓没有删除或改写。

| 来源 | 导入提交 | 目标 |
| --- | --- | --- |
| TXBoard | `c424a71bc582b9194f0436c426d62a7ada8eb509` | 保留根结构 |
| TXBoard-Gateway | `4d36e3b4ed6a0f1c9fde99ce233f526636c4723a` | `gateway/` |
| TX-Node | `b6bd28de4f8e3deebace83a77fd942d55218d757` | `node/` |
| TXBoard-Deploy | `ac9b811afb540206080fe4b6ae08d9feb1423d85` | 保留安装仓根结构 |
| TX-Node-Installer | `303fd425d9eb0853d4abe9b79b1c26c42c9ac241` | 安装仓 `node/` |

B0 基线及已经冻结的 B1–B5 验证报告使用当时的仓库名、路径与提交，不改写历史证据。旧四仓静态盘点脚本仍仅用于复现旧布局的离线基线。当前合仓不表示 B0–B8 的生产迁移门禁全部完成，也不启用任何默认关闭的隔离功能。
