# TXBoard API

`api/` is the Laravel control-plane service for TXBoard. It owns authentication, subscriptions, orders, payments, users, server management, plugins, queues, and the node-facing V1/V2 protocol.

## Requirements

- PHP 8.2+
- Composer 2
- MySQL 5.7+ or SQLite
- Redis for queues and production caching

## Development

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

The React admin and Vue user applications live in `../web/admin` and `../web/user`. They communicate with this service through `/api/v2` and `/api/v1` respectively.

## Docker

The component Dockerfile builds directly from the checked-out `api/` source. From the repository root:

```bash
docker build -t txboard-api ./api
docker compose -f deploy/compose.yaml up -d --build
```

Published monorepo images use `ghcr.io/<owner>/<repo>-api`.

## Legacy theme

`theme/Xboard/` is retained as a compatibility fallback for existing Xboard installations. New frontend development belongs in `web/`; generated frontend assets must not be edited inside the legacy bundle.

## Provenance

This component derives from Xboard and retains the MIT license in `LICENSE`.
