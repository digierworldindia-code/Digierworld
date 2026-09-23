-- =============================================================================
-- COLIFEES — PostgreSQL role separation and least privilege
-- -----------------------------------------------------------------------------
-- Run ONCE per environment as a PostgreSQL superuser, before the first
-- migration:
--
--   psql "postgresql://postgres@db-host:5432/postgres" \
--        -v owner_password="$(openssl rand -base64 24)" \
--        -v app_password="$(openssl rand -base64 24)" \
--        -v readonly_password="$(openssl rand -base64 24)" \
--        -f scripts/sql/01_roles.sql
--
-- Three roles, three jobs, none of them the superuser:
--
--   colifees_owner     owns the schema. Runs migrations and pg_dump. Its
--                      credentials live with the release pipeline, NOT in the
--                      running application's environment.
--   colifees_app       what the API connects as. DML only: no CREATE, no DROP,
--                      no ALTER, cannot disable Row Level Security, cannot
--                      UPDATE or DELETE audit rows.
--   colifees_readonly  optional analyst / BI login. SELECT only, and Row Level
--                      Security still applies to it.
--   colifees_backup    the ONLY role permitted to bypass Row Level Security.
--                      SELECT-only, used by pg_dump and by nothing else.
--
-- Why the backup role needs BYPASSRLS, stated plainly: the dealer-isolation
-- policies are FORCED, so they apply to the table owner as well. pg_dump run by
-- any role subject to those policies fails outright with "query would be
-- affected by row-level security policy" — it does not fall back to a partial
-- dump. A backup must read every row by definition, so it gets a role that can,
-- and that role can do nothing else: no INSERT, no UPDATE, no DELETE, no DDL.
-- Guard its credentials like the dump itself, because they are equivalent.
--
-- The application must never use the superuser. A superuser silently bypasses
-- every Row Level Security policy in this database, which would make dealer
-- isolation depend on application code alone.
-- =============================================================================

\set ON_ERROR_STOP on

-- -----------------------------------------------------------------------------
-- 1. Roles
-- -----------------------------------------------------------------------------
-- psql variables cannot be referenced inside a dollar-quoted block, so each
-- CREATE ROLE is built as a top-level statement and executed with \gexec.
SELECT format('CREATE ROLE colifees_owner LOGIN PASSWORD %L', :'owner_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'colifees_owner')
\gexec

SELECT format('CREATE ROLE colifees_app LOGIN PASSWORD %L', :'app_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'colifees_app')
\gexec

SELECT format('CREATE ROLE colifees_readonly LOGIN PASSWORD %L', :'readonly_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'colifees_readonly')
\gexec

SELECT format('CREATE ROLE colifees_backup LOGIN PASSWORD %L', :'backup_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'colifees_backup')
\gexec

-- No role here may create databases, create roles or replicate. Only the backup
-- role may bypass Row Level Security, and only so that pg_dump can read every
-- row; see the note above.
ALTER ROLE colifees_owner    NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
ALTER ROLE colifees_app      NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
ALTER ROLE colifees_readonly NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
ALTER ROLE colifees_backup   NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION BYPASSRLS;
ALTER ROLE colifees_backup   SET default_transaction_read_only = on;

-- Bound the damage a runaway query or a stuck transaction can do.
ALTER ROLE colifees_app      SET statement_timeout = '30s';
ALTER ROLE colifees_app      SET idle_in_transaction_session_timeout = '60s';
ALTER ROLE colifees_readonly SET statement_timeout = '120s';
ALTER ROLE colifees_readonly SET default_transaction_read_only = on;

-- -----------------------------------------------------------------------------
-- 2. Database
-- -----------------------------------------------------------------------------
SELECT 'CREATE DATABASE colifees OWNER colifees_owner ENCODING ''UTF8'''
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'colifees')
\gexec

REVOKE ALL ON DATABASE colifees FROM PUBLIC;
GRANT CONNECT ON DATABASE colifees TO colifees_owner, colifees_app, colifees_readonly, colifees_backup;

\connect colifees

-- -----------------------------------------------------------------------------
-- 3. Schema privileges
-- -----------------------------------------------------------------------------
-- PostgreSQL 15+ already removes the public CREATE grant; this is explicit so
-- the same script is correct on 13 and 14 too.
REVOKE ALL ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO colifees_owner;
GRANT USAGE ON SCHEMA public TO colifees_app, colifees_readonly, colifees_backup;
GRANT ALL   ON SCHEMA public TO colifees_owner;

CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- Existing objects (no-op on a fresh database, needed when re-running later).
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES    IN SCHEMA public TO colifees_app;
GRANT USAGE, SELECT                  ON ALL SEQUENCES IN SCHEMA public TO colifees_app;
GRANT EXECUTE                        ON ALL FUNCTIONS IN SCHEMA public TO colifees_app;
GRANT SELECT                         ON ALL TABLES    IN SCHEMA public TO colifees_readonly;
GRANT SELECT                         ON ALL TABLES    IN SCHEMA public TO colifees_backup;
GRANT SELECT                         ON ALL SEQUENCES IN SCHEMA public TO colifees_backup;

-- Objects created by future migrations inherit the same grants automatically,
-- so a new table is never accidentally unreachable or over-exposed.
ALTER DEFAULT PRIVILEGES FOR ROLE colifees_owner IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO colifees_app;
ALTER DEFAULT PRIVILEGES FOR ROLE colifees_owner IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO colifees_app;
ALTER DEFAULT PRIVILEGES FOR ROLE colifees_owner IN SCHEMA public
  GRANT EXECUTE ON FUNCTIONS TO colifees_app;
ALTER DEFAULT PRIVILEGES FOR ROLE colifees_owner IN SCHEMA public
  GRANT SELECT ON TABLES TO colifees_readonly;
ALTER DEFAULT PRIVILEGES FOR ROLE colifees_owner IN SCHEMA public
  GRANT SELECT ON TABLES TO colifees_backup;
ALTER DEFAULT PRIVILEGES FOR ROLE colifees_owner IN SCHEMA public
  GRANT SELECT ON SEQUENCES TO colifees_backup;

-- -----------------------------------------------------------------------------
-- 4. Reminder: network exposure is handled outside this file
-- -----------------------------------------------------------------------------
-- pg_hba.conf should allow ONLY the application subnet, over TLS:
--
--   hostssl  colifees  colifees_app       10.0.2.0/24  scram-sha-256
--   hostssl  colifees  colifees_owner     10.0.9.10/32 scram-sha-256
--   hostssl  colifees  colifees_readonly  10.0.3.0/24  scram-sha-256
--   hostssl  colifees  colifees_backup    10.0.9.11/32 scram-sha-256
--
-- and postgresql.conf should bind to the private interface only:
--
--   listen_addresses = '10.0.1.20'
--   ssl = on
--   password_encryption = scram-sha-256
--
-- The database port must not be reachable from the public internet. See
-- docs/07-security-guide.md.
