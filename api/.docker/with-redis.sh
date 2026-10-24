#!/bin/sh
set -eu

# Supervisor priority orders forks, not readiness. Wait before Laravel boots.
if [ "${ENABLE_REDIS:-true}" = "true" ]; then
    attempts=0
    until redis-cli -s /data/redis.sock ping 2>/dev/null | grep -qx PONG; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 60 ]; then
            echo '[runtime] Embedded Redis did not become ready within 60 seconds' >&2
            exit 1
        fi
        sleep 1
    done
fi

exec "$@"
