# TXBoard Agent Ops Module Integration Contract v1

Agent Ops is represented in Module Platform as the system Module `agent_ops`.

This integration is descriptive. Module Registry may expose Agent Ops identity, capabilities and bounded runtime health, but it does not execute Agent actions or replace Agent Ops authorization, approval, audit or TX-Node dispatch.

## Authoritative runtime

Agent Ops runtime remains authoritative:

```text
Agent / MCP
    -> Agent Ops API
    -> ability
    -> target scope
    -> approval / audit
    -> TXBoard domain service
    -> TX-Node typed operation
```

Module Platform reads only bounded runtime health through the existing Agent Ops service.

## Module identity

Stable descriptor identity:

```text
id:      agent_ops
type:    agent
source:  system
enabled: true
```

Descriptor metadata is projected from the system-owned Module Manifest:

```text
name:        Agent Ops
description: TXBoard AI-native operations and approval control plane
author:      TXBoard
```

Current capabilities remain:

```text
agent.api
agent.admin
```

These capabilities describe existing Agent Ops surfaces. They do not grant a token any ability.

## Runtime health

Agent Ops Module health is derived from the existing low-cost system status checks:

- `schedule` — scheduler heartbeat is current;
- `horizon` — Horizon master supervisors are available and not paused;
- `websocket_server` — TXBoard WebSocket worker heartbeat is current.

All three true:

```text
health = healthy
```

One or more false or unavailable:

```text
health = degraded
```

An operational problem in Agent Ops health collection must not hide the system Module from Registry. The adapter returns degraded health with unknown checks rather than exposing runtime exception text.

## Health details

The descriptor may expose the common runtime health detail shape:

```json
{
  "health": "degraded",
  "health_details": {
    "checks": {
      "schedule": true,
      "horizon": true,
      "websocket_server": false
    },
    "observed_at": 1790112000
  }
}
```

Health details are runtime-computed TXBoard facts. They are never accepted from a third-party package manifest.

Only bounded boolean/null checks are exposed. Phase H intentionally does not expose:

- Agent token identities or secrets;
- Agent abilities or target scopes for specific tokens;
- pending/running Agent actions;
- approval actors or reasons;
- audit-log content;
- node inventory, addresses or metrics;
- fleet inspection findings;
- raw exceptions, SQL/Redis errors, filesystem paths or credentials.

## Failure isolation

If `AgentOpsService::systemStatus()` throws, the adapter keeps `agent_ops` discoverable:

```json
{
  "health": "degraded",
  "health_details": {
    "checks": {
      "schedule": null,
      "horizon": null,
      "websocket_server": null
    }
  }
}
```

The exception text is not copied into Module Descriptor metadata. Registry also receives one fixed non-secret discovery diagnostic:

```text
adapter:   agent_ops
module_id: agent_ops
message:   Agent Ops health status unavailable
```

This keeps the operational problem visible without exposing the underlying exception.

## MCP boundary

MCP remains an optional protocol adapter. This contract does not add:

- MCP -> MySQL;
- MCP -> Redis;
- MCP -> TX-Node;
- SSH;
- Docker;
- generic shell;
- arbitrary filesystem;
- automatic approval bypass.

## Octane / state

Agent Ops Module health is derived per Module Registry discovery through container-managed services.

Phase H adds no process-global mutable cache and no Module health database table.

## Compatibility

This integration does not change:

- Agent Ops Admin API;
- Agent token format or abilities;
- target-scope rules;
- approval/audit semantics;
- MCP protocol adapter behavior;
- TXBoard ↔ TX-Node typed-operation contract.
