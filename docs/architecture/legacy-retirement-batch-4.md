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

## LR-12 / user-visible nodes

- `GET /txapi/me/nodes` uses the existing permission-aware `UserService::isAvailable` and `ServerService::getAvailableServers` to retain visibility and online/rate calculations.
- Its explicit response whitelist hides generated node passwords, server keys, private hosts, TLS material and other configuration data. Official Vue node page moves off `/api/v1/user/server/fetch` without changing how the list is displayed.
- Internal tests require 401 without Sanctum, empty list without a subscription, and no secret keys in serialized node data.

## LR-13 / V1 official-user compatibility route retirement

User decision: **external consumers adapt themselves and do not gate TXBoard internal route cleanup**. Remove V1 routes after their official Vue callers use TXAPI. Keep V2 Admin Passport and dynamic Admin routes, Telegram Bot webhook, payment callback, and subscription configuration URL. Legacy controllers/services are retained where V2 or domain internals still reference them; this PR removes route registrations only, not database tables.

Removed V1 official-user route patterns (after verifying internal baseline consumers):

`/resetSecurity`, `/changePassword`, `/update`, `/getSubscribe`, `/getStat`, `/checkLogin`, `/getQuickLoginUrl`, `/getActiveSession`, `/removeActiveSession`, `/order/detail`, `/order/getPaymentMethod`, `/order/cancel`, `/plan/fetch`, `/notice/fetch`, `/ticket/reply`, `/ticket/close`, `/ticket/save`, `/ticket/fetch`, `/coupon/check`, `/knowledge/fetch`, `/stat/getTrafficLog`, `/invite/details`.

Removed V1 passport bridge: `/auth/token2Login`, `/auth/forget`, `/auth/getQuickLoginUrl`, `/auth/loginWithMailLink`. V1 guest configuration and email-code issuance remain because internal theme, shared-recovery, and feature-switch regressions depend on them. Retained legacy V1 Passport login/register, invite, gift cards, commission transfer/withdraw, Stripe public-key bridge and all critical payment/subscription routes until the remaining TXBoard-internal callers migrate. V2 login and guest config remain registered for React Admin.

**Internal dependency exception:** retain old V1 order save/checkout/check/fetch, user server/fetch, and user knowledge/getCategory during this batch because P0/MySQL synthetic journeys, audit scripts, read baselines and feature tests still use them. These tests themselves must migrate to native before any later deletion. External client demand is not a blocker.
