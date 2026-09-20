# Deployment stack reference

This is the operator's reference for the Compose stack: what each service is,
how the images are built, and how to run HTTPS and backups. For the step-by-step
first-run flow see the "Deploy with Docker Compose" section of the
[repository README](../README.md).

Every image builds from the monorepo checkout; nothing is fetched from a
registry unless you point `TXBOARD_API_IMAGE` / `TXBOARD_WEB_IMAGE` at one.

## Services

| Service | Image | Role |
| --- | --- | --- |
| `database` | `mysql:8.4.11` | MySQL. The only stateful service with its own volume. |
| `api` | built from `api/` | All-in-one application container: Octane (Swoole), Horizon, an embedded Redis on a unix socket, the WebSocket server and an internal Caddy, all under supervisord. |
| `web` | built from `web/` | Caddy gateway serving both SPAs and proxying `/api/*` to `api`. |
| `backup` | `mysql:8.4.11` | Runs `backup.sh` on an interval. |

The gateway is the only service with published ports. `api` publishes nothing,
so its internal Caddy on `7001` is unreachable from the host.

## How the images are built

`api/Dockerfile` and `web/Dockerfile` are laid out so that the expensive layers
survive ordinary edits.

- **Base images are pinned to exact releases** (`6.2.2-php8.2-alpine`,
  `mysql:8.4.11`, `caddy:2.11.4-alpine`, `node:22.23.2-alpine`). Floating tags
  such as `mysql:8.4` move under you: an unrelated rebuild could upgrade the
  database beneath a running panel, and MySQL cannot downgrade a data directory.
  Bump these deliberately, and read the release notes when you do.
- **Dependencies are installed before the source is copied.** `composer.json`
  and `composer.lock` are copied first, `composer install` runs, and only then is
  the application copied in. Editing application code therefore reuses the
  vendor layer instead of re-resolving every package.
- **`APP_COMMIT` is set last**, so cutting a new commit only rebuilds the final
  metadata layer rather than invalidating the dependency install.
- **The autoloader is optimized after the source copy.** It classmaps
  `database/seeders` and `database/factories`, so it cannot run before those
  directories exist.
- **BuildKit cache mounts** keep the Composer download cache and the npm cache
  out of the image while making them reusable across builds.
- **`api/.dockerignore` excludes local state** — `bootstrap/cache/*.php`,
  developer SQLite files, uploads, logs and the test suite. A committed
  `bootstrap/cache/config.php` would otherwise be baked into the image and
  silently override the container's own environment. PHPUnit is a dev dependency
  and `composer install --no-dev` is used, so the suite is dead weight.
- **The `web` image is multi-stage**: Node builds the two SPAs, and only the
  static output reaches the final Caddy image.

At runtime the entrypoint only `chown`s the paths the application writes to
(`storage`, `bootstrap/cache`, `plugins`, `.env`) rather than re-walking the
entire tree — including `vendor` — on every container start.

## HTTPS

The stack serves plain HTTP until you configure it. Two supported shapes:

**Let Caddy obtain the certificate.** Point DNS at this machine, open ports 80
and 443 to it, then set the hostname in `deploy/.env`:

```sh
TXBOARD_SITE_ADDRESS=panel.example.com
```

```sh
docker compose -f deploy/compose.yaml up -d web
```

Caddy requests a Let's Encrypt certificate, serves HTTPS on 443 and redirects 80
to it. Certificates and the ACME account key live in the `caddy-data` /
`caddy-config` volumes, so recreating the container neither re-requests a
certificate nor risks the duplicate-certificate rate limit.

The hostname must already resolve here when the gateway first starts: the ACME
challenge has to be reachable from the internet. If it is not, Caddy retries with
backoff and reports the failure in `docker compose logs web`.

**Terminate TLS in front.** Leave `TXBOARD_SITE_ADDRESS` unset and use
Cloudflare, an ALB or nginx. Forward `X-Forwarded-Proto` and `X-Forwarded-For`;
the API already trusts the Cloudflare ranges plus RFC1918
(`api/app/Http/Middleware/TrustProxies.php`), which is what keeps generated URLs
and client IPs correct behind a proxy.

**Private CA.** Set `TXBOARD_TLS_DIRECTIVE="tls internal"` to have Caddy issue
from its own local CA instead of Let's Encrypt — useful for an internal
deployment, though browsers warn unless that CA is trusted.

