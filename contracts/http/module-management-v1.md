# Module Management Admin HTTP Contract v1

Module Management v1 is the controlled Admin mutation surface for TXBoard Module Platform.

It does not execute lifecycle logic itself. Every supported mutation delegates through the existing Module lifecycle orchestration layer:

```text
Admin HTTP
    -> ModuleLifecycle
    -> ModuleLifecycleAdapter
    -> specialized runtime
```

For current v1 runtimes:

```text
Plugin -> PluginLifecycleAdapter -> PluginManager
Theme  -> ThemeLifecycleAdapter  -> ThemeService
```

Base path:

```text
/api/v2/{secure_path}/module
```

Authentication and authorization use the existing TXBoard Admin middleware and dynamic `secure_path`.

## Supported operations

The stable lifecycle operation vocabulary is:

```text
install
enable
disable
upgrade
uninstall
```

Not every Module supports every operation. Support is derived from the registered lifecycle adapter and current runtime state.

Package upload, Plugin/Theme configuration and Agent approval are not generic lifecycle operations.

## Query supported operations

```http
GET /api/v2/{secure_path}/module/{id}/lifecycle
```

Response data:

```json
{
  "module_id": "theme.customtheme",
  "operations": ["enable", "uninstall"]
}
```

The operation list is state-aware and sorted by the stable lifecycle enum order.

Examples:

- Plugin modules expose operations according to installation/enabled state: install before installation; enable when installed and disabled; disable when enabled; upgrade/uninstall when installed.
- Theme modules expose `enable`, plus `uninstall` only for inactive user themes.
- Agent Ops currently exposes no generic Module lifecycle operations.

Unknown Module IDs return HTTP 404.

## Execute lifecycle operation

```http
POST /api/v2/{secure_path}/module/{id}/lifecycle/{operation}
```

No request body is required in v1.

On success, response data is the stable `ModuleLifecycleResult`:

```json
{
  "operation": "enable",
  "success": true,
  "module": {
    "id": "theme.customtheme",
    "name": "CustomTheme",
    "version": "1.0.0",
    "type": "theme",
    "source": "user",
    "installed": true,
    "enabled": true,
    "active": true,
    "health": "healthy",
    "capabilities": ["theme"],
    "compatibility": {
      "txboard": "*"
    }
  },
  "error": null
}
```

After execution, Module state is re-read from Module Registry. For successful uninstall operations where the specialized runtime removes discovery metadata, `module=null` is valid.

## Failure mapping

The HTTP adapter preserves the stable lifecycle error vocabulary and does not expose specialized runtime exception text.

| Condition | HTTP | Lifecycle code |
| --- | ---: | --- |
| unknown Module ID | 404 | `module_not_found` |
| Module type has no lifecycle adapter | 409 | `unsupported_module_type` |
| operation unsupported for type/current state | 409 | `unsupported_operation` |
| specialized runtime failed/rejected execution | 500 | `runtime_error` |
| final Registry state cannot be refreshed | 500 | `state_refresh_failed` |
| operation is outside the v1 vocabulary | 422 | HTTP validation error |

Lifecycle failures return the lifecycle result as response `data`, for example:

```json
{
  "status": "fail",
  "message": "Module lifecycle operation is not supported",
  "data": {
    "operation": "disable",
    "success": false,
    "module": {
      "id": "theme.txboard",
      "type": "theme"
    },
    "error": {
      "code": "unsupported_operation",
      "message": "Lifecycle operation is not supported for this module state",
      "adapter": "theme"
    }
  },
  "error": null
}
```

Runtime exception messages, stack traces, SQL errors, filesystem paths and credentials are not part of the public response contract.

## Registry boundary

The existing Registry resources remain read-only:

```http
GET /api/v2/{secure_path}/module
GET /api/v2/{secure_path}/module/{id}
```

The new nested lifecycle routes are an HTTP adapter over `ModuleLifecycle`; they do not make `ModuleRegistry` mutable.

## Specialized-runtime boundary

The following remain on their existing specialized APIs because they require payloads, configuration semantics or independent security policy:

- Plugin package upload/delete and Plugin configuration;
- Theme package upload and Theme configuration;
- Agent token/action/approval lifecycle;
- MCP tool execution.

The management API MUST NOT directly mutate `tx_plugins`, theme files, migrations, Plugin.php lifecycle, Agent approval state, MySQL, Redis or TX-Node.

## Compatibility

Module Management v1 does not change:

- Plugin Package v1;
- Theme Package v1;
- Admin Bridge v1;
- Agent Ops / MCP policy;
- TXBoard ↔ TX-Node protocol.

No new Module database table or runtime state source is introduced.
