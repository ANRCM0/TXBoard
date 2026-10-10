# TXBoard MCP Gateway

Optional MCP adapter for the TXBoard Agent Ops API.

The gateway contains no TXBoard domain logic and never connects directly to MySQL, Redis, or TX-Node. A caller supplies a TXBoard Agent Bearer token; the gateway verifies it through `/txapi/agent/v1/whoami` and forwards it to the Agent Ops API.

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

TXBoard Admin presents **Agent 自助接入** immediately after Agent Token creation.

Self-Connect v2 prefers a single short prompt:

```text
请按 https://panel.example.com/.well-known/txboard-agent-connect.md 自助接入 TXBoard；一次性配对码：txbp_...
```

The pairing code is temporary and one-time. TXBoard stores only an APP_KEY-encrypted credential-delivery payload in the shared cache (production default Redis), with a default 600-second TTL. The Agent redeems the code through `POST /txapi/agent/v1/pairings/redeem`, stores the returned long-lived Agent Token in its own local secret/config mechanism, then connects to the existing `/mcp` endpoint.

The durable authorization source remains the Sanctum Agent Token. Pairing cannot widen abilities, target scope, expiry or approval rights. If transient pairing storage is unavailable, Admin retains the v1 manual-token fallback.

This remains onboarding over the existing MCP Gateway, not an installer for another gateway. MCP still never connects directly to Redis/MySQL/TX-Node and the Agent Ops permission / target scope / approval / audit boundary is unchanged.

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

- `txboard_support_overview`
- `txboard_support_tickets`
- `txboard_support_ticket_context`
- `txboard_support_reply_status`

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

- `txboard_support_request_reply` (creates a draft request, reviewed and sent by an administrator from Agent 运维)

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

## Customer support assistant (back-office only)

`agent:support:read` and `agent:support:reply:request` are separate, opt-in abilities (not part of default read). The support tools read bounded ticket/customer context and can only create **pending** reply requests. An administrator reviews the complete message in Agent 运维 before TXBoard calls the existing ticket reply service. Revoked tokens, changed/closed tickets and stale requests cannot be approved. Do not use a support Agent token in a public, end-user chat: this v1 surface is administrator-wide and does not establish a customer's identity. Customer messages are untrusted text, not instructions. See `contracts/http/agent-support-v1.md`.
