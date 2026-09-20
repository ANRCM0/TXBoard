#!/usr/bin/env sh
#
# Render the gateway environment from the panel.
#
# web/Caddyfile proxies exactly one path segment (`/{subscribe_path}/{token}`)
# to the API for subscription output, while the panel decides what
# `subscribe_path` is. If the two drift, every subscription link silently
# breaks. This script asks the running API container for the panel value and
# writes it to `deploy/.env`, which docker compose reads automatically.
#
# Usage:
#   ./sync-gateway.sh
#   docker compose -f compose.yaml up -d web
#
set -eu

cd "$(dirname "$0")"

if ! docker compose -f compose.yaml ps --status running api >/dev/null 2>&1; then
    echo "The api service must be running so the panel setting can be read." >&2
    echo "Start it with: docker compose -f compose.yaml up -d api" >&2
    exit 1
fi

assignment="$(docker compose -f compose.yaml exec -T api php artisan panel:subscribe-path --export)"

# Rewrite only our own key. deploy/.env also holds the database credentials
# (and any external-Redis overrides), so truncating the file here would
# silently drop them and break the next `docker compose up`.
if [ -f .env ]; then
    tmp="$(mktemp)"
    grep -v '^TXBOARD_SUBSCRIBE_PATH=' .env > "$tmp" || true
    printf '%s\n' "$assignment" >> "$tmp"
    mv "$tmp" .env
else
    printf '%s\n' "$assignment" > .env
fi

echo "Rendered $(pwd)/.env:"
echo "  $assignment"
echo
echo "Apply it with: docker compose -f $(pwd)/compose.yaml up -d web"
