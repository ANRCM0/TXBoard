# TXBoard MCP Gateway

Optional MCP adapter for the TXBoard Agent Ops API.

The gateway contains no TXBoard domain logic and never connects directly to MySQL, Redis, or TX-Node. A caller supplies a TXBoard Agent Bearer token; the gateway verifies it through `/api/v2/agent/whoami` and forwards it to the Agent Ops API.

## Production image

The TXBoard production image embeds this gateway. It is disabled by default.

Set this in the root deployment environment:

```env
TXBOARD_ENABLE_MCP=true
```

After the container restarts, the same TXBoard Caddy ingress exposes:

```text
https://panel.example.com/mcp
```

The embedded Node process listens only on `127.0.0.1:3000`. Caddy is the public ingress and forwards the Agent Bearer token unchanged. The gateway continues to call the existing Agent Ops HTTP API over loopback; packaging it into the main image does not grant direct MySQL, Redis, WebSocket or TX-Node access.

When disabled, the MCP process is not started and Caddy returns `404` for `/mcp`.

The historical source-compose profile remains available for compatibility:

```bash
docker compose --profile mcp up -d
```

That compatibility service now reuses the same TXBoard image instead of building a second MCP image.

## Agent self-connect

After an administrator creates an Agent Token, TXBoard Admin now presents an **Agent 自助接入** dialog. The copied prompt intentionally contains no credential; it points the Agent at the version-matched public guide served by that TXBoard instance:

```text
https://panel.example.com/.well-known/txboard-agent-connect.md
```

The guide instructs Hermes, OpenClaw and other MCP-compatible Agents to detect their own native MCP configuration, connect to the existing remote `/mcp` endpoint, preserve unrelated configuration and verify with read-only tools only.

This is onboarding over the existing MCP Gateway, not an installer for another gateway. The Agent Token remains separate from the prompt and the Agent Ops permission / target scope / approval / audit boundary is unchanged.

## Standalone development

The gateway can still be built and run independently:

```bash
npm install
npm run build

TXBOARD_BASE_URL=https://panel.example.com \
MCP_ALLOWED_HOSTS=localhost,127.0.0.1 \
node dist/index.js
```

The standalone endpoint is `http://127.0.0.1:3000/mcp` by default. Keep any directly published standalone port on loopback and place a trusted authenticated HTTPS reverse proxy or private overlay network in front of it.

## Tools

Read tools:

- `txboard_system_status`
- `txboard_list_machines`
- `txboard_list_nodes`
- `txboard_node_metrics`
- `txboard_diagnose_node`
- `txboard_traffic_summary`
- `txboard_queue_status`
- `txboard_audit_logs`
- `txboard_fleet_health`
- `txboard_inspection_history`
- `txboard_incident_timeline`
- `txboard_remediation_plan`
- `txboard_action_status`
- `txboard_verify_action`

Approval-gated operation requests:

- `txboard_full_sync_node`
- `txboard_reload_node_config`
- `txboard_restart_kernel`
- `txboard_network_test`
- `txboard_tail_logs`

A state-changing or node-executed tool returns a pending action. An administrator approves or rejects it from **TXBoard Admin → Agent 运维**.

## AI-native workflow

A typical incident workflow is:

```text
txboard_fleet_health
  -> txboard_diagnose_node
  -> txboard_incident_timeline
  -> txboard_remediation_plan
  -> approval-gated operation
  -> txboard_action_status
  -> txboard_verify_action
  -> txboard_fleet_health
```

`txboard_remediation_plan` is advisory only. It never calls another tool or bypasses approval.

`txboard_verify_action` re-reads current telemetry. It may return `failed` even when an action status is `succeeded` if the observed node state did not recover.

TXBoard also runs a normalized fleet inspection every five minutes by default and retains seven days of inspection history. Administrators can trigger an immediate inspection from the Agent Ops Admin page.

## Security

- Bearer tokens are TXBoard Sanctum Agent tokens with explicit functional `agent:*` abilities.
- Tokens can be restricted to specific node IDs and/or machine IDs.
- The gateway does not persist bearer tokens.
- It forwards `X-Agent-Protocol: mcp` so Agent audit records identify MCP traffic.
- State-changing/node-executed tools create a pending action; an administrator must approve the action in TXBoard before dispatch.
- Repeated operations are subject to TXBoard cooldown and pending-queue limits.
- Log retrieval is limited to the operator-configured TX-Node application log, is line/byte bounded and redacted.
- No arbitrary shell, SQL, Redis, filesystem path, HTTP fetch, package-install or Docker tool is exposed.
- Network diagnostics remain constrained by TXBoard target policy.
