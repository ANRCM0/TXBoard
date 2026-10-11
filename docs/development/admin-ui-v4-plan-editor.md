# Admin UI v4 — Guided Plan Create / Edit Drawer

Scope: existing Plan Editor opened from `/finance/plan`. Master tracker: [#233](https://github.com/ANRCM0/TXBoard/issues/233). Base is the post-merge main of #241–#244.

## Skills reviewed and applied
- **UI/UX Pro Max**: focus on compact information hierarchy, progressive disclosure, 44px input targets and clear feedback over decorative cards.
- **Vercel Web Interface Guidelines**: visible input labels, context-specific inline errors, focus the first invalid field, accessible accordion controls, unsaved changes warnings and native form submit.
- **Vercel React Best Practices**: avoid redundant query calls; keep a pure model for validation, drafting and payload mapping, and preserve existing mutation/error feedback.
- **Feature-Sliced Design**: only one page uses this form; keep domain model and CSS beside `PlanEditor`, no global CSS overrides or framework migration.

## What changed
- Replaces a long 920px center modal with a right drawer and fixed footer.
- First-level sections: Basics / Prices / Usage limits / Description / Existing-subscriber impact.
- Frequently used prices (monthly/quarterly/yearly) visible by default; other periods are accessible on demand and auto-open if existing data is stored.
- Limit fields collapse only when empty. Unknown legacy group or traffic-reset enum values are preserved by an explicit fallback option, not silently overwritten.
- Validates name, transfer, limits and each price inline, expands the relevant section and focuses the first invalid field on submit.
- Keeps existing *explicit* monthly-discount generation action; never applies discounts unless clicked; generated values remain unsaved until Save.
- Unsaved edits ask confirmation on close/Escape/backdrop. Save disables further submission while pending.
- Editing existing subscribers' settings remains unchecked unless explicitly opted in; the unchanged API `force_update` is only sent if deliberately selected.
- Original backend field names, numeric semantics, pricing periods, tags, content and payload endpoint retained.

## Automated and manual test gate
- `npm run verify:web`: TypeScript, Vitest, build and bundle budget.
- Model tests validate native payload mapping, boundary validation, legacy values and price generation.
- Component tests validate drawer/sections, legacy retention, errors/focus, dirty close and quick-price action.
- Manual Dev: 390/768/1024/1440, light/dark, zoom 100/125/150%; new/legacy plan; empty/invalid prices; Edit with and without force_update; dirty close from Cancel/Escape/backdrop; keyboard Tab/Enter/Space; long descriptions and preview; server errors; scrolling to Save.
- Do not treat CI as authenticated visual approval. Merge and Stable deployment require separate user instruction.

No backend, contract, payment, permission or database changes.
