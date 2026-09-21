#!/usr/bin/env sh
set -eu

cd "$(dirname "$0")"

if ! docker compose -f compose.yaml ps --status running txboard >/dev/null 2>&1; then
    echo "The txboard service must be running so the panel setting can be read." >&2
    echo "Start it with: docker compose -f compose.yaml up -d txboard" >&2
    exit 1
fi

assignment="$(docker compose -f compose.yaml exec -T txboard php artisan panel:subscribe-path --export)"

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
echo "Apply it with: docker compose -f $(pwd)/compose.yaml up -d --remove-orphans txboard"
