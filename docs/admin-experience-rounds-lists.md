# Administrator Experience Rounds 4-6: Lists

This work is based on Core source `05c404b` and preserves the existing monochrome
interface, native operations, decimal identities and financial contracts. The
separate task-oriented list candidate PR #301 is not represented as merged here.

## Design Direction

Use the existing panel, foreground, muted, danger and border tokens and the
existing DM Sans/Manrope type hierarchy. A list remains the primary workspace;
status explains whether its rows are current, navigation commits only on an
explicit action, and selected-item controls remain a single clearly scoped group.
No new card system, animation or palette is introduced.

## Round 4: Explain Retained Data During Refresh

Finding: `DataTable` deliberately retains populated rows during loading and
refresh errors, but `QueryFeedback` described those states as generic initial
loading/failure. Users could not tell that the visible rows came from the prior
successful request. The list region did not reference its accompanying status.

Changes:

- `QueryFeedback` accepts an optional retained-data flag and status ID. Refresh
  and refresh failure explicitly identify the previously loaded data; initial
  empty loading/failure retains its existing copy and retry control.
- Desktop tables and mobile record regions reference the active feedback through
  `aria-describedby`, then remove that relationship after success.
- Selection remains disabled during loading and errors. Rows remain visible;
  no permission, write or query policy is broadened.

Regression authored in `DataTable.test.tsx`: retained rows, stale-data explanation,
  status relationship, retry, disabled selection and successful recovery.

## Round 5: Make Page Jumps Explicit and Recoverable

Finding: the page-jump input committed on blur as well as Enter. Editing the jump
value and then clicking Next could request both the typed page and Next. Invalid
values were silently reset without explaining the admitted range.

Changes:

- A jump commits through Enter or a visible jump button. Moving focus away from
  an unfinished page draft no longer navigates.
- Invalid input restores the current page and exposes an announced range error
  associated with the input. Editing clears that error; Escape restores the page
  and abandons the draft without navigation. IME composition is preserved.
- Each pagination navigation region includes its owning list label so multiple
  independent pagers are distinguishable.
- Backend totals, maximum-page bounds, current page size, disabled controls and
  current-page selection semantics remain unchanged.

Regressions authored in `TablePagination.test.tsx`: a draft blur followed by Next
  invokes only Next; an invalid jump exposes a linked message; explicit jump and
  Escape behave independently.

## Round 6: Keep List Preferences and Selection Understandable

Finding: `useColumnVisibility` updated persisted preferences in a passive effect
when its storage key changed. The first committed render of another list could
therefore show the previous list's preferences. Open tabs also did not receive
column preference changes from other tabs. Bulk controls had an accessible label
without a corresponding region and no separately named action group.

Changes:

- Column preferences are scoped to the current storage key during render, then
  synchronized through a key-filtered local-storage event listener. Preferences
  without a persistence key remain local. Required columns and the last visible
  column remain protected.
- Column settings show the visible/total count and explain fixed-column rules.
- Bulk selection exposes a named region, an atomic current-page count, a named
  action group and an unambiguous clear-current-page-selection button.
- On narrow screens, clearing stays alongside the selection summary and business
  actions occupy the following row with wrapping and larger touch targets. This
  does not change which actions are enabled or which records they affect.

Regressions authored in `ColumnSettings.test.tsx` and `BulkActionBar.test.tsx`:
  first-layout scope isolation, relevant/irrelevant storage events, storage clear,
  unpersisted choices, current-page action semantics and clearing without a write.

## Verification Boundary

Seven new component regression cases are authored. `git diff --check` passed.
The first full static TypeScript attempt was blocked because the fresh worktree
did not yet contain generated `@txboard/core-sdk` declarations, producing missing
module errors and their downstream type errors. It is not reported as passing.
Functional tests and desktop/mobile browser acceptance are delegated to the
root agent's unified exact-source GitHub Actions run; none ran locally in these
rounds. Mobile layout is source-authored and still requires browser evidence.

Existing native permission, CAS, pending/unknown-operation, identity and financial
tests must remain authoritative. These rounds do not establish release,
publication or deployment acceptance.
