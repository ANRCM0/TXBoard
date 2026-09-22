# Agent Ops HTTP Contract v1

Base path: `/api/v2/agent`

Authentication: `Authorization: Bearer <TXBoard Agent token>`.

Agent tokens are administrator-owned Sanctum tokens whose token name begins with `agent:`. Every endpoint requires an explicit functional `agent:*` ability. Phase 5 insight tools use `agent:insights:read`. Tokens can additionally carry resource restrictions:

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

## AI-native insight endpoints

All Phase 5 insight endpoints require `agent:insights:read`. Node-scoped insight calls also enforce the token's node/machine target restrictions.

### Fleet health

`GET /fleet/health` returns a normalized fleet snapshot with `healthy`, `degraded` and `critical` node states.

```json
{
  "status": "critical",
  "summary": {
    "status": "critical",
    "total_nodes": 8,
    "healthy_nodes": 6,
    "degraded_nodes": 1,
    "critical_nodes": 1,
    "warning_count": 4
  },
  "nodes": [],
  "generated_at": 1780000000
}
```

Severity is derived from normalized warning severity. Informational warnings remain visible without automatically downgrading the node.

### Inspection history

TXBoard persists normalized fleet snapshots in `v2_agent_inspection`.

The scheduler runs `agent:inspect-fleet` every five minutes when:

```text
AGENT_OPS_INSPECTION_ENABLED=true
```

Default retention is controlled by:

```text
AGENT_OPS_INSPECTION_RETENTION_DAYS=7
```

Inspection rows contain normalized health findings only. They do not contain raw TX-Node logs, Agent prompts or credentials.

### Incident timeline

`GET /nodes/{nodeId}/timeline?hours=24&limit=100` composes:

- inspection state transitions;
- Agent action requested/approved/finished events;
- Agent API audit events.

Consecutive inspection snapshots with the same node state/warning signature are collapsed.

Limits:

- `hours`: 1–168;
- `limit`: 1–100.

### Remediation plan

`GET /nodes/{nodeId}/remediation` returns deterministic guidance derived from current normalized warnings.

The response includes:

```json
{
  "automatic_remediation_enabled": false,
  "policy": "recommend_then_approve_then_execute_then_verify",
  "recommendations": []
}
```

A remediation plan can recommend an approval-gated action such as `ops.kernel.restart`, but it cannot approve or execute that action.

### Post-action verification

`GET /actions/{requestId}/verify` re-reads current telemetry instead of trusting the operation ACK.

Verification states:

- `waiting`;
- `passed`;
- `failed`;
- `inconclusive`;
- `action_not_successful`.

For example, an `ops.kernel.restart` action whose stored status is `succeeded` still verifies as `failed` when the node WebSocket is currently offline. If the target no longer exists, verification is `inconclusive` with `target_missing`.

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
