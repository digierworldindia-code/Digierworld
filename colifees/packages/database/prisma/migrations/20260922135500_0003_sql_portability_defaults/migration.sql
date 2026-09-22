-- =============================================================================
-- 0003 — SQL PORTABILITY DEFAULTS
-- -----------------------------------------------------------------------------
-- Prisma populates `updated_at` from the client. That is invisible to anyone
-- writing ordinary SQL — a psql session, a data-migration script, a restore
-- that replays partial rows — who would hit a NOT NULL violation on a column
-- they have no reason to think about.
--
-- COLIFEES owns this database and must be able to work in it with standard
-- tools, so every timestamp column that the application fills automatically
-- gets a database-level default too. The BEFORE UPDATE trigger from migration
-- 0002 keeps `updated_at` honest thereafter.
-- =============================================================================

DO $$
DECLARE
  col record;
BEGIN
  FOR col IN
    SELECT c.table_name, c.column_name
    FROM information_schema.columns c
    JOIN pg_class pc ON pc.relname = c.table_name
    JOIN pg_namespace pn ON pn.oid = pc.relnamespace AND pn.nspname = 'public'
    WHERE c.table_schema = 'public'
      AND pc.relkind = 'r'
      AND c.column_name IN ('created_at', 'updated_at')
      AND c.column_default IS NULL
  LOOP
    EXECUTE format('ALTER TABLE %I ALTER COLUMN %I SET DEFAULT now()', col.table_name, col.column_name);
  END LOOP;
END;
$$;

-- The audit chain columns are written by the seal trigger, so SQL callers need
-- never supply them.
ALTER TABLE audit_logs ALTER COLUMN row_hash SET DEFAULT '';
