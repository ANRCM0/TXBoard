# TXBoard Architecture

TXBoard 是 Control Plane；TX-Node 是独立 Agent / Data Plane。当前仓库只包含面板、前端、协议契约、主题与插件，不包含 TX-Node 源码。

## Runtime

```mermaid
flowchart LR
    Browser --> Caddy

    subgraph Image["TXBoard image"]
        Caddy --> Admin[React Admin]
        Caddy --> User[Vue User]
        Caddy --> API[Laravel / Octane]
        Caddy --> WS[WebSocket]
        API --> Horizon
        API --> Redis[(Redis)]
        API --> Plugin[Plugin Runtime]
        API --> Theme[Theme Runtime]
    end

    API --> DB[(MySQL)]
    Agent[TX-Node] -->|HTTPS| API
    Agent -->|WSS| WS
```

## Repository boundaries

### Control Plane

`api/` owns authentication, users, subscriptions, commerce, server/machine management, plugins, themes, queues and node-facing APIs.

### Frontends

- `web/admin/`: React administrator SPA.
- `web/user/`: Vue user SPA.
- Frontends depend on HTTP contracts, not Laravel implementation files.

### TX-Node

TX-Node lives in a separate repository. TXBoard never imports, builds or releases TX-Node. Compatibility is defined by `contracts/node-protocol/`.

### Themes

Theme Runtime is part of TXBoard's supported extension architecture. `api/theme/` contains system/compatibility themes; user-installed themes persist under storage.

### Plugins

- `api/plugins-core/`: bundled core plugins.
- `api/plugins/`: runtime/user plugins.
- `integrations/`: repository-level optional integrations such as AccessAudit.
- `web/admin/src/plugins/`: native React plugin renderers where schema-driven UI is not sufficient.

## Deployment boundary

Production has one TXBoard application image built by the root `Dockerfile`. It includes both SPAs, Caddy, Laravel/Octane, Horizon, embedded Redis and WebSocket.

MySQL and the backup helper are separate infrastructure services in `compose.yaml`.

## Dependency rules

1. TXBoard and TX-Node communicate only through versioned HTTP/WebSocket contracts.
2. Frontends consume APIs instead of importing backend implementation.
3. Theme and Plugin systems are supported extension layers and must not be treated as disposable legacy code.
4. Optional integrations must not become hard dependencies of the core node protocol.
5. Production application code is replaced by image deployment; running containers do not self-update source code.
6. Cross-component compatibility knowledge belongs under `contracts/`.
