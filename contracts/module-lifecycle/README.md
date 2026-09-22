# TXBoard Module Lifecycle Contract v1

Module Lifecycle v1 defines the stable orchestration vocabulary used by TXBoard Module Platform to request lifecycle changes from specialized runtimes.

This contract does **not** replace Plugin Runtime, Theme Runtime, Agent Ops, or their authoritative state.

## Operations

The v1 operation vocabulary is:

```text
install
enable
disable
upgrade
uninstall
```

Not every Module type must support every operation. Phase C provides a lifecycle adapter only for `plugin` modules.

## Execution model

```text
ModuleLifecycle
      -> ModuleLifecycleAdapter
      -> specialized runtime
```

For Plugin modules:

```text
ModuleLifecycle
      -> PluginLifecycleAdapter
      -> PluginManager
```

The Module layer MUST NOT directly:

- mutate `v2_plugins`;
- run plugin migrations;
- require `Plugin.php`;
- call plugin `boot()` or `cleanup()`;
- delete plugin files;
- publish plugin assets.

Those behaviors remain owned by `PluginManager`.

## Plugin operation mapping

During Phase C the normalized Plugin Module ID is the Plugin Package v1 `code`.

| Module operation | Plugin Runtime call |
| --- | --- |
| `install` | `PluginManager::install(code)` |
| `enable` | `PluginManager::enable(code)` |
| `disable` | `PluginManager::disable(code)` |
| `upgrade` | `PluginManager::update(code)` |
| `uninstall` | `PluginManager::uninstall(code)` |

Package upload/download and plugin file deletion are not lifecycle operations in v1.

## Result model

A lifecycle execution produces:

```json
{
  "operation": "enable",
  "success": true,
  "module": {
    "id": "access_audit",
    "type": "plugin",
    "installed": true,
    "enabled": true
  },
  "error": null
}
```

The returned `module` is re-read from `ModuleRegistry` after mutation. Runtime state is never trusted from a package declaration or inferred from the requested operation.

## Error model

Stable error codes:

- `module_not_found` — the Registry cannot resolve the requested Module before execution;
- `unsupported_module_type` — no lifecycle adapter supports the Module type;
- `runtime_error` — the specialized runtime rejected or failed the operation;
- `state_refresh_failed` — mutation returned but the final Module state could not be resolved.

Runtime exception text is not part of the public lifecycle error contract. Lifecycle errors expose stable, non-secret diagnostics and the adapter name when relevant.

## Registry boundary

Module Registry remains read-only inventory/normalization infrastructure.

Lifecycle orchestration may read the Registry before and after a mutation, but the Registry itself does not install, enable, disable, upgrade, uninstall, switch themes, or approve Agent actions.

## Compatibility

Plugin Package v1 remains unchanged.

Phase C does not require existing plugins to add a Module manifest, rename their code, move `admin/dist`, rebuild TXBoard Admin, or rewrite lifecycle methods.

## Runtime scope

`ModuleLifecycle` and `ModuleRegistry` are container-scoped services. They do not introduce process-global mutable lifecycle state under Laravel Octane / Swoole.
