#!/usr/bin/env bash
# Build a deployable release archive: the committed source tree plus the
# production vendor/ folder resolved from composer.lock.
#
#   scripts/build-release.sh 1.0.0      → dist/dgr-office-1.0.0.zip (+ .sha256)
#
# Never includes .env, uploads, backups, logs or dev dependencies.
set -euo pipefail

VERSION="${1:?usage: scripts/build-release.sh <version, e.g. 1.0.0>}"
[[ "$VERSION" =~ ^[0-9A-Za-z.+-]+$ ]] || { echo "Invalid version: $VERSION" >&2; exit 1; }

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NAME="dgr-office-$VERSION"
OUT="$ROOT/dist"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
STAGE="$WORK/dgr-office"

cd "$ROOT"
if [[ -n "$(git status --porcelain -- .)" && "${ALLOW_DIRTY:-0}" != 1 ]]; then
    echo "Uncommitted changes in $ROOT — the archive is built from HEAD only. Commit first (or set ALLOW_DIRTY=1)." >&2
    exit 1
fi

# 1. committed files only (git archive never sees .env or ignored runtime data)
PREFIX="$(git rev-parse --show-prefix)"
TREE="HEAD"
[[ -n "$PREFIX" ]] && TREE="HEAD:${PREFIX%/}"
mkdir -p "$STAGE"
# run from the top level: inside a subdirectory git archive filters paths by that prefix
git -C "$(git rev-parse --show-toplevel)" archive --format=tar "$TREE" | tar -x -C "$STAGE"
[[ -f "$STAGE/composer.json" ]] || { echo "git archive produced no files" >&2; exit 1; }
rm -rf "$STAGE/tests" "$STAGE/dist" "$STAGE/phpunit.dist.xml"

# every static asset the views reference must be in the committed tree
missing=0
while read -r asset; do
    [[ -f "$STAGE/public/$asset" ]] || { echo "Missing committed asset: public/$asset" >&2; missing=1; }
done < <(grep -rhoE "assets/[A-Za-z0-9_./-]+\.(css|js|png|svg|ico|woff2?)" "$STAGE/app/Views" | sort -u)
[[ $missing == 0 ]] || exit 1

# 2. production dependencies exactly as locked
composer install --working-dir="$STAGE" --no-dev --prefer-dist --optimize-autoloader \
    --no-interaction --no-progress

# 3. drop what is never needed at runtime
find "$STAGE/vendor" -depth -type d \( -name .git -o -name .github \) -exec rm -rf {} +
# (packages may be installed from source, where their export-ignore rules do not apply)
for pkg in "$STAGE"/vendor/*/*/; do
    rm -rf "${pkg}tests" "${pkg}Tests" "${pkg}test" "${pkg}docs" "${pkg}doc" "${pkg}examples" \
           "${pkg}samples" "${pkg}fixtures" "${pkg}dev-tools" "${pkg}infra" "${pkg}benchmarks"
done

# 4. smoke-check the autoloader with the classes the app depends on
php -d display_errors=1 -r '
    require $argv[1] . "/vendor/autoload.php";
    $need = ["CodeIgniter\\CodeIgniter", "CodeIgniter\\Shield\\Auth", "Dompdf\\Dompdf", "PhpOffice\\PhpSpreadsheet\\Spreadsheet",
        "Google\\Client", "Google\\Service\\Sheets", "Smalot\\PdfParser\\Parser", "Brick\\Math\\BigDecimal", "Anthropic\\Client"];
    $missing = array_filter($need, static fn ($c) => ! class_exists($c));
    if ($missing) { fwrite(STDERR, "Missing classes: " . implode(", ", $missing) . "\n"); exit(1); }
' "$STAGE"

# 5. build info + archive
{
    echo "version=$VERSION"
    echo "commit=$(git rev-parse HEAD)"
    echo "built_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "php_built_with=$(php -r 'echo PHP_VERSION;')"
    echo "composer_lock_sha256=$(sha256sum composer.lock | cut -d' ' -f1)"
} > "$STAGE/BUILD-INFO.txt"

mkdir -p "$OUT"
rm -f "$OUT/$NAME.zip" "$OUT/$NAME.zip.sha256"
(cd "$WORK" && zip -qr -X "$OUT/$NAME.zip" dgr-office)
(cd "$OUT" && sha256sum "$NAME.zip" > "$NAME.zip.sha256")

echo "Built $OUT/$NAME.zip ($(du -h "$OUT/$NAME.zip" | cut -f1)), vendor $(du -sh "$STAGE/vendor" | cut -f1)"
cat "$OUT/$NAME.zip.sha256"
