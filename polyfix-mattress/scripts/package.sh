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
NAME=POLYFIX-MATTRESS
ZIP="$OUT/${ZIP_NAME:-POLYFIX-MATTRESS-CI4-NO-2FA-FINAL}.zip"
SQL_SRC="$OUT/POLYFIX-MATTRESS-DATABASE.sql"

command -v zip >/dev/null || { echo "zip is not installed"; exit 1; }
[ -f "$SQL_SRC" ] || { echo "missing $SQL_SRC — generate the dump first"; exit 1; }

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

echo "==> adding the database dump and the empty writable tree"
cp "$SQL_SRC" "$STAGE/$NAME/POLYFIX-MATTRESS-DATABASE.sql"
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
    if grep -qiE '^[[:space:]]*(polyfix\.(encryptionKey|signingSecret)|encryption\.key|database\.default\.password|email\.SMTPPass)[[:space:]]*=[[:space:]]*.+' "$file" 2>/dev/null; then
        if ! grep -qiE '=[[:space:]]*.?CHANGE_ME' "$file"; then
            echo "  REFUSING: $file looks like it holds a real secret"; fail=1
        fi
    fi
done < <(find "$STAGE/$NAME" -maxdepth 2 -name '.env*' -type f -print0)

if grep -rlIE 'BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY' "$STAGE/$NAME" 2>/dev/null | head -1 | grep -q .; then
    echo "  REFUSING: a private key is in the package"; fail=1
fi

[ "$fail" -eq 0 ] || { echo "packaging aborted"; exit 1; }
echo "  clean: only .env.example, with placeholders"

echo "==> writing $ZIP"
rm -f "$ZIP"
( cd "$STAGE" && zip -qr9 "$ZIP" "$NAME" -x '*.DS_Store' )

echo
echo "built: $ZIP"
echo "       $(du -h "$ZIP" | cut -f1), $(unzip -l "$ZIP" | tail -1 | awk '{print $2}') files"
