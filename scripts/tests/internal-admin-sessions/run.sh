#!/usr/bin/env bash
set -euo pipefail
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
export INTERNAL_CORE_API=$(cd -- "${1:?Pass isolated Core api snapshot}" && pwd)
case "${DB_DATABASE:-}" in txboard_admin_sessions_test|txboard_admin_sessions_retry_test|txboard_admin_sessions_final_test|txboard_admin_sessions_corrected_test) ;; *) echo 'Exclusive Admin plan list database required' >&2; exit 2;; esac
: "${DB_HOST:?}" "${DB_USERNAME:?}" "${DB_PASSWORD:?}"
export INTERNAL_FIXTURE_DIR=$(mktemp -d /tmp/txboard-internal-admin-sessions.XXXXXXXX)
chmod 700 "$INTERNAL_FIXTURE_DIR"
trap 'python3 "$script_dir/cleanup.py" "$INTERNAL_FIXTURE_DIR"' EXIT
export APP_ENV=testing APP_DEBUG=false DB_CONNECTION=mysql
export CORE_INTERNAL_READONLY_ENABLED=true CORE_INTERNAL_ADMIN_LOGIN_ENABLED=true CORE_INTERNAL_ADMIN_IDENTITY_ENABLED=true CORE_INTERNAL_ADMIN_DASHBOARD_ENABLED=true CORE_INTERNAL_ADMIN_ORDER_LIST_ENABLED=true CORE_INTERNAL_ADMIN_PLAN_LIST_ENABLED=true CORE_INTERNAL_ADMIN_SESSIONS_ENABLED=true CORE_SHADOW_ISOLATED_COPY=true
export CORE_INTERNAL_READONLY_TRUST_FILE="$INTERNAL_FIXTURE_DIR/core-trust.json"
export CACHE_DRIVER=array SETTING_CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array
export APP_KEY='base64:ZdD07lazsdQQmdBAJ2tA5WjufeI2hP8xlP6zgmvCMZs='
python3 "$script_dir/../internal-readonly/generate-pki.py" "$INTERNAL_FIXTURE_DIR"
cd "$INTERNAL_CORE_API"
php artisan migrate --force --no-interaction > "$INTERNAL_FIXTURE_DIR/migrations.log"
php "$script_dir/fixture.php" > "$INTERNAL_FIXTURE_DIR/actors.json"
chmod 600 "$INTERNAL_FIXTURE_DIR/actors.json"
node "$script_dir/integration.mjs"
echo "Evidence retained: $INTERNAL_FIXTURE_DIR" >&2
