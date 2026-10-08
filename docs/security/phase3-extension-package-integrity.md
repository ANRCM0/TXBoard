# Phase 3: extension ZIP package integrity

Before extraction, plugin and theme archives reject traversal, backslashes,
dot/empty path segments, symlinks, excessive size, and duplicate paths
(including case-fold aliases). Plugin archives must have exactly one
installable `config.json` at the root or a single top-level directory and
a matching `Plugin.php` in that root.

These controls prevent ambiguous archives and accidental resource clobbering.
ZIP structural checks do **not** authenticate the publisher. Deployers must
obtain trusted, reviewed plugin code; arbitrary third-party PHP still runs
inside the TXBoard process with application privileges.
