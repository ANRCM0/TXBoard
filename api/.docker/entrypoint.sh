#!/bin/sh
set -e

# compose bind-mounts the host's api/.env over /www/.env. If that file did not
# exist, Docker silently created a *directory* there instead, which produces
# baffling failures. Fail loudly with the fix.
if [ -d /www/.env ]; then
    echo "[entrypoint] FATAL: /www/.env is a directory, not a file." >&2
    echo "[entrypoint] Create the env file first:  cp api/.env.example api/.env" >&2
    exit 1
fi

# The single TXBoard image owns the public HTTP(S) ports through Caddy.
# Application servers stay loopback-only and are never published directly.
if [ "${ENABLE_CADDY}" = "true" ]; then
    : "${OCTANE_HOST:=127.0.0.1}"
    : "${OCTANE_PORT:=7002}"
    : "${WS_HOST:=127.0.0.1}"
    : "${WS_PORT:=8076}"
else
    : "${OCTANE_HOST:=0.0.0.0}"
    : "${OCTANE_PORT:=7001}"
    : "${WS_HOST:=0.0.0.0}"
    : "${WS_PORT:=8076}"
fi
export OCTANE_HOST OCTANE_PORT WS_HOST WS_PORT
export OCTANE_INTERNAL_PORT="${OCTANE_PORT}"

# ---------------------------------------------------------------------------
# Auto-tune worker counts based on the host (CPU + memory).
#
# Heuristic: each PHP worker (Octane/Horizon) costs ~80 MiB. After reserving
# ~300 MiB for the always-on processes (caddy/redis/ws-server/masters), divide
# the remaining budget across roles.  Any user-set ENV wins.
# ---------------------------------------------------------------------------
detect_cpus() {
    if [ -r /sys/fs/cgroup/cpu.max ]; then
        # cgroup v2: "<quota> <period>" or "max <period>"
        read -r q p < /sys/fs/cgroup/cpu.max 2>/dev/null
        if [ "$q" != "max" ] && [ -n "$q" ] && [ -n "$p" ] && [ "$p" -gt 0 ]; then
            echo $(( (q + p - 1) / p ))
            return
        fi
    fi
    nproc 2>/dev/null || echo 1
}

detect_mem_mib() {
    if [ -r /sys/fs/cgroup/memory.max ]; then
        m=$(cat /sys/fs/cgroup/memory.max 2>/dev/null)
        if [ "$m" != "max" ] && [ -n "$m" ]; then
            echo $(( m / 1024 / 1024 ))
            return
        fi
    fi
    # No cgroup limit: avoid over-provisioning on big hosts. Cap the assumed
    # budget to MEM_FALLBACK_MIB (default 1024) unless the user opts out by
    # setting it explicitly. Use whichever is smaller of MemAvailable and cap.
    avail=$(awk '/MemAvailable/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 1024)
    cap=${MEM_FALLBACK_MIB:-1024}
    [ "$avail" -lt "$cap" ] && echo "$avail" || echo "$cap"
}

CPUS=$(detect_cpus)
MEM_MIB=$(detect_mem_mib)

# Resource profile presets. RESOURCE_PROFILE selects ratios for the budget split:
#   minimal     - smallest possible footprint (~250-350 MiB), single octane worker,
#                 horizon capped to 1/1/1. Suitable for VPS with <=512 MiB RAM.
#   balanced    - default; ~80 MiB per worker, octane gets 25% of slots.
#   performance - larger reserves for opcache/caches, more aggressive horizon caps.
#   auto        - same as balanced.
: "${RESOURCE_PROFILE:=auto}"
case "$RESOURCE_PROFILE" in
    minimal)     RESERVED_MIB=200; SLOT_MIB=100; OCT_NUM=1; OCT_DEN=1; OCT_FORCE=1; auto_horizon_mem=128; auto_octane_gc=64 ;;
    performance) RESERVED_MIB=400; SLOT_MIB=70;  OCT_NUM=1; OCT_DEN=3; OCT_FORCE=0; auto_horizon_mem=384; auto_octane_gc=256 ;;
    balanced|auto|*) RESERVED_MIB=300; SLOT_MIB=80;  OCT_NUM=1; OCT_DEN=4; OCT_FORCE=0; auto_horizon_mem=256; auto_octane_gc=128 ;;
