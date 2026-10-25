# Development Guide

## Source And Ownership

Core is the authority for current administrator and user sessions, operation
grants, record versions, subscriptions, billing and persisted configuration.
Core also admits browser requests through its HTTP middleware and controllers.
Web applications and Node must not create a second business authority or bypass
the owner of a write. The default deployment runs no separate Node.js Gateway.

- `api/app/Core/Kernel` is the generic, frozen Kernel. Its manifest is checked by
  `scripts/check-kernel-v1.py`; unrelated feature work must not rewrite it.
- `api/app/Core` supplies host composition and infrastructure. Business rules
  belong to their owners under `api/app/Domains` and cross boundaries through
  declared ports.
- `packages/core-sdk` is the same-repository generated SDK. Both web
  applications consume this workspace instead of a separate frontend checkout.
- `node/` pins the independent `ANRCM0/txnode` repository as a Git submodule.
  Its Go data plane, Provider, kernel dependencies, tests, releases and images
  are owned there. Develop in that repository; update the Core pin only through
  a reviewed integration change. Preserve vendored licenses and patch notes.

Keep money and identifier values in their declared exact representation. Submit
observed record versions with versioned writes. A stale write must conflict and
require a fresh read; it must not silently overwrite the current value.

When a write response is lost, retain the original input and idempotency key until
its result is confirmed. Navigation must not invent a replacement write. Clear
session-bound cached data and drafts when the relevant session or grant changes.
Existing credential values are never returned to configuration forms.

## Contracts And Extensions

Edit operation declarations under `contracts/core-api`, then regenerate the SDK,
packaged Core resources with:

```sh
python scripts/generate-core-contracts.py
python scripts/generate-core-contracts.py --check
```

Do not hand-edit generated operation types. Update the operation references in
`docs/api/` when their admission or recovery behavior changes. The module, plugin,
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

## Browser Admission And Theme Migration

The existing `/gateway/v1/bootstrap` and `/gateway/v1/core/{user|admin}` URLs
are handled directly by PHP Core. Browser admission uses a compiled, reviewed
operation surface, strict JSON validation, current actor permissions and the
existing transactional execution and receipt owners. Internal service calls
retain their separate mTLS identity checks.

`CORE_BROWSER_ORIGIN` defaults to `APP_URL` and must match the public HTTPS origin
(localhost HTTP is allowed for development). Same-origin browser requests carry
the original user Bearer and, for administrators, `X-Core-Admin-Path`. Redis owns
the shared ingress and login limits. Caddy overwrites `X-Core-Browser-Client-IP`;
Core accepts it only from explicitly configured trusted proxy peers. Caddy
resolves the client address only through its configured trusted proxy ranges
using strict chain parsing; untrusted forwarded headers do not create an
identity. User, admin and commerce admission have independent `CORE_BROWSER_*_ENABLED` switches.

Theme SDK `0.2.0` exposes `createThemeClient` over the canonical Core user
operations. Theme package manifests must declare `engines.browser`,
`engines.contract: core-browser/v1`, and the `browser` capability block. Old
Gateway DTO, HPKE and SC-v2 clients and manifests need migration before install
or activation; they are rejected by the current theme admission contract.
Use `examples/theme-native` for the current SDK consumer and
`contracts/theme-package/examples/theme.json` for the package manifest shape.

## Local Static Checks

The lockfiles and CI definitions select the supported toolchains: Node.js 22,
PHP 8.2 and Go 1.26. Install workspace dependencies using their checked-in
lockfiles. From the repository root, the following checks do not start the
application or a database:

```sh
git submodule update --init node
python scripts/check-node-component.py
npm ci
npm run check:frontend
npm run typecheck:web
npm run build:web
python scripts/generate-core-contracts.py --check
python scripts/check-core-boundaries.py
python scripts/check-kernel-v1.py
python scripts/check-admin-unification.py
python scripts/check-browser-core-surface.py
python scripts/check-ci-policy.py
node scripts/check-docs.mjs
git diff --check
```

SDKs, web applications, the module host and theme example share the root npm
workspace and lockfile. Their type checks and builds are static checks. Go Node
builds use the tags declared by
`node/Makefile` and its workflow. Preserve package locks and runtime contracts
when removing unused source or tooling.

## Acceptance In GitHub Actions

Run unit, application, MySQL, network, container and browser acceptance in GitHub
Actions. Local syntax checks, type checks and builds do not establish functional
acceptance. Commands such as `npm run verify:web`, `make verify` and component
test targets include functional suites and belong to the acceptance environment.

