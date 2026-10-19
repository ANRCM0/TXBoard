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

`src/admin-theme.css` is the final shared presentation layer. Existing page
styles retain specialized layouts; Core SDK operations and write ownership
remain unchanged. The reference is not a dependency of the application build.

Local type checks and builds are static checks. Component and browser acceptance
run in `ci-web.yml`; browser screenshots are retained as workflow artifacts.

## Components and charts

The shared shell follows the reference's page-owned headings, sidebar footer
tools and compact rail. Management pages use the same filter controls, status
tags, page selection, dialogs, empty states and disclosure patterns.

`src/components/charts/ReferenceCharts.tsx` provides dependency-free React/SVG
line, donut and horizontal-bar components, with theme tokens, keyboard detail
selection, legend emphasis, reduced-motion support and readable data tables.
The dashboard uses order trend lines, an order-status donut and upload/download
lines comparing nodes. Byte geometry uses bounded BigInt-derived ratios while
tooltips and tables preserve the exact decimal counters. Missing counters remain
gaps and appear as unavailable rather than zero.

The dashboard reads the latest 100 native orders and the first five nodes in
Core's list order. Its 7/14/30-day UTC order chart counts creation dates and the
orders' current states. It never treats the sample as full-site history or revenue.
Node counters are resettable cumulative values; the node comparison line is not
a time series or today's usage. Resource total cards use Core's list metadata.

## Local preview with test data

Run `npm run preview:admin` from the repository root and open
`http://127.0.0.1:3001/admin/`. Stop any existing development server using that
port first. Sign in with `admin@example.test` and `preview123456`.

This separate entry point serves synthetic data from the existing browser
fixtures plus sample users and orders. The visible badge identifies the preview.
It listens on loopback, handles the simulated API locally and rejects business
writes. It does not connect to Core or change real data. The normal development
command and production build do not include the preview middleware.
