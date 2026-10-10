#!/usr/bin/env bash
# Regression: an unsuccessful mysqldump must never produce a retained archive.
set -euo pipefail

script="${1:?usage: test-backup.sh path/to/backup.sh}"
test -f "$script"
script="$(realpath "$script")"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/backups" "$work/source/storage/app" "$work/source/storage/theme" "$work/source/plugins"
printf 'APP_KEY=base64:regression-test-key\n' > "$work/source/.env"
printf 'upload\n' > "$work/source/storage/app/example.txt"
printf 'installed theme\n' > "$work/source/storage/theme/theme.txt"
printf 'installed plugin\n' > "$work/source/plugins/plugin.txt"

cat > "$work/bin/mysqldump" <<'SH'
#!/bin/sh
printf '%s\n' 'CREATE TABLE backup_regression (id int);'
if [ "${MOCK_DUMP_FAIL:-0}" = 1 ]; then
  printf '%s\n' 'simulated mysqldump failure after partial output' >&2
  exit 27
fi
SH
chmod +x "$work/bin/mysqldump"

# Freeze only the archive folder stamp so the collision test is deterministic.
cat > "$work/bin/date" <<'SH'
#!/bin/sh
if [ "$1" = "-u" ] && [ "$2" = "+%Y%m%dT%H%M%SZ" ]; then
  printf '%s\n' '20260101T000000Z'
else
  exec /bin/date "$@"
fi
SH
chmod +x "$work/bin/date"

export PATH="$work/bin:$PATH"
export DB_HOST=database DB_PORT=3306 DB_DATABASE=txboard DB_USERNAME=test DB_PASSWORD=dummy
export BACKUP_DIR="$work/backups" BACKUP_SOURCE_DIR="$work/source"
export BACKUP_INTERVAL=0 BACKUP_RETENTION=7

# A good dump produces a gzip archive containing the database and key.
sh "$script" > "$work/success.log"
archive="$(find "$work/backups" -name db.sql.gz -print -quit)"
test -n "$archive"
gzip -cd "$archive" | grep -Fq 'CREATE TABLE backup_regression'
test -f "$(dirname "$archive")/env"
test -f "$(dirname "$archive")/storage-app.tar.gz"
test -f "$(dirname "$archive")/storage-theme.tar.gz"
test -f "$(dirname "$archive")/plugins.tar.gz"
# A second backup with the same stamp must neither overwrite nor delete
# the first valid archive (even when the caller is asked to retry).
if sh "$script" > "$work/collision.log" 2>&1; then
  echo 'timestamp-colliding backup unexpectedly succeeded' >&2
  exit 1
fi
test -s "$archive"
(cd "$(dirname "$archive")" && sha256sum -c CHECKSUMS.sha256 >/dev/null)
tar -xOzf "$(dirname "$archive")/storage-theme.tar.gz" ./theme.txt | grep -Fq 'installed theme'
tar -xOzf "$(dirname "$archive")/plugins.tar.gz" ./plugin.txt | grep -Fq 'installed plugin'
test ! -e "$(dirname "$archive")/db.sql"
test -s "$(dirname "$archive")/CHECKSUMS.sha256"
(cd "$(dirname "$archive")" && sha256sum -c CHECKSUMS.sha256 >/dev/null)
# A modified archive must fail the integrity check before restoration.
printf 'tampered' >> "$archive"
if (cd "$(dirname "$archive")" && sha256sum -c CHECKSUMS.sha256 >/dev/null 2>&1); then
  echo 'backup corruption was not detected' >&2
  exit 1
fi

# A broken mysqldump may have emitted partial SQL: do not call that a backup.
rm -rf "$work/backups"/*
if MOCK_DUMP_FAIL=1 sh "$script" > "$work/failure.log" 2>&1; then
  echo 'backup incorrectly succeeded after mysqldump failed' >&2
  exit 1
fi
if find "$work/backups" -mindepth 1 -print -quit | grep -q .; then
  echo 'partial backup was incorrectly retained' >&2
  exit 1
fi
# The key is indispensable for decrypting encrypted configuration.
printf 'APP_KEY=base64:regression-test-key\n' > "$work/source/.env"
rm -rf "$work/backups"/*
mv "$work/source/.env" "$work/source/.env.held"
if sh "$script" > "$work/missing-key.log" 2>&1; then
  echo 'backup incorrectly succeeded without .env' >&2
  exit 1
fi
test -z "$(find "$work/backups" -mindepth 1 -print -quit)"
mv "$work/source/.env.held" "$work/source/.env"

# A broken storage snapshot is also a failed backup, not a partial success.
cat > "$work/bin/tar" <<'SH'
#!/bin/sh
exit 32
SH
chmod +x "$work/bin/tar"
if sh "$script" > "$work/storage-failure.log" 2>&1; then
  echo 'backup incorrectly succeeded after storage archive failure' >&2
  exit 1
fi
test -z "$(find "$work/backups" -mindepth 1 -print -quit)"
echo 'backup regression checks passed'
