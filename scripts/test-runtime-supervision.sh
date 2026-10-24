#!/usr/bin/env bash
set -euo pipefail

image=${1:-txboard-ci:pr}

# Exercise the shipped launcher without booting Laravel or supplying credentials.
docker run --rm -i --network none --entrypoint sh "$image" -s <<'SH'
set -eu
export ENABLE_REDIS=true
/opt/txboard/with-redis.sh sh -c 'touch /tmp/application-started' &
launcher=$!
trap 'kill "$launcher" 2>/dev/null || true; redis-cli -s /data/redis.sock shutdown nosave >/dev/null 2>&1 || true' EXIT
sleep 2
test ! -e /tmp/application-started
redis-server --port 0 --unixsocket /data/redis.sock --unixsocketperm 777 \
    --daemonize yes --pidfile /tmp/wait-test-redis.pid --save '' --appendonly no
wait "$launcher"
test -f /tmp/application-started
redis-cli -s /data/redis.sock shutdown nosave >/dev/null
ENABLE_REDIS=false /opt/txboard/with-redis.sh sh -c 'touch /tmp/external-redis-started'
test -f /tmp/external-redis-started
echo 'Application launcher waits for embedded Redis and honors explicit external Redis.'
SH
