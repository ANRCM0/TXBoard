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

### 现存前端维护约束

旧 Admin UI v2/v3/v4 逐页计划和阶段完成清单已移除，原文可从 Git 历史查询。维护现存入口时仍须保留以下要求，不据此重启已延期的新前端：

- 复用现有 `design-tokens.css`、`form-system.css` 和页级样式，不叠加相互覆盖的全局主题。已知零值、未知、加载、错误、离线与停用必须区分，不能把请求失败显示成“无数据”或“全部正常”，状态不能仅靠颜色表达。
- 交互控件有可访问名称、正确的 dialog/listbox/switch 语义、可见焦点和键盘路径；保留弹窗焦点归还、滚动锁定与未保存确认。筛选列表不得对隐藏行重排；保存失败恢复原顺序并重新读取。
- Machine 凭据只在显式请求后显示，不预加载；离开安全面板、关闭弹窗或切换 Machine 时清除已显示内容，并使旧异步请求失效。保留现有 18 秒自动隐藏，秘密不写日志或诊断摘要。
- 运行时更新、令牌轮换、订阅 secret/流量重置、删除等敏感动作保留确认及服务端授权。按钮显隐不是权限；不能因视觉重构改变资金、归属、接口 payload 或审计语义。
- 编辑器遵循后端协议 Schema，不能在打开或保存时悄悄改写当前自定义配置。保留 JSON 校验、协议切换及放弃修改确认；不以预设伪造凭据，不在未经明确操作时自动生成或替换密钥。这是当前数据完整性约束，不是承诺兼容历史版本协议。

相关前端改动的类型/单测/构建在 CI 验收，本地遵循[轻量检查规则](development-workflow.md)。视觉与交互变更另需实际浏览器检查 light/dark、390/768/1024/1440px、缩放、键盘/读屏及 reduced-motion；CI 通过不能代替已认证页面的视觉验收，也不授权生产部署。

## 修改对外接口时

1. 对照 `api/routes/txapi.php`、`api/routes/web.php` 及实际路由注册器。
2. 修改或评审相关 [契约](../../contracts/README.md)，更新消费方、权限和错误语义测试。
3. 对 WebSocket、动态插件路由、外部 Gateway 分流，**不能仅以 Laravel `route:list` 为最终证明**。
4. 如果影响生产镜像，还需执行镜像构建、运行时健康检查和 [独立预发验收](../operations/release-staging-acceptance.md)。

提交前参阅 [CONTRIBUTING.md](../../CONTRIBUTING.md)。
