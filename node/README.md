# TX-Node

TX-Node 是独立维护的 **Xboard 兼容节点运行时**，支持 `sing-box` / `xray-core` 双内核，并在节点运行、machine 自愈、访问审计、部署运维和发布流程上持续独立演进。

项目起源于 [cedar2025/Xboard-Node](https://github.com/cedar2025/Xboard-Node)。TX-Node 保留 Xboard 面板 API、认证字段和现有节点配置的协议兼容，但不再以周期性同步上游作为开发模式；上游后续修复会按需审查并选择性移植。独立维护策略见 [`docs/standalone.md`](docs/standalone.md)。

## 部署（Docker，推荐）

镜像由 GitHub Actions 自动构建发布到 GHCR（amd64 + arm64）：

```
ghcr.io/paimoncai/txboard-node:latest
```

### 一键部署脚本（推荐）

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/PaiMonCai/TXBoard/main/node/deploy.sh)
```

不带参数执行会进入**交互式运维面板**；带参数则非交互执行单个命令。

脚本完成：Docker 环境检测/自动安装 → 交互式填写面板地址 / token / node_id（或 machine 模式 machine_id + 机器令牌）→ 生成 `/etc/txnode/config.yml` + `docker-compose.yml` → 拉镜像启动 → 部署后自检（容器存活 + 审计模块连通）。重复执行可升级/重配/卸载（幂等）。

> 首次使用前确认 GitHub Packages 里 `tx-node` 包的可见性为 **Public**
> （Packages → tx-node → Package settings → Change visibility），
> 否则节点拉取镜像需要先 `docker login ghcr.io`。

### 运维面板（`txnode`）

装好后会注册一个快捷命令，直接敲 `txnode` 进面板（等价于 `bash deploy.sh`）：

```
txnode
```

面板会自动识别当前的部署状态（txnode 的 Docker 部署 / `install.sh` 装的 systemd 部署 / 未部署），用同一套菜单：

| 菜单 | 作用 |
|---|---|
| 1 查看状态 | 运行状态、配置摘要、健康检查 |
| 2 查看日志 | 实时跟随（Ctrl+C 退出） |
| 3 重启 | 改完配置后用这个 |
| 4 启动 / 停止 | 子菜单：启动、停止、安全重启 |
| 5 升级 | 拉最新镜像并重建（等价 `docker compose pull && up -d`） |
| 6 修改配置 | 向导重填 / 手编 YAML / 改日志级别 |
| 7 访问审计开关 | 一键开启 / 关闭审计上报，见下 |
| 8 配置校验与诊断 | 排错用，见下 |
| 9 节点与机器管理 | 多节点 `nodes` 段增删、切换到 machine 模式 |
| 10 备份 / 恢复 | 配置备份、回滚、清理旧备份 |
| 11 卸载 | 移除容器 / 服务，**保留配置** |
| 12 彻底清除 | 删容器、镜像、配置、systemd 单元（需手输 `PURGE` 确认） |
| 13 快捷命令 | 安装 / 重建 `txnode` 命令 |
| 14 从 install.sh 导入 | **仅检测到 `install.sh` 部署时出现**，见下 |

几个概念上的区别，别用错：

- **停止**：手动停掉后**保持停止**，机器重启后不会被自动拉起（compose 用 `restart: unless-stopped`）。
- **安全重启**：先把容器重启策略降为 `no` 再重启，确认连续稳定 5 秒后才恢复 `unless-stopped`；若启动后立刻崩溃，会自动停掉容器并关掉自动重启，**不会陷入 Restarting 死循环**，同时打印最近日志。
- **pause**：旧命令保留作兼容，现在**等价于 stop**（不再改写 compose 的 restart 配置）。
- **卸载**：去掉运行环境，配置留在 `/etc/txnode`，之后能重新装回来。
- **彻底清除**：连配置一起删，**不可逆**。

### 快捷命令 `txnode`

装好后会注册 `txnode`，直接敲即进面板。菜单第 13 项「快捷命令」可随时重建它。

```bash
txnode                  # 进运维面板
txnode status           # 直接看状态
bash deploy.sh link     # 重建快捷命令
```

> **如果你是用 `bash <(curl -fsSL .../deploy.sh)` 装的，`txnode` 大概率是坏的。**
> 那种方式运行时脚本自身路径是 `/dev/fd/63` 这类**进程替换的临时 fd**，进程一退出就消失，
> 软链指向它就是个断链。执行 `bash deploy.sh link` 即可修复 —— 它会把脚本落到
> `/etc/txnode/deploy.sh`（取不到时从网络重新拉一份），再把 `txnode` 指过去。

### 访问审计开关

菜单第 7 项，也可以非交互一键设置：

```bash
txnode audit            # 显示当前状态并交互式切换
txnode audit on         # 一键开启（audit.enabled=true）
txnode audit off        # 一键关闭
txnode audit all on     # 全量上报：所有连接都记入面板访问日志
txnode audit all off    # 仅上报命中规则的连接
txnode audit status     # 只看不改
```

`report_all` 是最容易踩的坑，两种模式的取舍：

| 设置 | 行为 | 适合 |
|---|---|---|
| `all on` | 所有连接都上报，不看规则 | 要全量访问日志（量可能很大） |
| `all off` | **只**上报命中规则的连接 | 只要命中记录；**面板没配启用规则时一条都不会上报** |

`all off` 却忘了配规则 = 审计开了但面板空空如也，且不报错（详见下方「最常见的坑」）。
脚本会在该状态下于菜单里显式提醒。

### 从 `install.sh` 部署迁移（导入）

`install.sh` 装的是 systemd + 非 Docker 布局（`/etc/xboard-node`），txnode 用的是 Docker 布局（`/etc/txnode`）。
**两者目录与配置完全分离，可以并存**；也可以把 `install.sh` 的配置直接导入，转成 txnode 的 Docker 部署。

进入面板时如果检测到 `install.sh` 部署而本机还没有 txnode，会**主动询问**是否导入；也可以随时手动触发：

```bash
bash deploy.sh migrate              # 导入（交互式）
bash deploy.sh migrate --dry-run    # 只预览会生成什么，不写任何文件
```

导入过程分四步：

1. **提取**：读 `install.sh` 的 `config.yml` + `credentials.env` + `install-meta.json`（**只读**，不改它任何文件）。
   注意 `install.sh` 的密钥不落在 `config.yml` 里——它用 `token_env` 指向 `credentials.env` 中的变量名（由 systemd 注入），
   所以导入时必须把 `token_env` **解析成真实 token**。
2. **合并**：`install.sh` 是「多实例」结构（顶层 `instances:` 列表），txnode 是单份配置，按下面的规则智能归并。
3. **备份 + 写盘**：先把 `install.sh` 的三个文件快照到 `/etc/txnode/backups/legacy-source.<时间戳>/`，再写入新配置。
4. **切换**：先**停止** `xboard-node.service`，再启动 txnode。

第 4 步的顺序是刻意的：`machine` 模式下两边会用**同一个 `machine_id`** 连面板，若同时在线，
面板会看到重复的机器连接；且先起后停会留下「两套都在跑」的混乱状态。
先停可以把冲突窗口压到零，**若 txnode 启动失败会自动回滚**（重新拉起 `xboard-node.service`）。

`install.sh` 的配置**保留不删**，随时可切回：

```bash
systemctl enable --now xboard-node.service   # 切回 install.sh 部署
systemctl stop tx-node                       # 停掉 txnode
```

确认 txnode 稳定后再删残留（脚本会在结束时打印这行提示）。

**合并规则**（受限于 txnode 配置模型）：

| `install.sh` 里的实例 | 转换结果 |
|---|---|
| 同一 `panel.url` 下的多个 node | `panel: {url, token}` + `nodes: [{node_id, node_type, kernel.config_dir}, ...]` |
| 单个 node | `panel: {url, token, node_id, node_type}`（最贴近原语义） |
| machine 实例 | `panel: {url}` + `machine: {machine_id, token}` |
| 多个**不同** `panel.url` / machine | 单份配置无法表达 → **列出候选让你选一组**导入 |
| 同 `panel.url` 但 **token 不一致** | 无法合并（txnode 多节点共享一个 token）→ 明确报错，不会静默丢节点 |

几点值得提前知道的：

- **`machine` 与 `nodes` 互斥**（Go 侧强校验），只能二选一。
- **每个节点的 `kernel.config_dir` 会逐节点保留**——这些目录原本就是各自独立的，不保留会互相覆盖。
- **token 解析不到**时会警告并列出缺失的变量名，可以继续（之后在「修改配置」里补）。
- `install.sh` 三件套（配置 / 二进制 / systemd 单元）**只有部分存在**时会提示可能已被手工清理，需你确认后再继续。
- `migrate --dry-run` 只打印将要生成的配置与合并结果，适合先看一眼再动手。

### 非交互命令

适合写进脚本 / 定时任务：

```bash
bash deploy.sh install       # 安装 / 重新部署（交互式向导）
bash deploy.sh migrate       # 从 install.sh 部署导入并转成 docker
bash deploy.sh migrate --dry-run  # 只预览，不落盘
bash deploy.sh upgrade       # 升级到最新镜像并重建
bash deploy.sh status        # 查看状态与配置摘要
bash deploy.sh start|stop|restart|pause
bash deploy.sh logs          # 实时日志
bash deploy.sh reconfigure   # 修改配置
bash deploy.sh audit on|off  # 一键开关访问审计
bash deploy.sh audit all on|off  # 一键设置 report_all
bash deploy.sh validate      # 配置校验
bash deploy.sh doctor        # 环境与运行诊断
bash deploy.sh backup|restore
bash deploy.sh link          # 安装 / 重建快捷命令 txnode
bash deploy.sh uninstall     # 卸载（保留配置）
bash deploy.sh purge         # 彻底清除（不可逆）
bash deploy.sh help          # 帮助
```

### 升级 / 安装 / 迁移时的容器清理（重要）

`install`、`upgrade`、`migrate` 在启动容器前都会先**清理占用 `APP_NAME` 这个名字的旧容器**，再 `up -d --force-recreate`。原因是 `docker compose up -d` 只靠 `com.docker.compose.project` + `com.docker.compose.service` 两个标签识别「自己的旧容器」：

- 如果同名容器是**手工 `docker run` 创建的**，或来自**换过 `INSTALL_DIR` 的另一次部署**，compose 认不出它属于本项目；
- 于是 `up` 不会重建，而是直接报：
  ```
  Error response from daemon: Conflict. The container name "/tx-node" is already in use
  by container "a057926f...". You have to remove (or rename) that container to be able
  to reuse that name.
  ```

清理策略是「先让 compose 自己收，收不掉再按名字强删」：先 `compose down --remove-orphans`；若容器仍在，则 `docker rm -f <APP_NAME>` 兜底。这样正常部署不会被打断，只有真正认不出的残留容器才会被强制移除（会打印一条 `[!]` 警告说明原因）。

> 用 `--force-recreate` 而不是裸 `up -d`，是为了保证即使镜像 tag 没变（还是 `latest`），容器也会按新配置重建。

若你确实想保留某个手工容器，请在升级前先 `docker rename` 换个名字。

### 自定义安装路径

默认装在 `/etc/txnode`（与 `install.sh` 的 `/etc/xboard-node` 分开，互不干扰）。需要换位置时用环境变量覆盖：

```bash
INSTALL_DIR=/opt/tx-node bash deploy.sh install
```

可用变量：`INSTALL_DIR`、`APP_NAME`、`IMAGE`、`CLI_LINK`，以及只读探测用的 `LEGACY_INSTALL_ROOT`（默认 `/etc/xboard-node`）。

### 健康检查与节点自愈（`/healthz`）

给实例配上 `health_port` 后会起一个轻量 HTTP 端点。因为部署用的是 `network_mode: host`，
**在宿主机上直接就能查**，不用进容器：

```bash
curl -fsS http://127.0.0.1:<health_port>/healthz
# {"status":"ok"}
```

机器模式下这个端点反映**真实节点状态**：只要有节点启动失败（最常见是端口被占用），
它在重试退避期间就返回 503：

```json
{"status":"degraded","nodes":2,"failed":2}
```

这修掉了以前「节点全部挂掉、容器却显示健康」的问题。

节点失败后**会自动重试**，不会一次失败就永久躺平：退避从 15 秒起、每次翻倍、上限 5 分钟；
如果节点是稳定运行超过 1 分钟之后才挂的，退避计数归零、立即重试。日志形如：

```
ERROR [core] machine node exited with error node_id=1 error=... attempt=1 retry_in=15s
WARN  [core] machine has failing nodes, they will be retried with backoff failed=2 total=2 machine_id=16
```

> **host 网络下出现 `bind: address already in use`，通常不是节点自身的问题。**
> 节点端口直接占用宿主机端口，报这个错多半意味着**同一份节点配置已经在别处跑着**
> —— 比如老的 `install.sh` 部署没停干净，或两套部署并存。
> 用 `ss -lntup | grep <端口>` 找出占用者。

> 想在 Docker 的 `healthcheck` 里用这个端点，镜像内需要有 curl。
> 官方镜像基于 alpine、未预装，可自行 `apk add --no-cache curl`。

### 配置校验（`validate`）

`bash deploy.sh validate` 会做区段感知检查（能区分 `panel.token` 与 `machine.token`，不会串味），覆盖：

- `panel.url` 必填、必须是 `http(s)://`
- token：`token` / `token_env` 至少一个；明文与 `token_env` 同时给会报歧义
- 单节点模式缺 `panel.node_id`、机器模式缺 `machine.machine_id`
- `kernel.type` 取值（**正确值是 `singbox` / `xray`**；写成 `sing-box` 会报错——`sing-box` 是产品名，配置值不带连字符）
- TAB 缩进（YAML 不允许）
- `audit.enabled=true` 但内核不是 `singbox`（内嵌审计只在 singbox 下工作）
- `audit.enabled=true` 且 `report_all=false`（提示「规则没配 = 不上报」，见下方审计小节）


