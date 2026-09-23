-- =============================================================================
-- DOWN PATH for migration 0007 — brand rename residuals
-- -----------------------------------------------------------------------------
-- Restores seo_metadata.keywords, website_pages.hero and warehouses.name to the
-- exact values captured in brand_rename_backup before 0007 ran.
--
--   psql "$DIRECT_DATABASE_URL" -f scripts/sql/rollback-0007-brand-rename-residuals.sql
--
-- Run this BEFORE rolling back 0006. 0006's down path restores the pre-rename
-- hero JSON wholesale, so reversing them out of order would leave the hero
-- image path from whichever ran last.
--
-- Safe to run twice: it restores from a backup table it never modifies.
-- =============================================================================
\set ON_ERROR_STOP on

BEGIN;

-- Fail-closed RLS (migration 0005): without a scope these UPDATEs match nothing
-- and the transaction commits having changed zero rows.
SET app.dealer_id = 'ALL';

UPDATE seo_metadata s
   SET keywords = string_to_array(b.old_value, U&'\001F'),
       updated_at = now()
  FROM brand_rename_backup b
 WHERE b.migration = '0007_brand_rename_residuals'
   AND b.table_name = 'seo_metadata'
   AND b.column_name = 'keywords'
   AND s.id = b.record_id::uuid;

UPDATE website_pages w
   SET hero = b.old_value::jsonb,
       updated_at = now()
  FROM brand_rename_backup b
 WHERE b.migration = '0007_brand_rename_residuals'
   AND b.table_name = 'website_pages'
   AND b.column_name = 'hero'
   AND w.id = b.record_id::uuid;

UPDATE warehouses h
   SET name = b.old_value,
       updated_at = now()
  FROM brand_rename_backup b
 WHERE b.migration = '0007_brand_rename_residuals'
   AND b.table_name = 'warehouses'
   AND b.column_name = 'name'
   AND h.id = b.record_id::uuid;

-- Confirm every captured value is back in place before committing.
DO $$
DECLARE missing bigint;
BEGIN
  SELECT count(*) INTO missing
    FROM brand_rename_backup b
   WHERE b.migration = '0007_brand_rename_residuals'
     AND NOT EXISTS (
       SELECT 1 FROM seo_metadata s
        WHERE b.table_name = 'seo_metadata' AND s.id = b.record_id::uuid
          AND array_to_string(s.keywords, U&'\001F') IS NOT DISTINCT FROM b.old_value)
     AND NOT EXISTS (
       SELECT 1 FROM website_pages w
        WHERE b.table_name = 'website_pages' AND w.id = b.record_id::uuid
          AND w.hero::text::jsonb IS NOT DISTINCT FROM b.old_value::jsonb)
     AND NOT EXISTS (
       SELECT 1 FROM warehouses h
        WHERE b.table_name = 'warehouses' AND h.id = b.record_id::uuid
          AND h.name IS NOT DISTINCT FROM b.old_value);
  IF missing > 0 THEN
    RAISE EXCEPTION 'rollback 0007: % captured value(s) were not restored', missing;
  END IF;
  RAISE NOTICE 'rollback 0007: all captured values restored';
END $$;

COMMIT;
