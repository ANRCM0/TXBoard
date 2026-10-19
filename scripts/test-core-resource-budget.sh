#!/usr/bin/env bash
set -euo pipefail

image=${1:-txboard-ci:pr}

expect_budget() {
  local memory=$1 expected=$2 supplied=${3:-}
  local args=(--rm --network none --memory "${memory}m" -e ENABLE_CADDY=false)
  if [[ -n "$supplied" ]]; then args+=(-e "REDIS_MAXMEMORY_MIB=$supplied"); fi
  docker run "${args[@]}" "$image" sh -c \
    'test "$REDIS_MAXMEMORY_MIB" = "$1"' txboard:install "$expected"
}

expect_budget 512 64
expect_budget 1024 128
expect_budget 8192 512
expect_budget 2048 256 256

for invalid in 0 invalid 512 1024 999999999999999999999999999999; do
  if docker run --rm --network none --memory 512m -e ENABLE_CADDY=false \
    -e "REDIS_MAXMEMORY_MIB=$invalid" "$image" sh -c 'exit 0' txboard:install; then
    echo "Invalid Redis budget was accepted: $invalid" >&2
    exit 1
  fi
done

# Boot the image's actual supervisor/Redis configuration, with application
# services disabled, and prove an OOM refusal retains queued work.
docker run --rm --network none --memory 512m \
  -e ENABLE_CADDY=false -e ENABLE_WEB=false -e ENABLE_HORIZON=false \
  -e ENABLE_WS_SERVER=false -e ENABLE_REDIS=true -e REDIS_MAXMEMORY_MIB=16 \
  "$image" sh -c '
    set -e
    /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf >/tmp/resource-supervisor.log 2>&1 &
    supervisor=$!
    trap "kill $supervisor 2>/dev/null || true" EXIT
    count=0
    until redis-cli -s /data/redis.sock ping 2>/dev/null | grep -q PONG; do
      count=$((count + 1))
      if [ "$count" -gt 100 ]; then cat /tmp/resource-supervisor.log >&2; exit 1; fi
      sleep 0.1
    done
    php -r '\''
      $redis = new Redis(); $redis->connect("/data/redis.sock");
      if ($redis->config("GET", "maxmemory")["maxmemory"] !== "16777216"
          || $redis->config("GET", "maxmemory-policy")["maxmemory-policy"] !== "noeviction") {
          throw new RuntimeException("Redis resource policy was not applied");
      }
      $redis->lPush("resource-test-durable-work", "original-intent");
      $refused = false;
      try { $redis->set("resource-test-too-large", str_repeat("x", 32 * 1024 * 1024)); }
      catch (RedisException $error) { $refused = str_starts_with($error->getMessage(), "OOM"); }
      if (!$refused || $redis->lRange("resource-test-durable-work", 0, -1) !== ["original-intent"]
          || $redis->exists("resource-test-too-large")) {
          throw new RuntimeException("Redis OOM did not preserve durable work");
      }
      echo "Redis rejects oversized writes and retains original queued work.\n";
    '\''
  ' txboard:install

echo 'Core Redis memory budget acceptance passed.'
