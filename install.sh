#!/usr/bin/env bash
set -Eeuo pipefail

IMAGE="${TXBOARD_IMAGE:-ghcr.io/paimoncai/txboard:latest}"
INSTALL_DIR="${TXBOARD_INSTALL_DIR:-/opt/txboard}"
DOMAIN="${TXBOARD_DOMAIN:-}"
ADMIN_EMAIL="${TXBOARD_ADMIN_EMAIL:-}"
HTTP_PORT="${TXBOARD_HTTP_PORT:-80}"
HTTPS_PORT="${TXBOARD_HTTPS_PORT:-443}"
ASSUME_YES=0
RENDER_ONLY=0

log() { printf '[TXBoard] %s\n' "$*"; }
warn() { printf '[TXBoard] WARNING: %s\n' "$*" >&2; }
die() { printf '[TXBoard] ERROR: %s\n' "$*" >&2; exit 1; }

usage() {
  cat <<'EOF'
TXBoard image-only installer

Usage:
  install.sh [options]

Options:
  --dir PATH           Install directory (default: /opt/txboard)
  --image IMAGE        TXBoard image (default: ghcr.io/paimoncai/txboard:latest)
  --domain DOMAIN      Public domain. Empty = plain HTTP
  --email EMAIL        Initial administrator email
  --http-port PORT     Host HTTP port (default: 80)
  --https-port PORT    Host HTTPS port (default: 443)
  --yes                Non-interactive mode; accept defaults/environment values
  --render-only        Generate deployment files and validate Compose, do not start
  -h, --help           Show this help

Environment variables with the same TXBOARD_* names can be used instead.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dir) INSTALL_DIR="${2:?missing value for --dir}"; shift 2 ;;
    --image) IMAGE="${2:?missing value for --image}"; shift 2 ;;
    --domain) DOMAIN="${2-}"; shift 2 ;;
    --email) ADMIN_EMAIL="${2:?missing value for --email}"; shift 2 ;;
    --http-port) HTTP_PORT="${2:?missing value for --http-port}"; shift 2 ;;
    --https-port) HTTPS_PORT="${2:?missing value for --https-port}"; shift 2 ;;
    --yes) ASSUME_YES=1; shift ;;
    --render-only) RENDER_ONLY=1; ASSUME_YES=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) die "unknown option: $1" ;;
  esac
done

command -v docker >/dev/null 2>&1 || die "Docker Engine is required."
docker compose version >/dev/null 2>&1 || die "Docker Compose v2 is required (docker compose)."

case "$HTTP_PORT" in ''|*[!0-9]*) die "invalid HTTP port: $HTTP_PORT" ;; esac
case "$HTTPS_PORT" in ''|*[!0-9]*) die "invalid HTTPS port: $HTTPS_PORT" ;; esac

prompt_default() {
  local label="$1" default="$2" value=""
  if [[ "$ASSUME_YES" -eq 1 || ! -r /dev/tty ]]; then
    printf '%s' "$default"
    return
  fi
  if [[ -n "$default" ]]; then
    printf '%s [%s]: ' "$label" "$default" > /dev/tty
  else
    printf '%s: ' "$label" > /dev/tty
  fi
  IFS= read -r value < /dev/tty || true
  printf '%s' "${value:-$default}"
}

random_hex() {
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -hex 24
  else
    head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' | cut -c1-48
  fi
}

detect_host() {
  local host
  host="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
  printf '%s' "${host:-127.0.0.1}"
}

valid_email() {
  [[ "$1" == *@*.* ]]
}

mkdir -p "$INSTALL_DIR" 2>/dev/null || die "cannot create $INSTALL_DIR; run the installer with sudo or choose --dir."
cd "$INSTALL_DIR"
umask 077

FIRST_INSTALL=0
if [[ ! -f .env && ! -f api.env && ! -f compose.yaml ]]; then
  FIRST_INSTALL=1
elif [[ ! -f .env || ! -f api.env || ! -f compose.yaml ]]; then
  die "$INSTALL_DIR contains a partial TXBoard installation. Restore the missing .env/api.env/compose.yaml instead of overwriting it."
fi

