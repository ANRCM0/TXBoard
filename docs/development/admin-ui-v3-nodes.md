# Admin UI v3 · Page 02 — Node Management

Baseline: `main` at `18b8f15ab51ab8405fd85730751992bc92b92a4d`.
Parent roadmap: [Issue #233](https://github.com/ANRCM0/TXBoard/issues/233).
PR is single-page only: `/server/manage`, including row action surface and new per-page CSS.

## Page audit and decisions

| Previous issue | Fix | Acceptance |
|---|---|---|
| Node name, ID and protocol split across separate columns with wasted space | Name/ID/protocol in one readable identity cell | 1/10/100 rows remain scannable; long names wrap |
| Search, machine filter and refresh buttons use inconsistent sizes | Scoped 40px toolbar with accessible labels, query clear action and node count | 390/768/1024/1440 widths and keyboard nav |
| Missing online field represented as offline | Four explicit states: online/offline/unknown/disabled | Values true/false/undefined/disabled must be distinguishable by text, not only colors |
| Machine id fallback and no-address values unclear | Explicit “未关联” and “未设置地址”; IPv6 brackets when port appended | Machine link and host:port unambiguous |
| Wide table and absolutely positioned row menu compete with clipping | Scrollable table with a portaled keyboard-accessible action menu | On small widths: action popover visible, Escape restores focus, Arrow/Home/End navigate |
| Generic empty white container and filtered empty results | Lightweight informative first-use/filtered empty states | 0/1/many rows, filtered zero, fetch failure/refetch, long text |
| No list count/current refresh feedback | Total or displayed/total count and discreet sync feedback | Background poll retains stale rows without flicker |
| Status and metadata too decorative | Plain secondary text for protocol/id and dot+status for meaningful health | Both themes and legibility at zoom 125/150% |

## Functional safeguards
- Preserve NodeEditorModal schema-based editor, unsaved-change guards and secret generators.
- Preserve copy-then-editor flow and server-side node ID; do not log credentials.
- Preserve delete confirmation and existing API query/mutation signatures.
- Leave all other business pages, backend, runtime, schema and image pipeline unchanged.

## Gates
- [ ] Exact commit Web CI, documentation and Docker smoke checks successful.
- [ ] Authenticated Dev preview screenshots for 390/768/1024/1440, light/dark, normal/empty/filter/error.
- [ ] Keyboard-only menu and editor checks, no clipped scroll container, no double scroll.
- [ ] Manually approve comparison and close remaining P1 concerns before marking this route done in #233.

Do not merge or mark the page complete solely on CI success. No Stable/production update without a separate decision.
