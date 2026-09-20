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
5. Open `http://localhost:8080/` for the user frontend or
   `http://localhost:8080/admin/` for the admin frontend. The installer also
   prints the admin API prefix (`secure_path`), which is what
   `/api/v2/{secure_path}/...` uses; the SPA itself always lives at `/admin/`.

## State that outlives the containers

| Path | Why it must persist |
| --- | --- |
| `database-data` (named volume) | MySQL data directory. |
| `api/.env` (bind mount) | `INSTALLED=true`, the generated `APP_KEY` and Redis settings. Losing it makes the panel report itself as not installed. |
| `api/storage` (bind mount) | Logs, themes, uploads and sessions. |
| `api/plugins` (bind mount) | Installed plugins. |
| `api-redis` (named volume) | Embedded Redis data, including `/data/redis.sock`. |

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
