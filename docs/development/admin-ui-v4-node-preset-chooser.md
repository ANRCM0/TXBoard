# Admin UI v4 · Node guided preset chooser accessibility

Scope: `/server/manage` → node create/edit modal → schema protocol preset chooser.
Related: [UX roadmap #233](https://github.com/ANRCM0/TXBoard/issues/233) and merged guided-node PR #240.

## Skill-based diagnosis and focused change
Applied Vercel Web Interface Guidelines and UI/UX Pro Max accessibility / focus-management guidance:

- **Before:** a searchable text input and a separate custom-input action lived *inside* an ARIA `listbox`, despite neither being `option` elements. The trigger advertised a listbox popover, although the popup was an interactive composite.
- **After:** the chooser advertises a non-modal `dialog`; its search input, semantically valid nested `listbox` of `option` buttons, and optional custom action are independent children. IDs and `aria-controls` point to the popup.
- Keep ArrowDown from search, ArrowUp/Down/Home/End inside options, Escape to trigger, outside-click dismissal, and visible checked selection.
- When operator *requests* custom mode, focus moves to the new text field. Merely loading a legacy unknown custom value does not steal modal focus.
- No replacement of user-provided custom protocol settings, no data model/API change, and no changes to other admin pages.

## Regression tests
- ARIA popup semantics, valid listbox children, correct controls association
- Keyboard navigation to an option and focus return
- Escape dismissal without changing value
- Explicit custom editing focus
- Existing unknown/legacy custom value preservation

## Gates
- [ ] Exact head `npm run verify:web`, documentation and Docker smoke checks
- [ ] Browser keyboard and screen-reader test in authenticated Dev: open, search, choose, custom, Escape, click outside
- [ ] 390/768/1024/1440, light/dark and 125/150% zoom; ensure popover not clipped by the modal
- [ ] Existing node creation, serialization, secret fields, and unsaved-change confirmations unchanged

A passed CI is **not** a visual acceptance sign-off. PR stays Draft; no Stable/production deployment.
