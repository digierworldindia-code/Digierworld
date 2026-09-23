#!/usr/bin/env bash
# =============================================================================
# POLYFIX MATTRESS — database restore
# -----------------------------------------------------------------------------
# The other half of scripts/backup.sh. Restoring is the step nobody practises
# and everybody needs at the worst possible moment, so this script is
# deliberately loud, refuses to guess, and defaults to NOT touching an existing
# database.
#
#   ./scripts/restore.sh --file backups/polyfix-daily-20260923T020000Z.dump.enc \
#                        --into postgresql://polyfix_restore@127.0.0.1:5432/polyfix_verify
#
# What it does, in order:
#   1. verifies the SHA-256 checksum beside the artifact, if present
#   2. decrypts it, if it ends in .enc
#   3. refuses to restore into a non-empty database unless --force is given
#   4. runs pg_restore as the SCHEMA OWNER
#   5. reapplies grants and resynchronises identifier sequences
#   6. verifies the audit hash chain and prints a row-count summary
#
# It never writes to the source artifact and never deletes a backup.
# =============================================================================
set -Eeuo pipefail

FILE=""
TARGET_URL="${DIRECT_DATABASE_URL:-}"
FORCE=0
JOBS=4
DRY_RUN=0
KEEP_PLAINTEXT=0

usage() {
  cat <<'USAGE'
Usage: restore.sh --file <artifact> [options]
  --file <path>      backup artifact (.dump or .dump.enc). Required.
  --into <url>       target connection string, as the SCHEMA OWNER
                     (default: $DIRECT_DATABASE_URL)
  --force            allow restoring into a database that already has tables.
                     THIS OVERWRITES DATA. Without it, a populated target aborts.
  --jobs <n>         parallel pg_restore workers (default 4)
  --dry-run          verify, decrypt and list the artifact's contents, restore nothing
  --keep-plaintext   do not shred the decrypted temporary file (debugging only)
  -h, --help         this message

Environment:
  BACKUP_ENCRYPTION_PASSPHRASE_FILE  required to decrypt a .enc artifact
  DIRECT_DATABASE_URL                default target (the schema owner)

Always rehearse into a scratch database first. See docs/04-database-restore.md.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --file) FILE="${2:?--file needs a value}"; shift 2 ;;
    --into) TARGET_URL="${2:?--into needs a value}"; shift 2 ;;
    --force) FORCE=1; shift ;;
    --jobs) JOBS="${2:?--jobs needs a value}"; shift 2 ;;
    --dry-run) DRY_RUN=1; shift ;;
    --keep-plaintext) KEEP_PLAINTEXT=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage >&2; exit 2 ;;
  esac
done

log()  { printf '%s\n' "$*" >&2; }
fail() { printf 'restore failed: %s\n' "$*" >&2; exit 1; }

command -v pg_restore >/dev/null 2>&1 || fail "pg_restore is not on PATH"
command -v psql       >/dev/null 2>&1 || fail "psql is not on PATH"

[[ -n "$FILE" ]] || { usage >&2; fail "--file is required"; }
[[ -r "$FILE" ]] || fail "cannot read $FILE"
[[ -n "$TARGET_URL" ]] || fail "no target database (pass --into or set DIRECT_DATABASE_URL)"

# -----------------------------------------------------------------------------
# libpq does not understand the query parameters Prisma adds to a connection
# string (`schema`, `connection_limit`, `pool_timeout`, `pgbouncer`, …). Handed
# one, psql/pg_dump/pg_restore abort with "invalid URI query parameter", which
# the preflight below would otherwise report as a permissions problem. Since
# every URL in .env carries them, strip the Prisma-only ones here and keep the
# rest (sslmode, sslrootcert and friends are real libpq parameters and matter).
# -----------------------------------------------------------------------------
pg_url() {
  local url="$1" base query kept=""
  base="${url%%\?*}"
  [[ "$url" == *"?"* ]] || { printf '%s' "$url"; return; }
  query="${url#*\?}"
  local IFS='&' param
  for param in $query; do
    case "${param%%=*}" in
      schema|connection_limit|pool_timeout|pgbouncer|socket_timeout|statement_cache_size) continue ;;
      '') continue ;;
      *) kept="${kept:+${kept}&}${param}" ;;
    esac
  done
  printf '%s%s' "$base" "${kept:+?${kept}}"
}

