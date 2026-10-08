# Phase 3: Plugin upgrade and dependency policy

- Manifest `require` is a package/version map. `xboard` remains an alias of
  the TXBoard application version for legacy Telegram plugins; `txboard`
  is preferred. Installed plugin dependencies must exist and be enabled
  before an installed plugin is activated.
- Supported version constraints: exact `1.2.3`, comparison
  (`>=1.2.3`, `<2.0.0`), caret `^1.2.3`, tilde `~1.2.3`, `*`.
  Unsupported syntax is rejected, not ignored.
- An enabled dependent plugin blocks disabling and uninstalling its provider.
- Upload is staged on the same filesystem. The working directory is renamed
  to a backup before promotion and restored if the upgrade fails.
- Runtime metadata (enabled state/version/config) is conservatively restored
  or disabled on failure; a failed database migration or arbitrary plugin
  cleanup cannot be guaranteed reversible. PHP classes already loaded in
  Octane workers cannot be unloaded in-place: restart workers after upgrade.
- Install only trusted PHP plugins. Filesystem staging and manifest checks
  do not sandbox untrusted code.
