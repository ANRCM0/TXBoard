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

The published MCP port binds to loopback by default. Put your own authenticated HTTPS reverse proxy or private overlay network in front of it when remote clients need access.

## Security

- Bearer tokens are TXBoard Sanctum Agent tokens with explicit `agent:*` abilities.
- The gateway does not persist bearer tokens.
- State-changing tools create a pending action; an administrator must approve the action in TXBoard before dispatch.
- No arbitrary shell, SQL, Redis, filesystem or Docker tool is exposed.
- Network diagnostics remain constrained by TXBoard target policy.
