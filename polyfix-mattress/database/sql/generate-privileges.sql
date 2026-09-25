-- =============================================================================
-- POLYFIX MATTRESS — least-privilege grants for MySQL 8
-- -----------------------------------------------------------------------------
-- Run as an administrative account AFTER every `php spark polyfix:migrate`:
--
--   mysql -u root -p polyfix_mattress < database/sql/generate-privileges.sql \
--     | mysql -u root -p polyfix_mattress
--
-- The first command PRINTS the GRANT statements for every table currently in
-- the schema; the second applies them. Printing first means you can read them.
--
-- Why per table: MySQL cannot subtract a table privilege from a database-wide
-- grant. "GRANT ... ON polyfix_mattress.*" followed by "REVOKE UPDATE ON
-- audit_logs" fails. So nothing is granted at database level; each table gets
-- exactly the rights it should have.
--
-- Accounts (created once; see docs/database.md):
--   polyfix_app     the web application
--   polyfix_backup  mysqldump — reads everything, writes nothing
--
-- Append-only history (audit_logs, record_versions, mattress_events,
-- claim_events) gets SELECT + INSERT only. The application cannot UPDATE or
-- DELETE a history row because the privilege does not exist on its connection.
-- It cannot TRUNCATE either: MySQL's TRUNCATE requires DROP, never granted.
-- =============================================================================

SELECT CONCAT(
  'GRANT ',
  CASE
    WHEN t.table_name IN ('audit_logs', 'record_versions', 'mattress_events', 'claim_events')
      THEN 'SELECT, INSERT'
    -- failed login attempts are a security log: record and read, never rewrite
    WHEN t.table_name = 'failed_login_attempts'
      THEN 'SELECT, INSERT, DELETE'
    -- migration bookkeeping and the historical record of the previous platform
    WHEN t.table_name IN ('migrations', 'legacy_prisma_migrations', 'brand_rename_backup')
      THEN NULL
    ELSE 'SELECT, INSERT, UPDATE, DELETE'
  END,
  ' ON `', t.table_schema, '`.`', t.table_name, '` TO ''polyfix_app''@''localhost'', ''polyfix_app''@''127.0.0.1'';'
) AS `-- application grants`
FROM information_schema.tables t
WHERE t.table_schema = DATABASE()
  AND t.table_type = 'BASE TABLE'
  AND t.table_name NOT IN ('migrations', 'legacy_prisma_migrations', 'brand_rename_backup')
ORDER BY t.table_name;

-- mysqldump needs SELECT on every table, plus the ability to read triggers and
-- lock tables for a consistent snapshot. Nothing that writes.
SELECT CONCAT(
  'GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES ON `', DATABASE(),
  '`.* TO ''polyfix_backup''@''localhost'', ''polyfix_backup''@''127.0.0.1'';'
) AS `-- backup grants`;

SELECT 'FLUSH PRIVILEGES;' AS `-- apply`;
