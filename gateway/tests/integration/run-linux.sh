#!/usr/bin/env bash
set -euo pipefail
# Real Core + Gateway HTTP integration. All mutation is restricted to this DB.
# The caller supplies a Core checkout; only its committed HEAD is extracted.
: "${TXBOARD_CORE_REPO:?Set TXBOARD_CORE_REPO to a Core Git checkout}"
: "${TXBOARD_CORE_VENDOR:?Set TXBOARD_CORE_VENDOR to an installed api/vendor directory}"
: "${MYSQL_TEST_SOCKET:?Set MYSQL_TEST_SOCKET to the local test MariaDB/MySQL root socket}"
# Fixed ports, database and fixture settings are shared across repetitions.
exec 9>/tmp/txboard-gateway-native.lock
flock -n 9 || { printf 'Another Gateway Native integration is running\n' >&2; exit 1; }
gateway_root=$(cd "$(dirname "$0")/../.." && pwd)
scratch_parent=${GATEWAY_INTEGRATION_SCRATCH:-/tmp}
mkdir -p "$scratch_parent"
scratch=$(mktemp -d "$scratch_parent/gateway-native.XXXXXX")
mkdir -p "$scratch/core"
core_head=$(git -C "$TXBOARD_CORE_REPO" rev-parse HEAD)
git -C "$TXBOARD_CORE_REPO" archive "$core_head" | tar -x -C "$scratch/core"
cp -a "$TXBOARD_CORE_VENDOR" "$scratch/core/api/vendor"
printf '%s\n' "$core_head" > "$scratch/core/api/.gateway-integration-snapshot"
mkdir -p "$scratch/core/api/storage/framework/"{cache/data,sessions,views} "$scratch/core/api/storage/logs"
cat > "$scratch/core/api/.env" <<ENV
APP_NAME=TXBoard
APP_ENV=testing
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=false
APP_URL=http://127.0.0.1:18081
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=${MYSQL_TEST_PORT:-13306}
DB_DATABASE=txboard_gateway_test
DB_USERNAME=txboard_gateway_test
DB_PASSWORD=gateway-local-test-only
CACHE_DRIVER=file
SETTING_CACHE_STORE=file
CACHE_PREFIX=gateway_native_integration
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
MAIL_MAILER=log
MAIL_DRIVER=log
MAIL_HOST=127.0.0.1
MAIL_PORT=1
MAIL_FROM_ADDRESS=gateway-test@example.test
MAIL_FROM_NAME=Integration
LOG_CHANNEL=single
LOG_LEVEL=error
REDIS_HOST=127.0.0.1
REDIS_PORT=${REDIS_TEST_PORT:-16379}
REDIS_DB=12
REDIS_CACHE_DB=12
ENV
mariadb --socket="$MYSQL_TEST_SOCKET" -uroot <<'SQL'
CREATE DATABASE IF NOT EXISTS txboard_gateway_test;
CREATE USER IF NOT EXISTS 'txboard_gateway_test'@'127.0.0.1' IDENTIFIED BY 'gateway-local-test-only';
GRANT ALL PRIVILEGES ON txboard_gateway_test.* TO 'txboard_gateway_test'@'127.0.0.1';
SQL
(cd "$scratch/core/api" && php artisan migrate --force) > "$scratch/migrations.log" 2>&1
cd "$gateway_root"
npm run build > "$scratch/build.log" 2>&1
TXBOARD_CORE_TEST_ROOT="$scratch/core/api" \
GATEWAY_TEST_REDIS_URL="redis://127.0.0.1:${REDIS_TEST_PORT:-16379}/12" \
GATEWAY_INTEGRATION_LOG_DIR="$scratch" \
node tests/integration/native-core-gateway.mjs | tee "$scratch/result.json"
printf 'Evidence: %s\n' "$scratch"
