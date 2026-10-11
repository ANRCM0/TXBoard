# Theme Package v2: admission validation (GW-502, phase 1)

This directory contains a **non-executing validator** for the proposed
[Gateway Theme Package v2 ADR](https://github.com/ANRCM0/TXBoard-Gateway/blob/main/docs/adr-theme-package-v2.md).

## Implemented in this phase

- Strict, one-per-archive txboard-theme.json discovery (root or one top-level directory)
- Shared v1 ZIP path, symlink, entry-count and 50 MiB uncompressed-size guards
- Additional v2 restriction to dist/, assets/, metadata and schema
- Reject PHP/shell/binary executable files, .env, private-key extensions and
  hidden server directives
- Strict manifest field vocabulary, package identity, SemVer, v1/v2 separation
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

## Deliberately NOT implemented yet

This phase **does not** modify ThemeService, Theme Package v1, public
routing, controller permissions, admin UI, Module Registry or Gateway.
It does not upload, install, activate, publish assets, render SPA/SSR,
provide version rollback or expose any v2 theme configuration.

Integration must wait until:
1. The Gateway ADR and SDK release/version metadata become authoritative.
2. Laravel obtains trusted TXBoard/Gateway/SDK versions independently of ZIP.
3. A server-owned public-field allowlist is specified. Never expose a
   field merely because a ZIP declares it in config.public.
4. A staging/atomic promotion and rollback policy is defined with a tested
   failure path (without touching user, order or traffic data).
5. SSR has an isolated, per-request credential model. Never run uploaded
   server JavaScript within Laravel or share SSR user credentials.

The validator checks declared metadata and package contents but **does not
sandbox JavaScript**. SHA-256 detects mismatches against the uploaded
manifest; it does not authenticate the theme publisher or make arbitrary JS
safe. Manual trust/supply-chain review remains necessary.

## Test

Run from the API root:

    php artisan test --filter=ThemeV2PackageTest

When implementing the runtime, add end-to-end upload/replay, negative HTTP
authorization, CSP/browser and rollback tests before closing issue #220.
