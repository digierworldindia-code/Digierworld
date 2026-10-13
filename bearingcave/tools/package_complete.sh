#!/usr/bin/env bash
# Builds build/BEARINGCAVE_COMPLETE_WITH_VENDOR_AND_ALL_DEPENDENCIES.zip:
# the committed source (git archive, so .env, uploads, logs, backups and demo
# passwords can never be included) plus a production vendor/ installed with
#   composer install --no-dev --prefer-dist --optimize-autoloader
# The vendor/ is installed from composer.lock, so versions are exactly the
# tested ones. Run from inside the git checkout:  tools/package_complete.sh
set -euo pipefail
cd "$(dirname "$0")/.."
app="$(pwd)"
name="BEARINGCAVE_COMPLETE"
zip="BEARINGCAVE_COMPLETE_WITH_VENDOR_AND_ALL_DEPENDENCIES.zip"
stage="$app/build/stage"
prefix="$(git rev-parse --show-prefix)"
root="$(git rev-parse --show-toplevel)"

if ! git diff --quiet HEAD -- . ; then
    echo "Uncommitted changes in $(pwd): commit first so the package matches the repository." >&2
    exit 1
fi

rm -rf "$stage" "$app/build/$zip"
mkdir -p "$stage"
(cd "$root" && git archive --format=tar --prefix="$name/" "HEAD:${prefix%/}") | tar -x -C "$stage"

cd "$stage/$name"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
COMPOSER_ALLOW_SUPERUSER=1 composer validate --no-check-publish
COMPOSER_ALLOW_SUPERUSER=1 composer check-platform-reqs --no-dev

# Sanity: autoloader loads, framework + Shield + PhpSpreadsheet classes resolve.
php -r 'require "vendor/autoload.php";
foreach (["CodeIgniter\\CodeIgniter", "CodeIgniter\\Shield\\Auth", "PhpOffice\\PhpSpreadsheet\\Spreadsheet", "Psr\\Log\\LoggerInterface", "App\\Services\\ProductService"] as $c) {
    if (! class_exists($c) && ! interface_exists($c)) { fwrite(STDERR, "missing class $c\n"); exit(1); }
}
echo "autoload OK\n";'

# writable/ must exist with its subfolders (they are tracked via index.html files).
for d in cache logs session uploads debugbar; do mkdir -p "writable/$d"; done
chmod -R u+rwX,go+rX . && chmod -R 0775 writable

cd "$stage"
zip -qr -X "$app/build/$zip" "$name"
cd "$app"
echo "Wrote build/$zip ($(du -h "build/$zip" | cut -f1))"
unzip -l "build/$zip" | tail -1
