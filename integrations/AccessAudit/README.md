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

AccessAudit 从 v2.4.0 起采用 **Plugin Package v1**。复杂管理页面由插件自己的 `admin/dist` 提供，TXBoard 只负责同源 iframe 宿主与 Admin Bridge，不再把 AccessAudit React 代码编译进面板。

插件安装并启用后提供：

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


## Plugin Package v1

`config.json` 声明：

```json
{
  "package": { "schema": 1 },
  "admin_menus": [
    {
      "path": "dashboard",
      "app": "admin/index.html#/dashboard"
    }
  ]
}
```

发布包中的 `admin/dist` 会由 TXBoard 发布到 `/plugins/access_audit/admin/`。Admin Bridge 提供当前管理员 Authorization、Admin API prefix、插件信息和宿主导航能力。

因此 AccessAudit 的后台 UI 可以与 TXBoard 独立构建、独立版本化，而不要求修改 `web/admin`。


## Extraction status

AccessAudit 2.4.x is now self-contained at the package level:

- backend source is inside this plugin directory;
- migrations and routes are inside this plugin directory;
- Dashboard / Analytics are inside `admin/dist`;
- TXBoard Admin has no AccessAudit-specific compiled renderer.

The remaining in-repository placement is only a **distribution transition**. Once an official AccessAudit repository/release channel exists, this directory can be moved out of TXBoard and installed through the standard plugin ZIP flow without another host-side UI refactor.
