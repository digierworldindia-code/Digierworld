#!/usr/bin/env bash
# =============================================================================
# COLIFEES — database backup
# -----------------------------------------------------------------------------
# One script, used by both the nightly cron job and the "Run a backup now"
# button in the console, so there is a single procedure to test and to trust.
#
#   ./scripts/backup.sh --kind daily
#   ./scripts/backup.sh --kind manual --out /var/backups/colifees
#
# What it produces
#   colifees-<kind>-<timestamp>.dump.enc   encrypted custom-format dump
#   colifees-<kind>-<timestamp>.sha256     checksum of the encrypted file
#
# Why custom format (-Fc): it restores selectively with pg_restore, compresses,
# and is version-tolerant. A plain SQL file is also produced on request with
# --plain for readability or for loading into a different system.
#
# The last line of stdout is the artifact path. The API reads it, so do not add
# trailing output after it.
# =============================================================================
set -Eeuo pipefail

KIND="manual"
OUT_DIR="${BACKUP_DIR:-./backups}"
QUIET=0
PLAIN=0

usage() {
  cat <<'USAGE'
Usage: backup.sh [options]
  --kind <daily|weekly|monthly|manual|export>  label for the artifact (default: manual)
  --out <directory>                            where to write (default: $BACKUP_DIR or ./backups)
  --plain                                      also write a plain SQL dump
  --quiet                                      only print the artifact path
  -h, --help                                   this message

Environment:
  BACKUP_DATABASE_URL                connection string for the dump (colifees_backup)
  PGDATABASE_URL / DIRECT_DATABASE_URL / DATABASE_URL   fallbacks, in order
  BACKUP_ENCRYPTION_PASSPHRASE_FILE  file holding the encryption passphrase
  BACKUP_S3_BUCKET                   optional off-site destination (needs aws cli)
  BACKUP_RETENTION_DAILY             daily artifacts to keep locally (default 14)
  BACKUP_RETENTION_WEEKLY            weekly artifacts to keep locally (default 8)
  BACKUP_RETENTION_MONTHLY           monthly artifacts to keep locally (default 12)
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --kind) KIND="${2:?--kind needs a value}"; shift 2 ;;
    --out) OUT_DIR="${2:?--out needs a value}"; shift 2 ;;
    --plain) PLAIN=1; shift ;;
    --quiet) QUIET=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage >&2; exit 2 ;;
  esac
done

log() { [[ "$QUIET" -eq 1 ]] || printf '%s\n' "$*" >&2; }
fail() { printf 'backup failed: %s\n' "$*" >&2; exit 1; }

command -v pg_dump >/dev/null 2>&1 || fail "pg_dump is not on PATH"

DB_URL="${BACKUP_DATABASE_URL:-${PGDATABASE_URL:-${DIRECT_DATABASE_URL:-${DATABASE_URL:-}}}}"
[[ -n "$DB_URL" ]] || fail "no database URL (set BACKUP_DATABASE_URL)"

# -----------------------------------------------------------------------------
# Preflight: the connecting role must be able to read past Row Level Security.
#
# The dealer-isolation policies are FORCED, so they apply to the table owner as
# well. pg_dump run by a role subject to them fails with "query would be
# affected by row-level security policy" — it does not produce a partial dump,
# it produces no dump. This check turns that into a clear message before any
# work is done, and guards against someone pointing the backup at the
# application's own credentials.
# -----------------------------------------------------------------------------
if command -v psql >/dev/null 2>&1; then
  CAN_READ_ALL="$(psql "$DB_URL" -tAc \
    "SELECT (rolbypassrls OR rolsuper)::text FROM pg_roles WHERE rolname = current_user" 2>/dev/null || echo "")"
  if [[ "$CAN_READ_ALL" != "true" ]]; then
    fail "the backup role cannot bypass row level security. Use colifees_backup (see scripts/sql/01_roles.sql); a dump taken by any other role will fail or be incomplete."
  fi
fi

mkdir -p "$OUT_DIR"
# Backups are readable only by their owner: a dump is the whole database in one
# file, and a world-readable one on a shared host is a breach waiting to happen.
chmod 700 "$OUT_DIR" 2>/dev/null || true

TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BASENAME="colifees-${KIND}-${TIMESTAMP}"
DUMP_PATH="${OUT_DIR}/${BASENAME}.dump"

log "Dumping database (${KIND})…"
pg_dump \
  --dbname="$DB_URL" \
  --format=custom \
  --compress=9 \
  --no-owner \
  --no-privileges \
  --verbose \
  --file="$DUMP_PATH" 2> >(grep -v '^pg_dump: dumping contents' >&2 || true)

