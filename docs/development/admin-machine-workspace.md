# Phase C: Focused Machine Operations Workspace

Machine quick operations now have four accessible, keyboard-operable panels: overview, runtime, nodes and security. The machine status header stays visible. The full-detail route still hosts deeper workflows.

## Safety and compatibility
- The overview and runtime use existing metrics and update APIs; no machine or node data contract is changed.
- Node inventory is requested on demand when the Node panel is opened.
- Machine credentials are **never** preloaded by switching tabs. The Security tab requires an explicit reveal. Leaving the Security tab or switching machine scrubs the displayed token and invalidates any pending credentials request. Revealed credentials retain the existing 18-second auto-hide.
- Runtime update and token rotation still require existing confirmation dialogs.

## Interaction and visual verification
- Tab, Shift+Tab, left/right arrows, Home and End should navigate predictably.
- Verify inactive panels contain no focusable elements, the main header remains visible, and the active panel uses an appropriate scroll area.
- Check empty-node guidance, high-load metrics, last heartbeat, offline state and pending update states.
- Test at 390, 768, 1024 and 1440px, Light and Dark themes, and with reduced motion.
- CI command: `npm ci --no-audit --no-fund && npm run verify:web` from repository root.

The new workspace changes only the Admin web front end; it does not affect TX-Node runtime, Installer, machine tokens stored by the server or network routing.
