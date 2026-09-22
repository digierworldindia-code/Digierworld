-- =============================================================================
-- COLIFEES — privilege verification
-- Run after every deployment and after any manual database change:
--   psql "$DIRECT_DATABASE_URL" -f scripts/sql/03_verify_privileges.sql
-- Every row returned is a finding that needs attention.
-- =============================================================================

\echo '--- 1. Application role must not be a superuser or bypass RLS ---'
SELECT rolname, rolsuper, rolbypassrls, rolcreatedb, rolcreaterole
FROM pg_roles
WHERE rolname IN ('colifees_app', 'colifees_readonly', 'colifees_owner')
  AND (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole);

\echo '--- 2. Tables that hold dealer data but have RLS disabled ---'
SELECT c.relname
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
JOIN information_schema.columns col
  ON col.table_name = c.relname AND col.table_schema = n.nspname
WHERE n.nspname = 'public'
  AND c.relkind = 'r'
  AND col.column_name IN ('dealer_id', 'current_dealer_id')
  AND NOT c.relrowsecurity;

\echo '--- 3. Dealer-scoped tables without FORCE row level security ---'
SELECT c.relname
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public' AND c.relkind = 'r'
  AND c.relrowsecurity AND NOT c.relforcerowsecurity;

\echo '--- 4. Application role must not hold UPDATE/DELETE on audit_logs ---'
SELECT grantee, privilege_type
FROM information_schema.role_table_grants
WHERE table_name = 'audit_logs'
  AND grantee = 'colifees_app'
  AND privilege_type IN ('UPDATE', 'DELETE');

\echo '--- 5. Tables readable by PUBLIC (should return nothing) ---'
SELECT table_name, privilege_type
FROM information_schema.role_table_grants
WHERE table_schema = 'public' AND grantee = 'PUBLIC';

\echo '--- 6. Row Level Security policy inventory (informational) ---'
SELECT tablename, policyname, cmd
FROM pg_policies
WHERE schemaname = 'public'
ORDER BY tablename, policyname;
