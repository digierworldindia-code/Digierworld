#!/usr/bin/env bash
#
# Build POLYFIZZ-FRESH-DATABASE.sql — the schema and reference data for a new
# installation, as one file that imports in phpMyAdmin.
#
#   scripts/build-fresh-sql.sh <source-database> [output-file]
#
# The source database is one that has had the migrations and both seeders run
# against it and nothing else, so the file is generated from the migrations
# rather than written by hand and cannot drift away from them.
#
# Credentials come from MYSQL_HOST/MYSQL_USER/MYSQL_PWD in the environment.
#
# What goes in:   every table's structure, with its indexes, foreign keys,
#                 CHECK constraints and generated columns; the roles, the
#                 permissions and which role holds which; the settings; the
#                 first warehouse; the identifier counters; the website pages
#                 and FAQs; and the `migrations` rows, so the application
#                 knows the schema is current and does not try to re-apply it.
#
# What stays out: user accounts, dealers, customers, mattresses, sales,
#                 warranties, claims, sessions, the audit trail — everything
#                 that belongs to a running system. The first administrator is
#                 created afterwards with `php spark polyfix:create-user`, so
#                 no password is written into this file.
#
# There is no DROP TABLE and no CREATE DATABASE. Importing into a database
# that already holds these tables fails and changes nothing, which is the
# behaviour you want from a file somebody might point at the wrong database.
set -euo pipefail

cd "$(dirname "$0")/.."
SRC=${1:?usage: build-fresh-sql.sh <source-database> [output-file]}
OUT=${2:-delivery/POLYFIZZ-FRESH-DATABASE.sql}
HOST=${MYSQL_HOST:-127.0.0.1}
USER=${MYSQL_USER:-polyfix_owner}

mkdir -p "$(dirname "$OUT")"

# Tables whose contents a new installation needs. Everything else ships empty.
REFERENCE=(
    migrations
    permissions roles role_permissions
    system_settings identifier_counters
    warehouses
    website_pages faqs seo_metadata
)

common=(
    --host="$HOST" --user="$USER"
    --no-tablespaces
    --skip-add-drop-table
    --skip-add-locks
    --skip-comments
    --set-charset
    --default-character-set=utf8mb4
    --single-transaction
)

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

echo "==> structure of every table in \`$SRC\`"
mysqldump "${common[@]}" --no-data --routines --events "$SRC" > "$TMP/structure.sql"

echo "==> reference data (${#REFERENCE[@]} tables)"
mysqldump "${common[@]}" --no-create-info --complete-insert \
    "$SRC" "${REFERENCE[@]}" > "$TMP/data.sql"

# mysqldump writes its own session SET lines; the header below states the
# intent for whoever opens the file, and the two sandwich lines make the import
# order-independent, since the tables reference each other.
{
    cat <<'HEADER'
-- =============================================================================
-- POLYFIZZ — FRESH DATABASE
-- -----------------------------------------------------------------------------
-- The complete schema and the reference data a new installation needs. Import
-- this into an EMPTY database, then create the first administrator with
--
--     php spark polyfix:create-user --email you@your-domain --name "Your Name" \
--         --role SUPER_ADMIN
--
-- which prints a one-time password once and makes you change it at first
-- sign-in. There is no account, and no password, in this file.
--
-- Importing with phpMyAdmin:
--   1. Create the database (utf8mb4 / utf8mb4_0900_ai_ci) and select it.
--   2. Import -> choose this file -> Go.
--
-- Or from a shell:
--   mysql -u <user> -p <database> < POLYFIZZ-FRESH-DATABASE.sql
--
-- It contains no CREATE DATABASE, no USE and no DROP TABLE, so it cannot
-- overwrite an existing installation: pointed at a database that already has
-- these tables it fails on the first CREATE TABLE and changes nothing. To
-- bring an existing POLYFIZZ database up to date, use
-- POLYFIZZ-EXISTING-DATABASE-UPGRADE.sql instead — never this file.
--
-- What is included:  every table, with its indexes, foreign keys, CHECK
--                    constraints and generated columns; roles, permissions and
--                    the grants between them; the editable settings; the first
--                    warehouse; the identifier counters; the website pages and
--                    FAQs; and the applied-migration record.
-- What is not:       users, dealers, customers, mattresses, batches,
--                    dispatches, sales, warranties, claims, sessions and the
--                    audit trail. Those belong to a running system.
--
-- The website pages are a starting point, not finished copy. Edit them under
-- Console -> Content, and add the product range under Console -> Products.
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+00:00';

HEADER
    cat "$TMP/structure.sql"
    echo
    cat "$TMP/data.sql"
    echo
    echo "SET FOREIGN_KEY_CHECKS = 1;"
} > "$OUT"

tables=$(grep -c '^CREATE TABLE' "$OUT" || true)
inserts=$(grep -c '^INSERT INTO' "$OUT" || true)

if grep -qiE '^(DROP TABLE|DROP DATABASE|TRUNCATE|CREATE DATABASE|USE )' "$OUT"; then
    echo "REFUSING: the file contains a destructive or database-selecting statement"
    exit 1
fi

echo
echo "built: $OUT"
echo "       $(du -h "$OUT" | cut -f1), ${tables} tables, ${inserts} insert statement(s)"
