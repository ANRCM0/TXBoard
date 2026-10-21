# Native Configuration And Support Delivery

Status date: 2026-10-21. Implementation is committed in the paired TXBoard #290 and TXnode #4 draft pull requests. Unified Actions results and candidate revisions are tracked in [the repair ledger](admin-native-repair-progress.md). Merge, release and production deployment remain separate steps.

## Configuration

- Security settings show the selected captcha provider only. Switching providers keeps the local draft, while a save submits only visible changed values. Omitted and blank credential fields preserve existing stored credentials. Dirty cancellation and browser unload warn before discarding settings.
- Settings stack labels, explanations and controls vertically, with responsive spacing and business labels for the existing numeric traffic-reset and order-event values. The Core wire values, publication rules and financial behavior are unchanged.
- Mail variables describe their meaning and provide clearly marked example values. Variables insert into the last focused subject or body selection, with the existing length limits. The optional sample preview changes only the preview. Actual source tokens remain unchanged. HTML stays in an iframe with an empty sandbox and restrictive CSP; remote resources and scripts receive no permissions.
- Telegram configuration now has separate status inspection and confirmed Webhook registration operations. Both use the saved configuration version. Credentials are read only on Core, the callback comes from the saved HTTPS base address or site address, and no browser request carries the raw token. Saving an address alone never claims registration.
- Telegram status reports registration, agreement with saved configuration, queued updates and presence of a reported delivery error. Neither the callback URL nor upstream error text is returned. Registration confirms only Telegram's positive `setWebhook` result; it does not claim bot-command installation or successful delivery.
- SMTP diagnosis performs a real bounded connection/TLS/authentication handshake with the saved settings through the native mailer's SMTP transport. It sends no message. A non-empty username requires a successful authenticator callback; a server omitting AUTH cannot produce a false credential-acceptance result. The UI distinguishes this from message delivery.
- External calls release database locks. Native installation, current administrator grants and the saved configuration revision are checked around the operation. A concurrent settings change prevents a success claim for the newer configuration. Original registration idempotency keys remain recoverable across navigation.

## Support And Content

- Ticket conversations and content editors use shared modal portals with independently scrolling content and fixed actions. Both 1440 px and 390 px viewport checks cover bounds and draft cancellation.
- Ticket drafts require confirmation before close or switching. Ctrl/Cmd+Enter sends only outside IME composition. Plain Enter remains a newline. Reply attachments stay in the original workflow ledger and expand the optional upload section when needed.
- Reading older messages preserves position. A new-message affordance appears when appropriate. Cursor-based history does not imply that unread later pages have already been fetched.
- Knowledge language choices include named common languages and preserve custom language codes. Current-page category/status filtering is explicitly described as current-page filtering.

## Native Owner Boundaries

- The full-template implementation adds a persisted version owner, signed template/client/digest bindings and matching Node validation and consumption. Eight format identifiers preserve the original native clients and add the reference Stash/Surfboard clients. Unsupported Node capabilities reject custom templates explicitly. The editor loads local Monaco assets. Node output and the paired Core/Node chain are covered separately from synthetic editor fixtures; neither proves compatibility with every third-party client release.
- Administrator appearance persists through the approved settings owner and is consumed by the management shell. User-site theme configuration remains owned by the existing per-theme runtime. Local administrator light/dark preferences, shared administrator appearance and user-site theme configuration have separate consumers.
- Payment fee semantics and a global node token bypass are outside this slice and remain unchanged.

## Historical Local Diagnostics

The following records predate the final source freeze. They are diagnostic history, not acceptance of the current candidate. Per `docs/development.md`, functional suites now run only in GitHub Actions; see `admin-native-repair-progress.md` for the current unified status.

- `ConfigurationMail.test.tsx`: 18 focused cases pass, covering captcha filtering and omitted credentials, dirty settings, subject/body variable insertion, explicit sample preview, SMTP-versus-delivery language, Telegram confirmation, original-key recovery and failed-inspection data clearing.
- PHP diagnostics: 12 tests and 75 assertions pass, including real internal-operation ingress, current grants, stale saved versions, receipt replay, concurrent Telegram settings changes, SMTP no-send behavior and secret redaction. HTTP and SMTP transports are isolated test doubles; this is not an upstream Telegram or external SMTP delivery acceptance claim.
- The full administrator TypeScript check passes.
- `SupportContent.test.tsx`: 23 cases pass after the final ticket selection/recovery adjustment. Synthetic browser coverage has 12 successful checks, zero page errors and zero business writes at 1440 px and 390 px.
- Configuration browser evidence is recorded separately in `artifacts/admin-native-wave/configuration-browser.json`; these fixtures are schema checked, synthetic and isolated from Core. The final result in that file is authoritative for the browser run.