### 手动部署

**1. 准备配置文件**

```bash
mkdir -p /etc/txnode
cat > /etc/txnode/config.yml <<'EOF'
panel:
  url: "https://你的面板域名"
  token: "面板 server_token"
  node_id: 1            # 面板里这个节点的 ID

kernel:
  type: "singbox"       # 或 xray

# 访问审计（可选，需面板安装 AccessAudit 插件；不配 = 关闭，行为同原版）
audit:
  enabled: true
EOF
```

**2. 启动**

```bash
docker run -d --restart=unless-stopped --network=host \
  --name tx-node \
  -v /etc/txnode/config.yml:/etc/xboard-node/config.yml \
  ghcr.io/paimoncai/txboard-node:latest
```

**3. 验证**

```bash
docker logs tx-node | grep "audit reporter enabled"
```

看到这行 = 审计模块已启用并连上面板。之后在面板 `/plugin/access-audit` 配置审计规则，命中记录和节点状态都会出现在管理页。

**4. Docker Compose（等价手动方式）**

```yaml
services:
  tx-node:
    image: ghcr.io/paimoncai/txboard-node:latest
    container_name: tx-node
    restart: unless-stopped
    network_mode: host
    volumes:
      - ./config.yml:/etc/xboard-node/config.yml
```

## 审计说明

