# TXBoard

TXBoard is the control-plane project: it contains the Laravel API, the admin and user frontends, the plugin runtime, deployment files, and the panel-side node protocol. The node runtime is maintained independently in [PaiMonCai/TX-Node](https://github.com/PaiMonCai/TX-Node) and communicates with TXBoard only through HTTP/WebSocket protocol contracts.

**Docker Compose is the supported way to deploy TXBoard.** TXBoard itself is one application image: Caddy, both frontends, Laravel/Octane, Horizon, embedded Redis and the WebSocket server all run in the single `txboard` container. MySQL and the backup helper remain infrastructure services. Everything else in this document is either configuration for that stack or instructions for working on the source.

## Deploy with Docker Compose

### Requirements

- Docker Engine 24+ with Compose v2 (`docker compose version`).
- 2 vCPU and 2 GB RAM minimum; the stack auto-tunes its worker count to the CPU and memory it is given.
- Ports 80 and 443 free, or set `TXBOARD_HTTP_PORT` / `TXBOARD_HTTPS_PORT` to something else.
- A DNS record pointing at the host **if** you want automatic HTTPS. Plain HTTP and TLS-terminated-in-front setups do not need one.

### Quick start

```bash
git clone https://github.com/PaiMonCai/TXBoard.git
cd TXBoard

cp api/.env.example api/.env        # panel settings; APP_KEY is generated for you
cp deploy/.env.example deploy/.env  # stack settings
$EDITOR deploy/.env                 # set TXBOARD_DB_PASSWORD and TXBOARD_DB_ROOT_PASSWORD

docker compose -f deploy/compose.yaml up -d --build --wait
docker compose -f deploy/compose.yaml exec -it txboard php artisan xboard:install
```

Tip: `export COMPOSE_FILE=deploy/compose.yaml` in your shell and the `-f` flag
becomes unnecessary for every command below.

`--wait` matters. It blocks until MySQL has finished initialising and the container's embedded Redis is answering, which is what makes the following `xboard:install` safe to run immediately. Installing against a database that is still starting is the most common first-run failure.

`xboard:install` migrates the schema, creates the first administrator, and prints the generated password together with the admin API prefix (`secure_path`). **Record that password now** — it is printed once. Re-running the command later is safe: it sees `INSTALLED=true` in `api/.env` and prints the panel URL instead of reinstalling.

### What you get

| URL | What it is |
| --- | --- |
| `http://<host>/` | User frontend (Vue). |
| `http://<host>/admin/` | Admin frontend (React). |
| `http://<host>/api/v1/*` | Public and user API. |
| `http://<host>/api/v2/<secure_path>/*` | Admin API. The prefix is printed by the installer and is deliberately not guessable. |
| `http://<host>/<subscribe_path>/<token>` | Subscription output. Kept in step with the panel setting by `deploy/sync-gateway.sh`. |

### Configuration

Two files, with a strict division of responsibility.

`deploy/.env` — read by Docker Compose, controls the stack itself.

| Key | Default | Purpose |
| --- | --- | --- |
| `TXBOARD_DB_PASSWORD` | *required* | Application database password. |
| `TXBOARD_DB_ROOT_PASSWORD` | *required* | MySQL root password. |
| `TXBOARD_HTTP_PORT` / `TXBOARD_HTTPS_PORT` | `80` / `443` | Published host ports. |
| `TXBOARD_SITE_ADDRESS` | *empty* | Public hostname. Set it to get automatic HTTPS; leave empty to serve plain HTTP behind an external terminator. |
| `TXBOARD_TLS_DIRECTIVE` | *empty* | Raw Caddy TLS directive, e.g. `tls internal` for a private CA. |
| `TXBOARD_BACKUP_DIR` | `./backups` | Where archives are written. Prefer another disk. |
| `TXBOARD_BACKUP_INTERVAL` / `TXBOARD_BACKUP_RETENTION` | `86400` / `7` | Seconds between backups, and how many archives to keep. |
| `TXBOARD_REDIS_HOST` / `TXBOARD_REDIS_PORT` | `/data/redis.sock` / `0` | Point at an external Redis to opt out of the embedded one. |
| `TXBOARD_SUBSCRIBE_PATH` | `s` | Must match the panel setting; render it with `deploy/sync-gateway.sh`. |
| `TXBOARD_IMAGE` | `ghcr.io/paimoncai/txboard:latest` | Single published TXBoard application image. Pin a `sha-*` tag for deterministic rollouts. |

`api/.env` — read by the application. The `DB_*` and `REDIS_*` keys are overridden by the compose environment and do not need editing. The keys that matter in production are:

| Key | Set it to |
| --- | --- |
| `APP_URL` | Your public origin, e.g. `https://panel.example.com`. Verification and password-reset mail is sent by a queued Horizon worker with no request to infer the host from, so this is the only source for those links. |
| `SESSION_SECURE_COOKIE` | `true` once you are serving HTTPS. |
| `LOG_LEVEL` | `warning` (the default). Use `debug` only while diagnosing. |
| `CORS_ALLOWED_ORIGINS` | Empty. Both frontends are served from this same origin, so no cross-origin access is needed. Add origins only if a frontend genuinely lives elsewhere. |
| `MAIL_*` | Your SMTP credentials, or registration and password-reset mail is silently dropped. |

Editing `api/.env` takes effect after `docker compose restart api`.

### Common operations

```bash
# Status and health
docker compose -f deploy/compose.yaml ps

# Follow logs (rotation caps them at 10 MB x 3 per service)
docker compose -f deploy/compose.yaml logs -f txboard

# Open a shell, or run any artisan command
docker compose -f deploy/compose.yaml exec txboard sh
docker compose -f deploy/compose.yaml exec txboard php artisan about

# Restart one service after an .env change
docker compose -f deploy/compose.yaml restart txboard

# Update to the latest source
git pull
docker compose -f deploy/compose.yaml up -d --build --wait

# Stop (keeps data) / stop and delete ALL data
docker compose -f deploy/compose.yaml down
docker compose -f deploy/compose.yaml down -v   # destroys the database and APP_KEY
```

### Backups and restore

The `backup` service archives the database, the `APP_KEY` and the uploads on a schedule. Run one on demand:

```bash
docker compose -f deploy/compose.yaml run --rm backup
```

Each archive contains `db.sql.gz`, `env`, `storage-app.tar.gz` and a `MANIFEST`. All the parts are needed together: a database dump alone is not a backup, because the encrypted columns in it cannot be read without the `APP_KEY` stored in `env`. A dump that fails, or that fails its gzip integrity check, is discarded rather than kept — so anything present in the backup directory is restorable.

To restore, stop the writers first, then replay:

```bash
docker compose -f deploy/compose.yaml stop txboard

gunzip -c deploy/backups/<stamp>/db.sql.gz | \
  docker compose -f deploy/compose.yaml exec -T database \
    sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'

cp deploy/backups/<stamp>/env api/.env
tar -xzf deploy/backups/<stamp>/storage-app.tar.gz -C api/storage/app

docker compose -f deploy/compose.yaml up -d --wait
```

### State that outlives the containers

| Path | Why it must persist |
| --- | --- |
| `database-data` volume | MySQL data directory. |
| `api/.env` bind mount | `INSTALLED=true`, the generated `APP_KEY` and Redis settings. Losing it makes the panel report itself as not installed. |
| `api/storage` bind mount | Logs, uploads, themes and sessions. |
| `api/plugins` bind mount | Installed plugins. |
| `api-redis` volume | Embedded Redis data, including `/data/redis.sock`. |
| `caddy-data` / `caddy-config` volumes | TLS certificates and the ACME account key. Without them every recreate re-requests a certificate and can trip Let's Encrypt's duplicate-certificate rate limit. |
| `TXBOARD_BACKUP_DIR` | Archives. Put it on another disk. |

### Going to production

Three things are deliberately left to you, because the right answer depends on where the panel runs:

1. **HTTPS.** Set `TXBOARD_SITE_ADDRESS=panel.example.com` in `deploy/.env`, make sure DNS resolves to this host and ports 80/443 reach it, then `docker compose -f deploy/compose.yaml up -d txboard`. Caddy obtains and renews the certificate and redirects 80 to 443. Terminating TLS in front (Cloudflare, an ALB) also works — leave the value empty. Either way set `APP_URL` and `SESSION_SECURE_COOKIE=true` in `api/.env`.
2. **Backups.** Point `TXBOARD_BACKUP_DIR` at a different disk and confirm `docker compose -f deploy/compose.yaml run --rm backup` produces an archive. Test a restore before you need one.
3. **CORS.** Leave `CORS_ALLOWED_ORIGINS` empty unless a frontend is served from a different origin.

Also worth doing: change the database passwords from their initial values, keep `APP_DEBUG=false`, and monitor disk space — the log rotation and backup retention above bound growth, but the database itself does not shrink.

### Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| `502` from the gateway | The API container is not ready. `docker compose -f deploy/compose.yaml logs txboard` — during first-run troubleshooting look for a fatal from Octane. |
| `set TXBOARD_DB_PASSWORD in deploy/.env` | Compose refuses to start with blank database credentials. Copy `deploy/.env.example` and fill them in. |
| Panel says it is not installed after a recreate | `api/.env` was deleted or replaced; it holds `INSTALLED=true` and the `APP_KEY`. Restore it from a backup. |
| `xboard:install` fails at the cache step | MySQL or Redis was not up. Use `up -d --wait` and re-run. |
| Port 80/443 already in use | Set `TXBOARD_HTTP_PORT` / `TXBOARD_HTTPS_PORT`. |
| Subscription links 404 | `TXBOARD_SUBSCRIBE_PATH` drifted from the panel setting. Run `deploy/sync-gateway.sh` and restart `txboard`. |
| Lost the admin password | `docker compose -f deploy/compose.yaml exec txboard php artisan reset:password you@example.com` — it prompts for the new one. |

## Repository layout

- `api/` — Laravel control-plane API and plugin runtime.
- `web/admin/` — React administration frontend.
- `web/user/` — Vue user frontend.
- `integrations/AccessAudit/` — optional panel-side AccessAudit plugin and its compatibility sidecar assets. This directory is the plugin source of truth.
- `contracts/` — cross-repository compatibility contracts for the web/API surface and TX-Node protocol.
- `deploy/` — the single supported Compose stack documented above.
- `docs/` — architecture, deployment notes and archived implementation records.
- `.github/workflows/` — path-scoped CI and release workflows.

## Local development and verification

The commands below are for working on the source, not for deployment.

```bash
npm install
npm run verify:web
composer install --working-dir=api
composer test --working-dir=api
```

The API and web frontends can still be developed independently. The root scripts keep panel-side changes reproducible; TX-Node has its own CI, releases, and test suite in its separate repository.

To build the single TXBoard image without starting the stack:

```bash
docker compose -f deploy/compose.yaml build
```

TX-Node is intentionally not part of this repository or the root Compose stack because it runs on remote edge hosts. Install, build, and release it from [PaiMonCai/TX-Node](https://github.com/PaiMonCai/TX-Node).

## Versioning

TXBoard publishes one control-plane image from `main`:

- `ghcr.io/paimoncai/txboard:latest`
- `ghcr.io/paimoncai/txboard:sha-<commit>`

TX-Node versions and releases independently in its own repository; TXBoard tags do not imply a TX-Node version.

## Licensing and provenance

The API retains its existing MIT license in `api/LICENSE`. TX-Node licensing and provenance are maintained in the separate TX-Node repository; see `THIRD_PARTY_NOTICES.md` for TXBoard-side notes.
