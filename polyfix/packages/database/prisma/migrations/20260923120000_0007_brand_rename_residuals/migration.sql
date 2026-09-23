-- =============================================================================
-- 0007 — BRAND RENAME RESIDUALS: the three things 0006 did not reach
-- -----------------------------------------------------------------------------
-- Migration 0006 rewrote brand presentation text. Rendering the site afterwards
-- and re-scanning every table case-insensitively found three residual classes
-- it had missed. They are corrected here rather than by editing 0006, because
-- 0006 has already been applied and an applied migration is history.
--
-- WHAT THIS CHANGES
--   1. seo_metadata.keywords   3 rows. 0006 replaced the upper-case brand token
--      in title/description/social copy, but keywords are stored lower case in a
--      text[] ("about colifees", "why colifees", "contact colifees") and no
--      replacement rule matched them. These are search keywords the company
--      publishes about itself: brand presentation, so they change.
--
--   2. website_pages.hero -> image.src   2 rows. The hero and factory artwork
--      files were renamed on disk with the rebrand (colifees-hero.svg ->
--      polyfix-hero.svg, colifees-factory.svg -> polyfix-factory.svg). The
--      stored paths still pointed at the old filenames, so the home and about
--      heroes were requesting assets that no longer exist. This is a broken
--      reference, not a cosmetic one.
--
--   3. warehouses.name   1 row. "COLIFEES Jaipur Plant" -> "POLYFIX Jaipur
--      Plant". The company's own facility, carrying the company's own name.
--      Only the name changes. The facility ADDRESS is deliberately untouched:
--      the plant is where it is, and the new head-office location belongs in
--      system_settings, not stamped over an operational site record.
--
-- WHAT THIS DELIBERATELY DOES NOT CHANGE
--   Everything 0006 protected stays protected — audit_logs, record_versions,
--   notifications, users.email, customer/dealer/sales/warranty data, the CLF
--   serial prefix, and the colifees_* database roles, functions and database
--   name (identifiers, not branding). See docs/11-brand-configuration.md.
--
--   failed_login_attempts also matches a case-insensitive search: it stores the
--   email that was typed at a failed login. That is a record of what somebody
--   actually entered. Rewriting it would falsify a security log.
--
--   brand_rename_backup matches by design — it holds the pre-0006 values.
--
-- REVERSIBILITY
--   Same contract as 0006: every overwritten value is copied into
--   brand_rename_backup (migration = '0007_brand_rename_residuals') first.
--   Down path: scripts/sql/rollback-0007-brand-rename-residuals.sql
--
-- SAFETY
--   No DROP. No DELETE. No TRUNCATE. No schema change. No row is created or
--   removed; only text inside three columns of six existing rows is rewritten.
-- =============================================================================

-- The rename touches RLS-protected tables. Without an explicit scope the
-- fail-closed policy from migration 0005 silently matches nothing.
SET LOCAL app.dealer_id = 'ALL';

-- -----------------------------------------------------------------------------
-- 1. seo_metadata.keywords — lower-case brand tokens inside a text[]
-- -----------------------------------------------------------------------------
INSERT INTO brand_rename_backup (migration, table_name, record_id, column_name, old_value)
SELECT '0007_brand_rename_residuals', 'seo_metadata', id::text, 'keywords', array_to_string(keywords, U&'\001F')
  FROM seo_metadata
 WHERE array_to_string(keywords, ',') ILIKE '%colifees%';

UPDATE seo_metadata
   SET keywords = ARRAY(
         SELECT regexp_replace(kw, 'colifees', 'polyfix', 'gi')
           FROM unnest(keywords) AS kw
       ),
       updated_at = now()
 WHERE array_to_string(keywords, ',') ILIKE '%colifees%';

-- -----------------------------------------------------------------------------
-- 2. website_pages.hero -> image.src — asset paths renamed on disk
-- -----------------------------------------------------------------------------
INSERT INTO brand_rename_backup (migration, table_name, record_id, column_name, old_value)
SELECT '0007_brand_rename_residuals', 'website_pages', id::text, 'hero', hero::text
  FROM website_pages
 WHERE hero #>> '{image,src}' ILIKE '%colifees%';

UPDATE website_pages
   SET hero = jsonb_set(
         hero,
         '{image,src}',
         to_jsonb(replace(hero #>> '{image,src}', 'colifees-', 'polyfix-')),
         false
       ),
       updated_at = now()
 WHERE hero #>> '{image,src}' ILIKE '%colifees%';

-- -----------------------------------------------------------------------------
-- 3. warehouses.name — the company's own plant carries the company's own name
-- -----------------------------------------------------------------------------
INSERT INTO brand_rename_backup (migration, table_name, record_id, column_name, old_value)
SELECT '0007_brand_rename_residuals', 'warehouses', id::text, 'name', name
  FROM warehouses
 WHERE name ILIKE '%colifees%';

UPDATE warehouses
   SET name = regexp_replace(name, 'COLIFEES', 'POLYFIX', 'g'),
       updated_at = now()
 WHERE name ILIKE '%colifees%';

-- -----------------------------------------------------------------------------
-- 4. Verify — fail the migration rather than leave a half-applied rename
-- -----------------------------------------------------------------------------
DO $$
DECLARE
  leftover  bigint;
  protected bigint;
BEGIN
  SELECT count(*) INTO leftover FROM seo_metadata
   WHERE array_to_string(keywords, ',') ILIKE '%colifees%';
  IF leftover > 0 THEN
    RAISE EXCEPTION '0007: % seo_metadata row(s) still carry the old brand in keywords', leftover;
  END IF;

  SELECT count(*) INTO leftover FROM website_pages
   WHERE hero::text ILIKE '%colifees%' OR sections::text ILIKE '%colifees%';
  IF leftover > 0 THEN
    RAISE EXCEPTION '0007: % website_pages row(s) still reference the old brand', leftover;
  END IF;

  SELECT count(*) INTO leftover FROM warehouses WHERE name ILIKE '%colifees%';
  IF leftover > 0 THEN
    RAISE EXCEPTION '0007: % warehouse name(s) still carry the old brand', leftover;
  END IF;

  -- Nothing historical may have been captured, which would mean a rule reached
  -- a table this migration promised not to touch.
  SELECT count(*) INTO protected FROM brand_rename_backup
   WHERE migration = '0007_brand_rename_residuals'
     AND table_name NOT IN ('seo_metadata', 'website_pages', 'warehouses');
  IF protected > 0 THEN
    RAISE EXCEPTION '0007: % row(s) captured from a table outside the declared scope', protected;
  END IF;
END $$;
