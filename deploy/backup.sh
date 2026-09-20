#!/bin/sh
#
# Back up everything needed to rebuild this instance.
#
# Runs both as the compose `backup` service (periodic) and as a one-shot host
# command (`TXBOARD_BACKUP_INTERVAL` unset/0).
#
# What is captured, and why:
#   db.sql.gz           the whole schema and data
#   env                 APP_KEY lives here. The encrypted columns in the dump are
#                       unreadable without it, so a database-only backup is not
#                       a backup.
#   storage-app.tar.gz  uploads and anything else under storage/app
#   MANIFEST            what the archive is, so a restore needs no guesswork
#
# Environment:
#   DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD   connection
#   BACKUP_DIR        where archives are written          (default /backups)
#   BACKUP_RETENTION  archives to keep, 0 = keep all      (default 7)
#   BACKUP_INTERVAL   seconds between runs, 0 = run once  (default 0)
#   BACKUP_SOURCE_DIR the api checkout holding .env       (default /backup-source/api)
#
set -eu

DB_HOST="${DB_HOST:-database}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:?DB_DATABASE is required}"
DB_USERNAME="${DB_USERNAME:?DB_USERNAME is required}"
DB_PASSWORD="${DB_PASSWORD:-}"
BACKUP_DIR="${BACKUP_DIR:-/backups}"
BACKUP_RETENTION="${BACKUP_RETENTION:-7}"
BACKUP_INTERVAL="${BACKUP_INTERVAL:-0}"
BACKUP_SOURCE_DIR="${BACKUP_SOURCE_DIR:-/backup-source/api}"

log() { echo "[backup] $(date -u '+%Y-%m-%dT%H:%M:%SZ') $*"; }

prune() {
    case "$BACKUP_RETENTION" in
        ''|*[!0-9]*) return 0 ;;
    esac
    [ "$BACKUP_RETENTION" -gt 0 ] || return 0

    total=$(ls -1 "$BACKUP_DIR" 2>/dev/null | grep -cE '^[0-9]{8}T[0-9]{6}Z$' || true)
    [ "$total" -gt "$BACKUP_RETENTION" ] || return 0

    remove=$((total - BACKUP_RETENTION))
    log "pruning $remove archive(s), keeping $BACKUP_RETENTION"
    ls -1 "$BACKUP_DIR" | grep -E '^[0-9]{8}T[0-9]{6}Z$' | sort | head -n "$remove" |
        while read -r old; do
            [ -n "$old" ] || continue
            rm -rf "$BACKUP_DIR/$old"
        done
}

run_backup() {
    stamp=$(date -u '+%Y%m%dT%H%M%SZ')
    dest="$BACKUP_DIR/$stamp"
    mkdir -p "$dest"

    log "dumping $DB_DATABASE@$DB_HOST:$DB_PORT -> $dest/db.sql.gz"
    # --single-transaction keeps InnoDB consistent without locking the panel.
    # --set-gtid-purged=OFF stops mysqldump emitting GTID statements that a
    # restore into a server without GTID enabled would reject.
    if ! MYSQL_PWD="$DB_PASSWORD" mysqldump \
            --host="$DB_HOST" \
            --port="$DB_PORT" \
            --user="$DB_USERNAME" \
            --single-transaction \
            --quick \
            --routines \
            --events \
            --triggers \
            --set-gtid-purged=OFF \
            --default-character-set=utf8mb4 \
            "$DB_DATABASE" 2>/dev/null | gzip -9 > "$dest/db.sql.gz"; then
        log "ERROR: mysqldump failed; discarding the partial archive"
        rm -rf "$dest"
        return 1
    fi

    # A truncated dump can still exit 0 from the pipeline above, so verify the
    # archive before keeping it. An unverified backup is worse than none.
    if [ ! -s "$dest/db.sql.gz" ] || ! gzip -t "$dest/db.sql.gz" 2>/dev/null; then
        log "ERROR: db.sql.gz is empty or corrupt; discarding the archive"
        rm -rf "$dest"
        return 1
    fi
    log "  db.sql.gz: $(wc -c < "$dest/db.sql.gz" | tr -d ' ') bytes"

    if [ -f "$BACKUP_SOURCE_DIR/.env" ]; then
        cp "$BACKUP_SOURCE_DIR/.env" "$dest/env"
        chmod 600 "$dest/env"
        log "  captured .env (contains APP_KEY)"
    else
        log "  WARNING: no .env at $BACKUP_SOURCE_DIR/.env; APP_KEY NOT captured"
    fi

    if [ -d "$BACKUP_SOURCE_DIR/storage/app" ]; then
        tar -czf "$dest/storage-app.tar.gz" -C "$BACKUP_SOURCE_DIR/storage/app" . 2>/dev/null ||
            log "  WARNING: storage/app archive failed"
        log "  captured storage/app"
    else
        # Not an error: uploads live in storage/app, so if it does not exist yet
        # there is simply nothing to capture.
        log "  no storage/app yet (no uploads to capture)"
    fi

    {
        echo "created_at=$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
        echo "database=$DB_DATABASE"
        echo "db_host=$DB_HOST"
        echo "contents=db.sql.gz env storage-app.tar.gz"
    } > "$dest/MANIFEST"

    log "wrote $dest"
    prune
}

if [ "$BACKUP_INTERVAL" -gt 0 ] 2>/dev/null; then
    log "periodic mode: every ${BACKUP_INTERVAL}s, retention ${BACKUP_RETENTION}"
    while true; do
        run_backup || log "backup failed; will retry at the next interval"
        sleep "$BACKUP_INTERVAL"
    done
else
    run_backup
fi
