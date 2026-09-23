#!/usr/bin/env bash
# =============================================================================
# POLYFIX MATTRESS — role bootstrap for the Postgres container
# -----------------------------------------------------------------------------
# The official postgres image runs everything in /docker-entrypoint-initdb.d
# ONCE, on an empty data directory. This wrapper exists because
# scripts/sql/01_roles.sql takes psql variables for the passwords, and the
# image's plain-.sql runner cannot pass them.
#
# Passwords come from the container's environment, which compose reads from
# .env. They are never written into a file inside the image.
# =============================================================================
set -Eeuo pipefail

: "${POSTGRES_DB:?POSTGRES_DB is required}"
: "${APP_DB_PASSWORD:?APP_DB_PASSWORD is required (see .env.example)}"
: "${OWNER_DB_PASSWORD:?OWNER_DB_PASSWORD is required}"
: "${READONLY_DB_PASSWORD:?READONLY_DB_PASSWORD is required}"
: "${BACKUP_DB_PASSWORD:?BACKUP_DB_PASSWORD is required}"

echo "Creating least-privilege roles in ${POSTGRES_DB}…"

psql -v ON_ERROR_STOP=1 \
     --username "$POSTGRES_USER" \
     --dbname "$POSTGRES_DB" \
     -v owner_password="$OWNER_DB_PASSWORD" \
     -v app_password="$APP_DB_PASSWORD" \
     -v readonly_password="$READONLY_DB_PASSWORD" \
     -v backup_password="$BACKUP_DB_PASSWORD" \
     -f /opt/polyfix/sql/01_roles.sql

# The shadow database is only used by `prisma migrate dev` when authoring a new
# migration. Creating it here costs nothing and saves a confusing failure the
# first time a developer writes one.
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres <<SQL
SELECT 'CREATE DATABASE ${POSTGRES_DB}_shadow OWNER colifees_owner'
 WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = '${POSTGRES_DB}_shadow')\gexec
SQL

echo "Roles created. The application will connect as colifees_app."
echo "NOTE: grants for the schema's tables are applied by scripts/sql/02_grants.sql"
echo "      AFTER the first migration — see docs/02-database-setup.md."
