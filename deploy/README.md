# Deployment stack reference

TXBoard now ships **one application image**: `ghcr.io/paimoncai/txboard`.

That image contains the Laravel control plane, React Admin, Vue User app, Caddy,
Octane, Horizon, embedded Redis, the WebSocket server, and the bundled
AccessAudit integration. MySQL and the backup helper remain separate
infrastructure services; there is no second TXBoard/web image.

## Services

| Service | Image | Role |
| --- | --- | --- |
| `txboard` | `ghcr.io/paimoncai/txboard` | Complete TXBoard control plane and public gateway. |
| `database` | `mysql:8.4.11` | MySQL data store. |
| `backup` | `mysql:8.4.11` | Periodic database + APP_KEY + uploads backup helper. |

The `txboard` container is the only service that publishes ports 80/443.

## Image layout

The root `Dockerfile` is multi-stage. Node builds both SPAs first; their static
outputs are copied into the final PHP/Swoole image under `/srv/admin` and
`/srv/user`. The final container runs only one Caddy instance, which serves
those files and proxies API/WebSocket/plugin/subscription requests directly to
the local Octane and WS processes.

The production build therefore has one application artifact and one version:
`ghcr.io/paimoncai/txboard:<tag>`.

## Running

```sh
cp api/.env.example api/.env
cp deploy/.env.example deploy/.env
# Set TXBOARD_DB_PASSWORD and TXBOARD_DB_ROOT_PASSWORD.

docker compose -f deploy/compose.yaml up -d --wait
docker compose -f deploy/compose.yaml exec txboard php artisan xboard:install
```

To build from the checkout instead of pulling the published image:

```sh
docker compose -f deploy/compose.yaml up -d --build --wait
```

Set `TXBOARD_IMAGE` in `deploy/.env` to pin a specific published tag.

## HTTPS

Set `TXBOARD_SITE_ADDRESS=panel.example.com` for Caddy-managed HTTPS. Leave it
empty for plain HTTP behind Cloudflare/nginx/another TLS terminator. Certificates
and Caddy state persist in `caddy-data` and `caddy-config`.

After enabling HTTPS, set `APP_URL=https://panel.example.com` and
`SESSION_SECURE_COOKIE=true` in `api/.env`, then restart `txboard`.

## Subscription path sync

The Caddy route for `/{subscribe_path}/{token}` must match the panel setting.
Run:

```sh
deploy/sync-gateway.sh
```

The script reads the setting from the running `txboard` container, updates
`deploy/.env`, and prints the command that recreates the one application
container.

## Backups

The backup service is intentionally not part of the TXBoard image: it is a small
MySQL client helper with independent lifecycle and no application code. Run an
on-demand backup with:

```sh
docker compose -f deploy/compose.yaml run --rm backup
```

To restore, stop the application writer with
`docker compose -f deploy/compose.yaml stop txboard`, restore the SQL, env and
storage archive, then start the stack again.

## Persistent state

`api/.env`, `api/storage`, `api/plugins`, `database-data`,
`txboard-redis`, `caddy-data`, `caddy-config` and the external backup
directory survive application image replacement.

TX-Node remains a separate project and is not part of this Compose stack.
