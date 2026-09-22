# Node Ops Protocol v1

Node Ops extends the existing authenticated TXBoard WebSocket channel. It does not create a shell or a second transport.

## Request envelope

TXBoard sends one of the allow-listed `ops.*` events:

```json
{
  "event": "ops.kernel.restart",
  "data": {
    "node_id": 12,
    "request_id": "ops_01...",
    "args": {}
  },
  "timestamp": 1780000000
}
```

For machine-mode connections, `node_id` is mandatory and is used by the existing WS multiplexer.

## Result envelope

TX-Node returns:

```json
{
  "event": "ops.result",
  "data": {
    "node_id": 12,
    "request_id": "ops_01...",
    "operation": "ops.kernel.restart",
    "ok": true,
    "result": {
      "kernel_running": true
    }
  },
  "timestamp": 1780000001
}
```

Failure:

```json
{
  "event": "ops.result",
  "data": {
    "node_id": 12,
    "request_id": "ops_01...",
    "operation": "ops.config.validate",
    "ok": false,
    "error_code": "config_invalid",
    "message": "..."
  }
}
```

## Allow list

v1 TX-Node implements:

- `ops.kernel.status`
- `ops.kernel.restart`
- `ops.config.validate`
- `ops.config.reload`
- `ops.system.info`
- `ops.network.dns`
- `ops.network.port_check`

Unknown operation names MUST be rejected. Arbitrary shell/process execution is not part of this protocol.

## Safety limits

- `request_id` is required.
- Operation input is structured JSON with bounded fields.
- DNS and port-check destinations are validated by TXBoard policy before dispatch and validated syntactically again by TX-Node.
- TCP checks use a short timeout.
- No operation installs packages, runs arbitrary commands, reads arbitrary files, or returns secrets.