[[ -s "$DUMP_PATH" ]] || fail "pg_dump produced an empty file"

# Sanity check on the contents. A dump that restores cleanly but holds nothing
# is the failure mode worth catching here rather than during an incident.
ROW_CHECK="$(pg_restore --list "$DUMP_PATH" | grep -c 'TABLE DATA' || true)"
log "Dump contains ${ROW_CHECK} table data sections."
[[ "$ROW_CHECK" -gt 0 ]] || fail "dump contains no table data at all"


if [[ "$PLAIN" -eq 1 ]]; then
  log "Writing a plain SQL copy…"
  pg_dump --dbname="$DB_URL" --format=plain --no-owner --no-privileges \
    --file="${OUT_DIR}/${BASENAME}.sql"
fi

# -----------------------------------------------------------------------------
# Encryption at rest. A dump holds every customer record in the business, so it
# is encrypted before it is copied anywhere, including off-site.
# -----------------------------------------------------------------------------
ARTIFACT="$DUMP_PATH"
PASS_FILE="${BACKUP_ENCRYPTION_PASSPHRASE_FILE:-}"

if [[ -n "$PASS_FILE" && -r "$PASS_FILE" ]]; then
  command -v openssl >/dev/null 2>&1 || fail "openssl is not on PATH but a passphrase file was given"
  log "Encrypting…"
  openssl enc -aes-256-cbc -pbkdf2 -iter 600000 -salt \
    -in "$DUMP_PATH" -out "${DUMP_PATH}.enc" -pass "file:${PASS_FILE}"
  shred -u "$DUMP_PATH" 2>/dev/null || rm -f "$DUMP_PATH"
  ARTIFACT="${DUMP_PATH}.enc"
else
  # Refusing to continue silently: an unencrypted production dump is a finding,
  # not a warning to be lost in a log.
  if [[ "${APP_ENV:-development}" == "production" ]]; then
    fail "BACKUP_ENCRYPTION_PASSPHRASE_FILE is required in production"
  fi
  log "WARNING: no passphrase file set, backup is NOT encrypted (development only)."
fi

chmod 600 "$ARTIFACT"

CHECKSUM="$(sha256sum "$ARTIFACT" | awk '{print $1}')"
printf '%s  %s\n' "$CHECKSUM" "$(basename "$ARTIFACT")" > "${ARTIFACT}.sha256"
log "Checksum ${CHECKSUM}"

# -----------------------------------------------------------------------------
# Off-site copy. A backup that lives only on the machine it came from does not
# survive the failure it exists for.
# -----------------------------------------------------------------------------
if [[ -n "${BACKUP_S3_BUCKET:-}" ]]; then
  if command -v aws >/dev/null 2>&1; then
    log "Copying off-site to s3://${BACKUP_S3_BUCKET}/${KIND}/…"
    aws s3 cp "$ARTIFACT" "s3://${BACKUP_S3_BUCKET}/${KIND}/$(basename "$ARTIFACT")" --only-show-errors
    aws s3 cp "${ARTIFACT}.sha256" "s3://${BACKUP_S3_BUCKET}/${KIND}/$(basename "${ARTIFACT}.sha256")" --only-show-errors
  else
    log "WARNING: BACKUP_S3_BUCKET is set but the aws cli is not installed. The backup is local only."
  fi
else
  log "NOTE: BACKUP_S3_BUCKET is not set. This backup exists only on this host."
fi

# -----------------------------------------------------------------------------
# Local retention. Off-site retention is handled by the bucket's lifecycle
# policy, which is where it belongs: a compromised host must not be able to
# delete its own history.
# -----------------------------------------------------------------------------
case "$KIND" in
  daily)   KEEP="${BACKUP_RETENTION_DAILY:-14}" ;;
  weekly)  KEEP="${BACKUP_RETENTION_WEEKLY:-8}" ;;
  monthly) KEEP="${BACKUP_RETENTION_MONTHLY:-12}" ;;
  *)       KEEP=0 ;;
esac

if [[ "$KEEP" -gt 0 ]]; then
  mapfile -t OLD < <(ls -1t "${OUT_DIR}/colifees-${KIND}-"*.dump* 2>/dev/null | grep -v '\.sha256$' | tail -n "+$((KEEP + 1))" || true)
  for file in "${OLD[@]:-}"; do
    [[ -n "$file" ]] || continue
    log "Pruning $(basename "$file")"
    rm -f "$file" "${file}.sha256"
  done
fi

log "Done."
# Contract with the API: the artifact path is the final line of stdout.
printf '%s\n' "$ARTIFACT"
