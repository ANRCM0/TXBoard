# Legacy Retirement Batch 4 — TXBoard-internal consumers only

> 2026-10-09. Scope decision: TXBoard backend, Vue User, and React Admin determine the internal-retirement gate. Gateway, Node and third-party clients are responsible for their own adaptation. They no longer block internal cleanup.

## LR-10 / dashboard

- Introduce authenticated `GET /txapi/me/subscription`: reads current user's plan, counters, reset timing and generates a private subscribe URL; no raw token field. The Vue dashboard consumes native bytes and ISO dates through a small presentation adapter.
- Introduce authenticated `GET /txapi/me/dashboard-stats`: owner-scoped unpaid orders, open tickets and invited users. Vue maps named counters to its existing three dashboard slots.
- Keep public `/{subscribe_path}/{token}` subscription **configuration delivery**: this is a separate active native user-facing function, not a disposable old API detail. Preserving the URL format is a functional requirement.
- No financial mutation, privilege update or schema change. Legacy V1 endpoint removal can follow an internal call graph check; production zero-call evidence from external consumers is **not** a blocker by user decision.

## Telegram integration finding

The V1 `guest/telegram/webhook` handles Bot commands and join requests, **not** signed Telegram Login Widget assertions. Vue's widget sends login data only to the optional `telegram_login_endpoint` supplied by configuration/plugin; native verification must be implemented from a separately verified Telegram login contract. Do not treat Bot webhook access-token hashes as proof of a user's Telegram identity.

## Follow-up

User public/user common configuration, eligible node list, invitations/withdrawals and gift card commands are internal V1 consumers to migrate by domain. React Admin V2 path remains needed until its API client is migrated. Removing routes is authorized only after TXBoard-internal callers and tests have been switched.

## LR-11 / site settings

- The two Vue configuration consumers move to `GET /txapi/public/site-config` and `GET /txapi/me/site-config`; V1 Guest/User configs and TXAPI share the same `SiteConfigService` projections, preserving theme hooks and feature flags without copying configuration logic.
- Existing `GET /txapi/public/config` remains a minimal system metadata contract. Sensitive payment and Bot signing secrets must not be exposed to either public or user site config.
- External consumers adapt independently and do not block TXBoard-internal API removal decisions.
