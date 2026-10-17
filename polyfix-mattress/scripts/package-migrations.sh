#!/usr/bin/env bash
#
# Build the migrations-only package.
#
#   scripts/package-migrations.sh [output-directory]
#
# This is what a running installation needs to come forward: the migration
# classes, the schema they build from, the reference-data seeder, and the
# two commands that apply them. No application code, no vendor tree, no
# data.
#
# There is deliberately no data dump in here. The client's database already
# holds their records, and every migration in it is additive — a dump beside
# the migrations is an invitation for somebody to restore it over live rows.
#
# Like the main package, this is taken from `git archive` of HEAD, so only
# committed files can get in, and it refuses to produce an archive that
# contains a secret.
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT=$(pwd)
OUT=${1:-"$ROOT/delivery"}
NAME=POLYFIZZ-MYSQL-MIGRATIONS
ZIP="$OUT/${ZIP_NAME:-$NAME}.zip"

command -v zip >/dev/null || { echo "zip is not installed"; exit 1; }

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$OUT" "$STAGE/$NAME"

echo "==> exporting the database layer from HEAD"
git archive --format=tar HEAD \
    app/Database \
    app/Commands/Migrate.php \
    app/Commands/Seed.php \
    app/Commands/Doctor.php \
    docs/database.md \
    | tar -x -C "$STAGE/$NAME"

[ -d "$STAGE/$NAME/app/Database/Migrations" ] || { echo "no migrations were exported"; exit 1; }

COUNT=$(find "$STAGE/$NAME/app/Database/Migrations" -name '*.php' | wc -l | tr -d ' ')
echo "  $COUNT migration(s)"

echo "==> writing the instructions"
cat > "$STAGE/$NAME/README.md" <<'EOF'
# POLYFIZZ — MySQL migrations

Everything needed to bring an existing POLYFIZZ database forward. No
application code and no data: your records stay exactly where they are.

## What is in here

| Path | What it is |
|---|---|
| `app/Database/Migrations/` | the migrations, applied in filename order |
| `app/Database/Schema/core_schema.php` | the tables a fresh install starts from |
| `app/Database/Seeds/ReferenceData.php` | roles, permissions and default settings |
| `app/Commands/Migrate.php` | `php spark polyfix:migrate` |
| `app/Commands/Seed.php` | `php spark polyfix:seed` |
| `app/Commands/Doctor.php` | `php spark polyfix:doctor`, which checks the install |
| `docs/database.md` | the database accounts and the grants each needs |

These files are already inside the full application package. This archive
exists so a database administrator can read and apply the schema changes
without taking delivery of the whole application.

## Applying them

Migrations run as the schema owner, not as the application's account — the
application deliberately has no rights to change the schema. The password
is read at the prompt or from the environment, and is never written to
`.env` or any file.

```bash
# from the application root
php spark polyfix:migrate --owner-user polyfix_owner

# or, for automation
POLYFIZZ_OWNER_PASSWORD='…' php spark polyfix:migrate --owner-user polyfix_owner
```

Then load the reference data. It is idempotent: roles, permissions and
settings are brought into line with the code, and nothing else is touched.

```bash
php spark polyfix:seed ReferenceData
```

Finally, confirm:

```bash
php spark polyfix:doctor
```

## What the migrations do

Every one of them is additive. None drops a table, deletes a record or
resets a sequence.

- **CreateCoreSchema** — the tables, for a fresh installation only.
- **CreateFrameworkTables** — sessions, migrations and the identifier
  counters. The counters are inserted with `INSERT IGNORE`, so a re-run can
  never reset a sequence that has already issued numbers.
- **AddDispatchInvoiceAndClaimVideo** — two columns.
- **ReleaseAccountLocksAndAddSecuritySettings** — clears existing lockouts
  and adds the security settings.
- **RebrandToPolyfizz** — corrects the company name in website copy, FAQs,
  SEO metadata and the company-name setting, and widens the serial format
  check so `PFZ-2026-000001` is accepted alongside the older `CLF…`
  numbering. **No existing serial is rewritten.** Sales, claims, warranties,
  dispatches, customer text and the audit trail are not touched: they record
  what happened.
- **AgentSurveyAndWarehouse** — the seven tables the survey programme and
  the warehouse panel need. Each is `CREATE TABLE IF NOT EXISTS`.
- **AddFieldAgentRole** — widens the `roles.key` enum so the Field Agent
  role can exist. Widening an enum adds a member; existing rows are
  untouched.
- **FixScanUniqueness** — replaces the scan uniqueness key with one that
  actually holds. (MySQL treats NULLs in a unique index as distinct, and
  every scan row has a NULL in one of the two context columns, so the
  original key did not prevent a double count.)
- **SurveyIdentifierCounters** — two counters, with `INSERT IGNORE`.
- **ReviewInvoiceUploads** — lets an uploaded file belong to a warranty
  review as well as to a claim, enforced by a CHECK.

## Before you run them

Take a backup. Not because these migrations are risky — they are additive
and were rehearsed against a copy of the production database — but because
a backup taken before a schema change is the cheapest insurance there is.

```bash
mysqldump --single-transaction --routines --triggers DATABASE > backup.sql
```
EOF

# --- refuse to ship a secret -------------------------------------------------
echo "==> checking for secrets"
fail=0
if [ -e "$STAGE/$NAME/.env" ] || [ -d "$STAGE/$NAME/.git" ]; then
    echo "  REFUSING: .env or .git is in the package"; fail=1
fi
if grep -rlIE 'BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY' "$STAGE/$NAME" 2>/dev/null | head -1 | grep -q .; then
    echo "  REFUSING: a private key is in the package"; fail=1
fi
# No password that is not plainly an example.
if grep -rnIE "(password|secret|PASSWORD)[[:space:]]*=[[:space:]]*['\"][^'\"]{8,}" "$STAGE/$NAME" 2>/dev/null \
    | grep -viE 'CHANGE_ME|…|example|your-|placeholder' | head -1 | grep -q .; then
    echo "  REFUSING: something in the package looks like a real password"; fail=1
fi
[ "$fail" -eq 0 ] || { echo "packaging aborted"; exit 1; }
echo "  clean"

echo "==> writing $ZIP"
rm -f "$ZIP"
( cd "$STAGE" && zip -qr9 "$ZIP" "$NAME" -x '*.DS_Store' )

echo
echo "built: $ZIP"
echo "       $(du -h "$ZIP" | cut -f1), $(unzip -l "$ZIP" | tail -1 | awk '{print $2}') files"
