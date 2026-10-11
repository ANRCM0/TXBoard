# Admin UI v2 · Batch 3 — dashboard, machine drawer, quality gate

Third stacked PR, based on Batch 2 (#230), which in turn is based on Batch 1 (#229).

## Changes
- Dashboard: 6 primary KPIs use a denser responsive layout; exception-first action cards remain prominent and clickable. Income trend, two traffic ranks and queue-monitoring are retained. Empty rank/trend cards no longer reserve unnecessarily tall areas.
- Machine drawer: keep the 4 accessible tabs, but reduce nonessential vertical spacing. Overview now includes short runtime/node summaries that navigate to the existing tabs; opening Overview still does **not** fetch the node list.
- Runtime actions: compact channel selector and update button grouped together without changing mutation, conditions or risk confirmation.
- Node tab: compact no-node guidance and plain protocol metadata rather than a badge.
- Telemetry: chart height 248px (was 320px), legend localized, tooltip easier to read. Missing/null metrics remain unknown instead of displaying a synthetic 0%.
- No changes to backend, node updater, secrets lifetime, token operations, permissions or deployment settings.

## Automated regression and integration requirements
- First/second/third batch combination: `npm ci --no-audit --no-fund && npm run verify:web`.
- GitHub Docs and Docker smoke checks on **the identical head commit**.
- Dashboard tests for compact grid and surviving panels.
- Machine-drawer tests for quick navigation, lazy node requests, keyboard navigation, runtime update compatibility and credential isolation.
- Telemetry test rejects zero-filling missing CPU/memory/disk data; valid zero stays zero.

## Browser visual acceptance (must be verified before merging)
Capture matching screenshots with actual dashboard, machine details and lists:
- widths 390 / 768 / 1024 / 1440 px; Chrome, with light/dark themes
- normal / zero / filtered zero / loading / stale error states
- dashboard 6 KPIs and attention section do not overflow or produce orphan cards
- chart labels, tooltip, axes readable at 125% and 150% zoom
- drawer: overview summary links, runtime controls, node empty/data states; only one scrollable drawer body and no clipped controls
- keyboard-only flow: Tab, arrows/Home/End on machine tabs, Enter/Space on glance cards; focus is restored on close
- sensitive credentials: reveal only on explicit request, hide after 18 seconds, clear when changing machine/tab, discard late responses
- test runtime channel update confirmation and permissions using a disposable development machine, never production

The Browser acceptance checklist is **not** satisfied solely by CI. Do not claim visual QA is complete without actual browser runs and saved screenshots.

## Merge/deploy order
1. Approve and merge Batch 1 (#229) into main.
2. Retarget Batch 2 (#230) onto main; test and merge.
3. Retarget this Batch 3 PR onto main; re-run CI, perform browser QA, review and merge.
4. Main push may trigger Dev Docker image publishing. Production deployment requires separate authorization.