write_compose() {
  cat > compose.yaml <<'YAML'
# Managed by the TXBoard image-only installer.
name: txboard

x-logging: &default-logging
  driver: json-file
  options:
    max-size: "10m"
    max-file: "3"

services:
  database:
    image: mysql:8.4.11
    restart: unless-stopped
    logging: *default-logging
    environment:
      MYSQL_DATABASE: ${TXBOARD_DB_DATABASE:-txboard}
      MYSQL_USER: ${TXBOARD_DB_USERNAME:-txboard}
      MYSQL_PASSWORD: ${TXBOARD_DB_PASSWORD:?missing TXBOARD_DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${TXBOARD_DB_ROOT_PASSWORD:?missing TXBOARD_DB_ROOT_PASSWORD}
    volumes:
      - database-data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "--host=127.0.0.1", "--user=root", "--password=${TXBOARD_DB_ROOT_PASSWORD:?}"]
      interval: 10s
      timeout: 5s
      retries: 12
      start_period: 40s

  txboard:
    image: ${TXBOARD_IMAGE:-ghcr.io/paimoncai/txboard:latest}
    restart: unless-stopped
    logging: *default-logging
    stop_grace_period: 30s
    depends_on:
      database:
        condition: service_healthy
    volumes:
      - ./data/storage:/www/storage
      - ./data/plugins:/www/plugins
      - ./api.env:/www/.env
      - api-redis:/data
      - caddy-data:/caddy-data
      - caddy-config:/caddy-config
    environment:
      docker: "true"
      ADMIN_ACCOUNT: ${TXBOARD_ADMIN_EMAIL}
      DB_CONNECTION: mysql
      DB_HOST: database
      DB_PORT: 3306
      DB_DATABASE: ${TXBOARD_DB_DATABASE:-txboard}
      DB_USERNAME: ${TXBOARD_DB_USERNAME:-txboard}
      DB_PASSWORD: ${TXBOARD_DB_PASSWORD:?missing TXBOARD_DB_PASSWORD}
      REDIS_HOST: /data/redis.sock
      REDIS_PORT: 0
      REDIS_PASSWORD: "null"
      SUBSCRIBE_PATH: ${TXBOARD_SUBSCRIBE_PATH:-s}
      TXBOARD_SITE_ADDRESS: ${TXBOARD_SITE_ADDRESS:-:80}
      TXBOARD_TLS_DIRECTIVE: ${TXBOARD_TLS_DIRECTIVE:-}
      ENABLE_CADDY: "true"
      ENABLE_HORIZON: "true"
      ENABLE_REDIS: "true"
      ENABLE_WS_SERVER: "true"
    ports:
      - "${TXBOARD_HTTP_PORT:-80}:80"
      - "${TXBOARD_HTTPS_PORT:-443}:443"
    healthcheck:
      test: ["CMD-SHELL", "redis-cli -s /data/redis.sock ping | grep -q PONG"]
      interval: 5s
      timeout: 5s
      retries: 24
      start_period: 10s

  backup:
    image: mysql:8.4.11
    restart: unless-stopped
    logging: *default-logging
    depends_on:
      database:
        condition: service_healthy
    entrypoint: ["/bin/sh", "/usr/local/bin/txboard-backup.sh"]
    environment:
      DB_HOST: database
      DB_PORT: 3306
      DB_DATABASE: ${TXBOARD_DB_DATABASE:-txboard}
      DB_USERNAME: ${TXBOARD_DB_USERNAME:-txboard}
      DB_PASSWORD: ${TXBOARD_DB_PASSWORD:?missing TXBOARD_DB_PASSWORD}
      BACKUP_DIR: /backups
      BACKUP_SOURCE_DIR: /backup-source
      BACKUP_INTERVAL: ${TXBOARD_BACKUP_INTERVAL:-86400}
      BACKUP_RETENTION: ${TXBOARD_BACKUP_RETENTION:-7}
    volumes:
      - ./backup.sh:/usr/local/bin/txboard-backup.sh:ro
      - ./backups:/backups
      - ./api.env:/backup-source/.env:ro
      - ./data/storage:/backup-source/storage:ro

volumes:
  database-data:
  api-redis:
  caddy-data:
  caddy-config:
YAML
}

copy_backup_script() {
  local script_dir=""
  if [[ -n "${BASH_SOURCE[0]:-}" && "${BASH_SOURCE[0]}" != "/dev/stdin" ]]; then
    script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd || true)"
  fi

  if [[ -n "$script_dir" && -f "$script_dir/backup.sh" ]]; then
    cp "$script_dir/backup.sh" backup.sh
  elif [[ "$RENDER_ONLY" -eq 0 ]]; then
    docker run --rm --entrypoint cat "$IMAGE" /opt/txboard/backup.sh > backup.sh
  elif [[ ! -f backup.sh ]]; then
    cat > backup.sh <<'EOF'
#!/bin/sh
echo "render-only placeholder; the real backup.sh is extracted from the TXBoard image during installation."
EOF
  fi
  chmod 700 backup.sh
}

