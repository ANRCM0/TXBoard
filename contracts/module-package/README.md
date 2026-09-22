# TXBoard Module Package Contract v1

Module Package v1 defines the **common metadata contract** used by TXBoard's future Module Registry.

It does not replace the existing Plugin Package v1 runtime contract. Plugin, Theme and Agent integrations keep their own specialized runtime behavior; adapters will normalize them into this common Module model.

Current schema version: **1**.

## Design goals

The contract separates two kinds of state:

1. **Package declaration** — stable metadata supplied by a module manifest.
2. **Runtime descriptor** — installation, enabled/active and health state derived by TXBoard.

A package manifest MUST NOT claim runtime facts such as `enabled`, `healthy` or `installed`.

## Manifest file

Native Module Package v1 manifests use:

```text
manifest.json
```

The canonical JSON Schema is:

```text
contracts/module-package/schema-v1.json
```

Example:

```json
{
  "schema": 1,
  "module": {
    "id": "access_audit",
    "name": "Access Audit",
    "version": "1.2.0",
    "type": "plugin",
    "description": "Audit extension for TXBoard",
    "author": "TXBoard"
  },
  "compatibility": {
    "txboard": ">=1.0.0"
  },
  "capabilities": [
    "admin.menu",
    "admin.app",
    "api.route",
    "database.migration"
  ],
  "admin": {
    "navigation": [
      {
        "id": "audit",
        "title": "Access Audit",
        "path": "audit",
        "icon": "shield",
        "order": 100
      }
    ]
  }
}
```

## Module identity

Required fields:

- `module.id` — stable lowercase identifier, 2–64 characters, pattern `[a-z0-9][a-z0-9._-]*`;
- `module.name` — display name;
- `module.version` — SemVer version;
- `module.type` — one of:
  - `core`
  - `plugin`
  - `theme`
  - `integration`
  - `provider`
  - `agent`

Optional fields:

- `module.description`;
- `module.author`.

A module ID is an identity, not a directory name. Runtime adapters are responsible for mapping legacy plugin/theme names into a stable Module ID.

## Compatibility

Every native v1 manifest declares a TXBoard compatibility constraint:

```json
{
  "compatibility": {
    "txboard": ">=1.0.0"
  }
}
```

Module Package v1 treats the value as a non-empty version constraint string. Constraint evaluation belongs to the future Module Compatibility service, not the manifest DTO.

## Capabilities

Capabilities describe **what the module extends**, not which files happen to exist.

Module Package v1 defines:

```text
admin.menu
admin.settings
admin.crud
admin.app
api.route
web.route
hook.action
hook.filter
database.migration
scheduler
command
theme
payment.provider
notification.provider
agent.api
agent.admin
```

Capabilities MUST be unique and MUST come from the v1 capability registry.

Adding a new capability to the v1 registry is a contract change and requires corresponding runtime/documentation support.

## Dependencies

Optional module dependencies use a map from Module ID to version constraint:

```json
{
  "dependencies": {
    "base_reporting": ">=1.0.0"
  }
}
```

Dependency resolution is not implemented by PR A. The manifest only defines and validates the declaration.

## Admin navigation

A module may declare host navigation metadata:

```json
{
  "admin": {
    "navigation": [
      {
        "id": "dashboard",
        "title": "Dashboard",
        "path": "dashboard",
        "icon": "layout-dashboard",
        "order": 100
      }
    ]
  }
}
```

Navigation paths are safe relative paths. Absolute URLs, protocols, empty path segments and `.` / `..` traversal are invalid.

This declaration does not define how a page is rendered. Plugin Package v1 `admin_menus[].app` and schema-driven CRUD remain specialized rendering contracts.

Phase G projects validated navigation through `ModuleDescriptor.admin.navigation` and the existing Module Registry API. See [Admin Navigation Registry Contract v1](../admin-navigation/README.md).

Admin Bridge v2 is an additive host-service protocol and does not change the navigation declaration schema.

## Runtime descriptor

TXBoard runtime code may normalize a manifest into a descriptor containing state such as:

```json
{
  "id": "access_audit",
  "name": "Access Audit",
  "version": "1.2.0",
  "description": "Audit extension for TXBoard",
  "author": "TXBoard",
  "type": "plugin",
  "source": "user",
  "installed": true,
  "enabled": true,
  "active": null,
  "health": "healthy",
  "capabilities": ["admin.app"],
  "compatibility": {
    "txboard": ">=1.0.0"
  }
}
```

Runtime state is derived by TXBoard and is never trusted from the package manifest.

The descriptor may also project the already-validated intrinsic `module.description` and `module.author` fields as optional top-level metadata. This is a projection of package/system metadata, not a second state source.

A runtime descriptor may optionally expose bounded health checks:

```json
{
  "health_details": {
    "checks": {
      "schedule": true,
      "websocket_server": false
    },
    "observed_at": 1790112000
  }
}
```

`health_details` is runtime-only. It is not a Module Manifest field. Check values are boolean or null and must not contain arbitrary error text or secrets.

Initial health vocabulary:

```text
healthy
degraded
disabled
failed
incompatible
missing_dependency
```

Initial source vocabulary:

```text
system
bundled
user
external
```

## Relationship to Plugin Package v1

Plugin Package v1 remains the current stable publishing contract for plugins.

The migration model is:

```text
Plugin Package v1 config.json
        -> PluginModuleAdapter
        -> ModuleDescriptor
```

Existing plugins are **not** required to add `manifest.json` or change their runtime lifecycle in Module Platform v1.

A future Plugin Package v2 may adopt Module Manifest fields directly, but that is explicitly outside this contract step.

## Relationship to Theme and Agent Ops

Theme Package v1 preserves the specialized `config.json + dashboard.blade.php` package boundary and maps package metadata into Module descriptors through `ThemeModuleAdapter`. Theme runtime state continues to come from `ThemeService`.

Agent Ops registers as a system Agent/Integration module. Its bounded runtime health projection is defined by [Agent Ops Module Integration Contract v1](../agent-ops-module/README.md). MCP remains an optional protocol adapter and does not become a generic plugin runtime.

## PR A scope

This first contract step intentionally includes only:

- the versioned contract;
- schema;
- PHP value objects / DTOs;
- validation tests.

It intentionally does **not** include:

- Module Registry;
- database tables;
- Plugin/Theme adapters;
- lifecycle execution;
- Module API;
- Module Center;
- Admin Bridge v2;
- changes to current Plugin, Theme or Agent behavior.
