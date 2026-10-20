#!/usr/bin/env bash
#
# Re-verifies the self-hosted frontend libraries in public/assets/vendor
# against the official npm registry, and checks them for known
# vulnerabilities. Run it before every release and after any upgrade.
#
#   scripts/verify-vendor-assets.sh
#
# For each package in public/assets/vendor/MANIFEST.json it:
#   1. asks the registry for the package's published tarball URL + sha512,
#      and requires both to equal what MANIFEST.json pins;
#   2. downloads that tarball and checks its sha512 against the registry;
#   3. requires every shipped file to be byte-identical to its source file
#      inside the tarball, and its sha384 to equal MANIFEST.json;
#   4. runs `npm audit` for the pinned versions (skipped, with a warning,
#      when npm is not installed).
#
# Needs: bash, curl, tar, openssl, php (to read JSON). Exits 1 on any mismatch.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VENDOR="$ROOT/public/assets/vendor"
MANIFEST="$VENDOR/MANIFEST.json"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

fail=0
bad() { echo "  FAIL: $*"; fail=1; }

# "<package> <version> <tarball> <integrity>" per line
php -r '$m = json_decode(file_get_contents($argv[1]), true);
    foreach ($m["packages"] as $p => $d) { echo "$p {$d["version"]} {$d["tarball"]} {$d["tarballIntegrity"]}\n"; }' "$MANIFEST" > "$WORK/packages.txt"

while read -r pkg ver tarball integrity; do
    echo "== $pkg@$ver"
    curl -fsSL "https://registry.npmjs.org/$pkg/$ver" -o "$WORK/meta.json"
    reg_tarball="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["dist"]["tarball"];' "$WORK/meta.json")"
    reg_integrity="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["dist"]["integrity"];' "$WORK/meta.json")"
    [ "$reg_tarball" = "$tarball" ] || bad "tarball URL differs from registry ($reg_tarball)"
    [ "$reg_integrity" = "$integrity" ] || bad "MANIFEST integrity differs from registry ($reg_integrity)"

    dir="$WORK/$pkg"
    mkdir -p "$dir"
    curl -fsSL "$tarball" -o "$dir/pkg.tgz"
    got="sha512-$(openssl dgst -sha512 -binary "$dir/pkg.tgz" | openssl base64 -A)"
    [ "$got" = "$reg_integrity" ] || bad "downloaded tarball sha512 does not match the registry"
    tar -xzf "$dir/pkg.tgz" -C "$dir"

    php -r '$m = json_decode(file_get_contents($argv[1]), true);
        foreach ($m["packages"][$argv[2]]["files"] as $f => $d) { echo "$f {$d["source"]} {$d["sha384"]}\n"; }' "$MANIFEST" "$pkg" > "$dir/files.txt"
    while read -r file source sha384; do
        if ! cmp -s "$VENDOR/$file" "$dir/package/$source"; then
            bad "$file is not identical to $source in the official tarball"
            continue
        fi
        got="sha384-$(openssl dgst -sha384 -binary "$VENDOR/$file" | openssl base64 -A)"
        [ "$got" = "$sha384" ] || bad "$file sha384 differs from MANIFEST.json"
        echo "  ok  $file"
    done < "$dir/files.txt"
done < "$WORK/packages.txt"

echo "== npm audit"
if command -v npm > /dev/null; then
    php -r '$m = json_decode(file_get_contents($argv[1]), true); $deps = [];
        foreach ($m["packages"] as $p => $d) { $deps[$p] = $d["version"]; }
        echo json_encode(["name" => "dgr-office-vendor-audit", "private" => true, "dependencies" => $deps]);' "$MANIFEST" > "$WORK/package.json"
    if (cd "$WORK" && npm install --package-lock-only --ignore-scripts --no-fund --silent > /dev/null && npm audit --omit=dev); then
        :
    else
        bad "npm audit reported vulnerabilities (or could not run)"
    fi
else
    echo "  skipped: npm is not installed (install Node.js to run the vulnerability check)"
fi

if [ "$fail" -ne 0 ]; then
    echo "Vendor verification FAILED"
    exit 1
fi
echo "Vendor assets verified: official, unmodified, pinned."
