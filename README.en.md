# TXBoard

**A modular control plane for subscription services and managed network nodes.**

TXBoard owns accounts, plans, billing, nodes/machines, the admin and user applications, and extension runtimes. [TX-Node](https://github.com/ANRCM0/TX-Node) is the separate agent/data plane.

> **Status:** Actively evolving. CI and image publication are not proof of production readiness. Validate database migrations, payments, node integration and extensions in an isolated environment before rollout.

## Components

| Repository | Responsibility |
| --- | --- |
| [TXBoard](https://github.com/ANRCM0/TXBoard) | Core control plane, Laravel TXAPI, React Admin, Vue User, contracts |
| [TXBoard-Deploy](https://github.com/ANRCM0/TXBoard-Deploy) | Public install/upgrade/backup/operations scripts |
| [TX-Node](https://github.com/ANRCM0/TX-Node) | Go agent/data plane, proxy runtimes and traffic reporting |
| [TXBoard-Gateway](https://github.com/ANRCM0/TXBoard-Gateway) | Optional theme/user API BFF and SDK; **not** the embedded MCP adapter |
| [TXBoard-AccessAudit](https://github.com/ANRCM0/TXBoard-AccessAudit) | Independent audit integration |

## Installation

Use the public installer described in [TXBoard-Deploy](https://github.com/ANRCM0/TXBoard-Deploy). On a trusted Linux amd64/arm64 host, inspect the installer before executing it:

```bash
curl -fL https://raw.githubusercontent.com/ANRCM0/TXBoard-Deploy/main/install.sh -o install.sh
less install.sh
sudo bash install.sh
```

Use a validated version tag or image digest for production, not an untested development image. **Never point a fresh installation at an existing XBoard/TXBoard production database** without a separately verified migration. TXBoard uses native `tx_*` application tables.

## Documentation

- [Documentation index (Chinese)](docs/README.md)
- [Getting started](docs/getting-started/introduction.md)
- [Architecture and project boundaries](docs/architecture/README.md)
- [Operations and release acceptance](docs/operations/README.md)
- [Development](docs/development/README.md)
- [Versioned contracts](contracts/README.md)
- [Security](docs/security/README.md)
- [Contributing](CONTRIBUTING.md)

**Licensing note:** `api/composer.json` declares MIT for the backend package. Repository-wide licensing and third-party attribution remain to be verified before a root LICENSE is asserted.