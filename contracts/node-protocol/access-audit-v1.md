# AccessAudit native Node API v1

Status: **implementation contract**. This is an opt-in audit feature, distinct from traffic billing and administrator request logs.

## Architecture and compatibility

- TX-Node uses one sing-box observation/matching/buffering reporter. The control-plane provider selects **Xboard legacy plugin** transport or **TXBoard native bearer** transport.
- Xboard is unchanged: `GET /api/v1/plugin/access-audit/rules`, `POST /api/v1/plugin/access-audit/report`, old plugin credentials and payload shape. Xboard must actually install the compatible server plugin.
- TXBoard implements independent native routes below; **no legacy plugin endpoints** are mounted inside TXBoard. It uses the existing `TxNodeAuth` middleware. The audit feature is off by default in TX-Node config.
- Machine mode supplies both `X-TX-Machine-ID` and `X-TX-Node-ID` for per-node audit; another Machine's node returns 404.
- The optional reporter only attaches to the sing-box kernel. Xray and Standalone do not claim feature parity.
- TXBoard administrators manage rules and logs; node credentials cannot use the Admin API.

## Node endpoints

```http
GET /txapi/node/v1/audit/rules
Authorization: Bearer <node-or-machine-token>
X-TX-Node-ID: 12
X-TX-Machine-ID: 8   # only for a Machine-managed Node
```

Successful response:

```json
{
  "data": {
    "protocol_version": 1,
    "rules": [
      { "id": 1, "name": "Example", "match_type": "domain_suffix", "match_value": "example.net" }
    ]
  }
}
```

Supported match types: `domain`, `domain_suffix`, `keyword`, `ip_cidr`. Rule values may be comma/newline-separated. Only enabled rules are sent. The Node polls periodically (`audit.rules_refresh`, default five minutes).

```http
POST /txapi/node/v1/audit/report
Content-Type: application/json
Authorization: Bearer <token>
X-TX-Node-ID: 12
```

```json
{
  "protocol_version": 1,
  "events": [
    {
      "event_id": "0123456789abcdef0123456789abcdef",
      "user_id": 42,
      "target": "api.example.net",
      "target_ip": "203.0.113.5",
      "source_ip": "198.51.100.2",
      "matched": true
    }
  ]
}
```

Max 200 events and 1 MiB request body; each event has a 32-character lowercase hex ID generated once at observation time. A retry reuses its ID; the database has `UNIQUE(server_id,event_id)` to ignore duplicates. Successful reply is HTTP 200 with `data.accepted`, `data.received` and `data.inserted`. Unknown users and malformed addresses are rejected, not partially accepted.

TX-Node uses `report_all: false` by default, which reports **only rule matches**. With zero enabled rules, no events are reported. `report_all: true` collects all observed connections and increases load and privacy exposure. Loss is possible on queue overflow or abrupt process termination because the audit queue is memory-bound; **it is not the durable traffic billing spool**.

## Admin endpoints

All under `/txapi/admin/{admin_path}` with existing admin-path + authorization + audit middleware:

- `GET access-audit/rules` — list audit rules (up to 500)
- `POST access-audit/rules` — create/update by optional ID
- `DELETE access-audit/rules/{id}` — delete rule
- `GET access-audit/events` — paginated and bounded filter on server/user/target/matched

Accessible under **Node Management → AccessAudit** in the React Admin.

## Data protection and retention

The tables `tx_access_audit_rule` / `tx_access_audit_event` (or `tx_access_audit_*` on native cutover) are isolated from admin logs, user balances and traffic billing. Event logs contain sensitive targets and IP addresses and must not be rendered to non-admin users. API replies set `Cache-Control: no-store`. A daily scheduled `access-audit:prune` command removes events older than 30 days in bounded batches; the deployment must run Laravel `schedule:run` regularly.

This feature **does not** automatically ban accounts, reset traffic, change proxy routing, inspect HTTPS content, or guarantee crash-proof retention. End-to-end live verification is required before broad deployment.