esac

BUDGET=$(( MEM_MIB - RESERVED_MIB ))
[ "$BUDGET" -lt "$SLOT_MIB" ] && BUDGET=$SLOT_MIB
SLOTS=$(( BUDGET / SLOT_MIB ))

clamp() { v=$1; lo=$2; hi=$3; [ "$v" -lt "$lo" ] && v=$lo; [ "$v" -gt "$hi" ] && v=$hi; echo "$v"; }

if [ "$OCT_FORCE" = "1" ]; then
    auto_octane=1
    auto_dp=1; auto_biz=1; auto_notif=1
else
    auto_octane=$(clamp $(( (SLOTS * OCT_NUM) / OCT_DEN )) 1 "$CPUS")
    remaining=$(( SLOTS - auto_octane - 2 ))
    [ "$remaining" -lt 3 ] && remaining=3
    auto_dp=$(clamp $(( remaining / 2 )) 1 $(( CPUS * 2 )))
    auto_biz=$(clamp $(( remaining / 4 )) 1 "$CPUS")
    auto_notif=$(clamp $(( remaining / 4 )) 1 "$CPUS")
fi

# User-set ENV always wins.
: "${OCTANE_WORKERS:=$auto_octane}"
: "${OCTANE_TASK_WORKERS:=1}"
: "${OCTANE_MAX_REQUESTS:=500}"
: "${OCTANE_GARBAGE_MB:=$auto_octane_gc}"
: "${OCTANE_MAX_EXECUTION_TIME:=60}"
: "${HORIZON_DATA_PIPELINE_MAX:=$auto_dp}"
: "${HORIZON_BUSINESS_MAX:=$auto_biz}"
: "${HORIZON_NOTIFICATION_MAX:=$auto_notif}"
: "${HORIZON_WORKER_MEMORY_MB:=$auto_horizon_mem}"
: "${HORIZON_WORKER_MAX_TIME:=0}"
: "${HORIZON_WORKER_MAX_JOBS:=0}"

export OCTANE_WORKERS OCTANE_TASK_WORKERS OCTANE_MAX_REQUESTS \
       OCTANE_GARBAGE_MB OCTANE_MAX_EXECUTION_TIME \
       HORIZON_DATA_PIPELINE_MAX HORIZON_BUSINESS_MAX HORIZON_NOTIFICATION_MAX \
       HORIZON_WORKER_MEMORY_MB HORIZON_WORKER_MAX_TIME HORIZON_WORKER_MAX_JOBS \
       RESOURCE_PROFILE

echo "[entrypoint] Auto-tune (profile=${RESOURCE_PROFILE}): cpus=${CPUS} mem=${MEM_MIB}MiB slots=${SLOTS} -> octane=${OCTANE_WORKERS} horizon(dp/biz/notif)=${HORIZON_DATA_PIPELINE_MAX}/${HORIZON_BUSINESS_MAX}/${HORIZON_NOTIFICATION_MAX} horizon_worker_mem=${HORIZON_WORKER_MEMORY_MB}MB"
echo "[entrypoint] Horizon supervisors use balance=auto with minProcesses=1, so they scale up to the cap on demand and back down when idle."

