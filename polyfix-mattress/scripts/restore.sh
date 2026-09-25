#!/usr/bin/env bash
#
# POLYFIX MATTRESS — restore a backup.
#
#   scripts/restore.sh backup.sql.gz                      # into polyfix_restore_check
#   scripts/restore.sh backup.sql.gz polyfix_drill        # into a named database
#   scripts/restore.sh backup.sql.gz polyfix_mattress --force   # over a database that has data
#
# By default it restores into a SEPARATE database so you can look before
# touching anything live, and it refuses to overwrite a database that already
# holds rows unless --force is given.
set -euo pipefail

ARCHIVE="${1:-}"
TARGET="${2:-polyfix_restore_check}"
FORCE="${3:-}"
DEFAULTS_FILE="${DEFAULTS_FILE:-/etc/mysql/polyfix-restore.cnf}"

if [[ -z "$ARCHIVE" || ! -r "$ARCHIVE" ]]; then
  echo "usage: scripts/restore.sh <backup.sql.gz> [database] [--force]" >&2
  exit 1
fi

mysql_cmd=(mysql --default-character-set=utf8mb4)
if [[ -r "$DEFAULTS_FILE" ]]; then
  mysql_cmd+=(--defaults-extra-file="$DEFAULTS_FILE")
fi

checksum="$ARCHIVE.sha256"
if [[ -r "$checksum" ]]; then
  echo "restore: checking $checksum"
  ( cd "$(dirname "$ARCHIVE")" && sha256sum -c "$(basename "$checksum")" )
else
  echo "restore: no checksum beside the archive — carrying on, but it was not verified" >&2
fi

"${mysql_cmd[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$TARGET\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"

existing="$("${mysql_cmd[@]}" -N -B -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$TARGET';")"

if [[ "$existing" -gt 0 && "$FORCE" != "--force" ]]; then
  echo "restore: \`$TARGET\` already holds $existing table(s)." >&2
  echo "restore: restore into a scratch database instead, or pass --force if you mean to overwrite it." >&2
  exit 1
fi

if [[ "$existing" -gt 0 ]]; then
  echo "restore: --force given; overwriting \`$TARGET\`. Take a backup of it first if you have not."
  sleep 3
fi

echo "restore: loading $ARCHIVE into \`$TARGET\`"
gunzip -c "$ARCHIVE" | "${mysql_cmd[@]}" "$TARGET"

tables="$("${mysql_cmd[@]}" -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$TARGET';")"
mattresses="$("${mysql_cmd[@]}" -N -B -e "SELECT COUNT(*) FROM \`$TARGET\`.mattresses;" 2>/dev/null || echo '?')"
audit="$("${mysql_cmd[@]}" -N -B -e "SELECT COUNT(*) FROM \`$TARGET\`.audit_logs;" 2>/dev/null || echo '?')"

echo "restore: done — $tables tables, $mattresses mattresses, $audit audit entries."
cat <<'NOTE'

Before trusting it:
  1. Verify the audit chain (Console → Audit → Verify the chain). It must say Intact.
  2. Check identifier_counters is not behind the highest serial — see docs/restore.md.
  3. Re-apply privileges if this is a new server:
       mysql -u root -p <db> < database/sql/generate-privileges.sql | mysql -u root -p <db>
  4. Restore writable/uploads separately; claim photographs are files, not rows.
NOTE
