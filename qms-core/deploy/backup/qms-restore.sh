#!/usr/bin/env bash
# QMS restore (installed as /usr/local/sbin/qms-restore).
#
#   sudo qms-restore /var/backups/qms/qms-db-20261003-013000.sql.gz [/var/backups/qms/qms-files-20261003-013000.tar.gz]
#
# Replaces the CURRENT database with the dump. Take a fresh backup first
# (sudo qms-backup) if the current data might still be needed.
set -Eeuo pipefail
umask 027

[[ $EUID -eq 0 ]] || { echo "Run as root (sudo)." >&2; exit 1; }
[[ -f /etc/qms/backup.conf ]] && source /etc/qms/backup.conf
APP_DIR="${QMS_APP_DIR:-/var/www/qms}"
DB_NAME="${QMS_DB_NAME:-qms}"
DUMP="${1:-}"
FILES="${2:-}"
[[ -f "$DUMP" ]] || { echo "Usage: qms-restore <qms-db-*.sql.gz> [qms-files-*.tar.gz]" >&2; exit 1; }

SUM="$(dirname "$DUMP")/qms-$(basename "$DUMP" | sed -E 's/^qms-db-(.*)\.sql\.gz$/\1/').sha256"
if [[ -f "$SUM" ]]; then
    ( cd "$(dirname "$DUMP")" && sha256sum --check --ignore-missing "$(basename "$SUM")" ) || { echo "Checksum mismatch - aborting." >&2; exit 1; }
fi

read -r -p "This replaces database '$DB_NAME' with $(basename "$DUMP"). Type the database name to continue: " answer
[[ "$answer" == "$DB_NAME" ]] || { echo "Aborted."; exit 1; }

mkdir -p /var/lib/qms && touch /var/lib/qms/maintenance          # nginx answers 503 meanwhile
trap 'rm -f /var/lib/qms/maintenance' EXIT

mysql --protocol=socket -uroot -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
gunzip -c "$DUMP" | mysql --protocol=socket -uroot "$DB_NAME"
echo "Database restored."

if [[ -n "$FILES" ]]; then
    tar -xzf "$FILES" -C "$APP_DIR"
    chown -R qms:qms "$APP_DIR/storage/uploads"
    echo "Uploaded files restored."
fi
echo "Done. Log in and check the latest reports; then run: sudo -u qms php $APP_DIR/bin/qms.php sheets:sync"
