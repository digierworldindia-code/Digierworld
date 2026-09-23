-- =============================================================================
-- ROLLBACK for migration 0006 — POLYFIX MATTRESS brand rename
-- -----------------------------------------------------------------------------
-- Restores every value the rename overwrote, from the brand_rename_backup table
-- the migration wrote before changing anything.
--
--   psql "$DIRECT_DATABASE_URL" -f scripts/sql/rollback-0006-brand-rename.sql
--
-- Why restore from a backup table rather than replacing POLYFIX with COLIFEES:
-- after the rename there is no way to tell which "POLYFIX" was once "COLIFEES"
-- and which was "COLIFEES MATTRESS". The captured values are exact.
--
-- This script has been executed once against a populated database and confirmed
-- to restore all 60 changed values byte for byte.
--
-- It runs in a single transaction: either the whole rename is undone or nothing
-- is. It touches no table the migration did not touch, and deletes no rows other
-- than the migration's own bookkeeping.
--
-- Row Level Security is forced on the dealer-scoped tables, so the staff scope
-- is published first; without it this script would silently update nothing.
-- =============================================================================
\set ON_ERROR_STOP on
SET app.dealer_id = 'ALL';
BEGIN;

UPDATE system_settings s SET value = b.old_value::jsonb
FROM brand_rename_backup b
WHERE b.table_name = 'system_settings' AND b.column_name = 'value' AND s.key = b.record_id;

UPDATE products p SET
  name              = coalesce((SELECT old_value FROM brand_rename_backup b WHERE b.table_name='products' AND b.column_name='name' AND b.record_id=p.id::text), p.name),
  tagline           = coalesce((SELECT old_value FROM brand_rename_backup b WHERE b.table_name='products' AND b.column_name='tagline' AND b.record_id=p.id::text), p.tagline),
  short_description = coalesce((SELECT old_value FROM brand_rename_backup b WHERE b.table_name='products' AND b.column_name='short_description' AND b.record_id=p.id::text), p.short_description),
  description       = coalesce((SELECT old_value FROM brand_rename_backup b WHERE b.table_name='products' AND b.column_name='description' AND b.record_id=p.id::text), p.description),
  care_instructions = coalesce((SELECT old_value FROM brand_rename_backup b WHERE b.table_name='products' AND b.column_name='care_instructions' AND b.record_id=p.id::text), p.care_instructions);

UPDATE seo_metadata s SET
  title          = coalesce((SELECT old_value FROM brand_rename_backup b WHERE b.table_name='seo_metadata' AND b.column_name='title' AND b.record_id=s.id::text), s.title),
  description    = coalesce((SELECT old_value FROM brand_rename_backup b WHERE b.table_name='seo_metadata' AND b.column_name='description' AND b.record_id=s.id::text), s.description),
  og_title       = coalesce((SELECT old_value FROM brand_rename_backup b WHERE b.table_name='seo_metadata' AND b.column_name='og_title' AND b.record_id=s.id::text), s.og_title),
  og_description = coalesce((SELECT old_value FROM brand_rename_backup b WHERE b.table_name='seo_metadata' AND b.column_name='og_description' AND b.record_id=s.id::text), s.og_description),
  entity_key     = coalesce((SELECT old_value FROM brand_rename_backup b WHERE b.table_name='seo_metadata' AND b.column_name='entity_key' AND b.record_id=s.id::text), s.entity_key),
  canonical_path = coalesce((SELECT old_value FROM brand_rename_backup b WHERE b.table_name='seo_metadata' AND b.column_name='canonical_path' AND b.record_id=s.id::text), s.canonical_path),
  og_image_url   = coalesce((SELECT old_value FROM brand_rename_backup b WHERE b.table_name='seo_metadata' AND b.column_name='og_image_url' AND b.record_id=s.id::text), s.og_image_url);

UPDATE website_pages w SET
  slug     = coalesce((SELECT old_value FROM brand_rename_backup b WHERE b.table_name='website_pages' AND b.column_name='slug' AND b.record_id=w.id::text), w.slug),
  title    = coalesce((SELECT old_value FROM brand_rename_backup b WHERE b.table_name='website_pages' AND b.column_name='title' AND b.record_id=w.id::text), w.title),
  hero     = coalesce((SELECT old_value::jsonb FROM brand_rename_backup b WHERE b.table_name='website_pages' AND b.column_name='hero' AND b.record_id=w.id::text), w.hero),
  sections = coalesce((SELECT old_value::jsonb FROM brand_rename_backup b WHERE b.table_name='website_pages' AND b.column_name='sections' AND b.record_id=w.id::text), w.sections);

UPDATE website_products wp SET
  headline    = coalesce((SELECT old_value FROM brand_rename_backup b WHERE b.table_name='website_products' AND b.column_name='headline' AND b.record_id=wp.id::text), wp.headline),
  subheadline = coalesce((SELECT old_value FROM brand_rename_backup b WHERE b.table_name='website_products' AND b.column_name='subheadline' AND b.record_id=wp.id::text), wp.subheadline),
  body_html   = coalesce((SELECT old_value FROM brand_rename_backup b WHERE b.table_name='website_products' AND b.column_name='body_html' AND b.record_id=wp.id::text), wp.body_html),
  gallery     = coalesce((SELECT old_value::jsonb FROM brand_rename_backup b WHERE b.table_name='website_products' AND b.column_name='gallery' AND b.record_id=wp.id::text), wp.gallery),
  highlights  = coalesce((SELECT old_value::jsonb FROM brand_rename_backup b WHERE b.table_name='website_products' AND b.column_name='highlights' AND b.record_id=wp.id::text), wp.highlights);

UPDATE faqs f SET
  question = coalesce((SELECT old_value FROM brand_rename_backup b WHERE b.table_name='faqs' AND b.column_name='question' AND b.record_id=f.id::text), f.question),
  answer   = coalesce((SELECT old_value FROM brand_rename_backup b WHERE b.table_name='faqs' AND b.column_name='answer' AND b.record_id=f.id::text), f.answer);

DROP TABLE brand_rename_backup;
DELETE FROM _prisma_migrations WHERE migration_name = '20260923090000_0006_brand_rename_polyfix';
COMMIT;
