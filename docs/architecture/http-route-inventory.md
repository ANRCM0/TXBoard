# HTTP route inventory — TXAPI-only development

> Effective branch: PR #182, 2026-10-10. The registered Laravel HTTP route list is authoritative; old plans do not imply live endpoints.

## 对外接入专题

- [外部主题 TXAPI 路由清单、示例与响应约定](../../contracts/http/theme-integration-current.md)
- [TXNode 原生 HTTP/WSS 集成指南及身份/流量协议](../../contracts/node-protocol/txnode-integration-current.md)
- [对外 HTTP/WS 总入口](../../contracts/http/external-adapter-current.md)

## Canonical route families

- `/txapi/public/*`, `/txapi/auth/*`, `/txapi/me/*`, `/txapi/orders/*`, `/txapi/billing/*` — public and user APIs.
- `/txapi/admin/{admin_path}/*` — admin Bearer, rotating path, RBAC and audit.
- `/txapi/node/v1/*` — typed authenticated Node/Machine HTTP; native WebSocket at `/txapi/node/v1/ws` runs through Workerman, not Laravel.
- `/txapi/agent/v1/*` — Agent runtime operations and pairing. The current handlers still reuse the existing Agent services and response body contract; future envelope changes need explicit tests.
- `/txapi/payment/webhook/{method}/{uuid}` — signed payment callback (GET/POST, raw provider ACK).
- `/txapi/integrations/telegram/webhook` — Telegram callback; server validates the configured digest.
- `/{subscribe_path}/{token}` — dynamic subscription delivery outside the HTTP TXAPI namespace.
- `/plugin/{code}/*` — plugin-owned routes, scoped to installed and enabled plugins.
- `/api/health` and `/txapi/health` — liveness probes; neither verifies database or payment services.

**Removed:** all `/api/v1/*` and `/api/v2/*` application route registration, plus Caddy's old `/ws` forwarding path. They must not be reintroduced by an extension or a future refactor. No V1/V2 compatibility is promised in development.

## CI snapshot and regression guard

The `p0-native-baseline` workflow runs `php api/artisan route:list --json`, then `scripts/export-route-catalog.mjs --check` and `scripts/p0-api-audit.mjs`. The generated `txboard-http-route-catalog` artifact lists HTTP method, URI, principal and middleware. Both guards fail if `api/v1/*` or `api/v2/*` appears again. The PHP contract suite independently asserts the absence of old route names.

Important limitations: Laravel's route registry does not cover Caddy/Workerman WebSocket routing or dynamically installed plugin routes. Run live HTTP/WS and payment/Telegram/MCP tests before release. [Native entrypoint details](../../contracts/http/external-adapter-current.md).
