# Admin UI v4 · User Management UX follow-up

Target route: `/user/manage`. Parent roadmap: [Issue #233](https://github.com/ANRCM0/TXBoard/issues/233).

## Applied skills
- **Vercel Web Interface Guidelines:** named icon-only buttons, focus, semantic form controls, URL state and accessible empty/error states.
- **UI/UX Pro Max:** compact metadata instead of badge clutter, touch-friendly responsive interactions, clear state hierarchy.
- **Vercel React Best Practices:** stable cached query keys, list state derived from query parameters rather than duplicated React state.
- **Feature-Sliced Design:** keep single-page helpers/styles in `pages/users/`; avoid a speculative application-wide abstraction.

## Changes
1. URL-synchronized user-list filters (email, plan, banned status), sort key/direction, pagination page/page size. Validates parameters and preserves unrelated URL parameters; browser Back/shareable URLs restore the view.
2. Search uses a semantic form with native Enter submit, preserving the existing *explicit* email-search behavior.
3. Consolidate numeric user ID into the identity cell and keep role/group as plain secondary text.
4. Name edit/copy/credential-rotation/delete icon controls distinctly; preserve server confirmations, loading protection, selection-scope reset, and existing API payloads.
5. Page-scoped density and focus CSS with compact empty results and horizontal table scrolling on narrow viewports.
6. Unit and page integration tests for URL parsing/writing, deep links, icon semantics, query behavior, filtered zero and request failures.

## Acceptance — code
- [ ] `npm run verify:web` on PR SHA (typecheck + Vitest + build + compressed bundles)
- [ ] Docs and Docker smoke checks on same PR SHA
- [ ] No endpoint or server/database changes

## Acceptance — browser pending
- [ ] Screenshots: 390/768/1024/1440, light/dark, browser zoom 100/125/150%.
- [ ] 0/1/many and 100 users per page; long emails, missing plan, Staff/Admin labels.
- [ ] Keyboard-only filter, sort, paging, selection, copy, edit, delete confirmation and mail.
- [ ] Browser back/forward restores filters, and active bulk selection never carries into a changed query.
- [ ] Authenticated Dev visual approval before marking this route completed in #233.

Keep this PR separate from #241 and node-editor work. No Stable deployment or production restart.
