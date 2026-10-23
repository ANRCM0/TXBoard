# Administrator Page Refinement

This pass follows the form, dialog and component proportions in the local
`references/webb/xboard-admin-rebuild` project. The approved monochrome theme,
horizontal navigation, native Core operations, decimal string identities,
financial units and optimistic concurrency contracts remain the baseline.
Reference code and legacy HTTP adapters are not imported.

## Shared Interaction Details

- Dialogs use compact (448px), medium (640px), wide (896px) and full (1180px)
  widths, constrained to the viewport. Long editor bodies scroll independently
  while their supplied header and footer remain visible.
- Every native browser confirmation is replaced with a shared secondary dialog.
  Cancelling retains the parent draft and restores focus. Context changes,
  unmounting, permission loss and superseding a request settle it as cancelled.
- Nested dialogs expose their depth, cover the parent visually, and retain the
  existing focus trap, background inertness, Escape handling and scroll lock.
- Form sections separate headings, descriptions, fields and help. Submission
  errors appear inside the affected editor rather than behind its backdrop.
- Existing money, revision, unknown-outcome and permission assertions are retained
  when test selectors follow forms into their portals.

## Page Ledger

The navigation inventory has 35 destinations. "Retained" means the existing
native presentation was reviewed and kept, with shared dialog/field refinements
where used. It does not imply a new write capability.

| Destination | Reference | Refinement / reviewed detail |
| --- | --- | --- |
| `/` | `dashboard` | Retained native metrics, independent chart controls, ranks and queue details; no inferred financial totals. |
| `/server/manage` | `server/manage` | Four editor sections; advanced outbounds/routes/certificates in a secondary tabbed dialog; permission-group creation in a compact child dialog. |
| `/server/machine` | `server/machine` | Compact creation form, bounded credentials/detail panels and secondary confirmations; existing channel filters and node ownership remain. |
| `/server/group` | `server/group` | Compact name editor with fixed actions, nested discard confirmation and preserved resource association counts. |
| `/server/route` | `server/route` | Medium editor; match/action controls and conditional parameters remain grouped; full-set ordering uses secondary discard confirmation. |
| `/server/access-audit` | Native extension of `server/route` | Medium rule editor and aligned event filters; delete/discard confirmations share dialog ownership. |
| `/user/manage` | `user/manage` | Retained account filters and batch editor; creation, identity and account adjustment drafts use secondary confirmations. |
| `/user/traffic-reset` | `user/traffic-reset-logs` | Retained account lookup and quota summary; reset confirmation remains bound to the loaded account and subscription version. |
| `/finance/order` | `finance/order` | Retained bounded order drawer, fixed header and financial sections; native payment/refund lifecycle controls remain distinct. |
| `/finance/plan` | `finance/plan` | Wide immutable publication editor with fixed actions; compact availability editor; nested permission-group creation; local errors and retained revision conflicts. |
| `/finance/coupon` | `finance/coupon` | Medium creation dialog with basic, discount/usage and validity/scope sections; fixed actions, cancellation and expiry presets. |
| `/finance/gift-card` | `finance/gift-card` | Retained separate single-card/batch views and per-card status; no administrative issuance or redemption-code display is invented. |
| `/finance/commission` | Native extension of `finance/order` | Retained policy, earned rewards and payout sections with separate pagination and explicit financial decisions. |
| `/user/ticket` | `user/ticket` | Bounded conversation body and full-width reply footer; attachment and assignment recovery stay with their native owners. |
| `/config/notice` | `config/notice` | Wide editor divided into basic information, body and publication settings; fixed actions and local submission errors. |
| `/config/knowledge` | `config/knowledge` | Same editor grouping with category/language controls; raw content and safe preview remain separate. |
| `/config/system` | `config/system` | Site and registration groups, consistent field hierarchy and sticky save/discard bar. |
| `/config/system/features` | Native extension of `config/system` | Commerce/invitation, content/support, user records and registration groups, ordered by section. |
| `/config/system/safe` | `config/system/safe` | Registration, provider-specific captcha and rate-limit groups; password blanks and write-only credential patches remain. |
| `/config/system/subscribe` | `config/system/subscribe` | Plan change, traffic reset and order activation groups; current bounded choices remain authoritative. |
| `/config/system/subscribe-template` | `config/system/subscribe-template` | Retained provider/source editor and confirmed discard/reset; shared bounded dialog styling. |
| `/config/system/invite` | `config/system/invite` | Uses the native commission policy owner; retained policy/reward/payout boundaries and native units. |
| `/config/server` | `config/system/server` | Synchronization/reporting and device-limit sections with the same save bar; no synthetic runtime knobs. |
| `/config/email` | `config/system/email` | SMTP and sender/authentication groups; saved-configuration diagnosis remains separate from test delivery. |
| `/config/email/templates` | Native extension of `config/system/email` | Source and isolated live preview share a responsive workspace; save state stays visible; reset/test use secondary confirmation. |
| `/config/telegram` | `config/system/telegram` | Bot connection, callback and discussion sections; saved Webhook diagnosis retains its own operation and recovery. |
| `/config/app` | `config/system/app` | Retained Windows/macOS/Android sections with consistent field spacing and save controls. |
| `/config/payment` | `config/payment` | Medium editor with display and merchant sections, fixed actions and local errors; blank credentials preserve saved values. |
| `/config/appearance` | `config/system/frontend` | Interface appearance and login-page sections; persistent save/discard actions and existing validated background handling. |
| `/system/modules` | `config/plugin` | Retained native feature cards and controlled configuration/runtime/documentation drawers; shared bounded dialog layout. |
| `/config/plugin` | `config/plugin` | Retained plugin category, configuration and controlled lifecycle actions; no legacy plugin endpoints. |
| `/config/theme` | `config/theme` | Retained controlled theme deployment, package selection and documentation; fixed dialog boundaries. |
| `/system/queue` | Dashboard queue / native extension | Retained read-only metrics and bounded failure detail dialog; no retry/clear controls are invented. |
| `/system/sessions` | Native identity extension | Retained session list and explicit revocation confirmation; existing memory-only identity behavior remains. |
| `/system/audit-log` | Native audit extension | Aligned filters and native audit rows; large administrator IDs stay decimal strings. |

Node, machine and user detail routes also receive the shared confirmation and
modal changes. Sign-in retains the approved layout and background behavior;
this pass does not change authentication.

## Verification

Run `npm run verify:web` and `npm run test:browser`. The production browser suite
checks schema-validated synthetic Core ingress, including desktop/mobile nested
advanced, group and confirmation dialogs. The nested cancellation scenarios must
retain parent drafts, block unapplied JSON, restore scroll ownership, fit the
viewport and make zero business writes.

`ConfirmationFlow.test.tsx` uses the real confirmation hook and host for lifecycle,
permission, resource replacement, StrictMode and focus/scroll behavior. Business
component tests keep their existing explicit confirm/cancel decisions through the
native-hook test adapter. Node editor tests separately cover advanced apply/cancel,
exact revision preservation and deferred complete-configuration submission.

The local read-only preview can be used to inspect all 35 destinations on desktop
and mobile. Preview data and browser fixtures do not establish live Core UI,
physical-node, release or production acceptance. Candidate CI, merged-main CI and
image publication must be reported against their own source SHA.
