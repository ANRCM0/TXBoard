# HTTP 路由审计与目录导出（CURRENT）

> 当前代码权威来源是 **Laravel 已注册的运行时 HTTP route:list**；仓库中的规划文档不自动构成可访问的接口。参见 [外部服务适配手册](../../contracts/http/external-adapter-current.md)。

## CI 自动导出

`p0-native-baseline` workflow 在启动 Laravel 并导出 `php api/artisan route:list --json` 后，执行：

```bash
node --test scripts/tests/export-route-catalog.test.mjs
node scripts/export-route-catalog.mjs \
  --routes "$RUNNER_TEMP/p0-routes.json" \
  --json artifacts/route-catalog/http-routes.json \
  --markdown artifacts/route-catalog/http-routes.md \
  --check
```

CI 工件（Actions Artifact）名：`txboard-http-route-catalog`。其 Markdown 是**全量真实注册表**：每条 GET/POST/PUT/PATCH/DELETE 等 HTTP method、path、作用域分类、身份和实际 middleware，包含 V1/V2 兼容路由和 web routes。JSON 是可读机器快照，供 Gateway、Node、Agent 和发布工具在固定 SHA 的构建记录里使用。

本地需要 PHP 依赖和 Laravel 可引导的测试环境：

```bash
cd api
php artisan route:list --json > ../route-list.json
cd ..
node scripts/export-route-catalog.mjs --routes route-list.json \
  --json /tmp/txboard-http-routes.json \
  --markdown /tmp/txboard-http-routes.md --check
```

`--check` 仅做**明确的关键合同守卫**：TXAPI 健康/登录/管理员/Node HTTP 路由存在；V2 Agent、旧支付/订阅/Telegram 外部入口仍注册；管理员接口受 `admin.path` 与 `admin` 保护；退役 V2 管理路径不可复活。它不是对所有接口的 OpenAPI 请求字段、Webhook 签名或动态插件运行时的全面验证。

## 外部服务不得假设 route:list 覆盖的内容

- WebSocket `/txapi/node/v1/ws` 与老 `/ws` 是 **Workerman + Caddy** 路由，需真实 Upgrade 测试，不在 Laravel HTTP 清单中。
- Gateway BFF `/txapi/bff/v1/*` 在**另一个仓库/容器**实现；本仓路由表不包含它不代表外部组件运行状态。
- 插件注册的 `/plugin/{code}/*` 依部署安装及启用状态而异。官方 CI 无对应第三方包的运行时清单不能替代预发部署清单。
- 订阅 `/{subscribe_path}/{token}` 可能随设置变化；Laravel web route 注册表及 Caddy 环境变量都必须检查，不能把默认 `/s` 当不可变合同。
- 路由存在不代表 TLS、支付 Provider、Node/Agent 对接、Redis/Horizon 就绪。只有 [预发验收手册](../operations/release-staging-acceptance.md) 可以提供实证。
- 清单包含 URI 中的花括号**占位符**，并不包含客户端 Token。不要把实际 URL/凭据或真实订单号附在公开 Issues/CI Artifact。

## 当前代码目录边界

| Scope | 真实代码位置 | 客户端归属 |
|---|---|---|
| TXAPI 本体（公共、用户、管理、Node、支付回调） | `api/routes/txapi.php` | Laravel |
| V1/V2 兼容（Agent、Node、Client、Passport 等） | `api/app/Http/Routes/V1/*`, `V2/*` | Laravel 旧协议 |
| Web 前端与动态订阅路径 | `api/routes/web.php` | Laravel 和 Caddy |
| WS proxy 与静态插件资源 | `api/.docker/caddy/Caddyfile` + Workerman | 部署层 |
| 对外标准/业务字段与错误语义 | `contracts/node-protocol/node-native-v1.md`, `contracts/http/agent-ops-v1.md`, `external-adapter-current.md` | 双端协议 |
| 仅规划中的 Agent Native 和 BFF | `contracts/http/txapi-target-v1.md`, `txapi-bff-target-v1.md` | **TARGET，非 CURRENT** |

**更改路由时**：先改后端和契约测试 → 更新 CURRENT 接入手册 → CI 导出并检查新快照 → 外部调用者验收 → 再处理旧协议退役。不要把 Code SHA、镜像 digest、staging PASS 与 production PASS 混成一项。