# ---------------------------------------------------------------------------
# Self-provision APP_KEY before any service starts.
#
# The key cannot be baked into the image (it would be a shared secret) and a
# fresh checkout ships .env.example with it blank, but Laravel refuses to boot
# without one: Octane dies with MissingAppKeyException, supervisord exhausts
# its restart budget and the panel answers 502 forever. Worse, env_file values
# are frozen into the container at create time, so a plain `docker compose
# restart` keeps the empty value even after .env has been fixed. Generating the
# key here and persisting it to the bind-mounted .env closes both holes: the
# panel boots before the installer runs, and the key never changes afterwards.
# ---------------------------------------------------------------------------
ensure_app_key() {
    key=$(printf '%s' "${APP_KEY:-}" | tr -d '"'"'"' ')

    if [ -z "$key" ] && [ -f /www/.env ]; then
        key=$(grep -E '^APP_KEY=' /www/.env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"'"'"' ')
    fi

    if [ -n "$key" ]; then
        export APP_KEY="$key"
        return 0
    fi

    key="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
    export APP_KEY="$key"

    if [ -f /www/.env ]; then
        new_env=$(mktemp)
        if grep -qE '^APP_KEY=' /www/.env; then
            sed "s|^APP_KEY=.*|APP_KEY=${key}|" /www/.env > "$new_env"
        else
            cat /www/.env > "$new_env"
            printf '\nAPP_KEY=%s\n' "$key" >> "$new_env"
        fi
        # `sed -i`/`mv` rename a temp file over the target, which fails with
        # EBUSY because compose bind-mounts .env as a single file. Writing
        # through the existing inode updates it in place instead. A failure
        # here must not kill the container: the key is still exported for this
        # boot, it just would not survive a recreate.
        if cat "$new_env" > /www/.env 2>/dev/null; then
            echo "[entrypoint] Generated a new APP_KEY and persisted it to /www/.env"
        else
            echo "[entrypoint] WARNING: could not persist APP_KEY to /www/.env; it is ephemeral for this boot." >&2
        fi
        rm -f "$new_env"
    else
        echo "[entrypoint] WARNING: /www/.env missing; APP_KEY is ephemeral for this boot." >&2
    fi
}
ensure_app_key

redis_reachable() {
    local host port
    host=$(grep -E '^REDIS_HOST=' /www/.env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'")
    port=$(grep -E '^REDIS_PORT=' /www/.env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'")
    command -v redis-cli >/dev/null 2>&1 || return 1
    [ -n "$host" ] || return 1
    case "$host" in
        /*) [ -S "$host" ] && redis-cli -s "$host" ping 2>/dev/null | grep -q PONG ;;
        *)  redis-cli -h "$host" -p "${port:-6379}" ping 2>/dev/null | grep -q PONG ;;
    esac
}

# Never trust INSTALLED in .env by itself. A stale marker paired with an
# empty/new database previously caused the runtime updater to create tables/plugins
# while skipping the installer, leaving a panel with no administrator.
if echo " $* " | grep -q ' txboard:install '; then
    echo "[entrypoint] Skipping txboard:update while running the installer."
elif php /www/artisan txboard:install-status --no-interaction >/dev/null 2>&1; then
    if redis_reachable; then
        echo "[entrypoint] Running txboard:update (installed database confirmed, redis reachable)..."
        php /www/artisan txboard:update --no-interaction || \
            echo "[entrypoint] WARNING: txboard:update failed; continuing so supervisor can boot anyway." >&2
    else
        echo "[entrypoint] Running txboard:update (installed database confirmed, redis not yet up, using array/sync drivers)..."
        CACHE_DRIVER=array QUEUE_CONNECTION=sync SESSION_DRIVER=array \
            php /www/artisan txboard:update --no-interaction || \
            echo "[entrypoint] WARNING: txboard:update failed; continuing so supervisor can boot anyway." >&2
    fi
else
    echo "[entrypoint] Skipping txboard:update (database has no administrator yet or is unavailable)."
fi

echo "[entrypoint] Starting services (caddy=${ENABLE_CADDY} web=${ENABLE_WEB} horizon=${ENABLE_HORIZON} ws=${ENABLE_WS_SERVER})..."
# Drop stale Octane/WorkerMan state files so the new master does not signal
# PIDs left over from a previous container run (causes Swoole kill EPERM).
rm -f /www/storage/logs/octane-server-state.json \
      /www/storage/logs/txboard-ws-server.pid 2>/dev/null || true

# Only the paths the application writes to need to be owned by www. This used to
# be `chown -R www:www /www`, which re-walked the entire tree -- including the
# whole vendor directory -- on every single container start. On the bind-mounted
# storage tree that cost seconds per boot and achieved nothing, because the
# read-only code and vendor files already ship with the right ownership.
# The mkdir also guarantees the writable tree exists when storage/ is bind
# mounted from a host checkout that has never run the application.
mkdir -p /www/storage/app/public \
         /www/storage/framework/cache/data \
         /www/storage/framework/sessions \
         /www/storage/framework/views \
         /www/storage/logs \
         /www/bootstrap/cache 2>/dev/null || true
chown -R www:www /www/storage /www/bootstrap/cache 2>/dev/null || true
chown -R www:www /www/plugins 2>/dev/null || true
[ -f /www/.env ] && chown www:www /www/.env 2>/dev/null || true
chown redis:redis /data 2>/dev/null || true
exec "$@"
