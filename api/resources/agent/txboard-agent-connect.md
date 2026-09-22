# TXBoard Agent Self-Connect Guide v2

Audience: AI Agents and coding/operations assistants.

This document is executable onboarding guidance. Follow it in order.

## Goal

Connect the current Agent runtime to the TXBoard instance that served this document.

Derive the panel origin from this document URL:

```text
https://panel.example.com/.well-known/txboard-agent-connect.md
                         ↓
https://panel.example.com
```

The TXBoard MCP endpoint is:

```text
<PANEL_ORIGIN>/mcp
```

TXBoard already hosts the MCP Gateway. **Do not install or start another TXBoard MCP server.**

## Security rules

TXBoard remains the Control Plane. MCP is only a protocol adapter.

Do not create or use an alternate TXBoard/TX-Node control path through:

- MySQL;
- Redis;
- direct TX-Node HTTP/WebSocket connections;
- SSH;
- Docker;
- generic remote shell;
- arbitrary remote filesystem access;
- automatic approval bypass.

Local shell/file access may be used only to inspect and update the current Agent client's own configuration.

Never print, echo, commit or intentionally include the long-lived TXBoard Agent Token in chat summaries or logs.

Do not disable TLS verification automatically.

## Step 1 — Detect the current Agent/client

Identify the client you are running in before editing anything.

Examples include:

- Hermes Agent;
- OpenClaw;
- Codex or another coding Agent with native MCP configuration;
- Claude Code;
- Cursor or another MCP-compatible client.

Inspect existing MCP configuration first. Preserve unrelated servers and settings.

Prefer the client's native MCP management command when one exists.

## Step 2 — Resolve the TXBoard endpoints

Use:

```text
guide:    <PANEL_ORIGIN>/.well-known/txboard-agent-connect.md
pairing:  <PANEL_ORIGIN>/api/v2/agent/pairings/redeem
MCP:      <PANEL_ORIGIN>/mcp
```

Configure MCP as Remote HTTP / Streamable HTTP, not stdio.

## Step 3 — Obtain the Agent Token

### Preferred v2 path: one-time pairing code

If the user's onboarding prompt contains a value beginning with `txbp_`, treat it as a short-lived one-time pairing capability.

Redeem it exactly once:

```http
POST <PANEL_ORIGIN>/api/v2/agent/pairings/redeem
Content-Type: application/json

{
  "pairing_code": "txbp_..."
}
```

A successful TXBoard response contains `data.plain_text_token`.

Capture that value without echoing it into the conversation, terminal transcript or logs. Immediately place it into the detected Agent client's local secret/config mechanism.

Do not retry a successful redemption: the pairing code is consumed.

The pairing code does not grant extra abilities. The returned Agent Token already has the administrator-selected functional abilities, target scope and expiry.

### v1 fallback: no pairing code

If no pairing code was provided, prefer a client-supported secret, environment-variable or protected credential mechanism for the Agent Token.

If no token is available, stop before authenticated MCP configuration and ask the operator to place the newly-created TXBoard Agent Token into the client's local secret/environment mechanism.

Do not ask the operator to paste a long-lived token into the conversation when a safer local mechanism exists.

TXBoard MCP authentication is:

```http
Authorization: Bearer <TXBOARD_AGENT_TOKEN>
```

## Step 4 — Configure the detected client

### Hermes Agent

Hermes supports URL-based remote HTTP MCP servers in `~/.hermes/config.yaml` under `mcp_servers`.

Inspect the current file first and merge a `txboard` entry without replacing other servers.

Structural example:

```yaml
mcp_servers:
  txboard:
    url: "https://panel.example.com/mcp"
    headers:
      Authorization: "Bearer <locally stored Agent Token>"
    enabled: true
    timeout: 120
    connect_timeout: 30
```

Use the current Hermes-supported secret interpolation/config mechanism when available. Do not invent unsupported placeholder syntax.

Use Hermes' native MCP test/status command when available. Reload/restart Hermes only if required by the current client version.

### OpenClaw

Inspect current outbound MCP state first:

```bash
openclaw mcp status --verbose
```

Prefer current OpenClaw native MCP management commands over rewriting unrelated settings.

Configure server `txboard` as a remote `streamable-http` endpoint:

```text
<PANEL_ORIGIN>/mcp
```

with the Authorization header using the locally stored Agent Token.

Use native validation/probing when available, for example the current equivalent of:

```bash
openclaw mcp doctor txboard --probe
```

### Other MCP-compatible clients

Use the client's native Remote HTTP / Streamable HTTP MCP configuration.

Required values are:

```text
name = txboard
url = <PANEL_ORIGIN>/mcp
Authorization = Bearer <TXBOARD_AGENT_TOKEN>
```

Preserve existing MCP servers. Do not install a local TXBoard MCP package.

## Step 5 — Verify without mutation

Verification during onboarding must be read-only.

Perform, in order:

1. establish the MCP connection;
2. confirm MCP tool discovery;
3. make one read-only TXBoard call.

Prefer:

```text
txboard_system_status
```

or:

```text
txboard_fleet_health
```

Do **not** use these onboarding checks:

- `txboard_restart_kernel`;
- `txboard_reload_node_config`;
- `txboard_full_sync_node`;
- `txboard_tail_logs`;
- `txboard_network_test`;
- any other node-executed or approval-gated action.

The Agent Token's abilities and target scope remain authoritative.

## Step 6 — Report completion

Report only non-secret results:

- detected Agent/client;
- client configuration location or native command used;
- TXBoard MCP endpoint;
- whether pairing redemption/authentication succeeded;
- whether tools were discovered;
- the read-only verification result;
- whether a client reload/restart is required.

Never include the pairing code or long-lived Agent Token in the completion report.

## Troubleshooting

### Pairing returns 410

The code is invalid, expired, already redeemed, or its Agent Token was revoked.

Do not guess why and do not brute force. Ask the operator to create a new Agent Token/pairing if needed.

### Pairing returns 503

TXBoard transient pairing storage is unavailable.

Do not connect to Redis directly. Use the v1 manual local-secret fallback if the operator still has the one-time plain Agent Token, or ask the operator to issue a new token later.

### `/mcp` returns 404

The embedded TXBoard MCP Gateway is probably disabled.

Tell the operator to enable MCP in the TXBoard deployment configuration. Do not install a second TXBoard MCP server as a workaround.

### `/mcp` returns 401

The Agent Token is missing, expired, revoked or invalid.

Re-check the local Authorization header source without printing the token.

### Request returns 403

The token may lack the required functional ability, may be outside its node/machine target scope, or a host/origin/policy check may have rejected the request.

Report the exact non-secret rejection. Do not widen permissions automatically.

### TLS error

Stop and report the certificate/hostname problem.

Do not use `--insecure`, disable certificate verification, or install an untrusted CA automatically.

### Connection timeout

Report network reachability to the panel origin and MCP URL.

Do not fall back to direct database, Redis, TX-Node, SSH or Docker access.

## Completion condition

Self-connect is complete only when this path works:

```text
Current Agent
  -> one-time pairing redemption (v2, when provided)
  -> locally stored Agent Token
  -> HTTPS <PANEL_ORIGIN>/mcp
  -> TXBoard MCP Gateway
  -> Agent Ops API
  -> read-only verification
```

No additional TXBoard runtime should be installed locally.
