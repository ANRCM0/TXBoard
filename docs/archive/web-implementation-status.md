> Archived implementation snapshot. Kept for project history; it is not the current roadmap.

# Implementation Status

## Phase 1 — completed

- [x] React 18 + TypeScript + Vite admin project
- [x] Router and auth guard
- [x] Axios client for `/api/v2/<instance-id>`
- [x] Bearer token injection and 401/403 handling
- [x] Responsive admin layout and dark mode
- [x] Nodes / Machines / Groups / Routes
- [x] Plans / Orders
- [x] Plugin list/actions/details route

## Phase 2 — functionally complete

- [x] Source-structure visual parity pass
- [ ] Pixel-match UI using screenshots/Figma
- [x] Exact core config schemas
- [x] Email / Telegram / APP / Theme / Payment
- [x] Knowledge / Notice
- [x] Machine charts / token reset / node binding
- [x] Full plan pricing / Markdown / drag sort
- [x] Full order pagination / filters / details / commission
- [x] Schema-driven plugin CRUD / settings / admin menus / dynamic sidebar
- [x] Payment manager: dynamic gateway form / CRUD / enable / fees / sort
- [x] GitHub Actions admin typecheck/build

## Phase 3 — admin business modules completed

- [x] User management core
- [x] Tickets
- [x] Gift cards
- [x] Coupons
- [x] Traffic reset
- [x] Audit logs
- [x] Dashboard statistics / traffic rankings

## Phase 4 — Vue user frontend core completed

- [x] Vue 3 + TypeScript + Vite + Pinia
- [x] Hash Router
- [x] Current Xboard `localStorage["xboard_auth_data"]` auth contract
- [x] `/api/v1` API client and session handling
- [x] Login / Register / Forgot Password
- [x] Responsive user layout + dark mode
- [x] Dashboard + subscription overview + notices
- [x] Plans + create order
- [x] Orders + checkout + cancel
- [x] Nodes
- [x] Traffic logs
- [x] Knowledge base
- [x] Tickets + create / reply / close
- [x] Invite codes + commission transfer
- [x] Gift card check / redeem / history
- [x] Profile + password / sessions / quick login / security reset
- [x] Independent User Vue CI: typecheck + production build

## Phase 5 — parity hardening

- [x] CAPTCHA: reCAPTCHA v2 / v3 / Turnstile
- [ ] Telegram login (plugin-dependent; requires redirect URL or `telegram_login_endpoint`)
- [x] Mail-link login
- [x] token2Login via `?verify=`
- [x] Email whitelist suffix selector
- [x] One-click client import + protocol filters
- [x] Stripe Card Element checkout
- [x] Commission transfer + withdrawal ticket flow
- [x] Gift-card usage detail
- [x] Cached user comm config + strict feature gates
- [x] Full current-page zh-CN / en-US language switcher
- [x] Multi-level invite commission rate UI

## Phase 6 — visual parity pass

- [x] User shell: 220px sidebar / 60px header / flat Xboard tokens
- [x] User auth page rebuilt to original single-card structure
- [x] User dashboard: alerts / banner / subscription / shortcuts
- [x] Plans + plan detail visual structure
- [x] Orders + payment detail + dark total summary
- [x] Node list / traffic table / knowledge collapse
- [x] Tickets / Invite / Gift Card / Profile visual structure
- [x] Admin shell: 256px light sidebar / 64px toolbar / shadcn slate tokens
- [x] Admin collapsible navigation + functional command menu
- [x] Admin dashboard visual hierarchy
- [x] Admin sign-in visual structure
- [ ] Runtime screenshot-by-screenshot pixel calibration
- [ ] Fine visual calibration for individual admin business modules

## Phase 7 — deployment preview

- [x] Static Preview Mode isolated from production behavior
- [x] Admin preview uses Hash Router and auth bypass only in preview builds
- [x] User preview bypasses auth / feature guards only in preview builds
- [x] GitHub Pages static visual preview workflow
- [x] Admin + User preview builds verified in GitHub Actions
- [x] Enable repository Pages source = GitHub Actions
- [x] GitHub Pages visual preview deployed: https://paimoncai.github.io/TXBoard/
- [ ] Connect preview/production deployment to a real Xboard backend

## Remaining parity work

- [ ] Pixel-perfect visual parity with original Xboard screenshots
- [x] Plan detail page / order detail page
- [x] Payment method selection / QR checkout / redirect checkout / status polling
- [x] Stripe card form + Stripe.js token checkout
- [x] Coupon input/check in plan checkout
- [x] Client import modal / one-click subscription import
- [ ] Telegram login (plugin-dependent) / CAPTCHA / mail-link login / token2Login
- [x] User-side withdrawal flow
- [x] Advanced invite distribution UI
- [x] Gift-card detail view
- [x] Full zh-CN / en-US i18n across current user pages and shared user components
- [ ] Restore original extended language set: ja-JP / ko-KR / vi-VN / zh-TW / fa-IR / ru-RU — deferred by current project scope
- [x] Feature-gate parity and cached user comm config behavior
