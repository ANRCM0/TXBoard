# Node Ops Protocol v1

Node Ops extends the existing authenticated TXBoard WebSocket channel. It does not create a shell or a second transport.

Protocol version: **1**.

Compatibility requirement: TX-Node must contain the Node Ops v1 handlers. Older releases that predate Agent Ops do not provide this capability and must not be treated as operation-capable by operators.

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

For machine-mode connections, `node_id` is mandatory and is used by the existing WebSocket multiplexer. `request_id` is required and limited to 64 characters.

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

TX-Node caches recent results by `request_id`. Replaying the same request ID returns the previous result and does not repeat a non-idempotent action such as a kernel restart.

## Operation definitions

| Operation | Input | Output | Node timeout/bound | Mutates runtime | Idempotent by action | Retry behavior |
| --- | --- | --- | --- | --- | --- | --- |
| `ops.kernel.status` | none | kernel name + running | immediate | no | yes | cached by request ID |
| `ops.kernel.restart` | none | kernel + running | action-level timeout from TXBoard | yes | no | duplicate request ID returns cached result |
| `ops.config.validate` | none | `valid=true` or validation error | immediate | no | yes | cached |
| `ops.config.reload` | none | reload + running state | action-level timeout from TXBoard | yes | effectively idempotent | cached |
| `ops.system.info` | none | bounded runtime metrics | immediate | no | yes | cached |
| `ops.network.dns` | `target` | resolved addresses, max 16 | 5 seconds | no | yes | cached |
| `ops.network.port_check` | `target`, `port` | reachability | 5 seconds | no | yes | cached |
| `ops.logs.tail` | `source=application`, `lines<=200`, `max_bytes<=65536` | redacted tail text | bounded file read | no | yes | cached |

The protocol does not include arbitrary `exec`, process launch, filesystem path, SQL, Redis, Docker, HTTP fetch, package-install or generic service-control operations.

## Log retrieval rules

`ops.logs.tail` can only read the application log file already configured by the operator in TX-Node's `log.output`.

- Caller cannot provide a path.
- `stdout`, `stderr` and empty log outputs are not file-tail capable and return an error.
- Only the last requested lines are returned.
- Maximum 200 lines.
- Maximum 65536 returned bytes.
- File reading starts from a bounded window at the end of the file; the whole file is never loaded.
- Bearer credentials and common secret fields including token/password/secret/private_key/api_key/credential/uuid are redacted.
- Current TX-Node file log format does not encode a calendar date, so v1 bounds retrieval by tail window/line/byte count rather than claiming an unreliable historical time filter.

## Network diagnostic rules

- TXBoard validates the destination against node host / deployment allow-list before dispatch.
- TX-Node validates target syntax again.
- TCP port must be in `1..65535`.
- DNS and TCP diagnostics use a 5-second timeout.
- No arbitrary outbound HTTP request is exposed.

## Error codes

Typical errors include:

- `config_unavailable`
- `config_invalid`
- `kernel_restart_failed`
- `config_reload_failed`
- `invalid_target`
- `invalid_port`
- `dns_lookup_failed`
- `port_check_failed`
- `log_tail_failed`
- `unsupported_operation`

The exact error message is diagnostic text; Agent logic should primarily branch on `error_code`.
