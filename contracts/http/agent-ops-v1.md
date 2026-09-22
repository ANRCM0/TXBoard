# Agent Ops HTTP Contract v1

Base path: `/api/v2/agent`

Authentication: `Authorization: Bearer <TXBoard Agent token>`.

Agent tokens are administrator-owned Sanctum tokens whose token name begins with `agent:`. Every endpoint requires an explicit functional `agent:*` ability. Tokens can additionally carry resource restrictions:

- `agent:target:restricted`
- `agent:target:node:<id>`
- `agent:target:machine:<id>`

When `agent:target:restricted` is absent, the token is not resource-restricted. When it is present, all node/machine reads and node actions are filtered to the declared targets. A machine target includes the nodes currently assigned to that machine.

## Read endpoints

| Method | Path | Ability | Target enforcement |
| --- | --- | --- | --- |
| GET | `/whoami` | Agent token | returns functional abilities + target scope |
| GET | `/system/status` | `agent:system:read` | global |
| GET | `/machines` | `agent:machines:read` | filtered |
| GET | `/nodes` | `agent:nodes:read` | filtered |
| GET | `/nodes/{nodeId}/metrics` | `agent:metrics:read` | enforced |
| GET | `/nodes/{nodeId}/diagnose` | `agent:nodes:diagnose` | enforced |
| GET | `/fleet/health` | `agent:insights:read` | filtered |
| GET | `/inspections?limit=20` | `agent:insights:read` | filtered |
| GET | `/nodes/{nodeId}/remediation` | `agent:insights:read` | enforced |
| GET | `/nodes/{nodeId}/timeline?hours=24&limit=100` | `agent:insights:read` | enforced |
| GET | `/traffic/summary` | `agent:traffic:read` | filtered |
| GET | `/queue/status` | `agent:system:read` | global |
| GET | `/audit?limit=50` | `agent:audit:read` | filtered for node-targeted records |
| GET | `/actions/{requestId}` | `agent:nodes:read` | enforced |
| GET | `/actions/{requestId}/verify` | `agent:insights:read` | enforced |

## Action request

```http
POST /api/v2/agent/nodes/{nodeId}/actions
Content-Type: application/json
Authorization: Bearer ...

{
  "action": "ops.kernel.restart",
  "input": {}
}
```

The action is created with status `pending`. The Agent API never auto-approves an operation.

Supported v1 actions:

- `node.full_sync`
- `ops.kernel.status`
- `ops.kernel.restart`
- `ops.config.validate`
- `ops.config.reload`
- `ops.system.info`
- `ops.network.dns`
- `ops.network.port_check`
- `ops.logs.tail`

### Bounded log input

```json
{
  "action": "ops.logs.tail",
  "input": {
    "source": "application",
    "lines": 100
  }
}
```

Only the named `application` source is accepted. TXBoard adds a server-controlled `max_bytes` value. v1 limits are at most 200 lines and 65536 bytes. TX-Node independently enforces the same hard bounds and redacts secrets before returning log content.

## Action policy

Policy values are deployment-configurable, with hard safety caps where applicable:

- action timeout: default 120 seconds;
- same-action cooldown: default 30 seconds;
- pending actions per Agent token: default 20;
- pending actions per node: default 5;
- log tail: at most 200 lines / 65536 bytes.

An identical pending request is returned as the existing pending action rather than duplicated. A different request for the same action while one is pending is rejected.

Policy violations and malformed action input return HTTP 422. Missing abilities or target-scope violations return HTTP 403.

## Admin control plane

Admin Agent Ops routes live under the existing secure Admin path.

Token management:

- `GET /api/v2/{secure_path}/agent/abilities`
- `GET /api/v2/{secure_path}/agent/tokens`
- `POST /api/v2/{secure_path}/agent/tokens/create`
- `POST /api/v2/{secure_path}/agent/tokens/revoke`

Fleet inspection:

- `GET /api/v2/{secure_path}/agent/fleet/health`
- `GET /api/v2/{secure_path}/agent/inspections`
- `POST /api/v2/{secure_path}/agent/inspections/run`
- `GET /api/v2/{secure_path}/agent/nodes/{nodeId}/timeline`

Approval:

- `GET /api/v2/{secure_path}/agent/actions`
- `POST /api/v2/{secure_path}/agent/actions/approve`
- `POST /api/v2/{secure_path}/agent/actions/reject`

## Response envelope

```json
{
  "status": "success",
  "message": "请求成功",
  "data": {},
  "error": null
}
```

Agent responses include `X-TXBoard-Request-ID` for correlation.

Agent audit records include actor type, Agent client/token identity, protocol (`mcp` or `http`), tool, risk level, target, redacted input, approval requirement, request timing, result status and source IP. Sensitive request values are redacted before persistence.
