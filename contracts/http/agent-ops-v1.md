# Agent Ops HTTP Contract v1

Base path: `/api/v2/agent`

Authentication: `Authorization: Bearer <TXBoard Agent token>`.

Agent tokens are administrator-owned Sanctum tokens whose token name begins with `agent:`. Every endpoint also requires an explicit `agent:*` ability.

## Read endpoints

| Method | Path | Ability |
| --- | --- | --- |
| GET | `/whoami` | authenticated Agent token |
| GET | `/system/status` | `agent:system:read` |
| GET | `/machines` | `agent:machines:read` |
| GET | `/nodes` | `agent:nodes:read` |
| GET | `/nodes/{nodeId}/metrics` | `agent:metrics:read` |
| GET | `/nodes/{nodeId}/diagnose` | `agent:nodes:diagnose` |
| GET | `/traffic/summary` | `agent:traffic:read` |
| GET | `/queue/status` | `agent:system:read` |
| GET | `/audit?limit=50` | `agent:audit:read` |
| GET | `/actions/{requestId}` | `agent:nodes:read` |

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

Admin approval routes live under the existing secure Admin path:

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

Agent responses include `X-TXBoard-Request-ID` for correlation. Sensitive request values are redacted before Agent audit persistence.
