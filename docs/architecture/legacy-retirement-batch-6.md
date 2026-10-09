# Legacy Retirement Batch 6 — TXBoard-internal V1 user route pruning

> 2026-10-09. Changes limited to TXBoard Laravel, Vue User, React Admin and internal CI callers. External consumers migrate independently. No payment-provider, TX-Node, Gateway or third-party adaptations are performed here.

## Native ownership

- Native Auth: POST /txapi/auth/login, user sessions and password lifecycle.
- Native Orders: POST /txapi/orders, POST /txapi/orders/{tradeNo}/checkout and /cancel, GET /txapi/orders and /{tradeNo}.
- Native finance: commission transfer, withdrawals, invite codes, gift card redemption/history and Stripe publishable-key lookup (Batch 5).
- Native node list: GET /txapi/me/nodes, with ownership and secret-field projections.
- The P0 purchase→provider callback→native node→traffic ledger regression and synthetic read baseline now run through TXAPI.

## Deleted V1 user route registrations

Account: /info, /transfer.

Commerce: /order/save, /order/checkout, /order/check, /order/fetch.

Invitation/withdrawal: /invite/save, /invite/fetch, /ticket/withdraw.

Node listing: /server/fetch.

Gift cards: /gift-card/check, /gift-card/redeem, /gift-card/history, /gift-card/detail, /gift-card/types.

Payment UI bridge: /comm/getStripePublicKey.

All above paths are relative to /api/v1/user. This PR changes route registrations, not database table names and not reusable domain services or old controller files still reachable through other internal code.

## Still active and out of this deletion batch

- V1 user common site configuration, knowledge categories and Telegram bot info remain until remaining in-repo tests and callers migrate.
- V1 guest payment webhook, Telegram Bot webhook, old subscription URL and V1 Server protocols remain explicitly guarded; removing them requires different protocol/operational evidence.
- V2 React Admin and agent/node entries remain until their TXBoard-owned clients and permissions migrate. External adaptation is not a prerequisite for beginning that internal implementation.

## Acceptance gate

- Removed paths must be absent from route:list and preserved paths must remain registered.
- MySQL and SQLite order/financial regression, P0 native purchase/traffic baseline, web builds and image Caddy validation must pass.
- Native checkout rejects negative orders; payment/traffic balances and billing idempotence remain intact.
- Route deletion alone is **not** controller/service dead-code proof. A later deletion requires call-graph evidence and full back-end CI.

See [Core release closeout](core-release-closeout.md) for the remaining Admin V2, schema, stability and deferred external integration work.