TARGET_URL="$(pg_url "$TARGET_URL")"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Anything we decrypt lands here and is shredded on the way out, however we exit.
WORK_DIR="$(mktemp -d)"
chmod 700 "$WORK_DIR"
cleanup() {
  if [[ "$KEEP_PLAINTEXT" -eq 1 ]]; then
    log "Leaving decrypted artifact in $WORK_DIR (--keep-plaintext)."
    return
  fi
  if [[ -d "$WORK_DIR" ]]; then
    find "$WORK_DIR" -type f -exec shred -u {} + 2>/dev/null || true
    rm -rf "$WORK_DIR"
  fi
}
trap cleanup EXIT

# -----------------------------------------------------------------------------
# 1. Integrity. A corrupted backup discovered during the restore is a second
#    incident on top of the first one.
# -----------------------------------------------------------------------------
if [[ -r "${FILE}.sha256" ]]; then
  log "Verifying checksum…"
  EXPECTED="$(awk '{print $1}' < "${FILE}.sha256")"
  ACTUAL="$(sha256sum "$FILE" | awk '{print $1}')"
  [[ "$EXPECTED" == "$ACTUAL" ]] || fail "checksum mismatch — this artifact is not the one that was written"
  log "Checksum OK."
else
  log "WARNING: no ${FILE}.sha256 beside the artifact; integrity is unverified."
fi

# -----------------------------------------------------------------------------
# 2. Decryption
# -----------------------------------------------------------------------------
DUMP="$FILE"
if [[ "$FILE" == *.enc ]]; then
  PASS_FILE="${BACKUP_ENCRYPTION_PASSPHRASE_FILE:-}"
  [[ -n "$PASS_FILE" && -r "$PASS_FILE" ]] || \
    fail "BACKUP_ENCRYPTION_PASSPHRASE_FILE is not set or not readable, and this artifact is encrypted"
  command -v openssl >/dev/null 2>&1 || fail "openssl is not on PATH"
  log "Decrypting…"
  DUMP="${WORK_DIR}/restore.dump"
  # Same parameters as backup.sh. A mismatch here reads as "bad decrypt".
  openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 \
    -in "$FILE" -out "$DUMP" -pass "file:${PASS_FILE}" \
    || fail "decryption failed — wrong passphrase file, or the artifact is damaged"
  chmod 600 "$DUMP"
fi

# -----------------------------------------------------------------------------
# 3. Sanity-check the artifact before touching the target
# -----------------------------------------------------------------------------
SECTIONS="$(pg_restore --list "$DUMP" 2>/dev/null | grep -c 'TABLE DATA' || true)"
[[ "$SECTIONS" -gt 0 ]] || fail "this artifact contains no table data"
log "Artifact holds ${SECTIONS} table data sections."

if [[ "$DRY_RUN" -eq 1 ]]; then
  log "--- contents (dry run, nothing restored) ---"
  pg_restore --list "$DUMP"
  log "Dry run complete."
  exit 0
fi

# -----------------------------------------------------------------------------
# 4. Refuse to silently overwrite a populated database
# -----------------------------------------------------------------------------
EXISTING="$(psql "$TARGET_URL" -tAc \
  "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'" 2>/dev/null || echo "unreachable")"
[[ "$EXISTING" != "unreachable" ]] || fail "cannot connect to the target database"

