# TX-Node standalone maintenance policy

TX-Node is maintained as an independent project. It originated from Xboard-Node and keeps Xboard panel compatibility where that compatibility is part of the protocol surface, but TX-Node no longer treats upstream synchronization as its development model.

## Source of truth

- `PaiMonCai/TXBoard` (`node/` component) is the source of truth for development and releases.
- `main` is the product mainline.
- Releases are created from semantic `v*` tags only.
- Upstream repositories are references for fixes and ideas, not merge targets.

## Upstream changes

Do not periodically merge an upstream branch into TX-Node. For a useful upstream change: inspect the diff, confirm it applies, port the minimal change, adapt it to TX-Node, run tests, and release it under the TX-Node version line.

## Control-plane boundary

TX-Node core code depends on the `ControlPlane` interface and TX-native `NodeSpec` / `UserSpec` models rather than directly on a panel implementation.

- `LocalControlPlane` provides panel-free standalone operation.
- `XboardControlPlane` is the Xboard-compatible protocol adapter.
- machine mode uses the corresponding machine Xboard adapter with a shared websocket transport.
- future control planes such as TuneX should be added as new adapters instead of introducing panel-specific branches throughout service/kernel code.

See [`controlplane.md`](controlplane.md) for the adapter contract and extension rules.

## Compatibility boundary

The following are compatibility surfaces, not branding leftovers:

- Xboard panel API paths such as `/api/v1/server/UniProxy/*` and `/api/v2/server/*`;
- panel authentication fields such as `token`, `node_type`, and `machine_id`;
- existing Xboard node configuration fields consumed by deployed panels;
- the legacy `xboard-node` executable alias during the migration window;
- `xbctl` and the legacy native/systemd `/etc/xboard-node` layout for existing installations;
- the in-container `/etc/xboard-node/config.yml` path until deployment compatibility is migrated explicitly.

Canonical TX-Node-facing identities are:

- node binary: `tx-node`;
- host Docker deployment directory: `/etc/txnode`;
- Docker image: `ghcr.io/paimoncai/txboard-node`;
- management entry point: `deploy.sh` / `txnode`.

`xbctl` is intentionally **not** renamed to `txctl` yet. Its service/configuration model still targets the legacy systemd installation layout, while its release download source now points to `PaiMonCai/TXBoard` (`node/` component). A future `txctl` should be introduced only after the management CLI is redesigned around the current TX-Node deployment model.

## Go module identity

The Go module and TX-Node self-imports use:

```text
github.com/PaiMonCai/TXBoard/tree/main/node
```

This source-identity change does not alter Xboard panel protocol compatibility.

## Kernel fork dependencies

TX-Node no longer depends on `cedar2025`-owned kernel forks. The current replacements are TX-Node-maintained forks:

- `github.com/PaiMonCai/sing-box`, with the current Mieru patch baseline retained on `tx-mieru`;
- `github.com/PaiMonCai/Xray-core`, with the current per-user bandwidth patch baseline retained on `tx-bandwidth`.

The pinned commits are intentionally unchanged from the previously validated cedar fork revisions. Kernel upgrades are developed separately on `upgrade/sing-box-2026q3` and `upgrade/xray-core-2026q3`, with upstream changes reviewed and the small TX patch set reapplied/tested explicitly.

## License provenance

The historical `cedar2025/Xboard-Node` repository declares `MPL-2.0` in its README but does not currently expose a top-level LICENSE file through GitHub. TX-Node preserves project provenance and existing notices; before changing license terms or distributing under a different license, verify the licensing of inherited source and dependencies explicitly.

## Compatibility window

Standalone releases publish the canonical `tx-node` binary while preserving `xboard-node` and `xbctl` artifacts where required for existing installations. Remove legacy names only in a separately announced breaking release.
