# Development Guide

## Source And Ownership

Core is the authority for current administrator and user sessions, operation
grants, record versions, subscriptions, billing and persisted configuration.
Gateway validates and transports declared operations. Web applications and Node
must not create a second business authority or bypass the owner of a write.

- `api/app/Core/Kernel` is the generic, frozen Kernel. Its manifest is checked by
  `scripts/check-kernel-v1.py`; unrelated feature work must not rewrite it.
- `api/app/Core` supplies host composition and infrastructure. Business rules
  belong to their owners under `api/app/Domains` and cross boundaries through
  declared ports.
- `gateway/packages/core-sdk` is the same-repository generated SDK. Both web
  applications consume this workspace instead of a separate frontend checkout.
- `node/internal` owns data-plane and provider implementations. Local replacements
  under `node/internal/deps` retain their Go sources, licenses and `TXNODE-PATCH.md`
  development notes. They are dependencies, not independent release projects.

Keep money and identifier values in their declared exact representation. Submit
observed record versions with versioned writes. A stale write must conflict and
require a fresh read; it must not silently overwrite the current value.

When a write response is lost, retain the original input and idempotency key until
its result is confirmed. Navigation must not invent a replacement write. Clear
session-bound cached data and drafts when the relevant session or grant changes.
Existing credential values are never returned to configuration forms.

## Contracts And Extensions

Edit operation declarations under `contracts/core-api`, then regenerate the SDK,
packaged Core resources and operation references with:

```sh
python scripts/generate-core-contracts.py
python scripts/generate-core-contracts.py --check
```

Do not hand-edit generated operation types or API references. The module, plugin,
theme and renderer declarations under `contracts/` define their explicit package
boundaries. Inspect the trusted development commands with:

```sh
python scripts/txboard-dev.py module --help
python scripts/txboard-dev.py renderer --help
```

Module declarations do not grant installation or execution authority. Controlled
deployment owns installation, enablement, upgrades and removal. The administrator
module, plugin and theme pages expose registry state and declared capabilities.
New user-facing product features require their own scope and acceptance.

## Local Static Checks

The lockfiles and CI definitions select the supported toolchains: Node.js 22,
PHP 8.2 and Go 1.26. Install workspace dependencies using their checked-in
lockfiles. From the repository root, the following checks do not start the
application or a database:

```sh
npm ci
npm run check:frontend
npm run typecheck:web
npm run build:web
python scripts/generate-core-contracts.py --check
python scripts/check-core-boundaries.py
python scripts/check-kernel-v1.py
python scripts/check-admin-unification.py
python scripts/check-ci-policy.py
node scripts/check-docs.mjs
git diff --check
```

Gateway has its own locked npm workspace under `gateway/`. Its `typecheck` and
`build` commands are static checks. Node builds use the tags declared by
`node/Makefile` and its workflow. Preserve package locks and runtime contracts
when removing unused source or tooling.

## Acceptance In GitHub Actions

Run unit, application, MySQL, network, container and browser acceptance in GitHub
Actions. Local syntax checks, type checks and builds do not establish functional
acceptance. Commands such as `npm run verify:web`, `make verify` and component
test targets include functional suites and belong to the acceptance environment.

| Workflow | Acceptance responsibility |
| --- | --- |
| `ci-api.yml` | Current Core application suite and external route boundaries |
| `ci-mysql.yml` | MySQL 8.4 transactions, conflicts, concurrent receipts and recovery |
| `ci-web.yml` | Frontend boundaries, components, builds and browser regressions |
| `ci-gateway.yml` | Gateway behavior, container and Core transport integration |
| `ci-node.yml` | Node tests, protocol behavior and Node image delivery |
| `ci-native-c2.yml` | Native Core, Gateway and Node integration |
| `docker-txboard.yml` | Actual Core image verification and eligible publication |
| `docs.yml` | Development documentation links and CI policy |

Record the exact candidate commit and tested tree. Check merged-main runs and
publication separately. Browser fixtures explicitly identify synthetic Gateway
responses; they do not establish live production UI integration. A green build
or published development image is not a formal release or production rollout.

Keep reproducible fixtures and active checks in source. Store run-specific
evidence in ignored `artifacts/` directories or uploaded CI artifacts. Existing
database migrations remain part of upgrade and recovery compatibility even when
their names refer to earlier schemas.
