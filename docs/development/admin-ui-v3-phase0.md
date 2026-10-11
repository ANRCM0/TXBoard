# Admin UI v3 Phase 0 — shell and shared component baseline

Master tracker: [Issue #233](https://github.com/ANRCM0/TXBoard/issues/233).
Base commit: `e8e45946b6d80ff91c9100c34e6d739119e5790a` (v2 integrated).

## Delivered in this foundation PR
- Consolidate page gutters, max width, compact sidebar width, heading and form control size into existing `design-tokens.css`; do not create another global override CSS layer.
- Refine top bar/page header alignment and navigation typography; add a `SectionHeader` for subsequent single-page migrations.
- Add a skip-to-main link and keyboard-visible focus styling.
- Remove manual Sidebar Space-key-up toggle. Native buttons already activate on Space/Enter; the old handler could toggle twice.
- Make `DataTable` omit its empty table frame on initial loading or error, preserve real rows on background errors, and accept an accessible region label.
- Lower legacy server empty-state height without changing the explicitly scoped v2 list styles.

## Existing stylesheets and ownership
- `styles.css`: historical shell + legacy page styles, ~3000 lines; legacy first line remains minified and contains superseded selectors.
- `form-system.css`: form, editor and config widgets. Per-page variants stay isolated.
- `admin-experience.css`: generic empty-state appearance; scoped `admin-density.css`, `admin-list-density.css`, `admin-monitor-density.css` remain untouched.
- **Important:** no more append-only global density overrides. When a page is refined, remove or consolidate only that page's obsolete selectors in the same PR. Avoid mass deletion without authenticated browser screenshots.

## One-page implementation contract
Audit at 390/768/1024/1440px in light and dark, at 100/125/150% zoom; capture the old and new states using the same data. Review empty/one/many/filter/loading/error, mobile menus, charts/long text/overlays and keyboard navigation. Run `npm run verify:web` and PR CI; browser screenshots + manual UI review must be attached before checking a page as complete in #233.

## Next
Phase 0 is foundation only. No business page is marked complete. First single-page v3 target: Dashboard (`/`), then Node Management (`/server/manage`). The existing [Issue #232](https://github.com/ANRCM0/TXBoard/issues/232) still tracks v2 browser acceptance.

CI does not provide authenticated visual acceptance. No backend/API/security/deployment changes in this PR; merging to main may automatically publish the Dev image.
