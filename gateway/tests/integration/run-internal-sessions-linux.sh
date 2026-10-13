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
: "${INTERNAL_EXPECTED_CORE_COMMIT:?Pass the fixed Core snapshot commit}"
[[ "$INTERNAL_EXPECTED_CORE_COMMIT" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid Core snapshot commit' >&2; exit 2; }
export INTERNAL_EXPECTED_CORE_COMMIT
: "${INTERNAL_EMAIL_REDIS_PORT:=16380}"
: "${INTERNAL_GATEWAY_REDIS_DB:=22}"
case "$INTERNAL_GATEWAY_REDIS_DB" in
  12|22) ;;
  *) echo 'Expected dedicated Gateway fixture Redis database 12 or 22.' >&2; exit 2 ;;
esac
export INTERNAL_GATEWAY_REDIS_DB
export INTERNAL_FIXTURE_DIR=$(mktemp -d /tmp/txboard-internal-user-gateway.XXXXXX)
chmod 700 "$INTERNAL_FIXTURE_DIR"
cleanup_fixture_secrets() {
  python3 - <<'PY'
import os
from pathlib import Path
import shutil
path = Path(os.environ['INTERNAL_FIXTURE_DIR']).resolve()
if path.parent != Path('/tmp') or not path.name.startswith('txboard-internal-user-gateway.'):
    raise SystemExit('Unexpected fixture cleanup directory')
for item in path.iterdir():
    if item.name.endswith('.log') or item.name in ['result.json', 'public-evidence.json']:
        continue
    if item.is_dir() and not item.is_symlink():
        shutil.rmtree(item)
    else:
        item.unlink()
PY
}
trap cleanup_fixture_secrets EXIT
export APP_ENV=testing APP_DEBUG=false DB_CONNECTION=mysql
export CORE_INTERNAL_READONLY_ENABLED=true CORE_INTERNAL_TRUST_FILE="$INTERNAL_FIXTURE_DIR/core-trust.json"
export CORE_INTERNAL_READONLY_TRUST_FILE="$INTERNAL_FIXTURE_DIR/core-trust.json"
export CORE_SHADOW_ISOLATED_COPY=true
export CORE_INTERNAL_SESSIONS_ENABLED=true
export CACHE_DRIVER=redis SETTING_CACHE_STORE=redis SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_DRIVER=array MAIL_MAILER=array
export REDIS_HOST=127.0.0.1 REDIS_PORT="$INTERNAL_EMAIL_REDIS_PORT" REDIS_DB=14 REDIS_CACHE_DB=15
export GATEWAY_REDIS_URL="redis://127.0.0.1:${INTERNAL_EMAIL_REDIS_PORT}/${INTERNAL_GATEWAY_REDIS_DB}"
export APP_KEY='base64:ZdD07lazsdQQmdBAJ2tA5WjufeI2hP8xlP6zgmvCMZs='
python3 "$INTERNAL_CORE_API/../scripts/tests/internal-readonly/generate-pki.py" "$INTERNAL_FIXTURE_DIR"
(cd "$INTERNAL_CORE_API" && php artisan migrate --force --no-interaction) > "$INTERNAL_FIXTURE_DIR/migrations.log"
php "$INTERNAL_CORE_API/internal-readonly-fixtures/fixture.php" > "$INTERNAL_FIXTURE_DIR/actors.json"
chmod 600 "$INTERNAL_FIXTURE_DIR/actors.json"
cd "$gateway_root"
node tests/integration/internal-sessions-core-gateway.mjs
echo "Evidence retained: $INTERNAL_FIXTURE_DIR" >&2
