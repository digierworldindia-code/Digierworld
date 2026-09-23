-- =============================================================================
-- COLIFEES — least-privilege grants (idempotent)
-- -----------------------------------------------------------------------------
-- Run as the schema owner after EVERY migration:
--
--   psql "$DIRECT_DATABASE_URL" -f scripts/sql/02_grants.sql
--
-- `ALTER DEFAULT PRIVILEGES` in 01_roles.sql normally covers new tables
-- automatically, but those entries are attached to the schema: dropping and
-- recreating `public` (a reset, a restore into a fresh database, a manual
-- rebuild) silently discards them. Running this script afterwards is what
-- guarantees the application role ends up with exactly the rights it needs and
-- none of the rights it must not have.
--
-- The order matters: grant broadly first, then take back the privileges that
-- would let the application rewrite history.
-- =============================================================================

\set ON_ERROR_STOP on

-- --- 1. baseline ------------------------------------------------------------
GRANT USAGE ON SCHEMA public TO colifees_app, colifees_readonly;

GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES    IN SCHEMA public TO colifees_app;
GRANT USAGE, SELECT                  ON ALL SEQUENCES IN SCHEMA public TO colifees_app;
GRANT EXECUTE                        ON ALL FUNCTIONS IN SCHEMA public TO colifees_app;

GRANT SELECT  ON ALL TABLES    IN SCHEMA public TO colifees_readonly;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA public TO colifees_readonly;

-- The backup role reads everything and writes nothing. It bypasses Row Level
-- Security (set in 01_roles.sql) because pg_dump cannot read a FORCE-protected
-- table otherwise.
GRANT SELECT ON ALL TABLES    IN SCHEMA public TO colifees_backup;
GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO colifees_backup;

-- --- 2. future objects ------------------------------------------------------
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO colifees_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO colifees_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT EXECUTE ON FUNCTIONS TO colifees_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT SELECT ON TABLES TO colifees_readonly;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT SELECT ON TABLES TO colifees_backup;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT SELECT ON SEQUENCES TO colifees_backup;

-- --- 3. take back what the application must never do ------------------------
-- History is append-only. Database triggers enforce this as well; the missing
-- privilege means an attempt fails before the trigger is even reached.
REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs      FROM colifees_app, colifees_readonly;
REVOKE UPDATE, DELETE, TRUNCATE ON record_versions FROM colifees_app, colifees_readonly;
REVOKE UPDATE, DELETE, TRUNCATE ON mattress_events FROM colifees_app, colifees_readonly;
REVOKE UPDATE, DELETE, TRUNCATE ON claim_events    FROM colifees_app, colifees_readonly;
REVOKE UPDATE ON failed_login_attempts FROM colifees_app;

-- Prisma's own migration bookkeeping is none of the application's business.
REVOKE ALL ON TABLE _prisma_migrations FROM colifees_app, colifees_readonly;

-- Reporting must never see credential material, even though it is hashed or
-- encrypted, and must never read raw audit rows.
REVOKE SELECT ON users, sessions, refresh_tokens, password_reset_tokens, audit_logs
  FROM colifees_readonly;

-- A backup must contain every table, including the credential ones, or it is
-- not a backup. The control on those rows is that the dump is encrypted and the
-- role that produces it cannot write anything.

-- --- 4. confirm the outcome -------------------------------------------------
\echo 'Application-role privileges that should NOT exist (expect zero rows):'
SELECT table_name, privilege_type
FROM information_schema.role_table_grants
WHERE grantee = 'colifees_app'
  AND table_name IN ('audit_logs', 'record_versions', 'mattress_events', 'claim_events')
  AND privilege_type IN ('UPDATE', 'DELETE', 'TRUNCATE');
