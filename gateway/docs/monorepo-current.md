# Gateway 合仓运行约定

状态：Current / source-layout。Gateway 源码已随完整 Git 历史导入主仓 `gateway/`；主仓未来改名为 `tx-core` 不改变下面的源码相对路径、SDK 包名或 HTTP 协议。

## 开发与测试

从主仓根目录执行 `cd gateway`，再运行 `npm ci`、`npm run check`、`npm run test:redis` 和 `npm run test:browser`。`gateway/package-lock.json` 保持独立，根目录的 web workspace 不吸收 Gateway 依赖。SDK 仍位于 `gateway/packages/theme-sdk`；现有 TypeScript 相对 import 和 app 的 `../../.env` 指向保持在 Gateway 子树内。

实际工作流是根目录 [.github/workflows/ci-gateway.yml](../../.github/workflows/ci-gateway.yml)。所有 shell 步骤默认在 `gateway/` 执行，npm cache 使用 `gateway/package-lock.json`。验证保留 Gateway/SDK 类型检查、测试、构建、真实 Redis 多连接测试、SDK pack dry-run、启动拒绝检查、Compose/Docker/HPKE 容器检查、Chromium mock E2E、codec 和离线 crypto 浏览器检查。文档检查另覆盖 contracts、SDK README 和 staging 文档；这些门禁不代表生产 Core/CAPTCHA 联调。

历史 `gateway/.github/workflows/` 已移除；GitHub 只执行主仓根目录的 Actions。历史版本仍可由合并祖先追溯。

## 独立镜像与统一版本

镜像仓库为 `ghcr.io/<主仓 owner 小写>/txboard-gateway`，与 Core、Node 镜像分别发布。构建上下文固定 `gateway/`，Dockerfile 为 `gateway/Dockerfile`；`gateway/.dockerignore` 继续排除私钥、环境文件、测试和依赖目录。容器仍以非 root 用户启动，HPKE 私钥仅作为运行时只读挂载。

发布与上述三组门禁位于同一个工作流，必须全部成功：

| 主仓 Git ref | Gateway 镜像标签 |
| --- | --- |
| main 上的运行代码或构建配置变更 | `dev`、`dev-sha-<commit前12位>` |
| `vX.Y.Z` | `latest`、`vX.Y.Z` |
| `vX.Y.Z-rc.N`、`-beta.N`、`-preview.N` | `preview`、精确预发布标签 |
| PR、手动验证、文档或测试单独变更 | 验证，不发布 |

主仓 `v*` 是 Core/Gateway/Node 的统一发布版本，不另建 Gateway 发布 tag。版本标签使用主仓 [统一解析器](../../scripts/resolve-image-tags.sh)，且 release commit 必须已在 main；不支持的标签不会发布。OCI source/revision/version 从当前 GitHub repository、commit、ref 生成，主仓改名不要求修改镜像构建源码。流水线不自动发布 npm、切换部署、启用 Internal 开关，也未增加签名或 provenance 验收声明。

main 先发布不可变的 `dev-sha-*`；发布完成后重新读取 main，只有该 commit 仍属于 main 且 Gateway 运行源码、SDK 源码或构建配置没有继续变化，才从已构建 digest 更新 `dev`。后续仅文档/测试变更不阻止原运行版本发布；过时构建只保留不可变标签，不回写 `dev`。

本地构建从主仓运行 `docker build -f gateway/Dockerfile gateway`，或进入 `gateway/` 使用原 Compose 命令。部署仓统一安装器选择独立镜像，生产宜固定经过验收的 digest。基础 Compose、crypto 和 Redis overlay 继续默认关闭账户写入；公网仍只由既有 HTTPS 入口精确分流。

Gateway 的 TypeScript builder 在 `BUILDPLATFORM` 上运行，最终 Node 容器仍使用目标平台。`npm prune --omit=dev` 后，[便携依赖校验](../scripts/validate-portable-dependencies.mjs) 固定已审阅的生产 lock 图（含版本、integrity 与依赖），并检查实际安装包、工作区链接、平台元数据、安装钩子及原生二进制。当前审阅图也禁止未审阅的 WASM 文件；这不表示 WASM 本身依赖 CPU 架构。生产依赖新增或升级必须重新审阅并更新校验指纹；开发依赖可独立调整。仅该校验脚本进入 Docker 上下文，不扩大其他脚本或私钥的打包范围。它验证当前跨架构复制前提，多架构镜像仍需通过实际容器与发布门禁。

## 隔离快照与真实 Core 联调

Native 联调的 `TXBOARD_CORE_REPO` 指向主仓根目录，`TXBOARD_CORE_VENDOR` 指向其 `api/vendor`。`tests/integration/run-linux.sh` 仍归档 committed Core，再从 `gateway/` 构建消费者，不读取未提交的 Core 业务改动。

Internal 快照工具的 source 和 dependency-source 指向 Gateway workspace：

```sh
python3 gateway/tests/integration/prepare-internal-user-snapshot.py \
  /path/to/core/gateway /tmp/new-gateway-snapshot /path/to/linux-dependencies HEAD
```

工具默认归档该 source 相对 Git 根目录的 `gateway` 子树，然后叠加既定 Internal 文件；记录统一 commit、Gateway tree ID、source prefix 和叠加文件 SHA256。不能把整仓根目录当成 Gateway source。若复现迁移前的独立 Gateway commit，显式传第五参数空字符串 `''`，保留原 commit 身份；不会重写历史验收报告。

运行 Internal 两套 TLS launcher 前，设置 `INTERNAL_EXPECTED_CORE_COMMIT=<固定Core快照的40位commit>`，必须与 Core `internal-snapshot.json` 完全相等。仍需独占合成测试库、明确 Redis scope 和原 mTLS fixture；合仓不会授权连接生产数据库或外发邮件。

## SDK 兼容边界

包名仍为 `@txboard/theme-sdk`，版本 `0.1.0`；当前未发布 npm。ESM、声明文件、Node 下限、公开导出（含两个 experimental 子路径）、`/gateway/v1`、HPKE AAD 和 `{ok,data,meta}` 信封均未因移动目录改变。SDK 不保存用户凭据，公共接口不附带 Bearer，未知写结果不自动重试。

统一 `v*` 镜像发布不等于 SDK npm 发布。正式 npm 发布仍需独立版本/CHANGELOG、干净 tarball 安装、浏览器与 SSR 隔离、声明消费及 provenance/发布身份验收；迁移没有把历史发布设计当作已实现工作流。打包仍在 `gateway/` 运行 `npm pack --dry-run --workspace @txboard/theme-sdk`，只包含包自身 dist/README，不包含 Core、Node、secrets 或源码仓目录。
