# Deployment

`compose.yaml` builds every image from the monorepo checkout.

1. Copy `api/.env.example` to `api/.env` and configure the database, Redis, app URL, and secrets.
2. Run `docker compose -f deploy/compose.yaml up -d --build`.
3. Open `http://localhost:8080/` for the user frontend or `http://localhost:8080/admin/` for the admin frontend.

TX-Node is intentionally not started by the root compose file because it normally runs on remote edge hosts with host networking. Build it with `docker build -f node/Dockerfile node` or use `node/deploy.sh`.
