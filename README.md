# TXBoard Development

TXBoard Core and web applications share this source repository. TX-Node is
maintained independently in `ANRCM0/txnode`; `node/` is a pinned Git submodule for
cross-component acceptance. Clone with `git clone --recurse-submodules` or run
`git submodule update --init node` after checkout. Core owns identity, subscriptions,
billing, configuration and browser admission. Node and the web applications
consume its explicit contracts.

| Path | Development responsibility |
| --- | --- |
| `api/` | PHP Core, domain owners, migrations and extension composition |
| `packages/` | Generated Core SDK and the thin browser theme client |
| `tools/`, `examples/` | Development module host and current theme example |
| `node/` | Fixed external TX-Node commit for integration; develop in ANRCM0/txnode |
| `web/` | Administrator and user applications, plus shared frontend helpers |
| `contracts/` | Versioned operation, extension, renderer and ownership declarations |
| `scripts/` | Contract generation, static boundaries and development tooling |

Start with the [development guide](docs/development.md), the
[frontend guide](web/README.md), and the
[administrator](docs/api/core-admin-api.md) and
[user](docs/api/core-user-api.md) operation references.

The production image runs its own supervised scheduler. See the
[runtime scheduler guide](docs/runtime-scheduler.md) for task ownership,
health checks and container diagnosis.

Development documentation describes the current source and its ownership rules.
Acceptance logs, screenshots, machine-local snapshots and release histories belong
in CI artifacts or Git history rather than checked-in documentation.

Third-party license notices remain in `licenses/` and alongside vendored source.
