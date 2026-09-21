# Deployment

TXBoard has one supported executable deployment definition:

```text
deploy/compose.yaml
```

Use the repository root README for the complete first-run, upgrade, backup and restore flow. Keeping one Compose stack avoids configuration drift between several near-identical templates.

## 1Panel / aaPanel / existing reverse proxy

The supported stack can run behind an existing control panel or reverse proxy. Pick unused host ports through `TXBOARD_HTTP_PORT` / `TXBOARD_HTTPS_PORT`, then proxy the public hostname to the TXBoard web gateway. Do not proxy directly to the Laravel/Octane process.

If TLS terminates in 1Panel, aaPanel, Cloudflare or another upstream proxy, leave `TXBOARD_SITE_ADDRESS` empty and forward the original scheme/client headers.

## External services

The default stack owns MySQL and the API container's Redis state. Advanced operators can layer a local Compose override for external MySQL/Redis or process splitting, but TXBoard no longer ships separate `compose.*.sample.yaml` variants. This keeps the repository's supported deployment contract singular.

## Updating

Use Git + the supported Compose stack:

```sh
git pull
docker compose -f deploy/compose.yaml up -d --build --wait
```

The historical `api/update.sh` in-place updater is intentionally removed; container deployments should be replaced/rebuilt rather than mutating application code inside a running container.
