# TXBoard Theme Package Contract v1

Theme Package v1 formalizes the existing TXBoard theme package shape without forcing published themes into a breaking directory migration.

The compatibility boundary remains:

```text
config.json
dashboard.blade.php
optional assets / additional theme files
```

A Theme Package declares package facts. Active state, source, installed state and health are derived by TXBoard runtime and MUST NOT be trusted from the package.

## Required files

A package ZIP may contain the theme files at the archive root or under exactly one top-level directory.

Required:

```text
config.json
dashboard.blade.php
```

The fixed v1 rendering entrypoint is `dashboard.blade.php`.

Existing themes are not required to add `manifest.json`. Module Platform derives a Module Manifest through `ThemeModuleAdapter`. A future breaking package layout requires an explicit versioned contract.

## config.json

Required package metadata:

- `name` — runtime theme identity and install directory name;
- `version` — SemVer version.

Optional package metadata:

- `description`;
- `author`;
- `compatibility.txboard` — defaults to `*` when omitted;
- `configs` — schema-driven theme settings;
- legacy/theme-specific extension fields.

The canonical schema is [schema-v1.json](./schema-v1.json), with an example under [examples/theme.json](./examples/theme.json).

Theme names must be safe single directory names. Absolute paths, separators, NUL bytes, `.` and `..` are invalid for newly installed packages.

## Configuration schema

`configs` is a list of setting descriptors. Every descriptor must have a unique non-empty `field_name`.

Additional presentation fields such as `label`, `placeholder`, `field_type`, `select_options` and `default_value` remain theme-defined and backward compatible.

Runtime configuration is stored by TXBoard under:

```text
theme_{theme-name}
```

It is not package-declared runtime state.

### Theme-owned frontend appearance

There is no global Page Appearance panel. Colors, background images and other theme visuals are configured per installed theme through Theme Management and stored under `theme_{theme-name}`. Switching the active theme selects that theme's own settings; other themes retain their settings independently.

The current public `GET /txapi/public/site-config` response contains `data.frontend_theme` (active theme name) and `data.theme_config` (public settings declared by that theme). The obsolete `GET /api/v1/guest/comm/config` route is **not registered**. The built-in user front reads the native response to apply built-in appearance, while server-rendered themes continue to receive `theme_config` as Blade view data. For auth, user, order and billing routes see [External Theme TXAPI Integration](../http/theme-integration-current.md).

Manifest fields are public presentation values by default. **Never store API secrets in browser-facing theme fields.** Use `"public": false` for server-only fields; field types `password`, `secret`, and `hidden` are also excluded from public configuration. Site identity, payments, access control and other business settings stay in system configuration.

## Active theme state

The only canonical active-theme setting is:

```text
frontend_theme
```

The built-in default is:

```text
TXBoard
```

`current_theme` is legacy read-only compatibility input. If `frontend_theme` is absent, the runtime may resolve a valid legacy theme for compatibility, but read paths do not copy it into canonical state. An invalid non-empty `frontend_theme` falls back to `TXBoard` and does not delegate authority back to `current_theme`. Only explicit theme switching writes `frontend_theme`.

## Module normalization

Theme Module IDs remain host-derived for compatibility:

```text
ModuleId::legacy('theme', runtime-theme-name)
```

Package metadata maps into the common Module descriptor, while TXBoard derives:

- `source` from system/user location;
- `installed` from runtime discovery;
- `enabled` from Theme Runtime availability;
- `active` from `ThemeService::getActiveTheme()`;
- `health` from package/runtime validation.

## Lifecycle mapping

Theme Runtime remains authoritative:

```text
ModuleLifecycle
      -> ThemeLifecycleAdapter
      -> ThemeService
```

The generic Module lifecycle only maps operations that do not need an additional payload:

| Module operation | Theme Runtime behavior |
| --- | --- |
| `enable` | `ThemeService::switch(name)` |
| `uninstall` | `ThemeService::delete(name)` for inactive user themes |

`install` / `upgrade` require a ZIP payload and remain on the specialized Theme upload flow. `disable` is unsupported because TXBoard always has an effective active theme. Theme configuration remains a specialized Theme Runtime operation, not a lifecycle mutation.

System themes and the active theme cannot be uninstalled.

## Archive security

Before extraction, Theme Runtime validates:

- maximum archive entry count;
- maximum total uncompressed size;
- empty/NUL path rejection;
- absolute Unix path rejection;
- Windows drive-path rejection;
- `..` traversal rejection;
- symbolic-link rejection;
- exactly one `config.json`;
- package root depth;
- manifest metadata;
- required `dashboard.blade.php`.

The Module layer does not duplicate these checks.

## Octane / Swoole

Theme Package validation is stateless. Module lifecycle and Registry are container-scoped. No new process-global mutable theme state or cache is introduced by Theme Package v1.
