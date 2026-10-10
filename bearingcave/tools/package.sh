#!/usr/bin/env bash
# Builds build/bearingcave-source.zip from the committed source tree (git archive),
# so .env, vendor/, uploads, logs and demo credentials can never be included.
# Usage: tools/package.sh   (run inside the git checkout)
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p build
prefix="$(git rev-parse --show-prefix)"
git archive --format=zip --prefix=bearingcave/ -o build/bearingcave-source.zip "HEAD:${prefix}"
echo "Wrote build/bearingcave-source.zip ($(du -h build/bearingcave-source.zip | cut -f1))"
echo "After unzipping: composer install, then follow docs/INSTALL.md"