| 项 | 行为 |
|---|---|
| 启用方式 | config.yml 加 `audit: enabled: true`（**必须挂配置文件**，纯环境变量模式无法开启审计） |
| 认证 | 复用 `panel.url` / `token` / `node_id`（与原版节点上报同一套 ServerV2 认证，零额外密钥） |
| 数据流 | 连接路由时提取（user_id, 目标域名/IP, 来源 IP）→ 节点本地按面板下发的规则预过滤 → 只上报命中项 |
| 批量 | 攒批 50 条 / 15 秒上报一次；一次 tick 内连续发送直到队列清空（上限 10 批），面板不可达时本地排队（上限 5000 条），恢复后自动补报 |
| 内核范围 | **仅 sing-box**。xray 内核请用 AccessAudit 插件自带的旁路 `audit-agent.py`（tail access log） |
| 目标提取 | sniff 域名 > 代理协议自带域名 > 目标 IP（代理协议自带域名，绝大多数场景不依赖 sniff） |

可调参数（都有默认值）：

```yaml
audit:
  enabled: true
  report_all: false    # true = 上报全部连接（含未命中），面板留存全量访问日志
  batch_max: 50        # 每次上报最多事件数（report_all 默认 200；面板单批上限 500）
  flush_interval: 15   # 上报间隔（秒）
  rules_refresh: 5     # 规则拉取间隔（分钟）
  queue_cap: 5000      # 面板不可达时的本地队列上限（report_all 默认 50000）
```

