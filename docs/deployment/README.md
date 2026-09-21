# Deployment

TXBoard has one supported deployment definition and one application image:

```text
deploy/compose.yaml
ghcr.io/paimoncai/txboard
```

The single `txboard` container serves both SPAs and runs the Laravel control
plane, Caddy, Octane, Horizon, embedded Redis and WebSocket server. MySQL and the
backup helper stay separate infrastructure services.

For 1Panel, aaPanel, Cloudflare or another reverse proxy, leave
`TXBOARD_SITE_ADDRESS` empty and proxy to the host port published by the
`txboard` service. For direct HTTPS, set it to the panel hostname and let the
container's Caddy obtain the certificate.

Update by replacing the application image/container, not by mutating code inside
it:

```sh
docker compose -f deploy/compose.yaml pull txboard
docker compose -f deploy/compose.yaml up -d --remove-orphans --wait txboard
```

When deploying directly from a checkout, `--build` rebuilds the same single
root Dockerfile instead.
