# Admin UI v3 · Page 01 — Dashboard

Parent foundation: [Phase 0 PR #234](https://github.com/ANRCM0/TXBoard/pull/234). Master roadmap: [#233](https://github.com/ANRCM0/TXBoard/issues/233).

## Visual diagnosis → change → acceptance

| Problem observed in code | Page-specific improvement | Browser acceptance |
|---|---|---|
| Six separately elevated KPI cards for a small amount of data | One six-column responsive KPI surface, with dividers and predictable 3-column/2-column fallbacks | At 390/768/1024/1440px, no orphan tiles, cramped numbers or clipping |
| Income plot dates embedded in a 1000px SVG viewbox shrink on mobile | New IncomeTrendChart with HTML axis labels and responsive plot | Date and ¥ labels remain readable at 125/150% zoom; zero-only period has clear empty state |
| Filled SVG area path did not reliably close to baseline | Deterministic `incomeAreaPath` helper with unit tests | Filled area is below the line, not an arbitrary polygon |
| Missing income value treated as zero before normalization | Reject missing/invalid paid totals, preserve reported zero | Empty/partial telemetry is not confused with real 0 |
| Ranking traffic always converted to GB, making MB/KB look like 0.00 GB | Relative B/KB/MB/GB/TB formatter, with null/invalid retaining — | Correct values for B/KB/MB/GB/zero/unknown; long names truncate without pushing value out |
| Dashboard styling spread across oversized v2 card overrides | Consolidated Dashboard-specific styles in existing `admin-monitor-density.css` | Same density rhythm across metrics, ranking and queue cards |

## Functional behavior preserved
- Exception-first cards and clear/unknown attention behavior.
- Independent date range selection and persistence for income, user and node statistics.
- Pending approvals, queue health, failure-detail modal, and dashboard link destinations.
- No backend/API/security/permission/deployment change. No other business page modified.

## Acceptance gate
- [ ] Full `verify:web` and CI on exact SHA
- [ ] GitHub docs and Docker smoke CI on exact SHA
- [ ] Browser screenshot before/after: 390, 768, 1024, 1440px; light/dark; 100%, 125%, 150% zoom
- [ ] Data states: initial loading, all-zero attention, partial unknown, positive attention, 0/1/many rank entries, empty/positive income period, API errors and stale data
- [ ] Interactions: period picker keyboard navigation; links; queue refresh, error list and modal; no nested overflow
- [ ] Product visual approval and only then mark page `/` completed in #233

**This PR is stacked on Phase 0** because that code has not yet passed authenticated browser acceptance. Do not mark Dashboard done or merge out of order. Once #234 merges, retarget this PR to main, re-run main-based checks and perform browser QA.
