# Ten Administrator Experience Iterations

This iteration is based on Core `05c404b49f0a2731c93c47810ac09f74d8e2e4ce`,
the separately reviewed installation/diagnostics candidate in PR #302. Its paired
installer remains Deploy PR #48. This work does not merge either candidate.

## Design and Scope

Keep the established monochrome system: canvas `#fafafa`, panel `#ffffff`,
foreground `#171717`, secondary text `#666666`, border `#e4e4e4` and error
`#bc3535`, with existing dark-theme counterparts. DM Sans remains the interface
typeface and Manrope the display face. Existing tokens remain authoritative.

The layout keeps business navigation, page navigation and the main workspace.
Dialog and form actions stay attached to the resource being edited. Additional
structure communicates an action, error or state; it does not introduce a new
dashboard theme. Keyboard editing, narrow-screen controls and recovery from
failures are the priorities. No old visual-explorer selection is applied.

Three agents implemented nine focused rounds in parallel. A separate integration
round combines their changes with navigation and sign-in improvements. Each
round has a concrete observed source problem and regression coverage.

| Round | Source problem | Delivered behavior |
| --- | --- | --- |
| 1 | Dialog focus could target hidden/disabled controls or disappear with a removed trigger. | Focus targets respect ancestor visibility; nested close keeps a usable parent focus; confirmation defaults to cancellation. |
| 2 | Popover actions lacked keyboard opening and navigation. | Arrow opening, action navigation and first/last action selection skip unavailable controls while retaining text editing. |
| 3 | Clipboard completion could report success for a replaced resource. | Synchronous duplicate admission guard, request ownership, busy state and visible failure guidance. |
| 4 | Refreshing retained rows used the same message as initial loading. | Refresh and failure messages explicitly identify previously loaded data and describe the associated record region. Existing permission withdrawal remains authoritative. |
| 5 | Blurring the page input could navigate before a different pagination click. | Explicit Go/Enter submission, range feedback, Escape reset and no blur navigation. |
| 6 | A changed column preference key could briefly use another list's hidden columns. | First-render preference isolation, storage synchronization, visible-column explanation and scoped mobile bulk actions. |
| 7 | Field errors hid the original instructions and lacked a direct error relationship. | Instructions remain available with errors, caller descriptions are preserved, and required/error semantics follow the control. |
| 8 | Clearing a number could become zero; exiting configuration editing lost focus. | Empty numeric drafts remain empty, native constraints prevent new invalid writes, edit focus is restored and save state is announced. |
| 9 | Invalid login-background URLs were explained away from their field. | Local error association, input focus/scroll and immediate correction feedback; no draft image fetch or implicit save. |
| 10 | Slow-route focus expired early, language changes could invalidate search selection, sign-in errors only lived in a toast, and old login text colors did not follow the inverted dark-theme panel. | Bounded observation waits for a visible route title without overriding later user interaction; search selection stays valid and empty results can be cleared; login failure remains visible, duplicate submission is guarded and branding text inherits the panel's contrasting foreground. |

Detailed implementation records: `admin-experience-rounds-dialogs.md`,
`admin-experience-rounds-lists.md` and `admin-experience-rounds-forms.md`.

## Review Corrections

Independent source review also found and corrected inconsistent visible/accessibility
labels on the clear-selection action, hidden retained route titles in Suspense,
and Home/End handling that would otherwise override search-text editing. These
corrections have focused regression cases.

Screenshot review also caught low-contrast legacy login copy on the light panel
used in dark mode. The panel's text now inherits its theme foreground, with
production-browser contrast checks for all five text groups in both themes.

## Contracts

The original Core operations, decimal string identities, money units, revision
checks, write-only credential blanks, permission cleanup and pending-operation
ownership remain the boundary. New configuration validation applies to new
writes; recovery uses the retained original operation and input. No automatic
business-write retry or sensitive persistent draft is introduced.

Shared controls benefit the existing native pages; this does not imply that
every operation or every assistive device has been individually exercised.
Chinese, English and Russian copy is maintained for the new controls.

## Acceptance

Local execution is limited to source review and static checks. Unit, functional,
browser and fixed-image acceptance run in GitHub Actions against the final source.
Existing `verify:web`, production browser groups, actual Vite browser checks,
Core/MySQL HTTP checks and performance budgets remain enabled.

New production browser scenarios cover light/dark login-text contrast, persistent failed-login feedback,
command-search recovery, blank numeric settings with no write, and local unsafe
background correction. Existing nested-draft, unknown-result, credential, permission,
exact-money, desktop/mobile and language cases remain in place. CI screenshots
are inspected after the final run. Actual screen-reader output and mobile
operating-system keyboards are outside hosted Chromium acceptance.

Final commit, tested tree, workflow conclusions and artifact counts are recorded
in the pull request and machine-readable acceptance artifact after completion.
Publication, merge and production deployment are separate states.