Once HTTPS is live, set these in `api/.env` and restart the API:

```
APP_URL=https://panel.example.com
SESSION_SECURE_COOKIE=true
```

`APP_URL` is not cosmetic: verification and password-reset mail is sent from a
queued Horizon worker, which has no request to infer the host from.

## Backups and restore

A `backup` service writes an archive every `TXBOARD_BACKUP_INTERVAL` seconds
(default daily) into `TXBOARD_BACKUP_DIR` (default `deploy/backups`), keeping
`TXBOARD_BACKUP_RETENTION` of them (default 7). Run one on demand:

```sh
docker compose -f deploy/compose.yaml run --rm backup
```

Each archive holds `db.sql.gz`, `env`, `storage-app.tar.gz` and a `MANIFEST`.
All the parts matter: a database dump alone is **not** a backup, because the
encrypted columns in it cannot be read without the `APP_KEY` stored in
`api/.env`. A dump that fails, or that fails its gzip integrity check, is
discarded rather than kept — so any archive that exists is restorable.

Point `TXBOARD_BACKUP_DIR` at a different disk or an NFS mount. Backups kept on
the same volume as MySQL do not survive the failure they exist to cover. It must
be a path the Docker daemon can see: with a remote or VM-based daemon, a path
that only exists inside your shell is not enough.

To restore, stop the writers first so nothing is mid-write, then replay:

```sh
docker compose -f deploy/compose.yaml stop api web

gunzip -c deploy/backups/<stamp>/db.sql.gz | \
  docker compose -f deploy/compose.yaml exec -T database \
    sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'

cp deploy/backups/<stamp>/env api/.env
tar -xzf deploy/backups/<stamp>/storage-app.tar.gz -C api/storage/app

docker compose -f deploy/compose.yaml up -d --wait
```

The dump contains `DROP TABLE` statements, so replaying it over an existing
database replaces the schema rather than merging into it.

## Logs

Every service uses Docker's `json-file` driver capped at 10 MB × 3 files. Without
that cap a busy panel eventually fills the host disk and then cannot write logs,
sessions or backups either. Application-level logs under `api/storage/logs`
are separate and are **not** rotated by Docker — `api/storage` is a bind mount,
so they are yours to manage.

## State that outlives the containers

| Path | Why it must persist |
| --- | --- |
| `database-data` (named volume) | MySQL data directory. |
| `api/.env` (bind mount) | `INSTALLED=true`, the generated `APP_KEY` and Redis settings. Losing it makes the panel report itself as not installed. |
| `api/storage` (bind mount) | Logs, themes, uploads and sessions. |
| `api/plugins` (bind mount) | Installed plugins. |
| `api-redis` (named volume) | Embedded Redis data, including `/data/redis.sock`. |
| `caddy-data` / `caddy-config` (named volumes) | TLS certificates and the ACME account key. Without them every recreate re-requests a certificate and can trip Let's Encrypt's duplicate-certificate rate limit. |
| `TXBOARD_BACKUP_DIR` (host directory) | Archives written by the `backup` service. Ideally on a different disk. |

`docker compose down` keeps all of the above. **`docker compose down -v` deletes
the named volumes**, including the database and the ACME account key, and cannot
be undone.

The `DB_*` keys in `api/.env` are ignored under compose: the API service
receives them from `deploy/.env` through the environment, which takes precedence
over the file.

## Gateway / panel sync

The gateway (`web/Caddyfile`) routes subscription output at
`/{subscribe_path}/{token}`, which has to match the panel setting
`subscribe_path`. Rather than hand-editing an env value, render it from the
panel:

```sh
docker compose -f deploy/compose.yaml up -d api   # if not already running
deploy/sync-gateway.sh
docker compose -f deploy/compose.yaml up -d web
```

`sync-gateway.sh` runs `php artisan panel:subscribe-path --export` inside the
API container and writes `deploy/.env` (read automatically by docker compose).
Re-run it after changing `subscribe_path` in the admin panel. The default `s`
matches the Caddyfile fallback, so a fresh install works before the first sync.

## TX-Node

TX-Node is intentionally not started by this compose file because it normally
runs on remote edge hosts with host networking. Build it with
`docker build -f node/Dockerfile node` or use `node/deploy.sh`.
