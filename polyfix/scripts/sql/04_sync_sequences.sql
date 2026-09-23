-- =============================================================================
-- COLIFEES — identifier sequence synchronisation
-- -----------------------------------------------------------------------------
-- Run as the SCHEMA OWNER after any operation that inserts rows carrying
-- explicit identifiers:
--
--   psql "$DIRECT_DATABASE_URL" -f scripts/sql/04_sync_sequences.sql
--
-- When to run it:
--   * after restoring a data-only dump (sequence state is not carried)
--   * after importing legacy data with existing serial or dealer codes
--   * after any manual INSERT that supplied a code by hand
--
-- Why it matters: serials, claim numbers and dealer codes are issued from
-- PostgreSQL sequences. If rows exist above the sequence's current value, the
-- next generated identifier collides with one already in use and the insert
-- fails — or worse, a unique index is the only thing standing between two
-- mattresses and the same identity.
--
-- The application database role deliberately CANNOT run this: it holds USAGE
-- and SELECT on sequences but not UPDATE, so no request handler can rewind an
-- identifier sequence.
-- =============================================================================

\set ON_ERROR_STOP on

-- -----------------------------------------------------------------------------
-- Row Level Security applies to the schema owner too (FORCE ROW LEVEL SECURITY
-- in migration 0002), and the dealer-isolation policies fail CLOSED: with no
-- app.dealer_id set, a query over mattresses or dealers returns zero rows.
--
-- Without the line below this script would read an empty table, conclude the
-- highest serial is zero, and rewind every sequence to 1 — which would then
-- hand out identifiers that already exist.
--
-- The token 'ALL' means "staff scope: no dealer restriction". The same applies
-- to pg_dump (see scripts/backup.sh, which sets PGOPTIONS for this reason) and
-- to any ad-hoc psql session that needs to see all rows.
-- -----------------------------------------------------------------------------
SET app.dealer_id = 'ALL';

DO $$
DECLARE
  spec record;
  max_value bigint;
BEGIN
  FOR spec IN
    SELECT * FROM (VALUES
      -- sequence,                table,                    column,          digits after the prefix that are NOT the counter
      ('colifees_dealer_seq',   'dealers',                'code',          0),
      ('colifees_serial_seq',   'mattresses',             'serial_number', 2),
      ('colifees_claim_seq',    'warranty_claims',        'claim_number',  2),
      ('colifees_dispatch_seq', 'dispatches',             'dispatch_code', 2),
      ('colifees_batch_seq',    'manufacturing_batches',  'batch_code',    2)
    ) AS v(sequence_name, table_name, column_name, year_digits)
  LOOP
    -- Strip the 3-character prefix and the 2-digit year, then take the counter.
    EXECUTE format(
      'SELECT COALESCE(MAX(NULLIF(regexp_replace(substr(%I, %s), ''\D'', '''', ''g''), '''')::bigint), 0) FROM %I',
      spec.column_name, 4 + spec.year_digits, spec.table_name
    ) INTO max_value;

    PERFORM setval(spec.sequence_name, GREATEST(max_value, 1), true);

    RAISE NOTICE '% synced to %', spec.sequence_name, GREATEST(max_value, 1);
  END LOOP;
END;
$$;

\echo 'Current sequence positions:'
SELECT sequencename, last_value
FROM pg_sequences
WHERE schemaname = 'public' AND sequencename LIKE 'colifees_%'
ORDER BY sequencename;
