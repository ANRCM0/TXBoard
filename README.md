# TXBoard

TXBoard is maintained as a single monorepo containing the control-plane API, two web frontends, and the optional node runtime.

## Repository layout

- `api/` — Laravel control-plane API and plugin runtime.
- `web/admin/` — React administration frontend.
- `web/user/` — Vue user frontend.
- `node/` — Go node runtime compatible with the TXBoard/Xboard panel protocol.
- `integrations/AccessAudit/` — panel-side plugin consumed by the API and TX-Node audit reporter.
- `contracts/` — cross-component HTTP and node protocol contracts.
- `deploy/` — full-stack deployment files.
- `.github/workflows/` — path-scoped CI and release workflows.

## Local verification

```bash
npm install
npm run verify:web
composer install --working-dir=api
composer test --working-dir=api
make -C node test
```

Each component can still be developed independently. The root scripts exist to keep cross-component changes reproducible.

## Deployment

Copy `api/.env.example` to `api/.env`, configure it, then run:

```bash
docker compose -f deploy/compose.yaml up -d --build
```

The web gateway listens on port `8080`, serves the user frontend at `/`, serves the admin frontend at `/admin/`, and proxies `/api/*` to the Laravel service.

## Versioning

Components release independently from the same `main` branch:

- API images: `ghcr.io/<owner>/<repo>-api`
- Web images: `ghcr.io/<owner>/<repo>-web`
- Node images: `ghcr.io/<owner>/<repo>-node`
- Node release tags: `node-vX.Y.Z`

## Licensing and provenance

The API retains its existing MIT license in `api/LICENSE`. TX-Node contains inherited work whose redistribution terms must be confirmed before making the monorepo public; see `THIRD_PARTY_NOTICES.md`.
