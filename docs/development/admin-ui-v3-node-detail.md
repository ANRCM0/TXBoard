# Admin UI v3 — Page 04: Node Detail

Route `/server/node/:nodeId`, from `main` at `18b8f15ab51ab8405fd85730751992bc92b92a4d`.
Master [Issue #233](https://github.com/ANRCM0/TXBoard/issues/233).
Standalone PR, independent of unmerged node list #236 and machine list #237.

## Problem → correction
- Four tall elevated summary cards → one compact four-field strip with two columns on narrow screens.
- State status badges confuse missing data with offline → explicit online/offline/unknown/disabled; subscription visibility separately shown as low-key text.
- GB-only traffic formatting misreads small usage as 0.00GB → B/KB/MB/GB according to actual magnitude, missing is unknown and true zero is 0B.
- Runtime relation/health cards include tiny, inconsistent labels → scoped 13px readable field/value grid, Chinese Fleet labels, no empty table wrappers.
- Agent action table rendered even with no actions → shared DataTable with purposeful lightweight empty, error and loading states.
- Raw protocol settings were present in DOM despite folded details → only mount raw JSON on explicit user click; clear automatically after 18s or on editor/nav transition. Never include sensitive values in screenshots.
- Diagnostic action results can contain key/value secrets → redact secret-bearing nested keys before the short result summary.

## Unchanged
Node editor behavior, copy, delete confirmation, machine/parent/child/group/route links, API contracts, polling, and Agent Ops navigation.
No backend or runtime/deployment changes; node detail CSS is page-owned.

## Acceptance gate
- [ ] Exact-head Web CI, docs, Docker smoke; targeted helper and React page regressions.
- [ ] Authenticated browser screenshots at 390, 768, 1024, 1440px in light/dark, 100/125/150% zoom.
- [ ] Unknown/offline/disabled and Fleet no-data/error; 0/small/large traffic; long names; zero/many Agent action rows.
- [ ] Screen readers/keyboard: focus, editor, confirm deletion, raw secret reveal and 18s expiry.
- [ ] Human approval before marking this page complete on #233 or merging Draft PR.

No automatic Dev or Stable deployment. PR remains Draft pending browser QA.
