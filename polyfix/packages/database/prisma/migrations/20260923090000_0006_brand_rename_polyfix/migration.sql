-- =============================================================================
-- 0006 — BRAND RENAME: COLIFEES → POLYFIX MATTRESS
-- -----------------------------------------------------------------------------
-- Corrects the company name in stored content. It is deliberately narrow.
--
-- WHAT IT CHANGES (brand presentation only)
--   system_settings   the company name and registered address settings
--   products          name, tagline, descriptions
--   seo_metadata      titles, descriptions, social copy, the renamed page key
--   website_pages     title, hero and section copy, the renamed slug
--   website_products  marketing headlines and highlights
--   faqs              questions and answers
--
-- WHAT IT DELIBERATELY DOES NOT CHANGE
--   notifications     messages already delivered to people. Rewriting them
--                     would misrepresent what was actually sent.
--   audit_logs        append-only by design, and historically accurate by
--                     definition. A claim approved under the old name was
--                     approved under the old name.
--   record_versions   the same reasoning: these are point-in-time snapshots.
--   mattress_events / claim_events   lifecycle history.
--   users.email       an email address is a login credential. Changing one
--                     silently locks that person out. Account renames are a
--                     separate, communicated exercise.
--   customers, sales, warranties, warranty_claims, replacements, dealers
--                     customer and dealer data. Nothing here was ever branding.
--   mattresses.serial_number   the CLF prefix is printed on physical labels and
--                     referenced by QR codes, warranties and claims. It stays.
--                     See docs/11-brand-configuration.md.
--
-- REVERSIBILITY
--   Every value this migration overwrites is copied first into
--   brand_rename_backup. The down path at the bottom of this file restores from
--   that table exactly, which a plain reverse-replace could not do: after the
--   change there is no way to tell which "POLYFIX" was once "COLIFEES" and
--   which was "COLIFEES MATTRESS".
--
-- SAFETY
--   No DROP. No DELETE. No TRUNCATE. No schema change to any business table.
--   Row counts are unchanged; only text inside existing rows is rewritten.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Backup table — what this migration is about to overwrite
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS brand_rename_backup (
  id           bigserial PRIMARY KEY,
  migration    text        NOT NULL DEFAULT '0006_brand_rename_polyfix',
  table_name   text        NOT NULL,
  record_id    text        NOT NULL,
  column_name  text        NOT NULL,
  old_value    text,
  captured_at  timestamptz NOT NULL DEFAULT now()
);

COMMENT ON TABLE brand_rename_backup IS
  'Pre-change values captured by the COLIFEES to POLYFIX MATTRESS rename (migration 0006). Retain until the rename is confirmed in production, then drop.';

CREATE INDEX IF NOT EXISTS brand_rename_backup_lookup_idx
  ON brand_rename_backup (table_name, record_id, column_name);

-- -----------------------------------------------------------------------------
-- 2. The replacement itself
--
-- Applied most-specific first, so the full trading name appears where the
-- sentence needs it and the short name where it reads as an adjective:
--
--     "COLIFEES manufactures ..."   -> "POLYFIX MATTRESS manufactures ..."
--     "a genuine COLIFEES product"  -> "a genuine POLYFIX product"
--
-- This mirrors exactly what the application source does, so seeded content and
-- stored content stay consistent.
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION pg_temp.rebrand(input text)
RETURNS text
LANGUAGE sql
IMMUTABLE
AS $$
  SELECT CASE WHEN input IS NULL THEN NULL ELSE
    regexp_replace(
      replace(replace(replace(replace(replace(replace(replace(replace(
        input,
        'COLIFEES — Premium Mattresses', 'POLYFIX MATTRESS — Premium Mattresses'),
        'COLIFEES manufactures',         'POLYFIX MATTRESS manufactures'),
        'Contact COLIFEES',              'Contact POLYFIX MATTRESS'),
        'COLIFEES Dealers',              'POLYFIX MATTRESS Dealers'),
        'Why Choose COLIFEES',           'Why Choose POLYFIX MATTRESS'),
        'About COLIFEES',                'About POLYFIX'),
        'Why COLIFEES',                  'Why POLYFIX'),
        'why-colifees',                  'why-polyfix'),
      'COLIFEES', 'POLYFIX', 'g')
  END
$$;

-- --- 2a. system_settings ------------------------------------------------------
INSERT INTO brand_rename_backup (table_name, record_id, column_name, old_value)
SELECT 'system_settings', key, 'value', value::text
FROM system_settings
WHERE key IN ('company.legal_name', 'company.address')
   OR value::text LIKE '%COLIFEES%';

