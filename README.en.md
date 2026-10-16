# TX Core (formerly the TXBoard repository)

**A modular control plane for subscription services and managed network nodes.**

This repository contains TXBoard Core, [Gateway](gateway/README.md) and [TX-Node](node/README.md), each with its own build context and runtime boundary. It is prepared for the repository name `tx-core`. TXBoard Core owns accounts, plans, billing, nodes/machines, the admin and user applications, and extension runtimes. TX-Node remains the separate agent/data plane process.

> **Status:** Actively evolving. CI and image publication are not proof of production readiness. Validate database migrations, payments, node integration and extensions in an isolated environment before rollout.

The project has not entered formal testing. Native C1/C2 is the development
baseline; old-deployment migration and backward compatibility are not delivery
requirements. Coordinated breaking changes are allowed. C3 payments and money,
C4 formal APIs/credential lifecycle, and C5 installation/releases remain.
See the [development baseline and CI ownership](docs/development/native-development-baseline.md).

## Components

| Repository | Responsibility |
| --- | --- |
| Root `api/`, `web/` | Core control plane, Laravel TXAPI, React Admin, Vue User, contracts |
| [TXBoard-Deploy](https://github.com/ANRCM0/TXBoard-Deploy) | Sole installer and lifecycle manager for Core, Gateway and Node |
| [node/](node/README.md) | Go agent/data plane, proxy runtimes and traffic reporting |
| [gateway/](gateway/README.md) | Optional theme/user API BFF and SDK |
| [TXBoard-AccessAudit](https://github.com/ANRCM0/TXBoard-AccessAudit) | Independent audit integration |

## Installation

See [repository layout and release rules](docs/development/repository-layout.md). Old standalone repositories remain historical sources; active runtime development and image publication happen here.

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
