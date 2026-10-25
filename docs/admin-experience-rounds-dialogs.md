# Administrator Experience: Rounds 1-3

These rounds keep the approved monochrome presentation, DM Sans / Manrope
typography, native write owners and existing confirmation permissions. They
refine the shared controls used by administrator workflows instead of adding
new business operations.

## Round 1: Dialog focus and safe confirmation

Source review found that `useDialog` selected the first `[data-autofocus]`
without checking whether it was disabled or hidden. Its Tab loop checked the
control's own `display`, allowing controls in a CSS-hidden ancestor to enter
the loop. Closing a nested editor also attempted to focus its trigger even
when that trigger was no longer available.

- Autofocus and Tab now use the same availability check, including ancestor
  visibility and native disabled controls.
- Closing a child returns focus without scrolling the page. If its original
  trigger was removed, disabled or hidden, the surviving parent dialog takes
  focus and retains keyboard ownership.
- Confirmations initially focus Cancel. The destructive action still requires
  explicit selection; existing action, cancellation and authorization ownership
  are unchanged.
- Authored regressions cover hidden/disabled autofocus, Tab wrapping and a
  child whose trigger becomes disabled. The existing destructive-confirmation
  test also asserts the safe initial focus.

## Round 2: Keyboard access to popover actions

Source review found that popovers opened through click/Enter, but offered no
direction-key entry into action choices. Operators repeatedly using theme,
account or similar action panels had to Tab from the panel container each time.

- ArrowDown opens at the first available control; ArrowUp opens at the last.
- On action buttons/links, Up/Down wraps through available controls and
  Home/End reaches the first/last control. Hidden and disabled controls are
  skipped. Native text-field cursor keys are preserved.
- Handled keys stay with the child popover. Existing Escape ownership, outside
  dismissal, focus-leave behavior and viewport placement remain in place.
- Authored regressions cover keyboard entry, wrapping, hidden/disabled choices,
  Home/End, Escape restoration and preserving text-editor keys.

## Round 3: Copy feedback belongs to the current resource

Source review found that an asynchronous clipboard request could finish after
the rendered resource changed and show "copied" for the new value. The busy
guard also depended on a React render, leaving repeated activation in one
batch able to create more than one clipboard request.

- A synchronous in-flight guard prevents duplicate writes. A request generation
  and current-value check prevent old completion/failure callbacks from changing
  the current resource's feedback or busy state.
- Resource changes clear old feedback and permit copying the new value.
- Clipboard denial now has visible, wrapping guidance as well as a polite live
  announcement. Pending state exposes `aria-busy`; the clipboard still receives
  the complete original string, without formatting or numeric conversion.
- Authored regressions cover duplicate activation, overlapping requests across
  resource replacement, clearing old success and visible denial guidance.

## Verification Boundary

- `git diff --check` passed during authoring.
- A local static administrator typecheck was attempted but could not resolve
  the new worktree's unbuilt `@txboard/core-sdk` declarations, producing TS2307
  and downstream errors. This is not a passing typecheck claim.
- No local unit, browser or functional tests were executed. The seven new
  regression cases and strengthened existing cases require unified Actions
  execution, together with native authorization and nested-editor acceptance.
- No new translation keys were added; all feedback uses existing locale text.
