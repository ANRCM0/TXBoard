#!/usr/bin/env bash
set -euo pipefail
umask 077
# CI only. Real Core snapshot, native installation and real credential Provider.
: "${TXBOARD_CORE_REPO:?Set TXBOARD_CORE_REPO to a Core Git checkout}"
: "${TXBOARD_CORE_VENDOR:?Set TXBOARD_CORE_VENDOR to an installed api/vendor directory}"
if [[ -z ${MYSQL_TEST_SOCKET:-} ]]; then
  : "${MYSQL_TEST_HOST:?Set MYSQL_TEST_HOST=127.0.0.1 or MYSQL_TEST_SOCKET}"
  : "${MYSQL_TEST_ROOT_PASSWORD:?Set the dedicated test server root password}"
  [[ "$MYSQL_TEST_HOST" == 127.0.0.1 ]] || { echo 'Only loopback test DB allowed' >&2; exit 1; }
fi
exec 9>/tmp/txboard-gateway-native.lock
flock -n 9 || { echo 'Another Gateway Native integration is running' >&2; exit 1; }
gateway_root=$(cd "$(dirname "$0")/../.." && pwd)
scratch_parent=${GATEWAY_INTEGRATION_SCRATCH:-/tmp}
mkdir -p "$scratch_parent"
scratch=$(mktemp -d "$scratch_parent/gateway-native.XXXXXX")
# Logs/evidence survive failures; private scratch must not be uploaded wholesale.
printf 'Evidence: %s\n' "$scratch"
mkdir -p "$scratch/core" "$scratch/provider-private"
core_head=$(git -C "$TXBOARD_CORE_REPO" rev-parse HEAD)
git -C "$TXBOARD_CORE_REPO" archive "$core_head" | tar -x -C "$scratch/core"
cp -a "$TXBOARD_CORE_VENDOR" "$scratch/core/api/vendor"
printf '%s\n' "$core_head" > "$scratch/core/api/.gateway-integration-snapshot"
mkdir -p "$scratch/core/api/storage/framework/"{cache/data,sessions,views} "$scratch/core/api/storage/logs"
# No cached source configuration may override this isolated operator configuration.
rm -f "$scratch/core/api/bootstrap/cache/config.php"
(
  cd "$scratch"
  openssl req -x509 -newkey rsa:2048 -nodes -days 1 -keyout ca.key -out ca.pem -subj /CN=gateway-fixture-ca
  for name in server core node admin; do
    openssl req -new -newkey rsa:2048 -nodes -keyout "$name.key" -out "$name.csr" -subj "/CN=$name"
    if [[ "$name" == server ]]; then
      printf 'extendedKeyUsage=serverAuth\nsubjectAltName=IP:127.0.0.1,DNS:localhost\n' > "$name.ext"
    else
      printf 'extendedKeyUsage=clientAuth\n' > "$name.ext"
    fi
    openssl x509 -req -days 1 -in "$name.csr" -CA ca.pem -CAkey ca.key -CAcreateserial -out "$name.pem" -extfile "$name.ext"
  done
) > "$scratch/tls-setup.log" 2>&1
core_fingerprint=$(openssl x509 -in "$scratch/core.pem" -outform DER | openssl dgst -sha256 -r | cut -d' ' -f1)
node_fingerprint=$(openssl x509 -in "$scratch/node.pem" -outform DER | openssl dgst -sha256 -r | cut -d' ' -f1)
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
CORE_INSTALLATION_MODE=native
CORE_INSTALLATION_EPOCH=1
CORE_INTERNAL_API_ENABLED=true
CORE_INTERNAL_TLS_PROXIES=127.0.0.1
CORE_CREDENTIAL_PROVIDER_ID=primary-provider
CORE_CREDENTIAL_PROVIDER_ENDPOINT=https://127.0.0.1:18084/operations
CORE_CREDENTIAL_PROVIDER_EPOCH=1
CORE_CREDENTIAL_PROVIDER_CA_FILE=$scratch/ca.pem
CORE_CREDENTIAL_PROVIDER_CERT_FILE=$scratch/core.pem
CORE_CREDENTIAL_PROVIDER_KEY_FILE=$scratch/core.key
CORE_CREDENTIAL_PROVIDER_PROFILES=vless-uuid/v1,vmess-uuid/v1
NATIVE_PROVIDER_NODE_PROVIDER_ID=primary-provider
NATIVE_NODE_AUTHORIZATION_ENABLED=true
NATIVE_NODE_AUTHORIZATION_CORE_INSTANCE_ID=11111111-1111-4111-8111-111111111111
NATIVE_NODE_AUTHORIZATION_KEY_ID=gateway-fixture-key
NATIVE_NODE_AUTHORIZATION_KEY_EPOCH=1
NATIVE_NODE_AUTHORIZATION_PRIVATE_SEED=$(openssl rand -base64 32 | tr '+/' '-_' | tr -d '=\n')
NATIVE_NODE_AUTHORIZATION_POLICY_VERSION=native-provider-authorization/v2
CORE_USAGE_V2_INSTANCE_ID=11111111-1111-4111-8111-111111111111
ENV
# These are operator provisioned maps absent from the env-only production defaults.
# Configure the isolated snapshot for every process, preserving real admission.
cat > "$scratch/core/api/config/native_provider_node.php" <<PHP
<?php
return ['provider_id' => 'primary-provider', 'recipients' => [
  1 => ['machine_id' => null, 'recipient_sha256' => '$node_fingerprint'],
]];
PHP
cat > "$scratch/core/api/config/native_subscription_delivery.php" <<'PHP'
<?php
return ['providers' => ['primary-provider' => ['endpoint' => 'https://127.0.0.1:18085/subscription-render']]];
PHP
mysql_args=(-uroot)
if [[ -n ${MYSQL_TEST_SOCKET:-} ]]; then
  mysql_args+=(--socket="$MYSQL_TEST_SOCKET")
