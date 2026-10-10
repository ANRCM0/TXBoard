# Native TX-Node WebSocket v1

TXBoard supports **only** `/txapi/node/v1/ws` with `Authorization: Bearer ...` and either `X-TX-Node-ID` or `X-TX-Machine-ID`. Old `/ws?token=...`, `/api/v1/*`, `/api/v2/*` and unversioned JSON events have no compatibility entrypoint.

All frames have `protocol_version: 1`, string `event`, object `data` and a message-correlation `request_id`. The total frame limit is 1 MiB.

## Native inbound events

| Event | Data | Response |
| --- | --- | --- |
| `heartbeat.ping` / `heartbeat.pong` | `{}` | `heartbeat.ack` |
| `sync.request` | optional standalone node ID; machine connections require `node_id` | `sync.config`, `sync.users`, `sync.ack` |
| `traffic.report` | same validated native HTTP report contract; machine requires `node_id` | `traffic.ack` (queue acceptance **not** settlement) |
| `ops.result` | `request_id` (Agent action ID), boolean `ok`, optional object `result`, `error_code`, `message`, and for machines `node_id` | `ops.ack` |

`ops.result` is the exclusive native completion path for approved Agent actions. The **outer** `request_id` correlates the WS message; `data.request_id` identifies the approved Agent action. `ops.ack.data` contains `accepted`, `action_request_id` and `node_id`. A valid machine socket can only report for currently assigned enabled nodes; token rotation revokes access.

Operation completions are serialized transactionally: the first valid result wins, retries for completed actions are acknowledged without overwriting the result, and expired or unknown actions return `accepted: false`. `ok` must be a boolean. Error and message fields are bounded.

Do not send the retired inbound event names `pong`, `node.status`, `report.devices`, `request.devices` or a legacy JSON-shaped `ops.result`. Current HTTP or versioned-WS `traffic.report` handles alive/status/metrics; device state changes remain managed via the supported domain services. Reintroducing a retired name requires a new native schema and explicit contract tests.

## Native outbound events

The server sends `session.ready`, `heartbeat.ping`, `sync.nodes`, `sync.config`, `sync.users`, `sync.user.delta`, typed `ops.*` commands and `ops.ack` using `NativeNodeFrame`. Multiplexed machine connections carry node IDs on scoped commands. `NativeNodePush` handles outgoing configuration and device-state snapshots.

## Release boundaries

The feature switch is `TXBOARD_NATIVE_NODE_WS_ENABLED`; the reverse proxy must route the Upgrade path separately. Historical `v2_*` tables remain **active** payment, identity, traffic and Agent models. Removing old Controller classes does not authorize dropping those tables. Schema migration needs separate backup, backfill, rollback and accounting parity gates.

CI covers Laravel API, MySQL regressions, P0 route inventory and the image build. External TX-Node implementation and real Agent/Node integration must still be tested against these frames.
