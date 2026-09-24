# Module Registry Admin HTTP Contract v1

Module Registry v1 is the read-only Admin inventory for TXBoard Module Platform.

It normalizes existing Plugin, Theme and Agent Ops subsystems into the common Module Descriptor model. It does not own install/enable/disable/switch/approval lifecycle operations.

Base path:

```text
/api/v2/{secure_path}/module
```

Authentication uses the existing TXBoard Admin middleware and dynamic `secure_path`.

## List modules

```http
GET /api/v2/{secure_path}/module
```

Response data:

```json
{
  "modules": [
    {
      "id": "theme.txboard",
      "name": "TXBoard",
      "version": "1.0.0",
      "description": "TXBoard default theme",
      "author": "TXBoard",
      "type": "theme",
      "source": "system",
      "installed": true,
      "enabled": true,
      "active": true,
      "health": "healthy",
      "capabilities": ["theme"],
      "compatibility": {
        "txboard": "*"
      }
    }
  ],
  "errors": [],
  "summary": {
    "total": 1,
    "health": {
      "healthy": 1,
      "degraded": 0,
      "disabled": 0,
      "failed": 0,
      "incompatible": 0,
      "missing_dependency": 0
    },
    "discovery_errors": 0
  }
}
```

Modules are sorted by stable Module ID.

Descriptors may optionally project validated intrinsic `description` / `author` metadata and bounded runtime health details:

```json
{
  "id": "agent_ops",
  "description": "TXBoard AI-native operations and approval control plane",
  "author": "TXBoard",
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

`health_details` is runtime-derived only. Check values are boolean or null; arbitrary runtime exception text, credentials and secrets are not valid health-detail values.

A descriptor may additionally expose validated host navigation metadata:

```json
{
  "id": "access_audit",
  "type": "plugin",
  "installed": true,
  "enabled": true,
  "admin": {
    "navigation": [
      {
        "id": "dashboard",
        "title": "Dashboard",
        "path": "dashboard",
        "icon": "layout-dashboard",
        "order": 10
      }
    ]
  }
}
```

Navigation is package/module metadata, not runtime authorization. The Admin shell consumes this projection through the same Registry endpoint; specialized Plugin APIs remain responsible for page rendering metadata.

See `contracts/admin-navigation/README.md`.

A broken adapter or malformed legacy module must not prevent healthy modules from being returned. Discovery failures are reported in `errors`:

```json
{
  "adapter": "plugin",
  "module_id": "example_plugin",
  "message": "..."
}
```

Errors must not contain credentials or other secrets. Adapter failures and validation exceptions are reported with bounded, non-secret diagnostic messages; raw exception text and invalid/untrusted module IDs are never returned. The adapter name and (when validated) module ID remain available to locate the failing subsystem.

A runtime descriptor may optionally include bounded `health_details` derived by TXBoard:

```json
{
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

Check values are boolean or null. This field must not contain arbitrary runtime exception text.

## Get one module

```http
GET /api/v2/{secure_path}/module/{id}
```

Returns one Module Descriptor.

Unknown IDs return HTTP 404:

```json
{
  "status": "fail",
  "message": "Module not found",
  "data": null,
  "error": null
}
```

## Read-only boundary

Module Registry resources themselves remain read-only. Phase F adds a separate nested lifecycle management surface documented in `module-management-v1.md`; those mutation routes delegate to `ModuleLifecycle` and do not make `ModuleRegistry` mutable.

The following remain owned by their current specialized runtimes:

- Plugin install/enable/disable/upgrade/uninstall;
- Theme upload/switch/config/delete;
- Agent token/action/approval lifecycle;
- MCP tool execution.

Module Registry is an inventory and normalization layer, not a replacement control plane.

## Initial adapters

### Plugin

Legacy Plugin Package v1 entries are normalized conservatively from `config.json`, package layout and `v2_plugins` installation state.

The adapter does not load `Plugin.php`, call `boot()` or execute plugin lifecycle code during discovery.

### Theme

Theme discovery reuses `ThemeService` as the source of truth for valid themes and the effective active theme.

Legacy theme names are mapped to stable Module IDs such as:

```text
TXBoard -> theme.txboard
```

### Agent Ops

Agent Ops is registered as the system module:

```text
agent_ops
```

It describes the existing Agent Ops HTTP/Admin capability. Phase H additionally derives `healthy/degraded` state and bounded `health_details.checks` from the existing Agent Ops system-status service. Health collection failures keep the system Module discoverable and use a fixed non-secret diagnostic.

MCP remains optional and outside the Registry execution path.
