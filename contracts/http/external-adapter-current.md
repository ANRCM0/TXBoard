# TXBoard TXAPI — Current External HTTP/WS Entry Points

> **CURRENT SOURCE STATE, 2026-10-10, main (through PR #201).** Development mode: there is **no backwards compatibility** for `/api/v1/*` or `/api/v2/*`. This file describes the Laravel and runtime entry points, not verified production integrations.

## 外部开发者快速入口

- **[外部主题完整用户路由及接入示例](theme-integration-current.md)**：以 `/txapi` 为基础的公开、注册登录、钱包/订单/充值、工单、订阅与安全规范。
- **[TXNode HTTP/WS 适配手册](../node-protocol/txnode-integration-current.md)**：按节点/机器身份、HTTP 接口、WS 帧与 202 异步结算协议实现。
- [HTTP 全量注册表、变更守卫与 CI 工件](../../docs/architecture/http-route-inventory.md)。本文件列出接口族和授权边界，不取代这两份开发指南。

## Protocol ownership

| Operation | Path | Identity / security |
| --- | --- | --- |
| Public metadata and plans | `/txapi/public/*`, `/txapi/plans` | Anonymous, DTO whitelist |
| User authentication | `/txapi/auth/*` | CAPTCHA and rate limits as configured |
| User plans, orders, billing and account | `/txapi/me/*`, `/txapi/orders/*`, `/txapi/billing/*` | User Sanctum Bearer and ownership checks |
| Administration | `/txapi/admin/{admin_path}/*` | Admin Bearer, secure path, RBAC and audit |
| Node/Machine | `/txapi/node/v1/*` | Node/Machine Bearer plus scoped identity headers |
| Node websocket | `wss://<host>/txapi/node/v1/ws` | Authenticated Workerman upgrade (feature-gated) |
| Agent runtime | `/txapi/agent/v1/*` | Agent Bearer, target scopes, abilities and approval; pairing is separately rate-limited |
| Payment callback | `GET/POST /txapi/payment/webhook/{method}/{uuid}` | Provider-specific signed request and raw ACK; no Bearer |
| Telegram callback | `POST /txapi/integrations/telegram/webhook` | Configured digest, independent of Admin Bearer |
| Subscription delivery | `GET /{subscribe_path}/{token}` | Subscription URL credential and client format negotiation |
| Extension APIs | `/plugin/{code}/*` | Plugin-specific registration/authorization |

The legacy `/ws`, `/api/v1/*` and `/api/v2/*` endpoints have been removed. **Any calling Node/MCP/Telegram/payment/test adapter using those URLs must be updated**. In particular, payments always generate new native callback URLs. Existing providers must register or regenerate their callback configuration: historic V1 URLs will no longer work.

## Native response semantics

Native user, admin and Node operations return a JSON envelope with `data`, optional `meta` and `request_id`; errors use `error.code` and an HTTP error code. Signed payment callbacks return provider-required raw text, not a JSON envelope. The Agent runtime is currently routed under TXAPI but intentionally reuses Agent controller/service response semantics; do not assume its body has already been harmonized to the native envelope until that work is implemented and tested.

### Node/Machine v1

`POST /txapi/node/v1/handshake`, `GET /config`, `GET /users`, `POST /report`, `GET /machine/nodes`, `POST /machine/status`. Requests use `Authorization: Bearer <credential>` and identity headers (`X-TX-Node-ID` and/or `X-TX-Machine-ID`). Node traffic `202 queued` is **not** an irrevocable ledger settlement receipt. Stable batch ID, durable settlement receipt and reconciliation are still a production acceptance requirement.

### Agent v1

`POST /txapi/agent/v1/pairings/redeem` uses a one-time pairing code. Authenticated Agent runtime operations include `/whoami`, `/nodes`, `/fleet/health`, `/support/tickets` and action requests. Permissions are defined by an issued Agent token's abilities and target scopes; administrator approvals remain on separate `/txapi/admin/{admin_path}/agents/*` endpoints.

### Payment and Telegram

Payment checkout URLs use only `/txapi/payment/webhook/{method}/{uuid}`; signature checks, payment state and ledger authority stay in Laravel. Telegram Webhook registration uses `/txapi/integrations/telegram/webhook` and does not disclose its digest to browser responses. On changing an existing installation, re-register the bot Webhook URL.

## Validation

CI route inventory verifies actual registered Laravel paths, HTTP methods and critical middleware. It does **not** prove WebSocket, third-party provider, Caddy/1Panel proxy or plugin dynamic registration. Follow [release acceptance](../../docs/operations/release-staging-acceptance.md) before deployment.