if [[ "$EXISTING" -gt 0 ]]; then
  if [[ "$FORCE" -ne 1 ]]; then
    fail "the target already has ${EXISTING} table(s). Restore into an empty database, or pass --force to overwrite it. --force DESTROYS the data currently there."
  fi
  log "WARNING: target has ${EXISTING} table(s) and --force was given. Existing objects will be dropped and replaced."
fi

# -----------------------------------------------------------------------------
# 5. Restore
#
# --no-owner / --no-privileges because the dump was taken that way: ownership
# and grants belong to the environment, not to the backup. 02_grants.sql below
# is what re-establishes least privilege on the restored schema.
# -----------------------------------------------------------------------------
log "Restoring into the target database…"
RESTORE_ARGS=(
  --dbname="$TARGET_URL"
  --no-owner
  --no-privileges
  --jobs="$JOBS"
  --verbose
)
[[ "$FORCE" -eq 1 ]] && RESTORE_ARGS+=(--clean --if-exists)

# pg_restore reports non-fatal issues with a non-zero exit, so its status is
# captured and reported rather than allowed to abort the post-restore steps
# that make the database safe to use.
set +e
pg_restore "${RESTORE_ARGS[@]}" "$DUMP" 2> >(grep -viE '^pg_restore: (processing|creating|launching|entering)' >&2)
RESTORE_STATUS=$?
set -e
if [[ "$RESTORE_STATUS" -ne 0 ]]; then
  log "WARNING: pg_restore exited ${RESTORE_STATUS}. Read the messages above before trusting this database."
fi

# -----------------------------------------------------------------------------
# 6. Make the restored database safe and consistent
#
# Neither step is optional. Default privileges are attached to the schema, so a
# restore into a fresh database leaves the application role with nothing — or,
# worse, with more than it should have. Sequence state is not carried by a
# data-only dump, so the next generated serial would collide.
# -----------------------------------------------------------------------------
log "Reapplying least-privilege grants…"
psql "$TARGET_URL" -v ON_ERROR_STOP=1 -q -f "${SCRIPT_DIR}/sql/02_grants.sql" \
  || log "WARNING: 02_grants.sql failed. The application role's privileges are NOT correct yet."

log "Resynchronising identifier sequences…"
psql "$TARGET_URL" -v ON_ERROR_STOP=1 -q -f "${SCRIPT_DIR}/sql/04_sync_sequences.sql" \
  || log "WARNING: 04_sync_sequences.sql failed. The next generated serial may collide."

# -----------------------------------------------------------------------------
# 7. Verify
# -----------------------------------------------------------------------------
log ""
log "--- verification ---"
psql "$TARGET_URL" -v ON_ERROR_STOP=1 <<'SQL'
-- Row Level Security is FORCED on the dealer-scoped tables. Without a scope,
-- the counts below would all read zero and look like a failed restore.
SET app.dealer_id = 'ALL';

\echo 'Audit hash chain (any row below is a broken link):'
SELECT * FROM colifees_verify_audit_chain(0::bigint, 1000000);

\echo 'Row counts:'
SELECT 'mattresses' AS table, count(*) FROM mattresses
UNION ALL SELECT 'sales',           count(*) FROM sales
UNION ALL SELECT 'warranties',      count(*) FROM warranties
UNION ALL SELECT 'warranty_claims', count(*) FROM warranty_claims
UNION ALL SELECT 'dealers',         count(*) FROM dealers
UNION ALL SELECT 'customers',       count(*) FROM customers
UNION ALL SELECT 'users',           count(*) FROM users
UNION ALL SELECT 'audit_logs',      count(*) FROM audit_logs
ORDER BY 1;

\echo 'Migrations applied:'
SELECT migration_name, finished_at FROM _prisma_migrations ORDER BY finished_at DESC LIMIT 5;
SQL

log ""
log "Restore complete."
log "Before pointing the application at this database, run:"
log "  psql \"\$DIRECT_DATABASE_URL\" -f scripts/sql/03_verify_privileges.sql"
log "Every row it returns is a finding."