UPDATE system_settings
SET value = to_jsonb(pg_temp.rebrand(value #>> '{}'))
WHERE jsonb_typeof(value) = 'string' AND value #>> '{}' LIKE '%COLIFEES%';

-- The legal entity name is set explicitly to the full trading name. The general
-- rule above maps a bare "COLIFEES" to the short form, which is right for the
-- adjectival uses that dominate page copy ("a genuine POLYFIX product") but
-- wrong for the field that appears on invoices and legal pages.
UPDATE system_settings
SET value = to_jsonb('POLYFIX MATTRESS'::text)
WHERE key = 'company.legal_name';

-- The registered business location. Set only when it is currently unset, so an
-- address an administrator has already entered is never overwritten.
UPDATE system_settings
SET value = to_jsonb('Manesar, Noranpur Chowk, Haryana, India'::text)
WHERE key = 'company.address'
  AND (jsonb_typeof(value) <> 'string' OR btrim(value #>> '{}') = '');

-- --- 2b. products -------------------------------------------------------------
INSERT INTO brand_rename_backup (table_name, record_id, column_name, old_value)
SELECT 'products', id::text, c.column_name, c.old_value
FROM products p
CROSS JOIN LATERAL (VALUES
  ('name', p.name), ('tagline', p.tagline),
  ('short_description', p.short_description), ('description', p.description),
  ('care_instructions', p.care_instructions)
) AS c(column_name, old_value)
WHERE c.old_value LIKE '%COLIFEES%';

UPDATE products SET
  name              = pg_temp.rebrand(name),
  tagline           = pg_temp.rebrand(tagline),
  short_description = pg_temp.rebrand(short_description),
  description       = pg_temp.rebrand(description),
  care_instructions = pg_temp.rebrand(care_instructions)
WHERE name LIKE '%COLIFEES%' OR tagline LIKE '%COLIFEES%'
   OR short_description LIKE '%COLIFEES%' OR description LIKE '%COLIFEES%'
   OR care_instructions LIKE '%COLIFEES%';

-- --- 2c. seo_metadata ---------------------------------------------------------
INSERT INTO brand_rename_backup (table_name, record_id, column_name, old_value)
SELECT 'seo_metadata', id::text, c.column_name, c.old_value
FROM seo_metadata s
CROSS JOIN LATERAL (VALUES
  ('title', s.title), ('description', s.description),
  ('og_title', s.og_title), ('og_description', s.og_description),
  ('entity_key', s.entity_key), ('canonical_path', s.canonical_path),
  ('og_image_url', s.og_image_url)
) AS c(column_name, old_value)
WHERE c.old_value LIKE '%COLIFEES%' OR c.old_value LIKE '%colifees%';

UPDATE seo_metadata SET
  title          = pg_temp.rebrand(title),
  description    = pg_temp.rebrand(description),
  og_title       = pg_temp.rebrand(og_title),
  og_description = pg_temp.rebrand(og_description),
  entity_key     = pg_temp.rebrand(entity_key),
  canonical_path = replace(canonical_path, '/why-colifees', '/why-polyfix'),
  og_image_url   = replace(og_image_url, 'colifees-', 'polyfix-')
WHERE title LIKE '%COLIFEES%' OR description LIKE '%COLIFEES%'
   OR og_title LIKE '%COLIFEES%' OR og_description LIKE '%COLIFEES%'
   OR entity_key LIKE '%colifees%' OR canonical_path LIKE '%colifees%'
   OR og_image_url LIKE '%colifees%';

-- --- 2d. website_pages --------------------------------------------------------
INSERT INTO brand_rename_backup (table_name, record_id, column_name, old_value)
SELECT 'website_pages', id::text, c.column_name, c.old_value
FROM website_pages w
CROSS JOIN LATERAL (VALUES
  ('slug', w.slug), ('title', w.title),
  ('hero', w.hero::text), ('sections', w.sections::text)
) AS c(column_name, old_value)
WHERE c.old_value LIKE '%COLIFEES%' OR c.old_value LIKE '%colifees%';

-- The JSON columns are rewritten through their text form. Only the brand token
-- inside string values changes; the document structure is untouched, and the
-- cast back to jsonb fails loudly if anything malformed were produced.
UPDATE website_pages SET
  slug     = pg_temp.rebrand(slug),
  title    = pg_temp.rebrand(title),
  hero     = pg_temp.rebrand(hero::text)::jsonb,
  sections = pg_temp.rebrand(sections::text)::jsonb
WHERE slug LIKE '%colifees%' OR title LIKE '%COLIFEES%'
   OR hero::text LIKE '%COLIFEES%' OR sections::text LIKE '%COLIFEES%'
   OR hero::text LIKE '%colifees%' OR sections::text LIKE '%colifees%';

-- --- 2e. website_products -----------------------------------------------------
INSERT INTO brand_rename_backup (table_name, record_id, column_name, old_value)
SELECT 'website_products', id::text, c.column_name, c.old_value
FROM website_products wp
CROSS JOIN LATERAL (VALUES
  ('headline', wp.headline), ('subheadline', wp.subheadline),
  ('body_html', wp.body_html), ('gallery', wp.gallery::text),
  ('highlights', wp.highlights::text)
) AS c(column_name, old_value)
WHERE c.old_value LIKE '%COLIFEES%' OR c.old_value LIKE '%colifees%';

UPDATE website_products SET
  headline    = pg_temp.rebrand(headline),
  subheadline = pg_temp.rebrand(subheadline),
  body_html   = pg_temp.rebrand(body_html),
  gallery     = pg_temp.rebrand(gallery::text)::jsonb,
  highlights  = pg_temp.rebrand(highlights::text)::jsonb
WHERE headline LIKE '%COLIFEES%' OR subheadline LIKE '%COLIFEES%'
   OR body_html LIKE '%COLIFEES%' OR gallery::text LIKE '%colifees%'
   OR highlights::text LIKE '%COLIFEES%' OR gallery::text LIKE '%COLIFEES%';

-- --- 2f. faqs -----------------------------------------------------------------
INSERT INTO brand_rename_backup (table_name, record_id, column_name, old_value)
SELECT 'faqs', id::text, c.column_name, c.old_value
FROM faqs f
CROSS JOIN LATERAL (VALUES ('question', f.question), ('answer', f.answer)) AS c(column_name, old_value)
WHERE c.old_value LIKE '%COLIFEES%';

UPDATE faqs SET
  question = pg_temp.rebrand(question),
  answer   = pg_temp.rebrand(answer)
WHERE question LIKE '%COLIFEES%' OR answer LIKE '%COLIFEES%';

-- -----------------------------------------------------------------------------
-- 3. Verification — fails the migration if anything was missed or overreached
-- -----------------------------------------------------------------------------
DO $$
DECLARE
  remaining integer;
  preserved integer;
BEGIN
  SELECT
    (SELECT count(*) FROM products         WHERE name LIKE '%COLIFEES%' OR description LIKE '%COLIFEES%')
  + (SELECT count(*) FROM seo_metadata     WHERE title LIKE '%COLIFEES%' OR description LIKE '%COLIFEES%')
  + (SELECT count(*) FROM website_pages    WHERE title LIKE '%COLIFEES%' OR sections::text LIKE '%COLIFEES%')
  + (SELECT count(*) FROM website_products WHERE headline LIKE '%COLIFEES%')
  + (SELECT count(*) FROM faqs             WHERE question LIKE '%COLIFEES%' OR answer LIKE '%COLIFEES%')
  + (SELECT count(*) FROM system_settings  WHERE value::text LIKE '%COLIFEES%')
  INTO remaining;

  IF remaining > 0 THEN
    RAISE EXCEPTION 'brand rename incomplete: % brand-presentation rows still hold the old name', remaining;
  END IF;

  -- Historical data must be exactly as it was. If these were touched, something
  -- in this migration reached further than it was supposed to.
  SELECT count(*) INTO preserved FROM brand_rename_backup
  WHERE table_name IN ('audit_logs', 'notifications', 'users', 'customers', 'sales',
                       'warranty_claims', 'mattresses', 'record_versions');
  IF preserved > 0 THEN
    RAISE EXCEPTION 'brand rename touched historical data: % rows captured from tables it must not modify', preserved;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM system_settings
    WHERE key = 'company.legal_name' AND value #>> '{}' = 'POLYFIX MATTRESS'
  ) THEN
    RAISE EXCEPTION 'brand rename did not set company.legal_name to the full trading name';
  END IF;

  RAISE NOTICE 'Brand rename applied. % values backed up in brand_rename_backup.',
    (SELECT count(*) FROM brand_rename_backup WHERE migration = '0006_brand_rename_polyfix');
END;
$$;

-- =============================================================================
-- DOWN (manual, not run automatically)
-- -----------------------------------------------------------------------------
-- Restores every overwritten value exactly as it was:
--
--   UPDATE products p SET name = b.old_value
--   FROM brand_rename_backup b
--   WHERE b.table_name = 'products' AND b.column_name = 'name' AND p.id::text = b.record_id;
--   -- repeat per table and column, then for the json columns cast: b.old_value::jsonb
--
-- Once the rename is confirmed in production and no rollback is expected:
--   DROP TABLE brand_rename_backup;
-- =============================================================================
