# 为 TXBoard 贡献代码与文档

欢迎以 Issue、文档更正和独立 PR 参与 TXBoard。修改前建议先阅读 [项目介绍](README.md)、[文档中心](docs/README.md) 与 [AGENTS.md](AGENTS.md) 的安全、架构及兼容性约束。

## 选择修改范围

- **文档修改**：优先修订已有页面及链接，遵循 [文档维护规范](docs/DOCUMENTATION_GUIDE.md)；不要在多个仓库复制同一套命令和状态。
- **代码修改**：一项架构或功能主题尽量单独提交 PR；先确定权威运行时和已版本化契约，再修改行为。
- **跨仓接口**：先更新或评审 [contracts/](contracts/README.md)，同步实际消费者、兼容规则及回归测试。不要单纯修改文档声称实现完成。

## 开发与验证

本地环境与命令参阅 [本地开发指南](docs/development/local-development.md)。

组件目录与 CI 入口见 [双仓布局](docs/development/repository-layout.md)。Gateway 的 npm 命令在 `gateway/` 执行，Go 命令在 `node/` 执行；不要将它们加入 Core 的 npm workspaces 或 Docker 构建上下文。安装器修改提交到 TXBoard-Deploy。

后端（在 `api/`）执行相应的 Composer / Artisan 测试；前端（根目录）执行：

```bash
npm ci --no-audit --no-fund
npm run verify:web
```

仅修改文档时，检查内部链接：

```bash
node scripts/check-docs.mjs
```

对路由、安全、资金或生产镜像相关改动，仍须补充必要的独立验证；CI 不能代替真实环境数据库恢复、支付沙盒及 Node 联调。

## 提交与 Pull Request

1. 描述 **为何修改、影响范围、权威实现/契约来源**。
2. 提供验证命令与结果；如果未执行，明确原因，不能填写“全部通过”。
3. 说明兼容性、权限或迁移影响，以及回滚/数据恢复条件（如相关）。
4. 不提交密钥、Token、日志中的凭据、生产数据库备份及用户个人数据。
5. 若更改公共 API/包协议，同步版本化契约和相关文档，不静默扩展既有 v1 边界。

## 许可证与第三方来源

后端包的 `api/composer.json` 目前声明 MIT。**仓库整体的 LICENSE、上游来源和第三方代码版权仍需要维护者逐项确认**；不要自行假定每个模块、历史文件及单独项目都适用相同授权。对许可修订请单独提出包含来源证据的 PR。

## 提醒

涉及生产迁移、账本、Node 运行时及 Agent 审批的 PR 必须遵循 [AGENTS.md](AGENTS.md) 和 [发布验收文档](docs/operations/release-staging-acceptance.md)，在未验证之前保持保守说明。
