# TXBoard MCP Gateway

Optional MCP adapter for the TXBoard Agent Ops API.

The gateway contains no TXBoard domain logic and never connects directly to MySQL, Redis, or TX-Node. A caller supplies a TXBoard Agent Bearer token; the gateway verifies it through `/api/v2/agent/whoami` and forwards it to the Agent Ops API.

## Run

```bash
npm install
npm run build

TXBOARD_BASE_URL=https://panel.example.com \
MCP_ALLOWED_HOSTS=localhost,127.0.0.1 \
node dist/index.js
```

The endpoint is `http://127.0.0.1:3000/mcp` by default.

Docker Compose keeps the service disabled unless the `mcp` profile is selected:

```bash
docker compose --profile mcp up -d
```

The published MCP port binds to loopback by default. Put a trusted authenticated HTTPS reverse proxy or private overlay network in front of it when remote clients need access.

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