if [[ "$FIRST_INSTALL" -eq 1 ]]; then
  DOMAIN="${DOMAIN:-$(prompt_default "Public domain (leave empty for HTTP/IP)" "")}"
  ADMIN_EMAIL="${ADMIN_EMAIL:-$(prompt_default "Administrator email" "admin@example.com")}"
  valid_email "$ADMIN_EMAIL" || die "invalid administrator email: $ADMIN_EMAIL"

  DB_PASSWORD="$(random_hex)"
  DB_ROOT_PASSWORD="$(random_hex)"

  if [[ -n "$DOMAIN" ]]; then
    SITE_ADDRESS="$DOMAIN"
    APP_URL="https://$DOMAIN"
    SECURE_COOKIE=true
  else
    SITE_ADDRESS=":80"
    HOST="$(detect_host)"
    if [[ "$HTTP_PORT" == "80" ]]; then
      APP_URL="http://$HOST"
    else
      APP_URL="http://$HOST:$HTTP_PORT"
    fi
    SECURE_COOKIE=false
  fi

  mkdir -p data/storage data/plugins backups

  cat > .env <<EOF
TXBOARD_IMAGE=$IMAGE
TXBOARD_DB_DATABASE=txboard
TXBOARD_DB_USERNAME=txboard
TXBOARD_DB_PASSWORD=$DB_PASSWORD
TXBOARD_DB_ROOT_PASSWORD=$DB_ROOT_PASSWORD
TXBOARD_ADMIN_EMAIL=$ADMIN_EMAIL
TXBOARD_HTTP_PORT=$HTTP_PORT
TXBOARD_HTTPS_PORT=$HTTPS_PORT
TXBOARD_SITE_ADDRESS=$SITE_ADDRESS
TXBOARD_TLS_DIRECTIVE=
TXBOARD_BACKUP_DIR=./backups
TXBOARD_BACKUP_INTERVAL=86400
TXBOARD_BACKUP_RETENTION=7
TXBOARD_SUBSCRIBE_PATH=s
EOF

  cat > api.env <<EOF
APP_NAME=TXBoard
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=$APP_URL
LOG_CHANNEL=stack
LOG_LEVEL=warning
DB_CONNECTION=mysql
DB_HOST=database
DB_PORT=3306
DB_DATABASE=txboard
DB_USERNAME=txboard
DB_PASSWORD=$DB_PASSWORD
REDIS_HOST=/data/redis.sock
REDIS_PASSWORD=null
REDIS_PORT=0
BROADCAST_DRIVER=log
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_SECURE_COOKIE=$SECURE_COOKIE
CORS_ALLOWED_ORIGINS=
CORS_ALLOWED_ORIGINS_PATTERNS=
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME=TXBoard
MAILGUN_DOMAIN=
MAILGUN_SECRET=
INSTALLED=false
EOF

  write_compose
  chmod 600 .env api.env
else
  # Existing installer-managed deployment: preserve credentials and local data.
  IMAGE="$(grep -E '^TXBOARD_IMAGE=' .env | tail -1 | cut -d= -f2- || printf '%s' "$IMAGE")"
  log "Existing installation detected; configuration and data will be preserved."
fi

copy_backup_script

if [[ "$RENDER_ONLY" -eq 1 ]]; then
  TXBOARD_DB_PASSWORD=test TXBOARD_DB_ROOT_PASSWORD=test TXBOARD_ADMIN_EMAIL=admin@example.com \
    docker compose config >/dev/null
  log "Deployment files rendered successfully in $INSTALL_DIR"
  exit 0
fi

log "Pulling public images..."
docker compose pull

INSTALLED=false
if grep -Eq '^INSTALLED=(1|true)$' api.env; then
  INSTALLED=true
fi

if [[ "$INSTALLED" == "true" ]]; then
  log "Updating existing TXBoard deployment..."
  docker compose up -d --remove-orphans --wait
  log "TXBoard is up to date."
else
  log "Starting database and TXBoard..."
  docker compose up -d --remove-orphans --wait database txboard

  log "Initializing TXBoard..."
  docker compose exec -T txboard php artisan xboard:install | tee install-result.log

  if ! grep -Eq '^INSTALLED=(1|true)$' api.env; then
    die "installation did not complete. Check: docker compose logs txboard"
  fi

  docker compose up -d backup
  docker compose restart txboard >/dev/null
  docker compose up -d --wait txboard >/dev/null
  log "TXBoard installation completed."
fi

APP_URL="$(grep -E '^APP_URL=' api.env | tail -1 | cut -d= -f2-)"
log "Install directory: $INSTALL_DIR"
log "Panel: ${APP_URL%/}/admin/"
log "Backups: $INSTALL_DIR/backups"
log "Update later: cd $INSTALL_DIR && docker compose pull && docker compose up -d --remove-orphans --wait"
