#!/usr/bin/env bash
# Update an existing QMS installation to the version in THIS package:
#   1. maintenance page on, backup (database + uploads)
#   2. new code (keeps .env, writable/ and all secrets)
#   3. database migrations as qms_migrator (unlocked only for the update)
#   4. PHP-FPM reload, maintenance page off
#
#   sudo bash deploy/update.sh
exec bash "$(dirname "${BASH_SOURCE[0]}")/install.sh" --update "$@"
