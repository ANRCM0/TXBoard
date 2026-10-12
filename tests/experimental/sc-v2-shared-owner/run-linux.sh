#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../../.."
command -v redis-server >/dev/null
fixture_dir=$(mktemp -d /tmp/txboard-sc-owner.XXXXXXXX)
fixture_socket="$fixture_dir/redis.sock"
fixture_pid=''
cleanup() {
  if [[ -n "$fixture_pid" ]] && kill -0 "$fixture_pid" 2>/dev/null; then
    kill -TERM "$fixture_pid" 2>/dev/null || true
    wait "$fixture_pid" 2>/dev/null || true
  fi
  # Directory is launcher-owned, resolved under /tmp, and server/workers have stopped.
  case "$fixture_dir" in /tmp/txboard-sc-owner.*) rm -rf -- "$fixture_dir";; *) exit 1;; esac
}
trap cleanup EXIT
redis-server --port 0 --unixsocket "$fixture_socket" --unixsocketperm 700 \
  --dir "$fixture_dir" --appendonly yes --appendfsync always --save '' \
  --maxmemory 16mb --maxmemory-policy noeviction --daemonize no >"$fixture_dir/redis.log" 2>&1 &
fixture_pid=$!
for ((index=0; index<100; index++)); do
  if [[ -S "$fixture_socket" ]] && redis-cli -s "$fixture_socket" ping >/dev/null 2>&1; then break; fi
  sleep 0.05
done
redis-cli -s "$fixture_socket" ping >/dev/null
export SC_OWNER_REDIS_SOCKET="$fixture_socket" SC_OWNER_REDIS_PID="$fixture_pid"
printf 'SC_OWNER_FIXTURE_ONLY\n'
redis-server --version
if [[ "${1:-}" == '--ttl' ]]; then
  node --test tests/experimental/sc-v2-shared-owner/ttl.integration.test.mjs
elif [[ -z "${1:-}" ]]; then
  node --test tests/experimental/sc-v2-shared-owner/shared-owner.test.mjs
  node --test tests/experimental/sc-v2-shared-owner/redis.integration.test.mjs
else
  printf 'Invalid fixture option\n' >&2
  exit 1
fi
