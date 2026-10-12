# 双仓合并与新镜像发布验收

2026-10-13，运行时统一在 `ANRCM0/TXBoard`，未来名称为 `tx-core`；唯一安装入口为 `ANRCM0/TXBoard-Deploy`。本记录核验固定提交镜像及隔离安装生命周期，原始日志与 OCI 清单的哈希见 [验收凭据](consolidation-verification.json)。仓库布局与历史来源见 [双仓布局](repository-layout.md)。

## 已发布的固定镜像

三包均能匿名拉取；清单包含 `linux/amd64`、`linux/arm64`，两平台 OCI source 均为 `https://github.com/ANRCM0/TXBoard`，revision 与表中提交一致。

| 镜像 | 固定标签 | revision | 发布及门禁 |
| --- | --- | --- | --- |
| `ghcr.io/anrcm0/txboard-core` | `dev-sha-f9b271451eb3` | `f9b271451eb36438db946ae018ab55568d2f10d5` | [Core CI](https://github.com/ANRCM0/TXBoard/actions/runs/38300575376) 成功 |
| `ghcr.io/anrcm0/txboard-gateway` | `dev-sha-f9b271451eb3` | `f9b271451eb36438db946ae018ab55568d2f10d5` | [Gateway CI](https://github.com/ANRCM0/TXBoard/actions/runs/38300575445) 的 verify/container/browser/publish 成功；dev 提升排队 |
| `ghcr.io/anrcm0/txboard-node` | `dev-sha-0f18459250d8` | `0f18459250d8761fa6f426af1202580fc7a513e0` | [Node CI](https://github.com/ANRCM0/TXBoard/actions/runs/38302071059) 成功 |

| 镜像 | OCI index digest |
| --- | --- |
| Core | `sha256:ca27ef96fe0ca969a9fe50cf8ba66e3b0b2f656900645985baac88110a0e3727` |
| Gateway | `sha256:c14ca36e5b763d2f7e6415b277b5186cb44f69b262115f819be66effcae5fced` |
| Node | `sha256:94d784af263fbd24362519bb67d18a224b78a2e1e8ff83b7d86f64f498c93b48` |

Core 与 Node 的 `dev` 已指向对应固定 digest。Node 新包公开且直接关联主仓，不依赖旧 `tx-node` 包的转绑或权限。Gateway 固定镜像已发布并通过安装验收；记录时 `promote-dev` 仍等待 [旧构建](https://github.com/ANRCM0/TXBoard/actions/runs/38292809249) 释放 `gateway-publish-dev` 锁，不能将这个待执行别名更新记为成功。取消或结束旧运行后，已排队任务会重新核对 main 的 Gateway runtime，再提升该固定 digest。

首次正式 `vX.Y.Z` 标签尚未发布，新包的测试安装使用上述固定标签或已经发布的开发渠道；不假定 `latest` 存在。后续同一正式标签将触发三个组件各自门禁和发布。

## 验证结果

- Core：API 1004 通过、84 跳过、10016 assertions；Web 365 通过；MCP 类型与构建通过；实际容器健康/PDO/Octane/MCP 及隔离 MySQL 备份恢复通过。
- Gateway：713 项、SDK 68 项、Redis 4 项、快照 4 项、生产依赖 guard 38 项，以及 Chromium 与实际非 root/只读 HPKE 容器检查通过。原生 builder 的实际多架构 build/push 为 29 秒，生产依赖裁剪后 14 项、4053 文件通过可移植性检查。生产依赖与应用代码未升级。
- Node：432 主测试、194 子测试通过，零跳过；vet、8 个包的 race/shuffle/count10 稳定性及实际 Docker 版本、OCI 标签、旧配置路径 fallback、canonical 优先检查通过。main 不创建版本 Release，两个 tag-only 任务按规则跳过。
- Installer：`5618dd587d3479e165cbfd3e59ffd7043380e680` 的 [Deploy CI](https://github.com/ANRCM0/TXBoard-Deploy/actions/runs/38301662347) verify 与实际 Gateway 安装、health、启停、失败目标回滚、remove/config 保留均成功。[文档 CI](https://github.com/ANRCM0/TXBoard-Deploy/actions/runs/38301661999) 成功；该 push 的 Core smoke-install 按事件条件跳过。固定 Gateway image ID 与 OCI amd64 config 一致：`sha256:3443d5163a65af559f4d137addc31c301ef41284acdf8189d2bb1ac5d7f41ada`。

Node 首次更名提交 `f9b2714` 的 Go 测试已通过，旧名称检查却把 `xboard-node:latest` 子串误匹配到 `txboard-node:latest`，因此当次节点发布未执行。`0f18459` 收紧名称边界并经过 12 个正负用例及新一轮完整 CI；失败日志保留，未用重跑掩盖。Gateway 本地测试快照的文档路径和 npm 开发可选包 metadata 差异也在 [本地报告](../../gateway/docs/native-builder-verification.json) 保留，未改仓库锁文件或生产依赖指纹。

## 历史与验收边界

Gateway、Node、Node Installer 采用不 squash 的 subtree merge；原始源提交仍为新主仓祖先，导入时 subtree tree 与原 source tree 完全相同。旧三仓已提供迁移说明，活动 CI 集中到两个主仓；旧仓尚未删除。安装参数、程序名 `tx-node`、CLI `txnode` 及现有配置位置保留，新安装使用新包，旧容器不会因发布自动换镜像。

使用隔离合成数据，没有访问生产数据库、节点或密钥。镜像和安装生命周期验收不代表 B0–B8 的生产迁移、真实 Node admission、业务 TLS 或支付验收完成。
