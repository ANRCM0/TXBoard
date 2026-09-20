# Deployment

`compose.yaml` builds every image from the monorepo checkout.

1. Copy `api/.env.example` to `api/.env`. Leave `APP_KEY` blank: the installer
   generates one and writes it back to the mounted file.
2. Copy `.env.example` to `.env` and set `TXBOARD_DB_PASSWORD` and
   `TXBOARD_DB_ROOT_PASSWORD`. These are required, so `docker compose` refuses to
   start with the placeholder database passwords.
3. Run `docker compose -f deploy/compose.yaml up -d --wait`. `--wait` blocks
   until MySQL and the embedded Redis pass their healthchecks; installing before
   that makes the installer's cache step fail.
4. Install the panel once and record the administrator password it prints:

   ```sh
   docker compose -f deploy/compose.yaml exec -it api php artisan xboard:install
   ```

   The installer takes the database and Redis settings from the compose
   environment, so the only question it asks is the administrator email
   (or pass `ADMIN_ACCOUNT=you@example.com` to skip it entirely).
5. Open `http://<host>:<TXBOARD_HTTP_PORT>/` for the user frontend or `/admin/`
   for the admin frontend. The installer also prints the admin API prefix
   (`secure_path`), which is what `/api/v2/{secure_path}/...` uses; the SPA
   itself always lives at `/admin/`.

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
All three parts matter: a database dump alone is **not** a backup, because the
encrypted columns in it cannot be read without the `APP_KEY` stored in
`api/.env`. A dump that fails, or that fails its gzip integrity check, is
discarded rather than kept — so any archive that exists is restorable.

Point `TXBOARD_BACKUP_DIR` at a different disk or an NFS mount. Backups kept on
the same volume as MySQL do not survive the failure they exist to cover.

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

TX-Node is intentionally not started by the root compose file because it normally runs on remote edge hosts with host networking. Build it with `docker build -f node/Dockerfile node` or use `node/deploy.sh`.
