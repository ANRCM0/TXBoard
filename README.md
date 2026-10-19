# TXBoard Development

TXBoard is developed as one source repository. Core owns identity, subscriptions,
billing, configuration and browser admission. Node and the web applications
consume its explicit contracts.

| Path | Development responsibility |
| --- | --- |
| `api/` | PHP Core, domain owners, migrations and extension composition |
| `packages/` | Generated Core SDK and the thin browser theme client |
| `tools/`, `examples/` | Development module host and current theme example |
| `node/` | Go data plane, credential provider and protocol integrations |
| `web/` | Administrator and user applications, plus shared frontend helpers |
| `contracts/` | Versioned operation, extension, renderer and ownership declarations |
| `scripts/` | Contract generation, static boundaries and development tooling |

Start with the [development guide](docs/development.md), the
[frontend guide](web/README.md), and the
[administrator](docs/api/core-admin-api.md) and
[user](docs/api/core-user-api.md) operation references.

Development documentation describes the current source and its ownership rules.
Acceptance logs, screenshots, machine-local snapshots and release histories belong
in CI artifacts or Git history rather than checked-in documentation.

Third-party license notices remain in `licenses/` and alongside vendored source.
