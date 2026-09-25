#!/usr/bin/env bash
#
# POLYFIX MATTRESS — nightly database backup.
#
#   scripts/backup.sh
#   BACKUP_DIR=/srv/backups DB_NAME=polyfix_mattress scripts/backup.sh
#
# Credentials come from a MySQL option file, never from the command line: a
# password given as --password=… is visible to anyone who can run ps.
#
#   /etc/mysql/polyfix-backup.cnf   (chmod 600)
#     [client]
#     user = polyfix_backup
#     password = "…"
#     host = 127.0.0.1
#
# It writes <db>-<timestamp>.sql.gz and a .sha256 beside it, then removes
# backups older than RETENTION_DAYS. It does not touch the database it reads.
set -euo pipefail

DB_NAME="${DB_NAME:-polyfix_mattress}"
BACKUP_DIR="${BACKUP_DIR:-/srv/backups/polyfix}"
DEFAULTS_FILE="${DEFAULTS_FILE:-/etc/mysql/polyfix-backup.cnf}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"

if [[ ! -r "$DEFAULTS_FILE" ]]; then
  echo "backup: cannot read $DEFAULTS_FILE — see docs/backup.md" >&2
  exit 1
fi

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

stamp="$(date -u +%Y-%m-%dT%H%M%SZ)"
target="$BACKUP_DIR/$DB_NAME-$stamp.sql.gz"
partial="$target.partial"

echo "backup: dumping $DB_NAME to $target"

# --single-transaction: one consistent snapshot, without locking the live site.
# --routines --triggers: keep the optional append-only triggers.
# The sed strips DEFINER clauses so a restore does not fail on a server where
# the account that created a trigger does not exist.
mysqldump \
  --defaults-extra-file="$DEFAULTS_FILE" \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --events \
  --set-gtid-purged=OFF \
  --no-tablespaces \
  --default-character-set=utf8mb4 \
  "$DB_NAME" \
  | sed -E 's/DEFINER=`[^`]+`@`[^`]+`//g' \
  | gzip -9 > "$partial"

mv "$partial" "$target"
chmod 600 "$target"

( cd "$BACKUP_DIR" && sha256sum "$(basename "$target")" > "$(basename "$target").sha256" )

size="$(du -h "$target" | cut -f1)"
echo "backup: wrote $target ($size)"

# Never delete the most recent backup, whatever the retention window says.
find "$BACKUP_DIR" -name "$DB_NAME-*.sql.gz" -mtime "+$RETENTION_DAYS" -print -delete \
  | while read -r old; do
      rm -f "$old.sha256"
    done

echo "backup: done. Restore with scripts/restore.sh, and read docs/restore.md."
echo "backup: the encryption key in .env is NOT in this file — back it up separately."