| Workflow | Acceptance responsibility |
| --- | --- |
| `ci.yml` / Core API | Current Core application suite and external route boundaries |
| `ci.yml` / MySQL jobs | MySQL 8.4 transactions, conflicts, concurrent receipts and recovery |
| `ci.yml` / Web | Frontend boundaries, components, builds and browser regressions |
| `ci.yml` / browser-native-http | Real Core browser HTTP, SDK, Provider, financial and support workflows |
| External `ANRCM0/txnode` CI | Node tests, protocol behavior, pinned Core compatibility and Node releases |
| `ci.yml` / native-c2-live | Native Core and Go Node integration |
| `docker-txboard.yml` | Actual Core image verification and eligible publication |
| `ci.yml` / Static and documentation | Development documentation links, CI policy and selector regressions |

The Actions entry points are **CI** and **Images**. CI runs on every pull request
and main push. It selects component and integration jobs from the complete Git
diff using `.github/ci-paths.json`; Markdown-only changes run static checks without
starting application or database jobs. Workflow and selector changes require the
full current suite. An unavailable comparison base also selects the full suite.
Manual CI runs select all current checks; the optional `legacy_mysql` input adds
the retained full legacy MySQL regression. The **CI acceptance** job aggregates
all selected jobs and fails if selection, static checks or any selected job fails.

Historical Provider proof and migration workflows have been removed. Their
remaining fixtures may still be consumed by current native integration; removing
a workflow is not permission to remove shared fixtures. Retired Actions registry
entries are disabled separately, while run artifacts remain tied to their original
commits. Branch protection should require **CI acceptance** when adopting this
workflow layout.

Record the exact candidate commit and tested tree. Check merged-main runs and
publication separately. Browser fixtures explicitly identify synthetic Core ingress
responses; they do not establish live production UI integration. A green build
or published development image is not a formal release or production rollout.

Keep reproducible fixtures and active checks in source. Store run-specific
evidence in ignored `artifacts/` directories or uploaded CI artifacts. Existing
database migrations remain part of upgrade and recovery compatibility even when
their names refer to earlier schemas.

## Independent Node Adoption

Node main, SemVer tags and development images advance in `ANRCM0/txnode`.
Core releases no longer build, tag or attach Node release binaries. Core and Node
have independent versions; explicit protocol compatibility and integration proof
determine whether a pair is supported. Existing `txboard-node` image names remain.

Core's `node/` gitlink is the authoritative integration revision. Initialize it
with `git submodule update --init node`; do not use `--remote` in acceptance.
To adopt an already tested Node revision, fetch and check out that exact commit in
`node/`, then stage the gitlink with `git add node`. Core API, real HTTP, native C2
and image acceptance must verify the new pair before merge. Node source changes
are committed and reviewed in the Node repository, never bundled back into Core.

Git source archives omit submodule contents. Integration snapshot tooling exports
the verified Node commit explicitly and records its repository, revision and tree.
The renderer source fingerprints continue to verify the pinned Provider source.
Node owns its renderer package fingerprints in `compatibility/renderers.v1.json`.
Its compatibility CI checks the supported Core interface and actual delivery,
so compatible implementation updates can be reviewed and released independently.
When Core adopts a new renderer implementation, review and version the combined
Core renderer declaration and source fingerprints along with the Node pin.


## Native Node connection management candidate

The node detail page uses three closed Core operations: `network.nodes.connections.list.request`,
`network.nodes.connections.close`, and `network.nodes.connections.result.get`. The first requires
`network.nodes.read`, close requires `network.nodes.manage`, and result reads recheck read permission
and the original requesting administrator. Requests use existing workflow idempotency receipts.
The Node list remains an instantaneous bounded snapshot, with opaque process-scoped IDs and no
addresses or credentials. Unknown outcomes require observation; the UI does not automatically
repeat a close operation.

Core dispatch sends only a cached request ID through Redis and rechecks the live node membership,
credential and socket before forwarding the original command. The Node delegates to the existing
Service/kernel/session owner, retaining its cross-reload and foreign-node semantics. Results must
match the original request and currently dispatched socket; revocation, socket replacement and
expiry cannot manufacture a successful close. This is an explicit connection command contract,
not a generic remote command channel.

The dedicated `Native Node connection management` Actions job checks out the exact Core candidate
and the recorded Node submodule. It runs the private real Workerman/MySQL/Redis/Provider fixture,
requires the three reviewed operation contracts, and verifies pagination, a foreign-node rejection,
workflow replay, actual target EOF and continued traffic on the other live streams. The existing
native activation, membership, token rotation, durable tail replay and unique SQL assertions still
execute. Its bounded public artifact records source SHAs/trees, assertions and evidence hashes.
Functional Actions, main integration, Node image/release publication and deployment acceptance
remain separate records. S3 performance and actual host rollout are not implied by this candidate.
