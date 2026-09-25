-- =============================================================================
-- POLYFIX MATTRESS — privilege verification. Every row returned is a finding.
--   mysql -u root -p polyfix_mattress < database/sql/verify-privileges.sql
-- =============================================================================

-- 1. The application must hold no database-wide or global privilege: those
--    cannot be narrowed per table, so they would defeat the append-only rules.
SELECT 'app holds a database-wide privilege' AS finding, privilege_type
  FROM information_schema.schema_privileges
 WHERE grantee IN ('''polyfix_app''@''localhost''', '''polyfix_app''@''127.0.0.1''')
UNION ALL
SELECT 'app holds a global privilege', privilege_type
  FROM information_schema.user_privileges
 WHERE grantee IN ('''polyfix_app''@''localhost''', '''polyfix_app''@''127.0.0.1''')
   AND privilege_type <> 'USAGE';

-- 2. History tables must not be writable beyond INSERT.
SELECT 'app can rewrite history' AS finding, table_name, privilege_type
  FROM information_schema.table_privileges
 WHERE grantee IN ('''polyfix_app''@''localhost''', '''polyfix_app''@''127.0.0.1''')
   AND table_name IN ('audit_logs', 'record_versions', 'mattress_events', 'claim_events')
   AND privilege_type IN ('UPDATE', 'DELETE', 'DROP', 'ALTER');

-- 3. The application must be able to use every business table. A table it
--    cannot reach is a feature that will fail at the worst moment.
SELECT 'app cannot read table' AS finding, t.table_name, NULL
  FROM information_schema.tables t
 WHERE t.table_schema = DATABASE() AND t.table_type = 'BASE TABLE'
   AND t.table_name NOT IN ('migrations', 'legacy_prisma_migrations', 'brand_rename_backup')
   AND NOT EXISTS (
     SELECT 1 FROM information_schema.table_privileges p
      WHERE p.table_schema = t.table_schema AND p.table_name = t.table_name
        AND p.grantee = '''polyfix_app''@''localhost''' AND p.privilege_type = 'SELECT');

-- 4. The backup account must not be able to write.
SELECT 'backup account can write' AS finding, privilege_type, NULL
  FROM information_schema.schema_privileges
 WHERE grantee IN ('''polyfix_backup''@''localhost''', '''polyfix_backup''@''127.0.0.1''')
   AND privilege_type IN ('INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE');
