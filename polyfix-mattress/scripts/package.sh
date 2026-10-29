#!/usr/bin/env bash
#
# Build the client delivery package.
#
#   scripts/package.sh [output-directory]
#
# The application is taken from `git archive` of HEAD, so only committed,
# non-ignored files can get in — a stray .env, a scratch dump or an editor
# backup in the working tree cannot end up in a client's hands. vendor/ is
# added separately because it is not tracked, installed fresh with --no-dev.
#
# The script refuses to produce an archive that contains a secret. That check
# is the point of it: "I remembered to leave the .env out" is not a control.
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT=$(pwd)
OUT=${1:-"$ROOT/delivery"}
NAME=POLYFIZZ
ZIP="$OUT/${ZIP_NAME:-POLYFIZZ-CI4-UPDATED-COMPLETE}.zip"
FRESH_SQL="$OUT/POLYFIZZ-FRESH-DATABASE.sql"
UPGRADE_SQL="$OUT/POLYFIZZ-EXISTING-DATABASE-UPGRADE.sql"

command -v zip >/dev/null || { echo "zip is not installed"; exit 1; }

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$OUT" "$STAGE/$NAME"

echo "==> exporting HEAD"
git archive --format=tar HEAD | tar -x -C "$STAGE/$NAME"

echo "==> installing production dependencies"
# Built fresh from composer.lock rather than copied from the working tree: the
# development checkout is installed --prefer-source, so it carries 37 .git
# directories and about 2.9 GB of package history that no client needs. This
# resolves the exact locked versions, without the development packages.
composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction \
    --no-progress \
    --working-dir="$STAGE/$NAME" \
    --quiet

[ -f "$STAGE/$NAME/vendor/autoload.php" ] || { echo "composer install produced no autoloader"; exit 1; }
find "$STAGE/$NAME/vendor" -name '.git' -prune -exec rm -rf {} + 2>/dev/null || true

# The two SQL files are carried inside the archive as well as beside it, so
# whoever unpacks it on the server has everything in one place. Neither holds
# an account or a password: the fresh file installs no users at all, and the
# upgrade file only adds to a database that already exists.
echo "==> adding the database files"
for pair in "$FRESH_SQL:POLYFIZZ-FRESH-DATABASE.sql" "$UPGRADE_SQL:POLYFIZZ-EXISTING-DATABASE-UPGRADE.sql"; do
    src=${pair%%:*}
    dest=${pair#*:}
    if [ -f "$src" ]; then
        echo "  including $dest"
        cp "$src" "$STAGE/$NAME/$dest"
    else
        echo "  MISSING: $src — build it first (scripts/build-fresh-sql.sh, php spark polyfix:build-upgrade-sql)"
        fail_sql=1
    fi
done
[ "${fail_sql:-0}" -eq 0 ] || { echo "packaging aborted: a database file is missing"; exit 1; }

echo "==> adding the empty writable tree"
for d in cache logs session uploads debugbar; do
    mkdir -p "$STAGE/$NAME/writable/$d"
    [ -f "$STAGE/$NAME/writable/$d/index.html" ] || \
        printf '<!DOCTYPE html><title>403</title>Directory access is forbidden.\n' \
        > "$STAGE/$NAME/writable/$d/index.html"
done

# --- refuse to ship a secret -------------------------------------------------
echo "==> checking for secrets"
fail=0

if [ -e "$STAGE/$NAME/.env" ]; then
    echo "  REFUSING: .env is in the package"; fail=1
fi
if [ -d "$STAGE/$NAME/.git" ]; then
    echo "  REFUSING: .git is in the package"; fail=1
fi

# A real value against any of these names, as opposed to the CHANGE_ME
# placeholders .env.example is supposed to carry.
while IFS= read -r -d '' file; do
    if grep -qiE '^[[:space:]]*(polyfizz\.(encryptionKey|signingSecret)|encryption\.key|database\.default\.password|email\.SMTPPass)[[:space:]]*=[[:space:]]*.+' "$file" 2>/dev/null; then
        if ! grep -qiE '=[[:space:]]*.?CHANGE_ME' "$file"; then
            echo "  REFUSING: $file looks like it holds a real secret"; fail=1
        fi
    fi
done < <(find "$STAGE/$NAME" -maxdepth 2 -name '.env*' -type f -print0)

if grep -rlIE 'BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY' "$STAGE/$NAME" 2>/dev/null | head -1 | grep -q .; then
    echo "  REFUSING: a private key is in the package"; fail=1
fi

# The database files ship with the package, so they are checked too: a fresh
# install creates its first administrator with polyfix:create-user, and a
# password hash in the SQL would mean an account somebody else's copy of the
# file also knows the password to.
for sql in "$STAGE/$NAME"/*.sql; do
    [ -f "$sql" ] || continue
    if grep -qE '\$2[aby]\$[0-9]{2}\$' "$sql"; then
        echo "  REFUSING: $(basename "$sql") contains a password hash"; fail=1
    fi
    if grep -qiE 'INSERT INTO .?users.?' "$sql"; then
        echo "  REFUSING: $(basename "$sql") creates a user account"; fail=1
    fi
    if grep -qiE '^\s*(DROP TABLE|DROP DATABASE|TRUNCATE)' "$sql"; then
        echo "  REFUSING: $(basename "$sql") contains a destructive statement"; fail=1
    fi
done

[ "$fail" -eq 0 ] || { echo "packaging aborted"; exit 1; }
echo "  clean: only .env.example, with placeholders"

echo "==> writing $ZIP"
rm -f "$ZIP"
( cd "$STAGE" && zip -qr9 "$ZIP" "$NAME" -x '*.DS_Store' )

echo
echo "built: $ZIP"
echo "       $(du -h "$ZIP" | cut -f1), $(unzip -l "$ZIP" | tail -1 | awk '{print $2}') files"
