# TXBoard Admin UI v2 — Batch 2: List and Empty States

This PR is stacked on Batch 1 (#229), so it inherits the Typography and Surface tokens and contains only changes from the second batch. Do not merge it before its base has been integrated (or retarget it after #229 merges).

## Presentation rules
- **Real rows:** retain white data tables. The content determines height; no minimum row count or artificial blank block.
- **Empty data or filtered results:** transparent container with a subtle dashed outline, a purposeful explanation and an actionable next step. Do not show empty table headers or zero-result pagination.
- **Loading and failures:** do not represent failed requests as empty results. Preserve stale populated data during background refresh.
- **Pagination:** omit when the result total fits on one page. Keep page navigation when the result set spans multiple pages or the current page is beyond 1.
- **Statuses:** ordinary metadata uses text. Important state cues remain visible without heavy badge stacks.

## Targeted pages
User Management, Order Management, Node Management and Machine Management. All existing query keys, mutations, dangerous-operation confirmation dialogs and machine credentials are untouched.

## Verification
Run `npm ci --no-audit --no-fund && npm run verify:web`. Review with zero rows / filtered zero / one row / many rows / background refetch / stale-error, both themes and 390/768/1024/1440px.
Confirm selection and bulk actions never include a hidden page, order payment actions remain guarded, and machine/node operations still open correctly.

Batch 3 remains responsible for dashboard, machine-drawer spacing and full browser screenshot acceptance.
