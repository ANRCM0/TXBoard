# TX-Node Protocol Contract

TX-Node communicates with the panel exclusively over HTTP/WebSocket adapters in `node/internal/panel` and `node/internal/controlplane`.

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

## AccessAudit extension

- `GET /api/v1/plugin/access-audit/rules`
- `POST /api/v1/plugin/access-audit/report`

Changes to these paths or their payloads require matching tests in `node/internal/panel` or `node/internal/audit` and a corresponding API/plugin change.
