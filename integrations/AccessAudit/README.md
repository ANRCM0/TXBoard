# AccessAudit

AccessAudit is the canonical optional panel-side audit plugin for TXBoard.

## Ownership

- Plugin backend source: this directory.
- Native administrator UI: TXBoard's React plugin runtime in `web/admin/src/plugins/access-audit/`.
- Node-side collection/reporting: optional client in the independent TX-Node repository.
- Xray compatibility sidecar: `node-agent/` in this plugin.

TX-Node does not vendor or release this panel plugin.

## Admin UI

After the plugin is installed and enabled, use the TXBoard administrator UI:

- `/admin/plugins/access_audit/dashboard` — overview, node health, access logs, manual ban/unban.
- `/admin/plugins/access_audit/analytics` — analytics and rankings.
- `/admin/plugins/access_audit/rules` — schema-driven rule CRUD.
- `/admin/plugins/access_audit/reports` — recent matched reports.
- `/admin/plugins/access_audit/ban-logs` — recent ban/unban history.
- `/admin/plugins/access_audit/settings` — schema-driven plugin settings.

The historical `/plugin/access-audit` and `/plugin/access-audit/insights` URLs redirect to the native React UI. The old standalone Blade dashboard is no longer shipped.

## Node protocol

```http
POST {panel}/api/v1/plugin/access-audit/report
GET  {panel}/api/v1/plugin/access-audit/rules?token=<server_token>&node_id=1
```

These endpoints are optional extensions to the TXBoard ↔ TX-Node protocol. Core node operation does not depend on AccessAudit.

## Xray compatibility sidecar

`node-agent/audit-agent.py` tails Xray-compatible logs and reports them to the same plugin API. It belongs to AccessAudit rather than TX-Node because it is a plugin-specific compatibility adapter.
