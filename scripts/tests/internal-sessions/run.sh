#!/usr/bin/env bash
# Real loopback mTLS + Core/Sanctum/MariaDB fixture. No production enablement.
set -euo pipefail
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
export INTERNAL_CORE_API=$(cd -- "${1:?Pass isolated Core api snapshot}" && pwd)
if [ "${DB_DATABASE:-}" != txboard_internal_test ]; then
  echo 'Exclusive disposable txboard_internal_test database required.' >&2
  exit 2
fi
: "${DB_HOST:?}" "${DB_USERNAME:?}" "${DB_PASSWORD:?}"
: "${INTERNAL_EXPECTED_CORE_COMMIT:?Pass a fixed Core snapshot commit}"
[[ "$INTERNAL_EXPECTED_CORE_COMMIT" =~ ^[0-9a-f]{40}$ ]] || exit 2
export INTERNAL_EXPECTED_CORE_COMMIT
export INTERNAL_FIXTURE_DIR=$(mktemp -d /tmp/txboard-internal-readonly.XXXXXX)
chmod 700 "$INTERNAL_FIXTURE_DIR"
trap 'python3 "$script_dir/cleanup.py" "$INTERNAL_FIXTURE_DIR"' EXIT
export APP_ENV=testing APP_DEBUG=false DB_CONNECTION=mysql
export CORE_SHADOW_ISOLATED_COPY=true CORE_INTERNAL_SESSIONS_ENABLED=true
export CORE_INTERNAL_READONLY_ENABLED=true CORE_INTERNAL_READONLY_TRUST_FILE="$INTERNAL_FIXTURE_DIR/core-trust.json"
export CACHE_DRIVER=array SETTING_CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array
export APP_KEY='base64:ZdD07lazsdQQmdBAJ2tA5WjufeI2hP8xlP6zgmvCMZs='
python3 "$script_dir/../internal-readonly/generate-pki.py" "$INTERNAL_FIXTURE_DIR"
cd "$INTERNAL_CORE_API"
# Deliberately no migrate:fresh or implicit destructive reset.
php artisan migrate --force --no-interaction > "$INTERNAL_FIXTURE_DIR/migrations.log"
php "$INTERNAL_CORE_API/internal-readonly-fixtures/fixture.php" > "$INTERNAL_FIXTURE_DIR/actors.json"
chmod 600 "$INTERNAL_FIXTURE_DIR/actors.json"
node "$script_dir/integration.mjs"
echo "Evidence retained: $INTERNAL_FIXTURE_DIR" >&2
