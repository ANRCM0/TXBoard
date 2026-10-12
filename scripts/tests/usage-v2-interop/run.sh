#!/bin/bash
# Actual TX-Node Reporter + Linux spool + Laravel + isolated SQL + verified TLS.
set -euo pipefail
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
export USAGE_CORE_API=$(cd -- "${1:?Pass Core api directory}" && pwd)
node_source=$(cd -- "${2:?Pass TX-Node source directory}" && pwd)
if [ "${DB_DATABASE:-}" != txboard_usage_test ]; then
  echo 'This fixture requires the exclusive disposable txboard_usage_test database.' >&2
  exit 2
fi
: "${DB_HOST:?}" "${DB_USERNAME:?}" "${DB_PASSWORD:?}"
export USAGE_INTEROP_DIR=$(mktemp -d /tmp/txboard-usage-interop.XXXXXX)
chmod 700 "$USAGE_INTEROP_DIR"
export APP_ENV=testing DB_CONNECTION=mysql
export CORE_USAGE_V2_ISOLATED_COPY=true CORE_USAGE_V2_INSTANCE_ID=core_instance_test_0001 CORE_USAGE_V2_RECEIPT_GENERATION=1
export CACHE_DRIVER=array SETTING_CACHE_STORE=array AGENT_OPS_PAIRING_CACHE_STORE=array SESSION_DRIVER=array MAIL_MAILER=array QUEUE_CONNECTION=sync
export APP_KEY='base64:ZdD07lazsdQQmdBAJ2tA5WjufeI2hP8xlP6zgmvCMZs='
mkdir "$USAGE_INTEROP_DIR/node"
(cd "$node_source" && tar -cf - go.mod go.sum internal/usagev2) | tar -xf - -C "$USAGE_INTEROP_DIR/node"
mkdir "$USAGE_INTEROP_DIR/node/interop"
cp "$script_dir/node-client.go" "$USAGE_INTEROP_DIR/node/interop/main.go"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -keyout "$USAGE_INTEROP_DIR/key.pem" -out "$USAGE_INTEROP_DIR/cert.pem" -subj /CN=localhost -addext 'subjectAltName=IP:127.0.0.1,DNS:localhost' 2>/dev/null
chmod 600 "$USAGE_INTEROP_DIR/key.pem"
cd "$USAGE_CORE_API"
php artisan migrate --force --no-interaction > "$USAGE_INTEROP_DIR/migrations.log"
php "$script_dir/fixture.php" > "$USAGE_INTEROP_DIR/fixture.json"
php -S 127.0.0.1:18081 -t public public/index.php > "$USAGE_INTEROP_DIR/core-http.log" 2>&1 &
core_pid=$!
python3 "$script_dir/tls-proxy.py" > "$USAGE_INTEROP_DIR/proxy.log" 2>&1 &
proxy_pid=$!
trap 'kill "$core_pid" "$proxy_pid" 2>/dev/null || true' EXIT
for i in $(seq 1 30); do
  if curl --silent --cacert "$USAGE_INTEROP_DIR/cert.pem" -o /dev/null -X POST https://127.0.0.1:18443/txapi/node/v1/handshake; then break; fi
  sleep 0.2
done
cd "$USAGE_INTEROP_DIR/node"
go run ./interop | tee "$USAGE_INTEROP_DIR/result.json"
echo "Evidence retained: $USAGE_INTEROP_DIR" >&2
