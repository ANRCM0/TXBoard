#!/usr/bin/env bash
# CI-ONLY: synthetic older native schema upgrade and real mysqldump/restore.
# Requires APP_ENV=testing, TXBOARD_CI_RESTORE=1, MySQL 8.4 and Docker.
set -euo pipefail

: "${TXBOARD_CI_RESTORE:?TXBOARD_CI_RESTORE=1 required}"
: "${MYSQL_ROOT_PASSWORD:?set synthetic MySQL root password}"
: "${DB_PASSWORD:?set synthetic MySQL app password}"
if [[ "$TXBOARD_CI_RESTORE" != 1 || "${APP_ENV:-}" != testing ||
      "${DB_DATABASE:-}" != txboard_release_ci || "${DB_CONNECTION:-}" != mysql ]]; then
  echo 'Refusing release/restore test outside isolated synthetic MySQL database' >&2
  exit 2
fi

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/before-ledgers" "$work/backups" "$root/api/artifacts"
cp "$root"/api/database/migrations/*.php "$work/before-ledgers/"
for file in \
  2026_10_08_000001_create_traffic_batch_ledger.php \
  2026_10_09_000001_create_wallet_recharge_table.php; do
  if [[ ! -f "$work/before-ledgers/$file" ]]; then
    echo "Missing release migration: $file" >&2
    exit 1
  fi
  rm "$work/before-ledgers/$file"
done

# Always use the matching MySQL 8.4 CLI. The host may have mariadb-dump,
# which does not support mysqldump's --set-gtid-purged=OFF option.
cat > "$work/bin/mysqldump" <<'SH'
#!/bin/sh
exec docker run --rm --network host -e MYSQL_PWD mysql:8.4 mysqldump "$@"
SH
chmod 700 "$work/bin/mysqldump"
export PATH="$work/bin:$PATH"

dbcli() {
  docker run --rm -i --network host -e MYSQL_PWD mysql:8.4 mysql \
    --host=127.0.0.1 --port=3306 "$@"
}

cd "$root/api"
echo '[release-recovery] Create pre-ledger schema through historical migration files'
php artisan migrate --force --no-interaction --realpath --path="$work/before-ledgers"
php scripts/ci-release-fixture.php seed-old

echo '[release-recovery] Migrate existing synthetic money/traffic rows to current schema'
php artisan migrate --force --no-interaction
php artisan migrate --force --no-interaction
php scripts/ci-release-fixture.php verify-upgrade
php scripts/ci-release-fixture.php seed-ledgers

# The actual application backup is exercised, not a hand-crafted SQL dump.
mkdir -p storage/app storage/theme plugins
printf '%s\n' 'synthetic-release-storage-marker' > storage/app/ci-release-restore-marker.txt
printf '%s\n' 'synthetic-release-theme-marker' > storage/theme/ci-release-theme-marker.txt
printf '%s\n' 'synthetic-release-plugin-marker' > plugins/ci-release-plugin-marker.txt
DB_HOST=127.0.0.1 BACKUP_INTERVAL=0 BACKUP_RETENTION=1 \
  BACKUP_DIR="$work/backups" BACKUP_SOURCE_DIR="$root/api" \
  sh "$root/backup.sh" > "$work/backup.log"
archive="$(find "$work/backups" -mindepth 1 -maxdepth 1 -type d -print -quit)"
if [[ -z "$archive" ]]; then
  echo 'No archive from actual backup.sh' >&2
  exit 1
fi
(cd "$archive" && sha256sum -c CHECKSUMS.sha256 >/dev/null)
gzip -t "$archive/db.sql.gz"
tar -xOzf "$archive/storage-app.tar.gz" ./ci-release-restore-marker.txt |
  cmp -s - storage/app/ci-release-restore-marker.txt
tar -xOzf "$archive/storage-theme.tar.gz" ./ci-release-theme-marker.txt |
  cmp -s - storage/theme/ci-release-theme-marker.txt
tar -xOzf "$archive/plugins.tar.gz" ./ci-release-plugin-marker.txt |
  cmp -s - plugins/ci-release-plugin-marker.txt
cmp -s "$archive/env" .env

echo '[release-recovery] Restore actual backup into separate, empty MySQL schema'
MYSQL_PWD="$MYSQL_ROOT_PASSWORD" dbcli --user=root \
  --execute="CREATE DATABASE txboard_restore_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON txboard_restore_ci.* TO 'txboard_release_ci'@'%';"
# Abort if either decompressing or loading the dump fails.
gzip -dc "$archive/db.sql.gz" |
  MYSQL_PWD="$MYSQL_ROOT_PASSWORD" dbcli --user=root --database=txboard_restore_ci

echo '[release-recovery] Verify restored ledger, order, account and settings'
DB_DATABASE=txboard_restore_ci php artisan migrate --force --no-interaction
DB_DATABASE=txboard_restore_ci php scripts/ci-release-fixture.php verify-restore
DB_DATABASE=txboard_restore_ci php artisan migrate:status --no-interaction >/dev/null

# No credentials, SQL dumps, user identity, Tokens, or encrypted settings
# are uploaded to GitHub as evidence.
cat > artifacts/release-recovery.json <<'JSON'
{
  "schema_version": 1,
  "scope": "synthetic MySQL 8.4 previous-migrations-to-current upgrade",
  "migration": "pass",
  "preexisting_wallet_order_traffic": "preserved",
  "native_presentation_setting": "preserved",
  "backup": "actual backup.sh, checksum verified (database, APP_KEY, uploads, theme, plugin)",
  "restore": "isolated fresh MySQL schema, ledger and balances verified",
  "repeat_migration": "pass",
  "real_production_upgrade": "not_tested",
  "external_payment_node_agent": "not_tested"
}
JSON
echo '[release-recovery] PASS synthetic upgrade, protected backup and isolated restore'
