-- =============================================================================
-- 0005 — FAIL-CLOSED SCOPE SENTINEL
-- -----------------------------------------------------------------------------
-- Migration 0002 used the empty string to mean "staff: no dealer restriction",
-- and relied on an absent setting evaluating to NULL so that an unscoped query
-- would see nothing.
--
-- That reasoning was wrong in one important case. PostgreSQL treats a custom
-- setting as created for the whole session the first time any SET touches it.
-- `current_setting('app.dealer_id', true)` returns NULL only until the first
-- assignment; after a transaction-local SET commits, the value reverts to the
-- session default, which is the EMPTY STRING — precisely the value that meant
-- "staff, show everything".
--
-- On a pooled connection the consequence is that the second and later queries
-- that forget to publish a scope inherit staff visibility instead of being
-- refused. Demonstrated directly:
--
--     BEGIN; SELECT set_config('app.dealer_id','',true); COMMIT;
--     SELECT current_setting('app.dealer_id', true);   -- '' , not NULL
--
-- The fix is to stop giving the reverted value any meaning. Staff scope is now
-- the explicit token 'ALL'. Anything else — absent, empty, or a dealer UUID
-- that does not match the row — denies. Forgetting to set a scope now means an
-- empty result set again, which is what the design always claimed.
-- =============================================================================

CREATE OR REPLACE FUNCTION app_is_staff()
RETURNS boolean
LANGUAGE sql
STABLE
AS $$
  -- Only this exact token grants unrestricted visibility. NULL (never set) and
  -- '' (reverted after a transaction-local SET) both evaluate to false.
  SELECT coalesce(current_setting('app.dealer_id', true), '') = 'ALL'
$$;

CREATE OR REPLACE FUNCTION app_dealer_scope()
RETURNS text
LANGUAGE sql
STABLE
AS $$
  -- Returns NULL for the staff token so that `dealer_id::text = app_dealer_scope()`
  -- can never accidentally match a row when scope handling is misconfigured.
  SELECT NULLIF(coalesce(current_setting('app.dealer_id', true), ''), 'ALL')
$$;

GRANT EXECUTE ON FUNCTION app_dealer_scope(), app_is_staff() TO PUBLIC;
