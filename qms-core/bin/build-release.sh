#!/usr/bin/env bash
# Builds the release packages of the plain-PHP edition into dist/:
#   qms-core-deploy-<version>.zip   ready to upload / install: includes vendor/ (mPDF only)
#                                   and public/assets/vendor/ (Bootstrap 5, icons)
#   qms-core-source-<version>.zip   source, tests and docs without third-party code
#
#   bash bin/build-release.sh [version]      (default: version from app/Config/Qms.php)
#
# Needs: php 8.2+, composer 2, rsync, zip. Never packs .env, storage/ contents,
# vendor/ of the working copy, node_modules or build output.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-$(sed -n "s/.*public string \$version *= *'\([^']*\)'.*/\1/p" "$ROOT/app/Config/Qms.php")}"
[[ -n "$VERSION" ]] || { echo "Cannot determine the version." >&2; exit 1; }
for tool in php composer rsync zip unzip; do command -v "$tool" >/dev/null || { echo "$tool is required." >&2; exit 1; }; done

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
NAME="qms-core-$VERSION"
mkdir -p "$ROOT/dist"

echo "==> Clean source tree"
rsync -a \
    --include '/.env.example' --exclude '/.env' --exclude '/.env.*' --exclude '/vendor/' --exclude '/public/assets/vendor/' --exclude '/dist/' \
    --exclude '/tests/e2e/node_modules/' --exclude '/tests/e2e/*.png' --exclude '/.phpunit.cache/' --exclude '.phpunit.result.cache' \
    --exclude '/storage/*/*' --exclude '/storage/*.json' --exclude '/storage/*.lock' --exclude '/storage/*.txt' --exclude '/storage/*.flag' \
    "$ROOT/" "$WORK/$NAME/"
for dir in cache logs uploads; do
    mkdir -p "$WORK/$NAME/storage/$dir"
    touch "$WORK/$NAME/storage/$dir/.gitkeep"
done
chmod 0755 "$WORK/$NAME"/deploy/*.sh "$WORK/$NAME"/deploy/backup/*.sh "$WORK/$NAME"/bin/*.sh

echo "==> Source package"
mv "$WORK/$NAME" "$WORK/$NAME-source"
( cd "$WORK" && rm -f "$ROOT/dist/qms-core-source-$VERSION.zip" && zip -r -q -9 "$ROOT/dist/qms-core-source-$VERSION.zip" "$NAME-source" )
mv "$WORK/$NAME-source" "$WORK/$NAME"

echo "==> Production dependencies (composer install --no-dev)"
rm -rf "$WORK/$NAME/tests" "$WORK/$NAME/phpunit.xml.dist"
( cd "$WORK/$NAME" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --quiet )
[[ -f "$WORK/$NAME/public/assets/vendor/bootstrap/css/bootstrap.min.css" ]] || { echo "Bootstrap assets were not published." >&2; exit 1; }

echo "==> Trimming vendor/"
V="$WORK/$NAME/vendor"
find "$V" -name .git -type d -prune -exec rm -rf {} +
find "$V/mpdf/mpdf/ttfonts" -type f ! -name 'DejaVu*' -delete              # PDFs use DejaVu Sans only
rm -rf "$V/mpdf/mpdf/tests" "$V/mpdf/mpdf/utils" "$V/setasign/fpdi/tests" "$V/setasign/fpdi/local-tests" "$V/setasign/fpdi/scratches"
rm -rf "$V/twbs"                                                              # published to public/assets/vendor
for pkg in "$V"/*/*/; do
    for sub in tests Tests test docs doc examples .github; do rm -rf "${pkg}${sub}"; done
done
find "$V" -maxdepth 3 \( -name 'phpunit.xml*' -o -name '*.sh' -o -name '.php-cs-fixer*' -o -name 'phpstan*.neon*' -o -name 'psalm.xml' -o -name '.gitattributes' -o -name '.editorconfig' \) -delete
printf '# Third-party libraries are never served by the web server.\nRequire all denied\n' > "$V/.htaccess"
php -r 'require $argv[1]; new \Mpdf\Mpdf(["tempDir" => sys_get_temp_dir()]); echo "mPDF OK\n";' "$V/autoload.php" \
    || { echo "Trimmed vendor/ is broken." >&2; exit 1; }

echo "==> Syntax check"
find "$WORK/$NAME/app" "$WORK/$NAME/bin" "$WORK/$NAME/public" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

echo "==> Deploy package"
( cd "$WORK" && rm -f "$ROOT/dist/qms-core-deploy-$VERSION.zip" && zip -r -q -9 "$ROOT/dist/qms-core-deploy-$VERSION.zip" "$NAME" )
for zipfile in "$ROOT/dist/qms-core-deploy-$VERSION.zip" "$ROOT/dist/qms-core-source-$VERSION.zip"; do
    if unzip -l "$zipfile" | grep -qE '/\.env$|-sa\.json|\.pem$|installed\.lock|setup-key|storage/logs/[^.]|node_modules'; then
        echo "A secret or runtime file slipped into $(basename "$zipfile")." >&2; exit 1
    fi
done
cp "$ROOT/README.md" "$ROOT/dist/README.md"
( cd "$ROOT/dist" && sha256sum "qms-core-deploy-$VERSION.zip" "qms-core-source-$VERSION.zip" > "SHA256SUMS-core-$VERSION.txt" )
ls -lh "$ROOT/dist"
