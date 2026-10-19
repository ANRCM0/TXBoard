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