else
  mysql_args+=(--protocol=TCP --host="$MYSQL_TEST_HOST" --port="${MYSQL_TEST_PORT:-13306}")
fi
# Password travels via child environment, never command arguments or logs.
MYSQL_PWD=${MYSQL_TEST_ROOT_PASSWORD:-} mariadb "${mysql_args[@]}" <<'SQL'
CREATE DATABASE txboard_gateway_test;
CREATE USER IF NOT EXISTS 'txboard_gateway_test'@'%' IDENTIFIED BY 'gateway-local-test-only';
GRANT ALL PRIVILEGES ON txboard_gateway_test.* TO 'txboard_gateway_test'@'%';
CREATE USER IF NOT EXISTS 'txboard_gateway_test'@'127.0.0.1' IDENTIFIED BY 'gateway-local-test-only';
GRANT ALL PRIVILEGES ON txboard_gateway_test.* TO 'txboard_gateway_test'@'127.0.0.1';
SQL
# CREATE DATABASE deliberately refuses reuse: native initialization requires fresh data.
(cd "$scratch/core/api" && php artisan migrate --force) > "$scratch/migrations.log" 2>&1
cd "$gateway_root"
php tests/integration/core-fixture.php "$scratch/core/api" trust > "$scratch/trust.json"
(cd "$scratch/core/node" && go build -buildvcs=false -o "$scratch/credential-provider" ./cmd/credential-provider) > "$scratch/provider-build.log" 2>&1
"$scratch/credential-provider" --listen 127.0.0.1:18084 --store "$scratch/provider-private/provider.db" \
  --provider-id primary-provider --epoch 1 --tls-cert "$scratch/server.pem" --tls-key "$scratch/server.key" \
  --client-ca "$scratch/ca.pem" --client-fingerprint "$core_fingerprint" \
  --delivery-trust "$scratch/trust.json" --node-client-fingerprint "$node_fingerprint" \
  --subscription-listen 127.0.0.1:18085 --subscription-tls-cert "$scratch/server.pem" \
  --subscription-tls-key "$scratch/server.key" > "$scratch/provider.log" 2>&1 &
provider_pid=$!
trap 'kill "$provider_pid" 2>/dev/null || true; wait "$provider_pid" 2>/dev/null || true' EXIT
for attempt in {1..100}; do
  kill -0 "$provider_pid" 2>/dev/null || { echo 'Provider exited before readiness' >&2; exit 1; }
  if (echo > /dev/tcp/127.0.0.1/18084) 2>/dev/null; then break; fi
  [[ "$attempt" != 100 ]] || { echo 'Provider startup timed out' >&2; exit 1; }
  sleep 0.1
done
php tests/integration/core-fixture.php "$scratch/core/api" seed > "$scratch/fixture.json"
sed -i 's/^CORE_INSTALLATION_EPOCH=1$/CORE_INSTALLATION_EPOCH=2/' "$scratch/core/api/.env"
npm run build > "$scratch/build.log" 2>&1
TXBOARD_CORE_TEST_ROOT="$scratch/core/api" \
GATEWAY_TEST_REDIS_URL="redis://127.0.0.1:${REDIS_TEST_PORT:-16379}/12" \
GATEWAY_INTEGRATION_LOG_DIR="$scratch" \
node tests/integration/native-core-gateway.mjs | tee "$scratch/result.json"
php tests/integration/core-fixture.php "$scratch/core/api" user-service "$core_fingerprint" > "$scratch/user-service.private.json"
TXBOARD_CORE_TEST_ROOT="$scratch/core/api" GATEWAY_INTEGRATION_LOG_DIR="$scratch" \
GATEWAY_TEST_REDIS_URL="redis://127.0.0.1:${REDIS_TEST_PORT:-16379}/12" \
node tests/integration/core-user-gateway.mjs | tee "$scratch/core-user-result.json"