> `batch_max` 上限为 **500**（面板上报接口 `MAX_EVENTS` 限制），超过会被 422 拒绝整批。

### ⚠️ 最常见的坑：开了 `enabled` 却一条数据都没有

**`report_all: false` 时，唯一能上报的只有「命中规则」的连接。如果面板上一条启用规则都没有，节点会丢弃每一个连接，一条数据都不上报** —— 而配置和启动日志看起来一切正常。

原因：节点在本地按面板下发的规则预过滤（省面板流量），规则集为空 → `match()` 恒为 false → 全部丢弃。

所以只有两种有效组合：

| 目标 | 配置 |
|---|---|
| 只要**违规命中**记录（量小） | `report_all: false` **且面板上至少配一条启用规则** |
| 要**全量访问日志**（面板能看到所有连接） | `report_all: true`（无需配置规则，规则仅用于标记哪条算命中） |

节点启动时会打印一条 WARN 提醒这个状态；规则拉取到空集且 `report_all=false` 时也会再告警一次（每分钟最多一条）：

```
WARN [core] audit: report_all=false — only rule-matched targets are reported;
           with no enabled rules NOTHING will be sent. ...
```

看到这条日志就说明当前配置不会产生任何上报。

### 负载与容量边界（重要）

**单个节点对面板的压力上界是确定的**，可以按下面的公式估算后再决定是否开启 `report_all`：

