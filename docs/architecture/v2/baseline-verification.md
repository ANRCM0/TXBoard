# B0 本地基线验证

> 执行日期：2026-10-12，Asia/Shanghai。验证对象为本批工作前四仓基准提交及本批文档/工具改动，不是生产验收报告。

## 环境与提交

本机为 Windows / PowerShell，Node `24.14.1`、npm `11.11.0`、Go `1.26.3 windows/386`，另有 Git Bash 和 Python。当前 PATH 没有 PHP、Composer、Docker；无法启动 Laravel、导出真实 HTTP registry、运行 API/MySQL、Redis 容器和镜像验收。现有 GitHub CI 使用 Linux/Node 22，与本机环境不同。

| 仓库 | 本批基准 HEAD |
| --- | --- |
| TXBoard | `0c70d5a60503f6291cc079ce5002bd07f8d5e90a` |
| TXBoard-Gateway | `1b2f7a08645b9f0c8ad25a1c17c71f14d9c41cbc` |
| TX-Node | `925012ef6422fc1f56b813584e242eb304bb31f6` |
| TXBoard-Deploy | `ae607bbb9b0ccf6343b8a9c3cf0c2edeacbebdfc` |

工作开始时四仓干净；此记录保留执行验证时的源码基准，提交和推送后不改写这些历史 HEAD。当前提交与工作区状态以 Git 为准；完整盘点另保存每个扫描源文件的 LF 规范化 SHA-256，`--check` 保留快照基准并验证其为当前 HEAD 的祖先，源码增删或内容变化仍报漂移。

## 已执行结果

| 范围 | 命令（从对应仓库执行） | 实际结果 |
| --- | --- | --- |
| TXBoard Node 脚本回归 | `node --test scripts/tests/*.test.mjs` | 28/28 通过 |
| 原生 Schema 静态约束 | `node scripts/check-native-schema-only.mjs` | 通过；0 个静态违规，不是数据库运行验证 |
| Shared/Admin/User | `npm ci --no-audit --no-fund`；`npm run verify:web` | 类型检查通过；Shared 8、Admin 294、User 63，共 365 测试通过；两端构建及现有 bundle 预算通过 |
| Gateway / SDK | `npm ci --no-audit --no-fund`；`npm run check` | 类型检查通过；Gateway 390/391 通过、1 失败；SDK 32/32 通过；整个 check 非零退出 |
| Gateway / SDK 构建 | `npm run build` | 单独补跑构建通过；不能改写上一行失败结论 |
| TX-Node | `go test ./internal/controlplane ./internal/reporting` | controlplane 通过；reporting 持久化重启用例失败；命令非零退出 |
| Deploy shell 语法 | Git Bash 对 `install.sh/update.sh/txboard.sh/backup.sh/lib/*.sh/scripts/*.sh` 每个文件分别执行 `bash -n` | 通过，不代表执行了安装/升级或 Docker 场景 |
| Deploy 离线 SQL planner | `python scripts/test-xboard-plan.py` | 9/9 通过 |
| 文档相对路径 | 各仓 `node scripts/check-docs.mjs`（TXBoard/Gateway/Deploy） | 本批最终检查通过；TXBoard 现覆盖 contracts，外部 URL 与锚点不在检查范围 |
| 新静态盘点 | `node scripts/export-core-baseline.mjs --check` | 四仓盘点与保存快照一致；独立快照篡改负例被拒绝，未修改源码 |
| v2 草案一致性 | 单 H1、相对链接、JSON 示例解析、Node 示例规范化 SHA-256 核对 | 17 个 Markdown、143 个相对链接、4 个 JSON 示例通过；示例摘要一致，不代表协议运行时实现 |

React Router/服务端渲染告警、Vue 静态/动态重复导入告警是本轮现有测试/构建输出；不计为失败，也未据此改动产品代码。bundle 预算通过只是仓库已有静态指标，不代表真实 CWV、性能或跨浏览器验收。

## 两项 Windows 失败

1. Gateway `gw402-key-lifecycle.test.ts` 的 `loads a service with revoked kids and both key files present` 用 Windows 临时绝对路径创建 fixture，而运行时加载器要求以 `/` 开头的绝对 Docker secret 路径；失败信息为 `GATEWAY_HPKE_KEY_FILE must be an absolute Docker secret path`。没有放宽生产私钥路径校验来让测试通过。需在受支持 Linux 环境复跑，并另行决定测试 fixture 的跨平台支持策略。
2. Node `TestDurableSpoolReplaysUnacknowledgedPayloadAfterRestart` 在 fsync 临时目录时收到 Windows `Access is denied`。当前生产运行面向 Linux；该失败不能推断 Linux 通过，也不能删去目录 fsync 以绕开持久化门禁。需在 Linux 对本提交复跑 reporting/故障恢复测试。

## 未执行与 B0 退出缺口

- Laravel API 全量/身份/资金回归及 `route:list --json` 尚未执行；静态声明清单不得改称运行时注册表。
- MySQL 实际迁移、旧真实数据样本回填、逐行/聚合对账和备份恢复演练尚未执行。
- Gateway 真实 Core/Redis/CAPTCHA 联调、Docker 镜像/私钥挂载、浏览器加密、SC-v2 安全审查未执行。
- Node 的 Linux 持久化、双内核/Machine/WSS 整体稳定性及 v2 未实现协议未验收。
- Deploy 的 Docker 安装/升级/恢复、外部数据库和新版跨组件检查点未验收；Node Installer 本地缺失未核查。

因此本批交付为 **B0 静态盘点、架构/契约设计和可执行的本地基线**。B0 整体退出仍待上述核心注册表/API 与 Linux 基线证据；B1 可以定稿 Schema 与隔离验证设计，不能据此切换生产权威写入。[实施台账](implementation-plan.md) 跟踪这些门禁。
