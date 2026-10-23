# Administrator Reference Details

The administrator frontend adapts interaction patterns from
`references/webb/xboard-admin-rebuild/scaffold/src` in the parent workspace.
TXBoard's generated Core operations still own reads, writes, versions, permissions
and recovery. The reference project's legacy HTTP endpoints and numeric identity
conversions are not part of this adaptation.

The follow-up [page refinement ledger](admin-page-refinement.md) records the
35-destination review, sectioned forms and nested dialog behavior.

| Reference interaction | TXBoard implementation |
| --- | --- |
| Table pagination: page size, first/last page, page jump | `TablePagination`, `useListPagination` and `ReadList` |
| Column visibility preferences | Per-list local preferences, default-column reset and a visible-column fallback |
| Pinned action column | Shared `DataTable`; mobile tables scroll inside their card |
| Content editor toolbar and preview | `MarkdownEditor` with edit, split and preview modes; headings, emphasis, lists, quotes, links and code |
| Tag entry | `TagInput` with Enter/comma entry, deduplication, removal and IME support |
| Content drag sorting | Full-list content sorting with drag handles and move-up/down controls |
| Appearance choices | Light, dark and system preferences, including system color changes |
| Identity and transaction detail | Account avatars/roles, explicit detail actions, Chinese order periods, paid timestamps and copy controls |
| Network tables and route editing | Protocol/ownership/channel filters, precise traffic, IPv6 addresses and full-list route sorting |
| Commercial form guidance | Currency and percentage hints, validity/scope summaries and optional plan Markdown descriptions |
| Settings reading/editing | Field groups, descriptions, credential status and editor focus |
| Email HTML preview | Isolated sandbox iframe with restrictive CSP, live draft preview and copyable template variables |

Page-size changes reset the page to one and participate in the read query key.
User and order list URLs retain their filters and `per_page` across navigation.
Tables with independent pagination, such as plans/offers/history, maintain
independent page sizes. Available sizes follow current operation contracts:

| Lists | Offered page sizes |
| --- | --- |
| Notices and knowledge | 10, 20 |
| Coupons, gift cards/batches, commission rewards/payouts | 10, 20, 50 |
| Accounts, orders, network resources, plans/offers/history, payments, audit and tickets | 10, 20, 50, 100 |

Content remains source text when submitted. Local Markdown previews escape raw
HTML and use the parser's link validation. A preview is a formatting aid; public
content rendering remains owned by the user frontend. Tag limits follow the
notice contract: at most 30 tags, each at most 80 characters. Sorting retains
the observed record versions and requires an explicit save. Opening an editor,
previewing text or reordering a draft does not submit a write.

Monetary displays use integer arithmetic and currency-specific decimal places.
Unsupported currencies retain their raw minor-unit amount. Network counters also
use integer arithmetic and expose the original byte count. Real-time load,
bandwidth rates and online counts are not inferred from unrelated Core fields.
Gift-card identifiers are labelled as identifiers rather than redemption codes.

Email previews preserve the submitted HTML source. The preview iframe has an
empty sandbox, no same-origin or script permissions, no referrer, and a CSP that
blocks remote resources and forms. Template variables remain literal placeholders;
the preview does not send test email. The existing explicit send confirmation
continues to protect the test-mail operation.

## Local Preview

Run `npm run preview:admin` from the repository root, then open
`http://127.0.0.1:3001/admin/`. The preview uses synthetic data and the login
`admin@example.test` / `preview123456`. Its gateway middleware rejects business
writes and does not forward requests to Core. Source changes appear through Vite
hot updates. A browser refresh requires signing in again because administrator
credentials are memory-only.

## Verification

Use `npm run check:frontend`, `npm run typecheck:web`, `npm run test:web` and
`npm run build:web` for source, component and build checks. After building, run
the repository's administrator browser scripts for fixture-based workflow
regression checks. Synthetic browser checks verify frontend behavior and do not
replace authenticated Core or deployment acceptance.

### Initial Detail Pass: 2026-10-20

- `npm run verify:web` passed: boundaries, workspace type checks, 271 tests
  (214 administrator tests), both frontend builds and asset budgets.
- `npm run test:dev-browser --workspace @txboard/admin` passed actual Vite startup,
  SDK initialization, protected-route redirect and login validation.
- `npm run test:browser` passed all five browser suites, totalling 57 checks.
- Additional 1440px/390px preview checks cover copy controls, grouped settings,
  payment-editor focus, email sandbox/CSP, mobile clicks and full-length tag paste.
- Logs, preview screenshots and extra browser scripts are retained under
  `artifacts/admin-reference-details/`. These are local frontend results; no hosted
  CI, live Core acceptance, release or production deployment is claimed.

### Component And Page Audit: 2026-10-20

- All 141 reference-owned files are reviewed, with no missing/duplicate paths
  and unchanged reference hashes. See [the full audit](admin-component-audit.md),
  [the per-file records](admin-reference-file-audit.md) and
  [the node/server field and protocol matrix](admin-network-component-audit.md).
- The final workspace passes `npm run verify:web`: 295 tests, including 238
  administrator tests, source boundaries, types, both builds and asset budgets.
- All five browser suites pass 57 checks. Another 16 checks at 1440px/390px cover
  responsive single-DOM records, account/theme layers, positioning, nested Escape,
  focus and inert/scroll cleanup. Actual Vite startup also passes.
- This pass adds responsive user/order cards, removable filter chips, column
  metadata, native top-layer popovers and account menus, draft protection and
  variable insertion, plus paginated server binding and stale-view callback
  protection. It corrects server-delete wording to describe node unbinding.
- Full node creation/editing, server creation/credentials/load UI and the other
  documented gaps remain incomplete. Existing backend controller methods need
  unified Core contracts before those forms can be connected. The audit and
  local checks do not establish full reference parity or live Core acceptance.
