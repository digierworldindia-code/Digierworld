#!/usr/bin/env bash
# QMS nightly backup (installed as /usr/local/sbin/qms-backup, run by /etc/cron.d/qms).
#
#  * Consistent MySQL dump with triggers (--single-transaction, no table locks),
#    including the binary-log position for point-in-time recovery.
#  * Uploaded files (gauge certificates, company logo).
#  * SHA-256 checksums; files older than QMS_BACKUP_KEEP_DAYS are removed.
#
# Copy the files off the server every day (object storage, another region):
# set QMS_BACKUP_OFFSITE_CMD in /etc/qms/backup.conf, e.g.
#   QMS_BACKUP_OFFSITE_CMD='rclone copy /var/backups/qms remote:qms-backups'
#   QMS_BACKUP_OFFSITE_CMD='aws s3 sync /var/backups/qms s3://my-bucket/qms --sse AES256'
set -Eeuo pipefail
umask 077

[[ -f /etc/qms/backup.conf ]] && source /etc/qms/backup.conf
APP_DIR="${QMS_APP_DIR:-/var/www/qms}"
DEST="${QMS_BACKUP_DIR:-/var/backups/qms}"
KEEP_DAYS="${QMS_BACKUP_KEEP_DAYS:-14}"
DB_NAME="${QMS_DB_NAME:-qms}"
CNF=/etc/qms/secrets/backup.cnf
STAMP="$(date -u +%Y%m%d-%H%M%S)"

[[ -r "$CNF" ]] || { echo "Backup credentials $CNF missing" >&2; exit 1; }
mkdir -p "$DEST"
chmod 0700 "$DEST"

echo "$(date -u '+%F %T') backup $STAMP started"
mysqldump --defaults-extra-file="$CNF" --single-transaction --quick --routines --triggers \
    --hex-blob --no-tablespaces --source-data=2 --set-gtid-purged=AUTO "$DB_NAME" \
    | gzip -6 > "$DEST/qms-db-$STAMP.sql.gz.part"
mv "$DEST/qms-db-$STAMP.sql.gz.part" "$DEST/qms-db-$STAMP.sql.gz"

if [[ -d "$APP_DIR/writable/uploads" ]]; then
    tar -czf "$DEST/qms-files-$STAMP.tar.gz" -C "$APP_DIR" --exclude='writable/uploads/tmp' writable/uploads
fi

( cd "$DEST" && sha256sum qms-*-"$STAMP".* > "qms-$STAMP.sha256" )
find "$DEST" -maxdepth 1 -name 'qms-*' -type f -mtime +"$KEEP_DAYS" -delete

if [[ -n "${QMS_BACKUP_OFFSITE_CMD:-}" ]]; then
    bash -c "$QMS_BACKUP_OFFSITE_CMD"
fi
echo "$(date -u '+%F %T') backup $STAMP finished: $(du -ch "$DEST"/qms-*-"$STAMP".* | tail -1 | cut -f1)"
