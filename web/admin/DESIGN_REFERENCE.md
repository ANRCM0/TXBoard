# Management UI reference

The visual reference is `zyycn/codex-proxy-rs`, checked out read-only at
`references/codex-proxy-rs` in the parent workspace.

- Repository revision: `60570883007febebe732597a9881397098d724f5`
- UI submodule revision: `5eac879635ab4c15cfeea71d3d65fbf467780bbc`
- Reference sources: `docs/theme.md`, sidebar and login Vue components, and the
  UI module's theme constants, derived component tokens and control styles

TXBoard uses its own React components and CSS implementation. The adaptation
follows the reference's blue accent, neutral surfaces, subdued dividers,
18px cards, 8px controls, 38px control height and 88px compact navigation rail.
The primary button color is darker in light mode to keep its white label readable.
Shared tokens provide both light and dark palettes. No reference runtime,
Vue dependency, branding assets or backend is included.

`src/admin-theme.css` is the shared presentation layer for the shell and common
page patterns. `src/components/ui/ui.css` follows it for the shared primitives.
Existing page styles retain specialized layouts; business writes retain their
Core owners. The reference is not a dependency of the application build.

The CSS order is fixed in `src/main.tsx`: design tokens, legacy base styles,
form system, density layers, native page rules, the shared admin theme, then the
shared UI primitives. New page rules should use tokens and sit next to their
page; new shared rules belong in `ui.css` rather than adding another override
layer.

Local type checks and builds are static checks. Component and browser acceptance
run in `ci-web.yml`; browser screenshots are retained as workflow artifacts.

## Components and charts

The shared shell follows the reference's page-owned headings, sidebar footer
tools and compact rail. Management pages use the same filter controls, status
tags, page selection, dialogs, empty states and disclosure patterns.

`src/components/charts/ReferenceCharts.tsx` provides dependency-free React/SVG
line, donut and horizontal-bar components, with theme tokens, keyboard detail
selection, legend emphasis, reduced-motion support and readable data tables.
The dashboard uses income trend lines, an order-status donut and daily total
traffic lines, one per node. Byte geometry uses bounded BigInt-derived ratios;
tooltips, legends and axes display decimal GB rounded to two places (1 GB = 1,000,000,000 bytes). Raw totals retain exact integers.
Missing points remain gaps and appear as unavailable rather than zero.
Categorical series use the `--data-*` tokens from `design-tokens.css`; semantic
success, warning and danger colors remain reserved for status meaning.

### Shared primitives

`src/components/ui` owns the reusable primitives composed by pages:

- `Field`, `Input`, `Select`, `Textarea`, `Checkbox` and `Switch` keep labels,
  help and errors linked through `useId` and visible focus states.
- `DataTable` supports current-page selection with an indeterminate header
  checkbox; selection is never treated as a cross-page or full-result set.
- `StatusBadge`, `EmptyState`, `Skeleton`, `QuotaBar`, `FilterBar`,
  `BulkActionBar`, `MetricCard`, `DetailSection`, `DangerZone`, `ReadOnlyNotice`
  and `Popover` cover the repeated page patterns.
- `ReadList` keeps pagination, permission hiding and refresh feedback; it renders
  a table skeleton during the first read and the existing feedback during refresh.

Page templates are intentionally small: list pages combine a page header, a
filter bar, an optional bulk action bar and `ReadList`; detail pages combine a
summary section, grouped sections and a separate danger zone; observation pages
declare their read-only boundary before exposing runtime state.

Node management adds current-page selection with explicit bulk enable, disable,
hide, machine binding, counter reset and delete actions. All bulk writes reuse
the workflow recovery path and are limited to the selected current-page rows.
Batch machine binding selects from the current machine list instead of accepting
a free-form identifier. Node detail reuses the bounded traffic-history read for
its own 7/14/30-day chart.

The overview ranks its current node sample by exact cumulative upload plus
download bytes and charts the top five, instead of using list order. User detail
adds a subscription quota bar, recent orders from the native order list, and a
version-checked ban/unban action; the user list exposes the same status action.
Raw byte values remain visible for exact verification. Observation pages state
their read-only boundary and do not expose installation, retry or cleanup
actions that Core does not own.

The dashboard reads the latest 100 native orders and the first five nodes in
Core's list order. Its 7/14/30-day UTC income chart groups paid orders (processing or completed) by payment date. Each currency has its own line; amounts are gross order values, include wallet payments and do not subtract refunds. It never treats this recent-order sample as full-site cash receipts.
The independent 7/14/30-day traffic chart reads `network.nodes.traffic-history.get`.
UsageV2 aggregates settled raw upload plus download by the UTC day containing
batch `ended_at - 1`; Network owns the node identities. Days without settled
entries are zero-filled. The current day ends at the response cutoff; unprocessed
or future batches are excluded. Resource total cards use Core's list metadata.

## Local preview with test data

Run `npm run preview:admin` from the repository root and open
`http://127.0.0.1:3001/admin/`. Stop any existing development server using that
port first. Sign in with `admin@example.test` and `preview123456`.

This separate entry point serves synthetic data from the existing browser
fixtures plus sample users and orders. The visible badge identifies the preview.
It listens on loopback, handles the simulated API locally and rejects business
writes, including the new bulk node and user status actions. It does not connect
to Core or change real data. The normal development command and production build
do not include the preview middleware.

## Page detail adaptation

The dashboard uses the shared segmented range control; compact summaries and
expandable statistics definitions keep chart headers readable. User inventory
and details use current native styles, applied-filter feedback and wrapping
identities. Network, commerce, support, observation and settings pages refine
column hierarchy, form grouping, operation placement and pending-result panels.
The final shared layer aligns cards, control heights, table text and feedback
across these page layouts. Narrow tables retain horizontal scrolling; lengthy
identifiers, subjects, source previews and dialogs remain bounded.

Core SDK operation inputs, observed versions, original idempotency keys and
permission-withdrawal behavior remain owned by the existing page controllers.
Actions browser coverage checks these behaviors alongside desktop/mobile
screenshots. Local preview screenshots are synthetic visual evidence only.
