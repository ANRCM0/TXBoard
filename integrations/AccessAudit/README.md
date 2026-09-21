# AccessAudit

AccessAudit 是 TXBoard 的可选审计插件，负责规则、访问命中、分析、封禁与告警。

它不是 TX-Node 的硬依赖，也不属于核心节点协议。

## Ownership

```text
integrations/AccessAudit/
├── backend plugin            本目录
├── database / services       本目录
├── node-agent/               legacy Xray sidecar
└── admin native UI           web/admin/src/plugins/access-audit/
```

TX-Node 中只保留可选 audit reporter/client。

## Admin UI

插件安装并启用后，由 TXBoard React Admin Plugin Runtime 提供页面：

- `/admin/plugins/access_audit/dashboard`
- `/admin/plugins/access_audit/analytics`
- `/admin/plugins/access_audit/rules`
- `/admin/plugins/access_audit/reports`
- `/admin/plugins/access_audit/ban-logs`
- `/admin/plugins/access_audit/settings`

普通 CRUD 与 Settings 使用 schema-driven UI；Dashboard / Analytics 使用受控的原生 React renderer。

## Node extension

```http
GET  /api/v1/plugin/access-audit/rules
POST /api/v1/plugin/access-audit/report
```

这是核心 TXBoard ↔ TX-Node 协议之上的可选扩展。

## Compatibility sidecar

`node-agent/` 是为不能直接使用 TX-Node audit reporter 的 legacy Xray 部署保留的兼容 sidecar。新部署优先使用 TX-Node 原生可选审计能力。
