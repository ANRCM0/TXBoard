# Admin UI v4 · User detail page review and implementation

Page: `/user/:userId`. Tracking issue: [#233](https://github.com/ANRCM0/TXBoard/issues/233). This work is separate from open plan-list #241, user-list #242 and node picker #243.

## Rules applied from frontend Skills

- **UI/UX Pro Max**: reduce empty decorative cards; build compact hierarchy of high-importance account metrics, leave secondary detail readable, and preserve mobile/touch flows.
- **Vercel Web Interface Guidelines**: labeled semantic account definition list, accessible progress meter, scoped table names/column headings, error retry, keyboard action buttons and descriptive failure messaging.
- **React Best Practices**: preserve independent TanStack Query calls, stale data handling and existing mutation invalidation; avoid new derived global state or unnecessary dependencies.
- **Feature-Sliced Design**: retain single-page presentation components and scoped stylesheet in `pages/users`; remove superseded page-only global CSS instead of appending global overrides.

## Code behavior

- Four independent summary cards become one compact accessible overview with four definition-list metrics; subtitles and values remain visible.
- Current plan, remaining/consumed traffic, balance/commission, next reset, and per-user permission/limit metadata remain available.
- The account section keeps explicit confirmation for reset of subscription secret and traffic usage.
- Orders, tickets, and reset-history tables render **only if nonempty**; otherwise show concise section-local status text with links to full management pages.
- Initial loading and errors use existing `QueryFeedback` with retry; a failed request does not incorrectly display a no-data message.
- Preserve order detail modal and ticket deep links; improve table column header semantics and horizontal scrolling.
- Avoid truncated long metadata; improve narrow-screen layout.
- Remove the obsolete user-detail selectors from the global stylesheet; new `UserDetailPage.css` has page-scoped rules only.
- Reject unsafe numeric route identifiers before requesting protected resources.

## Regression tests
- Compact four-metric overview and complete account metadata.
- Three section-local empty activity messages without empty tables.
- Existing real activity records and order-detail action.
- Sensitive operations require confirmation before invoking mutations.
- Failed activity request exposes retry instead of an empty state.
- Invalid route identifier does not initiate resource requests.

## Browser acceptance (not automated / not done)
- 390, 768, 1024, 1440 px, light/dark, browser zoom 100/125/150%.
- Zero, one, many orders/tickets/reset-history rows; long email/notes; no traffic limit.
- Error, stale refetch, slow data, empty and invalid route states.
- Keyboard focus, tab order, open order details, copy subscription and confirm resets.
- Authenticated Dev screenshots and human UX approval before checking the route complete on #233.

No API, backend, finance, permissions, database or production deployment changes. Keep Draft until visual acceptance.
