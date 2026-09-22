# Agent Self-Connect Contract v1

Status: stable additive Agent Ops UX contract.

## Goal

After an administrator creates a TXBoard Agent Token, TXBoard should let an AI Agent configure itself as an MCP client without requiring the operator to understand MCP transport details.

The self-connect flow is:

```text
Admin creates Agent Token
        ↓
Admin copies self-connect prompt
        ↓
Agent reads version-matched guide from this TXBoard instance
        ↓
Agent detects its own client/runtime
        ↓
Agent configures the existing TXBoard Remote HTTP MCP endpoint
        ↓
Agent performs read-only verification
```

This is an onboarding layer over the existing runtime. It does not create a second Agent or MCP control plane.

## Stable public guide endpoint

Every compatible TXBoard instance exposes:

```http
GET /.well-known/txboard-agent-connect.md
```

Requirements:

- public read-only endpoint;
- `Content-Type: text/markdown; charset=UTF-8`;
- content ships with the running TXBoard version;
- no administrator session or Agent Token is required to read it;
- the document derives the MCP endpoint from its own origin as `/mcp`;
- the document must never embed deployment credentials or runtime secrets.

The runtime Markdown source is:

```text
api/resources/agent/txboard-agent-connect.md
```

## Admin prompt contract

The Admin UI may generate a short prompt containing the public guide URL.

The prompt MUST NOT contain the newly-created Agent Token.

The prompt should tell the Agent to:

1. read the guide first;
2. detect the current Agent/client before editing configuration;
3. use the existing remote HTTP MCP endpoint instead of installing another TXBoard MCP server;
4. preserve existing MCP configuration;
5. obtain the Agent Token through a local secret/environment mechanism where possible;
6. perform read-only verification only;
7. report the detected client, changed config location, endpoint, discovery/auth result and reload/restart requirement.

## Token handling

The plain-text Agent Token remains a one-time administrator secret.

Self-connect UX does not change the existing token contract:

- the token is shown only in the token-creation response/UI session;
- it is not persisted by the frontend;
- it is not inserted into the copied Agent prompt;
- it must not be committed to a repository;
- an Agent should avoid echoing it into chat/log output;
- revocation, expiry, functional abilities and target scope remain authoritative in Agent Ops.

If a client cannot reference a protected secret and requires a literal HTTP header value in local configuration, the Agent must explain that limitation and keep the value in the narrowest local configuration available instead of inventing a different control path.

## Verification contract

Onboarding verification MUST be non-mutating.

Preferred checks:

```text
MCP initialize / tool discovery
txboard_system_status
txboard_fleet_health
```

The onboarding guide MUST NOT instruct the Agent to test setup by requesting:

- kernel restart;
- config reload;
- full sync;
- log retrieval;
- network diagnostics;
- any other approval-gated/node-executed operation.

A successful MCP connection does not grant abilities beyond the Agent Token.

## Security boundary

Self-connect preserves the existing boundary:

```text
Agent
  -> HTTPS /mcp
  -> MCP Gateway
  -> Agent Ops API
  -> functional ability
  -> target scope
  -> approval / audit
  -> TXBoard domain service
  -> TX-Node typed operation
```

The guide must not introduce:

- MCP -> MySQL;
- MCP -> Redis;
- MCP -> TX-Node;
- SSH as a substitute TXBoard control path;
- Docker as a substitute TXBoard control path;
- generic remote shell;
- arbitrary filesystem access on TXBoard/TX-Node;
- automatic approval bypass.

Local edits needed to configure the Agent itself are outside the TXBoard control plane, but must be limited to the detected client configuration.

## Failure behavior

The guide defines bounded troubleshooting:

- `404 /mcp`: embedded MCP Gateway is probably disabled;
- `401`: missing/expired/invalid Agent Token;
- `403`: ability, target scope, host/origin or policy rejection;
- TLS failure: report it; never disable certificate verification automatically;
- timeout: report endpoint/network reachability.

The Agent must stop rather than falling back to SSH, database access, Redis, Docker or a generic shell against TXBoard/TX-Node.

## Compatibility

This contract is additive.

It does not change:

- Agent Ops HTTP v1;
- Agent Token format;
- MCP tool names;
- approval semantics;
- target scope;
- Agent Audit;
- TXBoard -> TX-Node typed operation protocol;
- Module Platform contracts.

Future pairing-code/token-exchange onboarding would require a separate explicit contract and is not part of v1.
