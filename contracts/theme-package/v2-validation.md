# Theme Package v2: admission validation (GW-502, phase 1)

This directory contains a **non-executing validator** for the current
[static Theme Package contract](README.md).

## Implemented in this phase

- Strict, one-per-archive txboard-theme.json discovery (root or one top-level directory)
- ZIP path, symlink, entry-count and 50 MiB uncompressed-size guards
- Additional v2 restriction to dist/, assets/, metadata and schema
- Reject PHP/shell/binary executable files, .env, private-key extensions and
  hidden server directives
- Strict manifest field vocabulary, package identity, SemVer, rejection of retired Blade package files
- Independently compare **trusted deployed** TXBoard, Gateway and SDK versions
  against manifest ranges; reject malformed, unknown and incompatible versions
- SHA-256 SRI verification for **every** bundled static asset, including JS entry
- Strict CSP and no-remote-code declarations; zero runtime dependencies
- Explicit refusal of SSR execution pending the GW-506 isolation contract
- Manifest capability and config-public values are **untrusted hints**, not grants

The provided example manifests in TXBoard-Gateway are templates, not
production-installable archives. They include placeholder SRI values and
might not list every bundled resource. They must be finalized when producing
a distributable ZIP.

## Runtime delegation

The validator never installs, publishes or executes package contents. The
current [static runtime](v2-runtime.md) owns installation, activation,
publication and rollback; ThemeService owns the canonical active selection.
Trusted TXBoard/Gateway/SDK versions and public config policy are server-owned.
SSR execution is refused. There is no old-package compatibility loader.

The validator checks declared metadata and package contents but **does not
sandbox JavaScript**. SHA-256 detects mismatches against the uploaded
manifest; it does not authenticate the theme publisher or make arbitrary JS
safe. Manual trust/supply-chain review remains necessary.

## Test

Run from the API root:

    php artisan test --filter=ThemeV2PackageTest

When implementing the runtime, add end-to-end upload/replay, negative HTTP
authorization, CSP/browser and rollback tests before closing issue #220.
