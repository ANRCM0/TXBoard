# TXBoard 文档维护规范

**文档面向读者，Contract 面向协议，实现代码决定当前行为。** 本规范适用于主仓库 `README*.md`、`docs/`、`contracts/` 及新增开发文档；独立部署、节点和 Gateway 仓库有各自的运行时权威资料。

## 1. 单一权威来源

| 内容 | 权威维护处 |
| --- | --- |
| 项目定位、开始路径、其他文档入口 | 根目录 `README.md`、`docs/README.md` |
| 当前 HTTP 路由、身份及错误语义 | Laravel 路由注册器及 `contracts/http/` |
| Node/Machine HTTP 与 WSS | `contracts/node-protocol/`、TX-Node 当前实现 |
| Package/Bridge/Module 版本化协议 | `contracts/` |
| 生产安装、更新、备份与 XBoard 导入命令 | [TXBoard-Deploy](https://github.com/ANRCM0/TXBoard-Deploy) |
| TX-Node 运行时和安装器 | [TX-Node](https://github.com/ANRCM0/TX-Node) 与 TX-Node-Installer |
| 用户主题 API BFF / SDK | [TXBoard-Gateway](https://github.com/ANRCM0/TXBoard-Gateway) |
| 已退役 Agent/MCP | [范围决定](architecture/v2/admin-only-scope.md)，旧契约只保留历史入口 |

不要在多个 README 中维护可变的菜单编号、全部环境变量、接口完整列表或重复的发布快照。其他位置给出简要说明并链接到权威文档。

## 2. 文档状态

页面涉及不完全落地的能力时，应明确标注：

- **Current**：在当前代码中已经实现，不等于生产验收。
- **Experimental**：需开关、沙盒或限定条件。
- **Target / Draft**：未来方案/待评审，不得使用“已发布”“生产可用”。
- **Deprecated**：正在废弃或仅兼容历史行为，并说明替代入口。
- **Historical**：仅作历史过程记录，不作为当下操作指南。

具有时效性的文档可在标题下注明“适用版本/提交或更新日期”，但不要在每个 README 中复制一份需要持续同步的研发状态台账。

已退役功能只保留一个范围与数据保留说明入口，不为旧路径复制空占位页。删除失效操作指南时同步修正索引和引用；仍需追溯的验收结果并入现有历史台账，既有验证 JSON、数据库迁移及审计记录不因文档清理删除。原操作过程通过 Git 历史查询，不作为当前命令指南。

## 3. 编写格式

1. 每篇文档仅一个 H1，标题简明反映用途；小节按 H2/H3 递进。
2. 优先使用中文解释，并统一专有名词：Control Plane、TX-Node、TXAPI、Module、Agent Ops、MCP、Gateway。
3. 文件名采用小写 kebab-case，优先相对 Markdown 链接；跨仓指向完整 GitHub URL。
4. 代码块注明语言（`bash`、`json`、`php`、`text`）。占位凭据一律使用示例值或 `<placeholder>`，不得粘贴真实 Token。
5. 命令标注执行前提、部署版本、安全范围和结果；高风险数据库/支付/权限操作必须写出备份和恢复限制。
6. 不把文件夹存在、CI 成功、设计评审或 PR 合并误写成生产验收已完成。
7. 优先修改既有权威文档；移动文件时提供旧链接兼容入口或同步修复引用。

## 4. 变更联动检查

| 发生变化 | 应同时检查 |
| --- | --- |
| 新增/修改 HTTP 路由 | API 路由、身份测试、`contracts/http/`、相关用户/开发指南 |
| Node/Agent 协议变化 | TX-Node 消费端、版本化契约、权限与审批测试、使用指南 |
| 主题/插件包变化 | Package Schema、示例包、Module 兼容性、开发指南 |
| 部署参数/升级命令变化 | TXBoard-Deploy 的对应专题文档与 CLI 帮助 |
| 镜像标签与发布策略变化 | [发布通道](operations/image-release-channels.md) |
| 安全风险或恢复流程变化 | [安全基线](security/README.md)、[运维 Runbook](operations/release-staging-acceptance.md) |

## 5. 文档自动检查

从仓库根目录执行：

```bash
node scripts/check-docs.mjs
```

检查范围：根 README/贡献说明及 `docs/`、`contracts/` 内 Markdown 文件的**本仓库相对文件路径**；自动跳过外部链接与片段锚点。工具不访问网络，也**不验证 URL 可达性、动态生成的文档、标题锚点或代码示例执行结果**。如修改版本化契约，仍需人工检查协议语义、Schema 与示例一致性。

文档 CI 对新增/修改的本仓库链接执行基本检查，不替代技术审查。
