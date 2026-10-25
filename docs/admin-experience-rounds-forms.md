# Form Experience Rounds 7-9

Base: Core `05c404b49f0a2731c93c47810ac09f74d8e2e4ce`.
These changes retain the approved monochrome surfaces, DM Sans/Manrope type,
existing field spacing and native operation owners. Color and geometry remain
the existing tokens; the new CSS only clarifies validation and busy states.

## Round 7: Keep Instructions Available During Correction

Evidence: `Field` removed its hint when an error appeared, did not expose
`aria-errormessage`, and omitted its required marker when only the actual control
declared `required`. This made the information needed to fix a value disappear.

Changes: preserve the hint beside the error, combine both with existing caller
descriptions, associate the error explicitly, and derive the visual required
marker from either the field or control. Clearing the field error restores any
caller-owned error association rather than deleting it.

Authored regression coverage: `components/ui/Field.test.tsx` checks coexistence,
caller descriptions, native required markers and association cleanup (2 cases).

## Round 8: Validate Settings and Return to the Editing Entry

Evidence: clearing a numeric field immediately evaluated `Number('')` to zero;
for a setting accepting zero this could turn an incomplete edit into a different
intent. Programmatic submission did not run native validation. Closing the editor
removed the focused control without returning focus to the editing entry, and an
unchanged form still offered an enabled save action.

Changes: retain an empty numeric draft as empty; check form validity before a
new write; associate native validation feedback with its field; focus the first
invalid control; clear that field's stale error when edited. Unchanged saves are
disabled. Save/cancel restores focus to the edit entry once refresh unlocks it.
The form exposes its busy state and saving label, and successful completion uses
a polite status message rather than an error alert. Opening an editor no longer
forces smooth scrolling, respecting the platform's motion preference.

Authored regression coverage: `native/ConfigurationMail.test.tsx` checks empty
numeric drafts cannot write zero, error focus/association, correction, unchanged
save state and focus after cancel/save (2 cases).

No change to selected-group patch construction, write-only blank credential
retention, revision comparison, forbidden-response cleanup or original pending
input/key recovery. Recovery intentionally bypasses current editor validation
and replays the retained original command.

## Round 9: Locate Invalid Long Background Addresses

Evidence: an unsafe login background address produced a message at the top of
the appearance page without identifying or focusing the field. The long URL
editor retained no accessible error relationship and correction left the earlier
message visible.

Changes: validation now presents a field-local error with the existing safety
instructions, focuses and reveals that field, and removes the error as soon as
the address becomes valid or empty. No image is fetched for a draft. The form
announces busy state and unsaved/current state. Cancel also resets validation.

Authored regression coverage: `native/AppearancePage.test.tsx` checks unsafe URL
refusal, focus, hint/error associations and correction without an implicit write
(1 case). Existing unsafe URL, cancel, conflict, retained-request and permission
tests remain unchanged.

## Verification Boundaries

- `git diff --check` passed for the authored source.
- An initial administrator TypeScript check could not resolve the workspace SDK
  before this worktree's shared declarations were generated. After the shared
  declarations became available, `npm run typecheck --workspace @txboard/admin`
  passed. Static TSX parsing also passed for all 6 authored component/test files.
- The 5 new tests are authored, not locally executed. Functional, browser and
  full-suite acceptance are reserved for GitHub Actions at the final source SHA.
- No commit, push, merge, release or deployment was performed by this agent.
- One label requires dictionary coverage: `正在保存…` (root owns translations).
