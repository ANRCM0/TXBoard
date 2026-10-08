# Phase 3: Plugin HTTP route revocation

PluginManager now nests plugin-defined web/API routes under
`EnsurePluginEnabled:<plugin_code>`. A route that persists in a
long-lived Octane router after the plugin is disabled or uninstalled
must return HTTP 404 without executing its controller.

This prevents stale *HTTP route invocation*, not arbitrary PHP execution.
A plugin service provider that has already changed the Laravel container
cannot be unloaded within the process; restart Octane and queue workers
after plugin enable/disable/upgrade/uninstall to clear long-lived state.
Standalone plugin scheduled jobs also require a scheduler reload.

Third-party PHP plugins remain trusted code and have no sandbox.
