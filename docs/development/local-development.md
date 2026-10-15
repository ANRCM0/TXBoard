# TXBoard 本地开发与检查

> 本页列出可选开发与验证命令，按[批次验收流程](development-workflow.md)选择相关项；不要求每次修改运行全量测试。文档修改只检查差异与引用。命令清单不能代替生产发布验收。

## 代码分区

- `api/`：Laravel 12 / PHP 8.2 应用、路由、中间件、领域服务、迁移和测试。
- `web/admin/`：React + TypeScript 管理应用。
- `web/user/`：Vue + TypeScript 用户应用。
- `web/shared/`：共享前端模块。
- `gateway/`：独立 npm workspaces、用户 BFF 和 Theme SDK。
- `node/`：独立 Go module、节点运行时与内核管理。
- `contracts/`：跨仓 HTTP、Node、Plugin、Theme 和 Module 的版本化契约。

[AGENTS.md](../../AGENTS.md) 规定域边界、安全要求和优先的 Contract-first 流程。
三组件构建与镜像发布规则见 [双仓布局](repository-layout.md)。

## 后端测试

具备 Composer、PHP 扩展与相应环境依赖后运行：

```bash
cd api
composer install --no-interaction --prefer-dist
cp .env.example .env
php artisan key:generate
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test
```

这只是代码测试路径；部署要求在真实 MySQL 8.4 环境核验 Schema 与订单/余额数据。

## 前端验证

仓库根目录的 npm workspaces 管理 `web/shared`、`web/admin` 和 `web/user`：

```bash
npm ci --no-audit --no-fund
npm run verify:web
```

`verify:web` 包含 TypeScript 检查、现有 Admin/User 单元测试、构建与前端性能检查。新管理前端与用户前端均暂缓；后续新增开发在独立 `TX-F` 项目进行，只本地提交、不推送。当前 `verify:web` 继续验证兼容入口。Agent/MCP 已清理。

## 修改对外接口时

1. 对照 `api/routes/txapi.php`、`api/routes/web.php` 及实际路由注册器。
2. 修改或评审相关 [契约](../../contracts/README.md)，更新消费方、权限和错误语义测试。
3. 对 WebSocket、动态插件路由、外部 Gateway 分流，**不能仅以 Laravel `route:list` 为最终证明**。
4. 如果影响生产镜像，还需执行镜像构建、运行时健康检查和 [独立预发验收](../operations/release-staging-acceptance.md)。

提交前参阅 [CONTRIBUTING.md](../../CONTRIBUTING.md)。
