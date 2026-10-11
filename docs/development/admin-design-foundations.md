# TXBoard Admin Design Foundations (Phase A)

The Admin UI uses a restrained infrastructure-control-console visual language: clear operational states, readable data, and purposeful visual hierarchy. The visual style should support frequent administrator work rather than decorate it.

## Authoritative tokens

- `web/admin/src/design-tokens.css` defines the **Light** and **Dark** core palettes, common radii, focus indicator and shell dimensions.
- `web/admin/src/styles.css` is the existing layout and feature stylesheet; its legacy palette blocks were removed. Prefer token references instead of adding new competing `:root` palettes.
- `web/admin/src/form-system.css` normalizes shared forms and drawer controls and must remain loaded after the layout stylesheet.
- Keep the existing `data-theme="dark"` toggle behavior and module/plugin boundaries unchanged.

## Review rules

1. Make operational status distinct without using color alone; pair color with labels and/or icons.
2. Normal administrative helper text should be at least 12px, with a readable line-height; prioritize 13–14px for longer copy.
3. Use `--radius-card`, `--radius-control` and semantic color tokens for shared surfaces. Components may use specialized shapes when justified by information hierarchy.
4. Keep focus styles visible and preserve the shared dialog focus/scroll-lock management.
5. Never expose or log Machine tokens while performing visual refactors. Preserve security confirmations and API/contract behavior.
6. Test both themes, empty/loading/error/data states, keyboard navigation, and 390px / 768px / 1024px / 1440px layouts before merging any visual overhaul.

## Verification

Run from the repository root:

```bash
npm ci --no-audit --no-fund
npm run verify:web
```

This first phase centralizes the core palette and increases readability in the machine operations drawer. Future PRs should address dashboard information hierarchy, empty states, complex forms, and broad component migration separately.
