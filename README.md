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

Two files have to exist before the first start:

```bash
cp api/.env.example api/.env        # panel settings + APP_KEY (left blank on purpose)
cp deploy/.env.example deploy/.env  # database credentials, Redis and gateway path
$EDITOR deploy/.env                 # set TXBOARD_DB_PASSWORD / TXBOARD_DB_ROOT_PASSWORD
```

Then build and start the stack, and run the installer once:

```bash
docker compose -f deploy/compose.yaml up -d --build --wait
docker compose -f deploy/compose.yaml exec -it api php artisan xboard:install
```

`--wait` blocks until MySQL and the container's embedded Redis report healthy;
installing before Redis is up makes the installer's cache step fail.

`xboard:install` reads the database and Redis settings from the container
environment, migrates the schema, creates the first administrator and prints the
generated password plus the panel URL. Re-running it later is safe: it detects
`INSTALLED=true` in `api/.env` (which is bind-mounted, so the state survives
container recreates) and reports the panel URL instead of reinstalling.

The database credentials live in `deploy/.env`; the `DB_*` keys in `api/.env`
are overridden by the compose environment and do not need to be edited.

The web gateway listens on ports `80` and `443` (`TXBOARD_HTTP_PORT` /
`TXBOARD_HTTPS_PORT`), serves the user frontend at `/`, the admin frontend at
`/admin/`, and proxies `/api/*` to the Laravel service.

### Before exposing it to the internet

Three things are deliberately left to you rather than defaulted, because the
right answer depends on where the panel runs:

1. **HTTPS.** Set `TXBOARD_SITE_ADDRESS=panel.example.com` in `deploy/.env` and
   Caddy will obtain a Let's Encrypt certificate for it (ports 80/443 must reach
   the machine and DNS must resolve first). Terminating TLS in front — Cloudflare,
   an ALB — also works: leave it unset. Either way, set `APP_URL` to the public
   HTTPS origin and `SESSION_SECURE_COOKIE=true` in `api/.env`. See
   `deploy/README.md`.
2. **Backups.** The `backup` service archives the database, the `APP_KEY` and the
   uploads on a schedule. Set `TXBOARD_BACKUP_DIR` to a different disk; run
   `docker compose -f deploy/compose.yaml run --rm backup` for one on demand.
   Restore steps are in `deploy/README.md`.
3. **CORS.** `CORS_ALLOWED_ORIGINS` is empty by default, which is correct when
   this gateway serves both SPAs and the API from one origin. Add origins only if
   a frontend genuinely lives elsewhere.

## Versioning

Components release independently from the same `main` branch:

- API images: `ghcr.io/<owner>/<repo>-api`
- Web images: `ghcr.io/<owner>/<repo>-web`
- Node images: `ghcr.io/<owner>/<repo>-node`
- Node release tags: `node-vX.Y.Z`

## Licensing and provenance

The API retains its existing MIT license in `api/LICENSE`. TX-Node contains inherited work whose redistribution terms must be confirmed before making the monorepo public; see `THIRD_PARTY_NOTICES.md`.
