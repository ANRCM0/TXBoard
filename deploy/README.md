# Deployment

`compose.yaml` builds every image from the monorepo checkout.

1. Copy `api/.env.example` to `api/.env` and configure the database, Redis, app URL, and secrets.
2. Run `docker compose -f deploy/compose.yaml up -d --build`.
3. Open `http://localhost:8080/` for the user frontend or `http://localhost:8080/admin/` for the admin frontend.

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
