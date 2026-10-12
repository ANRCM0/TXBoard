#!/usr/bin/env bash
set -euo pipefail
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
export INTERNAL_CORE_API=$(cd -- "${1:?Pass fixed isolated Core api}" && pwd)
gateway_root=$(cd -- "$script_dir/../.." && pwd)
if [ "${DB_DATABASE:-}" != txboard_internal_test ]; then
  echo 'Exclusive disposable txboard_internal_test required.' >&2
  exit 2
fi
: "${DB_HOST:?}" "${DB_USERNAME:?}" "${DB_PASSWORD:?}"
export INTERNAL_FIXTURE_DIR=$(mktemp -d /tmp/txboard-internal-user-gateway.XXXXXX)
chmod 700 "$INTERNAL_FIXTURE_DIR"
export APP_ENV=testing APP_DEBUG=false DB_CONNECTION=mysql
export CORE_INTERNAL_READONLY_ENABLED=true CORE_INTERNAL_READONLY_TRUST_FILE="$INTERNAL_FIXTURE_DIR/core-trust.json"
export CACHE_DRIVER=array SETTING_CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array
export REDIS_HOST=127.0.0.1 REDIS_PORT=16379 REDIS_DB=14 REDIS_CACHE_DB=15
export APP_KEY='base64:ZdD07lazsdQQmdBAJ2tA5WjufeI2hP8xlP6zgmvCMZs='
python3 "$INTERNAL_CORE_API/../scripts/tests/internal-readonly/generate-pki.py" "$INTERNAL_FIXTURE_DIR"
(cd "$INTERNAL_CORE_API" && php artisan migrate --force --no-interaction) > "$INTERNAL_FIXTURE_DIR/migrations.log"
php "$INTERNAL_CORE_API/internal-readonly-fixtures/fixture.php" > "$INTERNAL_FIXTURE_DIR/actors.json"
chmod 600 "$INTERNAL_FIXTURE_DIR/actors.json"
cd "$gateway_root"
node tests/integration/internal-user-core-gateway.mjs
echo "Evidence retained: $INTERNAL_FIXTURE_DIR" >&2
