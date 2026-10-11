# Admin UI v2 — Batch 1 (foundations, modules, plugins, themes)

Design direction: **light surfaces, strong typography, restrained state accents, and density proportional to information**.

## Shared primitives
- `SummaryStrip`: small, non-navigating numerical overview. Unlike dashboard KPI cards, it has no forced tall height and never turns missing measurements into zero.
- `StatusText`: a semantic text label paired with a decorative colored dot. Noncritical metadata stays plain text.
- `admin-density.css`: opt-in class names to avoid overriding legacy layouts; loaded after current stylesheet layers.
- Core font-size and spacing tokens live in `design-tokens.css`, with light/dark versions of subtle surfaces.

## Page design rules
- Modules: five compact metrics in one row at desktop sizes (wrap intentionally at narrower breakpoints); keep health and capacity details accessible via native `details`.
- Plugins: replace large cards with text-led rows; retain existing detail, install, upgrade, enable/disable, uninstall and delete operations and confirmations.
- Themes: use 16:9 image galleries **only** for available previews; themes without images use the dense list variant. Keep upload, preview, configure, switch, rollback and delete behavior.
- Loading, empty results, and errors remain distinct and must not claim all healthy/normal until data is available.

## Verification gate
1. `npm ci --no-audit --no-fund && npm run verify:web` from the repository root.
2. Review 390/768/1024/1440px and light/dark themes.
3. Check plugin action enable/disable/upgrade/delete and theme activate/rollback permissions and confirmations.
4. Confirm the module registry is read-only and technical metadata remains discoverable.

Only batch 1 is included in this PR. Users, nodes, orders and the machine drawer remain scheduled for batches 2 and 3.
