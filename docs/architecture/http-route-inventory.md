# TXBoard HTTP 路由清单

Laravel HTTP 路由由 `api/app/Providers/RouteServiceProvider.php` 注册，具体 URI 以 `api/routes/txapi.php`、`api/routes/web.php` 为准。

| 路由组 | 身份 |
| --- | --- |
| `/txapi/public/*`、`GET /txapi/plans` | 匿名 |
| `/txapi/auth/*` | 登录/注册匿名，部分会话操作需用户 Bearer |
| `/txapi/me/*`、`/txapi/orders/*`、`/txapi/billing/*`、`/txapi/tickets/*` | User Bearer |
| `/txapi/admin/{admin_path}/*` | Admin Bearer、动态管理路径、RBAC |
| `/txapi/node/v1/*` | Node/Machine Bearer 与身份头 |
| `/txapi/agent/v1/*` | Agent Bearer/能力范围（pairing 独立） |
| `/txapi/payment/webhook/{method}/{uuid}` | 支付商签名 |
| `/txapi/integrations/telegram/webhook` | Telegram 验证 |
| `/{subscribe_path}/{token}` | 私密订阅链接 |
| `/txapi/health`、`/api/health` | 无鉴权应用存活探针 |

## 生成全量路由列表

CI 工作流 `p0-native-baseline` 会执行 Laravel route-list 与 `scripts/export-route-catalog.mjs --check`。本地可执行：

```bash
cd api && php artisan route:list --json > ../route-list.json
cd .. && node scripts/export-route-catalog.mjs --routes route-list.json --json artifacts/routes.json --markdown artifacts/routes.md --check
```

此快照不包含 Workerman `wss://<host>/txapi/node/v1/ws`、反向代理配置和插件动态注册路由；这些须在实际运行环境核实。

[外部主题接口](../../contracts/http/theme-integration-current.md) · [TXNode 接口](../../contracts/node-protocol/txnode-integration-current.md)
