# TXBoard Admin Navigation Registry Contract v1

Admin Navigation Registry v1 defines the host-owned navigation model for Module Platform.

Navigation declarations are metadata. They do not grant authorization, register backend routes, execute lifecycle behavior, or replace a module's specialized runtime.

## Authoritative inventory

Admin navigation is projected through the existing Module Registry descriptor:

```text
Module / legacy package declaration
        -> Module adapter
        -> Module Descriptor.admin.navigation
        -> GET /api/v2/{secure_path}/module
        -> TXBoard Admin shell
```

No separate frontend Plugin/Theme/Agent inventory may be assembled just for navigation.

## Navigation item

A Module Descriptor may contain:

```json
{
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

Fields:

- `id`: stable navigation ID within the Module;
- `title`: host-visible label;
- `path`: safe relative Module path;
- `icon`: optional host hint; unknown icon names must degrade safely;
- `order`: optional integer ordering hint.

The stable validation rules remain those of Module Package v1. Paths are relative and may contain only safe path segments. Protocols, absolute paths, backslashes, empty segments, `.` and `..` are invalid.

## Runtime visibility

The Admin shell only renders navigation for Modules that are currently eligible for host navigation.

For Plugin modules in v1 this means:

- the Module is installed;
- the Module is enabled;
- it has one or more valid navigation items.

A navigation declaration does not override permissions or runtime status.

## Plugin Package v1 compatibility

Existing Plugin Package v1 plugins keep using `config.json -> admin_menus`.

They are not required to add `manifest.json` or change package layout.

`PluginModuleAdapter` may conservatively project compatible legacy menu fields into Module navigation:

```text
admin_menus[].id     -> admin.navigation[].id, when valid
admin_menus[].title  -> admin.navigation[].title
admin_menus[].label  -> title fallback
admin_menus[].path   -> admin.navigation[].path
admin_menus[].icon   -> admin.navigation[].icon
admin_menus[].order  -> admin.navigation[].order
```

Legacy rendering metadata such as `app`, `component`, `embed`, `renderer` and CRUD/config schemas remain Plugin Runtime concerns. The Navigation Registry does not copy or execute them.

A legacy menu with an unsafe navigation path is omitted from the unified navigation projection. It must not make an otherwise valid Plugin disappear from Module Registry.

## Host route resolution

Phase G currently provides host route resolution for Plugin module navigation:

```text
Module ID: access_audit
path:      reports/daily

host route:
/plugins/access_audit/reports/daily
```

The Admin shell owns this mapping.

The destination page continues to resolve specialized Plugin rendering metadata through the existing Plugin API/runtime. The Navigation Registry is not a second page-rendering system.

## Ordering

Within one Module, the host sorts navigation by:

1. `order` ascending, default `0`;
2. `title`;
3. `path`.

Module groups are sorted by Module name, then Module ID.

## Security

Navigation metadata is untrusted presentation input until validated.

The host MUST NOT:

- navigate to an external origin from Module navigation metadata;
- interpret navigation as authorization;
- execute arbitrary JavaScript from navigation fields;
- expose secrets through navigation data;
- accept path traversal.

The Admin shell remains responsible for actual routing and authorization context.

## Octane / state

Navigation is derived from each Module Registry snapshot. Phase G does not add a process-global mutable navigation registry or database table.

Registry invalidation and runtime state remain owned by the existing specialized runtimes and request/container-scoped Module services.