```
节点发送速率上限 = batch_max × 10 批 / flush_interval(秒)   [条/秒]
```

默认值下：`50 × 10 / 15 ≈ 33 条/秒`；`report_all` 默认值下：`200 × 10 / 15 ≈ 133 条/秒`。

面板侧每条上报的 SQL 次数（无论批内多少条）为固定开销 + `ceil(N/200)` 次批量插入，**不再随事件数线性放大**。但事件**产生**速率是随在线用户数线性增长的：

| 规模 | 事件产生速率（估算） | report_all 默认配置是否跟得上 |
|---|---|---|
| 1000 在线用户，人均 3 并发，连接均值 300s | ≈ 10 条/秒 | 跟得上 |
| 5000 在线用户，同假设 | ≈ 50 条/秒 | 跟得上（接近上限） |
| 20000 在线用户，同假设 | ≈ 200 条/秒 | **跟不上**，队列会持续堆积并最终丢弃 |

队列满或补报失败溢出时，**事件会被丢弃并记录 `dropped` 计数与限流告警**（每分钟最多一条 WARN），不会静默丢失。

**建议**：
- 只要「命中项上报」（默认模式）时，事件量极小，任何规模都无需调整。
- 需要**全量访问日志**（`report_all`）且在线用户数超过 ~5000 时，请同时调大 `batch_max`（如 500）与 `flush_interval`，或在面板侧接受日志采样；单节点无法保证不丢时，日志仅适合做抽样审计，不适合做计费依据。

## 配套面板插件（AccessAudit）

`../integrations/AccessAudit/` 是 Xboard 面板侧的审计插件（v2.1）：接收节点上报、名单匹配、阈值自动封禁、TG 告警、分节点查看、节点级异常通报（上报中断/命中突增）。

- 源码直接在本仓库 `../integrations/AccessAudit/`，发布时 CI 自动打包 `AccessAudit-plugin.zip`（Release 附件）
- 安装：zip 上传到 Xboard 后台插件管理（或放 `plugins/` 目录）→ 启用 → 管理页 `/plugin/access-audit`
- xray 内核节点用的旁路 agent 也在插件包内（`node-agent/audit-agent.py`）

详细文档见插件包内 `README.md`。

## Xboard 兼容与项目来源

TX-Node 保留 Xboard 面板协议及兼容配置字段。`xbctl` 与原生 `/etc/xboard-node` systemd 布局仅作为 legacy compatibility 保留；新的 Docker 运维入口是 `deploy.sh` / `txnode`。

项目历史来源于 [cedar2025/Xboard-Node](https://github.com/cedar2025/Xboard-Node)。后续 TX-Node 版本独立维护和发布；上游修复仅按需审查、移植，不再整分支同步。详见 [`docs/standalone.md`](docs/standalone.md)。

## License

MPL-2.0。项目保留其历史来源及适用的上游版权与许可证声明。

> **Disclaimer**: This project is for educational and learning purposes only.
