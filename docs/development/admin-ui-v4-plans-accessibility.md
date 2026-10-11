# Admin UI follow-up · Accessible plan management

Related: [UI v3 36-page roadmap #233](https://github.com/ANRCM0/TXBoard/issues/233).

## Scope
A single page: `/finance/plan`, plus reusable, opt-in UI primitives. This is an implementation follow-up to the static UX and code audit (2026-10-11).

## Changes
- Introduce `IconAction`: explicit button type and mandatory accessible name, preserving the existing icon-button visual class.
- Introduce `SettingSwitch`: accessible `role="switch"`, meaningful labels and checked/disabled states, 44px minimum hit width and reduced-motion support.
- Retain drag-to-sort and add always-visible Up/Down buttons as pointer and keyboard alternatives.
- Disable reordering when the list is filtered or a refresh/save is pending. Drag and buttons use the same pure reorder helpers.
- Optimistically display a reordered list, restore the prior order when the save fails, then refetch.
- Replace isolated tag/price pills with compact secondary text without hiding pricing periods.
- Remove the empty table frame for initial zero-data and filtered-zero states; distinct feedback for loading or API failure.
- Keep page-local CSS in `PlansPage.css`, do not append another global override layer.

## Safety and compatibility
- No API endpoint, payload, backend model, permission, finance, database or deployment changes.
- Existing delete confirmation, edit/create modal and show/sell/renew mutations are preserved.
- A filtered list cannot reorder hidden rows.
- No production/Stable deployment. `main` merge may trigger Dev image publishing; deploy only after explicit approval.

## Verification
Automated:
- `npm run verify:web` (type checks, Vitest, web build, compressed bundle budgets).
- Tests: plan ordering (boundaries, immutable moves, drag semantics), page table/empty states, accessible switch and icon naming, disabled ordering while filtered, persisted full sequence.

Browser acceptance **still required before marking this page complete in #233**:
- 390 / 768 / 1024 / 1440 px; light/dark; 100/125/150% zoom.
- 0/1/many plans, no matching search, loading, failed fetch, sort-save failure.
- Full keyboard navigation: tab to Up/Down and switches, Space/Enter interaction, focus visibility, drag-and-drop.
- Responsive horizontal table scrolling and no clipped focus, buttons or content.
- Create/edit/delete confirmations and accounting API payload preservation.
