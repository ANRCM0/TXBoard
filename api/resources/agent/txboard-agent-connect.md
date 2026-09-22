# TXBoard Agent Self-Connect Guide v1

Audience: AI Agents and coding/operations assistants.

This document is intentionally executable guidance. Follow it in order.

## Goal

Connect the current Agent runtime to the TXBoard instance that served this document.

Derive the panel origin from this document URL:

```text
https://panel.example.com/.well-known/txboard-agent-connect.md
                         ↓
https://panel.example.com
```

The TXBoard MCP endpoint is always:

```text
<PANEL_ORIGIN>/mcp
```

TXBoard already hosts the MCP Gateway. **Do not install or start another TXBoard MCP server.**

## Non-negotiable security rules

TXBoard remains the Control Plane. MCP is only a protocol adapter.

Do not create or use any alternate TXBoard/TX-Node control path through:

- MySQL;
- Redis;
- direct TX-Node HTTP/WebSocket connections;
- SSH;
- Docker;
- generic remote shell;
- arbitrary remote filesystem access;
- automatic approval bypass.

Local shell/file access may be used only as needed to inspect and update the current Agent client's own configuration.

Never print, echo, commit or intentionally copy the TXBoard Agent Token into source control, issue text, chat summaries or logs.

Do not disable TLS verification automatically.

## Step 1 — Detect the current Agent/client

Identify the client you are running in before editing anything.

Examples include:

- Hermes Agent;
- OpenClaw;
- Codex or another coding Agent with native MCP configuration;
- Claude Code;
- Cursor or another MCP-compatible client.

Inspect existing MCP configuration first. Preserve all unrelated servers and settings.

Do not guess a configuration path when the client provides a native MCP management command.

## Step 2 — Resolve the TXBoard endpoint

Set:

```text
server name: txboard
transport: Streamable HTTP / HTTP MCP
url: <PANEL_ORIGIN>/mcp
```

Do not configure TXBoard as stdio.

Do not start a local TXBoard MCP subprocess.

## Step 3 — Obtain the Agent Token safely

TXBoard MCP authentication is:

```http
Authorization: Bearer <TXBOARD_AGENT_TOKEN>
```

Prefer a client-supported secret, environment-variable or protected credential mechanism.

If the token is already available through a local secret/environment mechanism, use it without echoing its value.

If no token is available, stop before making authenticated changes and ask the operator to place the newly-created TXBoard Agent Token into the client's local secret/environment mechanism.

Do not ask the operator to paste a long-lived token into the conversation when a safer local mechanism exists.

If the detected client only supports literal HTTP headers in a local config file, explain that limitation, keep the token only in that client-owned local config, preserve restrictive file permissions where supported, and never commit that file.

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
      Authorization: "Bearer <token from the client's protected local configuration>"
    enabled: true
    timeout: 120
    connect_timeout: 30
```

Use the current Hermes-supported secret interpolation/config mechanism when available. Do not invent an unsupported placeholder syntax.

After changing configuration, use Hermes' native MCP test/status command when available, then reload/restart Hermes only if required by the client.

### OpenClaw

OpenClaw has a native outbound MCP registry.

Start by inspecting current state:

```bash
openclaw mcp status --verbose
```

Prefer `openclaw mcp add/set/configure` over hand-editing unrelated OpenClaw settings.

Configure server `txboard` as a remote `streamable-http` endpoint at:

```text
<PANEL_ORIGIN>/mcp
```

with the required Authorization header.

OpenClaw versions may require a literal header value for static bearer authentication. If so, explain this before storing the token, keep it only in the local OpenClaw-managed configuration, and do not commit or echo it.

Use native validation/probing when available:

```bash
openclaw mcp doctor txboard --probe
```

or the equivalent current command.

### Other MCP-compatible clients

Use the client's native Remote HTTP / Streamable HTTP MCP configuration.

Required values are only:

```text
name = txboard
url = <PANEL_ORIGIN>/mcp
Authorization = Bearer <TXBOARD_AGENT_TOKEN>
```

Prefer native configuration commands and secret stores. Preserve existing MCP servers.

Do not install a local TXBoard MCP package.

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

The Agent Token's abilities and target scope remain authoritative. A successful connection does not imply unrestricted access.

## Step 6 — Report completion

Report only non-secret results:

- detected Agent/client;
- client configuration location or native command used;
- TXBoard MCP endpoint;
- whether authentication succeeded;
- whether tools were discovered;
- the read-only verification result;
- whether a client reload/restart is required.

Never include the Agent Token in the report.

## Troubleshooting

### `/mcp` returns 404

The embedded TXBoard MCP Gateway is probably disabled.

Tell the operator to enable the MCP Gateway in the TXBoard deployment configuration. Do not install a second TXBoard MCP server as a workaround.

### `/mcp` returns 401

The Agent Token is missing, expired, revoked or invalid.

Re-check the local Authorization header source without printing the token.

### Request returns 403

The token may lack the required functional ability, may be outside its node/machine target scope, or a host/origin/policy check may have rejected the request.

Report the exact non-secret rejection. Do not widen permissions automatically.

### TLS error

Stop and report the certificate/hostname problem.

Do not set `ssl_verify=false`, `--insecure`, or an equivalent bypass automatically.

### Connection timeout

Report network reachability to the panel origin and MCP URL.

Do not fall back to direct database, Redis, TX-Node, SSH or Docker access.

## Completion condition

Self-connect is complete only when this path works:

```text
Current Agent
  -> HTTPS <PANEL_ORIGIN>/mcp
  -> TXBoard MCP Gateway
  -> Agent Ops API
  -> read-only verification
```

No additional TXBoard runtime should be installed locally.
