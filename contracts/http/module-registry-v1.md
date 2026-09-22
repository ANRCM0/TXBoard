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

A broken adapter or malformed legacy module must not prevent healthy modules from being returned. Discovery failures are reported in `errors`:

```json
{
  "adapter": "plugin",
  "module_id": "example_plugin",
  "message": "..."
}
```

Errors must not contain credentials or other secrets.

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

Module Registry v1 intentionally provides no mutation endpoints.

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

It describes the existing Agent Ops HTTP/Admin capability. MCP remains optional and outside the Registry execution path.
