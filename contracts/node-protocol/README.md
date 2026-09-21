# TX-Node Protocol Contract

TXBoard exposes the control-plane protocol consumed by the independent [PaiMonCai/TX-Node](https://github.com/PaiMonCai/TX-Node) agent. TXBoard does not compile, vendor, or deploy TX-Node. TX-Node connects outbound to the panel over HTTP/WebSocket and implements the client-side adapters in its own `internal/panel` and `internal/controlplane` packages.

## Core endpoints

- `POST /api/v2/server/handshake`
- `POST /api/v2/server/report`
- `GET /api/v2/server/config`
- `GET /api/v2/server/user`
- `POST /api/v2/server/machine/nodes`
- `POST /api/v2/server/machine/status`
- `GET /api/v1/server/UniProxy/config`
- `GET /api/v1/server/UniProxy/user`
- `POST /api/v1/server/UniProxy/push`
- `POST /api/v1/server/UniProxy/alive`
- `POST /api/v1/server/UniProxy/status`

These endpoints are the core panel/agent compatibility surface and remain available independently of optional plugins.

## Optional AccessAudit extension

When the AccessAudit plugin is installed and enabled, it extends the node protocol with:

- `GET /api/v1/plugin/access-audit/rules`
- `POST /api/v1/plugin/access-audit/report`

AccessAudit is not a TX-Node dependency and is not part of the core node protocol. TX-Node may enable its audit reporter only when this plugin capability is desired.

Changes to core endpoint paths or payloads require coordinated compatibility tests in TXBoard and the separate TX-Node repository. Changes to the AccessAudit extension require corresponding plugin tests and TX-Node audit-client compatibility checks.
