#!/usr/bin/env bash
# Builds the release packages into dist/:
#   qms-deploy-<version>.zip   ready to install (vendor/ without dev packages, trimmed)
#   qms-source-<version>.zip   source, tests and docs without third-party code
#
#   bash bin/build-release.sh [version]          (default: version from app/Config/Qms.php)
#
# Needs: php 8.2+, composer 2, rsync, zip. Never packs .env, writable/ contents,
# vendor/ of the working copy, node_modules or build output.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-$(sed -n "s/.*public string \$version *= *'\([^']*\)'.*/\1/p" "$ROOT/app/Config/Qms.php")}"
[[ -n "$VERSION" ]] || { echo "Cannot determine the version." >&2; exit 1; }
for tool in php composer rsync zip; do command -v "$tool" >/dev/null || { echo "$tool is required." >&2; exit 1; }; done

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
NAME="qms-$VERSION"
mkdir -p "$ROOT/dist"

echo "==> Clean source tree"
rsync -a \
    --exclude '/vendor/' --exclude '/.env' --exclude '/public/assets/vendor/' --exclude '/build/' --exclude '/dist/' \
    --exclude '/tests/e2e/node_modules/' --exclude '/tests/e2e/*.png' --exclude '.phpunit.cache/' --exclude '.phpunit.result.cache' \
    --exclude '/writable/*/*' --exclude '/writable/*.json' \
    "$ROOT/" "$WORK/$NAME/"
for dir in cache logs session uploads debugbar; do
    mkdir -p "$WORK/$NAME/writable/$dir"
    [[ -f "$ROOT/writable/$dir/index.html" ]] && cp "$ROOT/writable/$dir/index.html" "$WORK/$NAME/writable/$dir/"
done
chmod 0755 "$WORK/$NAME/spark" "$WORK/$NAME"/deploy/*.sh "$WORK/$NAME"/deploy/backup/*.sh "$WORK/$NAME"/bin/*.sh

echo "==> Source package"
mv "$WORK/$NAME" "$WORK/$NAME-source"
( cd "$WORK" && rm -f "$ROOT/dist/qms-source-$VERSION.zip" && zip -r -q -9 "$ROOT/dist/qms-source-$VERSION.zip" "$NAME-source" )
mv "$WORK/$NAME-source" "$WORK/$NAME"

echo "==> Production dependencies (composer install --no-dev)"
rm -rf "$WORK/$NAME/tests" "$WORK/$NAME/phpunit.dist.xml"
( cd "$WORK/$NAME" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --quiet )

echo "==> Trimming vendor/"
V="$WORK/$NAME/vendor"
find "$V" -name .git -type d -prune -exec rm -rf {} +
find "$V/mpdf/mpdf/ttfonts" -type f ! -name 'DejaVu*' -delete              # PDFs use DejaVu Sans only
rm -rf "$V/mpdf/mpdf/tests" "$V/mpdf/mpdf/utils" "$V/setasign/fpdi/tests" "$V/setasign/fpdi/local-tests" "$V/setasign/fpdi/scratches"
rm -rf "$V/twbs"                                                              # published to public/assets/vendor
for pkg in "$V"/*/*/; do
    [[ "$pkg" == "$V/codeigniter4/framework/" ]] && continue
    for sub in tests Tests test docs doc examples .github; do rm -rf "${pkg}${sub}"; done
done
rm -rf "$V/codeigniter4/framework/tests"
find "$V" -maxdepth 3 \( -name 'phpunit.xml*' -o -name '.php-cs-fixer*' -o -name 'phpstan*.neon*' -o -name 'psalm.xml' -o -name '.gitattributes' -o -name '.editorconfig' \) -delete
php -r 'require $argv[1]; new \Mpdf\Mpdf(["tempDir" => sys_get_temp_dir()]); exit(class_exists("Google\\Service\\Sheets") ? 0 : 1);' "$V/autoload.php" \
    || { echo "Trimmed vendor/ is broken." >&2; exit 1; }

echo "==> Deploy package"
( cd "$WORK" && rm -f "$ROOT/dist/qms-deploy-$VERSION.zip" && zip -r -q -9 "$ROOT/dist/qms-deploy-$VERSION.zip" "$NAME" )
if unzip -l "$ROOT/dist/qms-deploy-$VERSION.zip" | grep -qE '/\.env$|google-sa|\.pem$|writable/(logs|debugbar|session)/[^i]'; then
    echo "A secret or runtime file slipped into the package." >&2; exit 1
fi
cp "$ROOT/README.md" "$ROOT/dist/README.md"
( cd "$ROOT/dist" && sha256sum "qms-deploy-$VERSION.zip" "qms-source-$VERSION.zip" > "SHA256SUMS-$VERSION.txt" )
ls -lh "$ROOT/dist"
