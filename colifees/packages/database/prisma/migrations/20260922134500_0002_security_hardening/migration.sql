-- =============================================================================
-- 0002 — SECURITY HARDENING & DATA INTEGRITY
-- -----------------------------------------------------------------------------
-- Everything in this migration is deliberately *below* the application:
--
--   * identifier generation   so serials cannot collide or be chosen by a client
--   * CHECK constraints       so an invalid business state cannot be written at all
--   * append-only triggers    so audit history cannot be edited, even by the app
--   * a hash chain            so tampering with history out-of-band is detectable
--   * Row Level Security      so a missing WHERE clause leaks nothing
--
-- Plain SQL, no Prisma-specific features: it replays on any PostgreSQL 13+.
-- =============================================================================

CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- =============================================================================
-- 1. SERVER-SIDE IDENTIFIER GENERATION
-- -----------------------------------------------------------------------------
-- Serial numbers, claim numbers and dispatch codes are issued by the database.
-- A client cannot propose one, so it cannot pre-register a serial it does not
-- own or guess the next value of someone else's.
-- =============================================================================

CREATE SEQUENCE IF NOT EXISTS colifees_serial_seq   START 1 INCREMENT 1 MINVALUE 1 NO CYCLE;
CREATE SEQUENCE IF NOT EXISTS colifees_claim_seq    START 1 INCREMENT 1 MINVALUE 1 NO CYCLE;
CREATE SEQUENCE IF NOT EXISTS colifees_dispatch_seq START 1 INCREMENT 1 MINVALUE 1 NO CYCLE;
CREATE SEQUENCE IF NOT EXISTS colifees_batch_seq    START 1 INCREMENT 1 MINVALUE 1 NO CYCLE;
CREATE SEQUENCE IF NOT EXISTS colifees_dealer_seq   START 1 INCREMENT 1 MINVALUE 1 NO CYCLE;

-- CLF + 2-digit year + zero-padded counter, e.g. CLF26000001
CREATE OR REPLACE FUNCTION colifees_next_serial(p_year integer DEFAULT NULL)
RETURNS varchar
LANGUAGE plpgsql
AS $$
DECLARE
  v_year integer := COALESCE(p_year, EXTRACT(YEAR FROM CURRENT_DATE)::integer);
BEGIN
  RETURN 'CLF'
       || to_char(v_year % 100, 'FM00')
       || to_char(nextval('colifees_serial_seq'), 'FM000000');
END;
$$;

CREATE OR REPLACE FUNCTION colifees_next_claim_number(p_year integer DEFAULT NULL)
RETURNS varchar
LANGUAGE plpgsql
AS $$
DECLARE
  v_year integer := COALESCE(p_year, EXTRACT(YEAR FROM CURRENT_DATE)::integer);
BEGIN
  RETURN 'CLM'
       || to_char(v_year % 100, 'FM00')
       || to_char(nextval('colifees_claim_seq'), 'FM000000');
END;
$$;

CREATE OR REPLACE FUNCTION colifees_next_dispatch_code(p_year integer DEFAULT NULL)
RETURNS varchar
LANGUAGE plpgsql
AS $$
DECLARE
  v_year integer := COALESCE(p_year, EXTRACT(YEAR FROM CURRENT_DATE)::integer);
BEGIN
  RETURN 'DSP'
       || to_char(v_year % 100, 'FM00')
       || to_char(nextval('colifees_dispatch_seq'), 'FM000000');
END;
$$;

CREATE OR REPLACE FUNCTION colifees_next_batch_code(p_year integer DEFAULT NULL)
RETURNS varchar
LANGUAGE plpgsql
AS $$
DECLARE
  v_year integer := COALESCE(p_year, EXTRACT(YEAR FROM CURRENT_DATE)::integer);
BEGIN
  RETURN 'BAT'
       || to_char(v_year % 100, 'FM00')
       || to_char(nextval('colifees_batch_seq'), 'FM0000');
END;
$$;

CREATE OR REPLACE FUNCTION colifees_next_dealer_code()
RETURNS varchar
LANGUAGE plpgsql
AS $$
BEGIN
  RETURN 'DLR' || to_char(nextval('colifees_dealer_seq'), 'FM0000');
END;
$$;

-- =============================================================================
-- 2. FORMAT AND BUSINESS-STATE CONSTRAINTS
-- -----------------------------------------------------------------------------
-- These are the invariants that must hold no matter which code path writes the
-- row — the API, a migration, a maintenance script or a DBA at a psql prompt.
-- =============================================================================

-- --- identity formats --------------------------------------------------------
ALTER TABLE mattresses
  ADD CONSTRAINT mattresses_serial_format_chk
  CHECK (serial_number ~ '^CLF[0-9]{8,}$');

ALTER TABLE mattresses
  ADD CONSTRAINT mattresses_qr_token_format_chk
  CHECK (char_length(qr_token) >= 22);

ALTER TABLE warranty_claims
  ADD CONSTRAINT claims_number_format_chk
  CHECK (claim_number ~ '^CLM[0-9]{8,}$');

-- Emails are stored lowercase so uniqueness cannot be sidestepped by casing.
ALTER TABLE users
  ADD CONSTRAINT users_email_lowercase_chk
  CHECK (email = lower(email) AND email ~ '^[^@[:space:]]+@[^@[:space:]]+\.[a-z]{2,}$');

ALTER TABLE dealers
  ADD CONSTRAINT dealers_pincode_chk CHECK (pincode ~ '^[0-9]{6}$');
ALTER TABLE dealers
  ADD CONSTRAINT dealers_phone_chk CHECK (phone ~ '^[0-9+][0-9 -]{7,19}$');
ALTER TABLE dealers
  ADD CONSTRAINT dealers_gst_chk
  CHECK (gst_number IS NULL OR gst_number ~ '^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]{3}$');

-- --- money and measurements must be sane -------------------------------------
ALTER TABLE product_variants
  ADD CONSTRAINT product_variants_mrp_chk CHECK (mrp > 0);
ALTER TABLE product_variants
  ADD CONSTRAINT product_variants_dimensions_chk
  CHECK (width_in > 0 AND length_in > 0 AND height_in > 0);
ALTER TABLE products
  ADD CONSTRAINT products_warranty_years_chk CHECK (warranty_years BETWEEN 1 AND 30);
ALTER TABLE products
  ADD CONSTRAINT products_firmness_chk CHECK (firmness_score BETWEEN 1 AND 10);
ALTER TABLE sales
  ADD CONSTRAINT sales_price_chk CHECK (sale_price >= 0);
ALTER TABLE manufacturing_batches
  ADD CONSTRAINT batches_quantity_chk
  CHECK (planned_quantity > 0 AND produced_quantity >= 0);

-- --- lifecycle coherence -----------------------------------------------------
-- A mattress in a dealer's custody must name that dealer; a sold mattress must
-- record when it was sold. This is what makes "current_dealer_id" trustworthy
-- as the Row Level Security key.
ALTER TABLE mattresses
  ADD CONSTRAINT mattresses_custody_chk
  CHECK (
    current_status NOT IN ('DEALER_RECEIVED', 'SOLD', 'CLAIM_OPEN', 'REPLACED')
    OR current_dealer_id IS NOT NULL
  );

ALTER TABLE mattresses
  ADD CONSTRAINT mattresses_sold_at_chk
  CHECK (current_status NOT IN ('SOLD', 'CLAIM_OPEN', 'REPLACED') OR sold_at IS NOT NULL);

ALTER TABLE mattresses
  ADD CONSTRAINT mattresses_timeline_chk
  CHECK (
    (dispatched_at IS NULL OR dispatched_at >= manufactured_at)
    AND (received_at  IS NULL OR dispatched_at IS NULL OR received_at >= dispatched_at)
    AND (sold_at      IS NULL OR received_at  IS NULL OR sold_at      >= received_at)
  );

ALTER TABLE warranties
  ADD CONSTRAINT warranties_period_chk CHECK (end_date > start_date);
ALTER TABLE warranties
  ADD CONSTRAINT warranties_years_chk CHECK (years BETWEEN 1 AND 30);
ALTER TABLE warranties
  ADD CONSTRAINT warranties_void_reason_chk
  CHECK (status <> 'VOID' OR void_reason IS NOT NULL);

ALTER TABLE warranty_claims
  ADD CONSTRAINT claims_risk_score_chk CHECK (risk_score BETWEEN 0 AND 100);
-- A decision must always name a decision-maker and a moment.
ALTER TABLE warranty_claims
  ADD CONSTRAINT claims_decision_chk
  CHECK (
    status NOT IN ('APPROVED', 'REJECTED', 'REPLACED')
    OR (reviewed_by_user_id IS NOT NULL AND decision_at IS NOT NULL)
  );
ALTER TABLE warranty_claims
  ADD CONSTRAINT claims_rejection_reason_chk
  CHECK (status <> 'REJECTED' OR decision_reason IS NOT NULL);

ALTER TABLE claim_media
  ADD CONSTRAINT claim_media_size_chk CHECK (byte_size > 0 AND byte_size <= 26214400);
ALTER TABLE claim_media
  ADD CONSTRAINT claim_media_mime_chk
  CHECK (mime_type IN ('image/jpeg', 'image/png', 'image/webp', 'application/pdf'));

ALTER TABLE replacements
  ADD CONSTRAINT replacements_distinct_mattress_chk
  CHECK (original_mattress_id <> replacement_mattress_id);

-- --- deleting anything important requires a stated reason --------------------
ALTER TABLE mattresses       ADD CONSTRAINT mattresses_delete_reason_chk       CHECK (deleted_at IS NULL OR delete_reason IS NOT NULL);
ALTER TABLE sales            ADD CONSTRAINT sales_delete_reason_chk            CHECK (deleted_at IS NULL OR delete_reason IS NOT NULL);
ALTER TABLE warranties       ADD CONSTRAINT warranties_delete_reason_chk       CHECK (deleted_at IS NULL OR delete_reason IS NOT NULL);
ALTER TABLE warranty_claims  ADD CONSTRAINT claims_delete_reason_chk           CHECK (deleted_at IS NULL OR delete_reason IS NOT NULL);
ALTER TABLE dispatches       ADD CONSTRAINT dispatches_delete_reason_chk       CHECK (deleted_at IS NULL OR delete_reason IS NOT NULL);
ALTER TABLE dealers          ADD CONSTRAINT dealers_delete_reason_chk          CHECK (deleted_at IS NULL OR delete_reason IS NOT NULL);
ALTER TABLE customers        ADD CONSTRAINT customers_delete_reason_chk        CHECK (deleted_at IS NULL OR delete_reason IS NOT NULL);
ALTER TABLE products         ADD CONSTRAINT products_delete_reason_chk         CHECK (deleted_at IS NULL OR delete_reason IS NOT NULL);
ALTER TABLE users            ADD CONSTRAINT users_delete_reason_chk            CHECK (deleted_at IS NULL OR delete_reason IS NOT NULL);

-- =============================================================================
-- 3. updated_at MAINTENANCE
-- -----------------------------------------------------------------------------
-- Prisma sets updated_at for its own writes. The trigger guarantees it for
-- every other writer too, so "when did this last change" is always truthful.
-- =============================================================================

CREATE OR REPLACE FUNCTION colifees_set_updated_at()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
  NEW.updated_at := now();
  RETURN NEW;
END;
$$;

DO $$
DECLARE
  t text;
BEGIN
  FOR t IN
    SELECT c.relname
    FROM pg_class c
    JOIN pg_namespace n ON n.oid = c.relnamespace
    JOIN information_schema.columns col
      ON col.table_schema = n.nspname AND col.table_name = c.relname
    WHERE n.nspname = 'public' AND c.relkind = 'r' AND col.column_name = 'updated_at'
  LOOP
    EXECUTE format(
      'CREATE TRIGGER %I BEFORE UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION colifees_set_updated_at()',
      t || '_set_updated_at', t
    );
  END LOOP;
END;
$$;

-- =============================================================================
-- 4. APPEND-ONLY HISTORY
-- -----------------------------------------------------------------------------
-- audit_logs, record_versions, mattress_events and claim_events accept INSERTs
-- and nothing else. Two independent controls:
--   (a) the application role has no UPDATE/DELETE/TRUNCATE privilege, and
--   (b) a trigger refuses the operation regardless of who attempts it —
--       including the table owner and any future role with broader grants.
-- =============================================================================

CREATE OR REPLACE FUNCTION colifees_deny_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
  RAISE EXCEPTION
    'Table % is append-only; % is not permitted. Correct the record by inserting a new entry.',
    TG_TABLE_NAME, TG_OP
    USING ERRCODE = 'insufficient_privilege';
END;
$$;

DO $$
DECLARE
  t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['audit_logs', 'record_versions', 'mattress_events', 'claim_events']
  LOOP
    EXECUTE format(
      'CREATE TRIGGER %I BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION colifees_deny_mutation()',
      t || '_append_only', t
    );
    EXECUTE format(
      'CREATE TRIGGER %I BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION colifees_deny_mutation()',
      t || '_no_truncate', t
    );
  END LOOP;
END;
$$;

-- --- tamper-evident hash chain ----------------------------------------------
-- Each audit row seals the previous one. Editing or removing any historical row
-- out-of-band (for example by restoring a doctored dump) breaks verification
-- from that point onward. The values are computed here, not in the API, so a
-- compromised application cannot write a self-consistent forged chain.
CREATE OR REPLACE FUNCTION colifees_audit_seal()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
  v_prev char(64);
BEGIN
  -- Serialise chain appends so two concurrent inserts cannot claim the same link.
  PERFORM pg_advisory_xact_lock(hashtext('colifees_audit_chain'));

  SELECT row_hash INTO v_prev FROM audit_logs ORDER BY id DESC LIMIT 1;

  NEW.prev_hash := v_prev;
  NEW.row_hash := encode(
    digest(
      coalesce(v_prev, 'genesis')                                   || E'\x1f' ||
      to_char(NEW.occurred_at AT TIME ZONE 'UTC', 'YYYYMMDDHH24MISSUS') || E'\x1f' ||
      coalesce(NEW.user_id::text, '')                               || E'\x1f' ||
      coalesce(NEW.role_key, '')                                    || E'\x1f' ||
      coalesce(NEW.dealer_id::text, '')                             || E'\x1f' ||
      NEW.action                                                    || E'\x1f' ||
      NEW.entity                                                    || E'\x1f' ||
      coalesce(NEW.entity_id, '')                                   || E'\x1f' ||
      coalesce(NEW.previous_value::text, '')                        || E'\x1f' ||
      coalesce(NEW.new_value::text, '')                             || E'\x1f' ||
      coalesce(NEW.reason, ''),
      'sha256'
    ),
    'hex'
  );
  RETURN NEW;
END;
$$;

CREATE TRIGGER audit_logs_seal
  BEFORE INSERT ON audit_logs
  FOR EACH ROW EXECUTE FUNCTION colifees_audit_seal();

-- Re-walks the chain and returns every row whose seal no longer matches.
-- An empty result means the audit trail is internally consistent.
CREATE OR REPLACE FUNCTION colifees_verify_audit_chain(
  p_from_id bigint DEFAULT 0,
  p_limit   integer DEFAULT 100000
)
RETURNS TABLE (id bigint, occurred_at timestamptz, problem text)
LANGUAGE plpgsql
AS $$
DECLARE
  r            record;
  v_expected   char(64);
  v_running    char(64);
  v_first      boolean := true;
BEGIN
  FOR r IN
    SELECT * FROM audit_logs WHERE audit_logs.id > p_from_id ORDER BY audit_logs.id LIMIT p_limit
  LOOP
    IF v_first THEN
      v_running := r.prev_hash;
      v_first := false;
    ELSIF r.prev_hash IS DISTINCT FROM v_running THEN
      id := r.id; occurred_at := r.occurred_at;
      problem := 'prev_hash does not match the preceding row';
      RETURN NEXT;
    END IF;

    v_expected := encode(
      digest(
        coalesce(r.prev_hash, 'genesis')                              || E'\x1f' ||
        to_char(r.occurred_at AT TIME ZONE 'UTC', 'YYYYMMDDHH24MISSUS') || E'\x1f' ||
        coalesce(r.user_id::text, '')                                 || E'\x1f' ||
        coalesce(r.role_key, '')                                      || E'\x1f' ||
        coalesce(r.dealer_id::text, '')                               || E'\x1f' ||
        r.action                                                      || E'\x1f' ||
        r.entity                                                      || E'\x1f' ||
        coalesce(r.entity_id, '')                                     || E'\x1f' ||
        coalesce(r.previous_value::text, '')                          || E'\x1f' ||
        coalesce(r.new_value::text, '')                               || E'\x1f' ||
        coalesce(r.reason, ''),
        'sha256'
      ),
      'hex'
    );

    IF v_expected IS DISTINCT FROM r.row_hash THEN
      id := r.id; occurred_at := r.occurred_at;
      problem := 'row_hash does not match row contents';
      RETURN NEXT;
    END IF;

    v_running := r.row_hash;
  END LOOP;
END;
$$;

-- =============================================================================
-- 5. ROW LEVEL SECURITY — DEALER ISOLATION
-- -----------------------------------------------------------------------------
-- The API sets two transaction-local settings on every request:
--
--     app.dealer_id  -- '' for staff, or the dealer's UUID
--     app.user_id    -- the authenticated user
--
-- Policies below read app.dealer_id. It is deliberately fail-closed: when the
-- setting is absent, current_setting(...) returns NULL, the policy expression
-- evaluates to NULL, and PostgreSQL treats that as "no rows". Code that forgets
-- to open a scoped transaction therefore sees an empty table rather than
-- everyone's data.
--
-- FORCE ROW LEVEL SECURITY makes the policies apply to the table owner too, so
-- they cannot be sidestepped by connecting as colifees_owner.
--
-- Scope, stated honestly: this protects against a missing or wrong WHERE clause
-- in application code. It is not a defence against an attacker who already
-- holds the application's database credentials — that is what network
-- isolation, TLS and secret management are for. See docs/07-security-guide.md.
-- =============================================================================

CREATE OR REPLACE FUNCTION app_dealer_scope()
RETURNS text
LANGUAGE sql
STABLE
AS $$ SELECT current_setting('app.dealer_id', true) $$;

CREATE OR REPLACE FUNCTION app_is_staff()
RETURNS boolean
LANGUAGE sql
STABLE
AS $$ SELECT current_setting('app.dealer_id', true) = '' $$;

CREATE OR REPLACE FUNCTION app_current_user_id()
RETURNS uuid
LANGUAGE sql
STABLE
AS $$
  SELECT NULLIF(current_setting('app.user_id', true), '')::uuid
$$;

GRANT EXECUTE ON FUNCTION app_dealer_scope(), app_is_staff(), app_current_user_id() TO PUBLIC;

-- --- tables carrying a direct dealer_id --------------------------------------
DO $$
DECLARE
  spec record;
BEGIN
  FOR spec IN
    SELECT * FROM (VALUES
      ('mattresses',          'current_dealer_id'),
      ('dispatches',          'dealer_id'),
      ('dealer_receipts',     'dealer_id'),
      ('customers',           'dealer_id'),
      ('sales',               'dealer_id'),
      ('warranties',          'dealer_id'),
      ('warranty_claims',     'dealer_id'),
      ('claim_media',         'dealer_id'),
      ('replacements',        'dealer_id'),
      ('dealer_users',        'dealer_id')
    ) AS v(tbl, col)
  LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', spec.tbl);
    EXECUTE format('ALTER TABLE %I FORCE  ROW LEVEL SECURITY', spec.tbl);
    EXECUTE format($f$
      CREATE POLICY %I ON %I
        FOR ALL
        USING      (app_is_staff() OR %I::text = app_dealer_scope())
        WITH CHECK (app_is_staff() OR %I::text = app_dealer_scope())
    $f$, spec.tbl || '_dealer_isolation', spec.tbl, spec.col, spec.col);
  END LOOP;
END;
$$;

-- --- a dealer may only ever see its own dealer record -------------------------
ALTER TABLE dealers ENABLE ROW LEVEL SECURITY;
ALTER TABLE dealers FORCE  ROW LEVEL SECURITY;
CREATE POLICY dealers_dealer_isolation ON dealers
  FOR ALL
  USING      (app_is_staff() OR id::text = app_dealer_scope())
  WITH CHECK (app_is_staff() OR id::text = app_dealer_scope());

-- --- notifications: staff see all, a dealer sees only its own -----------------
ALTER TABLE notifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE notifications FORCE  ROW LEVEL SECURITY;
CREATE POLICY notifications_dealer_isolation ON notifications
  FOR ALL
  USING      (app_is_staff() OR dealer_id::text = app_dealer_scope())
  WITH CHECK (app_is_staff() OR dealer_id::text = app_dealer_scope());

-- --- child tables reached through their parent --------------------------------
ALTER TABLE dispatch_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE dispatch_items FORCE  ROW LEVEL SECURITY;
CREATE POLICY dispatch_items_dealer_isolation ON dispatch_items
  FOR ALL
  USING (
    app_is_staff() OR EXISTS (
      SELECT 1 FROM dispatches d
      WHERE d.id = dispatch_items.dispatch_id
        AND d.dealer_id::text = app_dealer_scope()
    )
  )
  WITH CHECK (
    app_is_staff() OR EXISTS (
      SELECT 1 FROM dispatches d
      WHERE d.id = dispatch_items.dispatch_id
        AND d.dealer_id::text = app_dealer_scope()
    )
  );

ALTER TABLE dealer_receipt_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE dealer_receipt_items FORCE  ROW LEVEL SECURITY;
CREATE POLICY dealer_receipt_items_dealer_isolation ON dealer_receipt_items
  FOR ALL
  USING (
    app_is_staff() OR EXISTS (
      SELECT 1 FROM dealer_receipts r
      WHERE r.id = dealer_receipt_items.receipt_id
        AND r.dealer_id::text = app_dealer_scope()
    )
  )
  WITH CHECK (
    app_is_staff() OR EXISTS (
      SELECT 1 FROM dealer_receipts r
      WHERE r.id = dealer_receipt_items.receipt_id
        AND r.dealer_id::text = app_dealer_scope()
    )
  );

-- Claim timeline: internal review notes are invisible to the dealer at the
-- database level, not merely filtered out in the API response.
ALTER TABLE claim_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE claim_events FORCE  ROW LEVEL SECURITY;
CREATE POLICY claim_events_dealer_isolation ON claim_events
  FOR ALL
  USING (
    app_is_staff() OR (
      is_internal = false AND EXISTS (
        SELECT 1 FROM warranty_claims c
        WHERE c.id = claim_events.claim_id
          AND c.dealer_id::text = app_dealer_scope()
      )
    )
  )
  WITH CHECK (
    app_is_staff() OR EXISTS (
      SELECT 1 FROM warranty_claims c
      WHERE c.id = claim_events.claim_id
        AND c.dealer_id::text = app_dealer_scope()
    )
  );

-- Mattress passport history: visible to staff, and to the dealer that holds or
-- previously handled the unit.
ALTER TABLE mattress_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE mattress_events FORCE  ROW LEVEL SECURITY;
CREATE POLICY mattress_events_dealer_isolation ON mattress_events
  FOR ALL
  USING (
    app_is_staff()
    OR dealer_id::text = app_dealer_scope()
    OR EXISTS (
      SELECT 1 FROM mattresses m
      WHERE m.id = mattress_events.mattress_id
        AND m.current_dealer_id::text = app_dealer_scope()
    )
  )
  WITH CHECK (
    app_is_staff()
    OR dealer_id::text = app_dealer_scope()
    OR EXISTS (
      SELECT 1 FROM mattresses m
      WHERE m.id = mattress_events.mattress_id
        AND m.current_dealer_id::text = app_dealer_scope()
    )
  );

-- =============================================================================
-- 6. PRIVILEGES THE APPLICATION ROLE MUST NOT HAVE
-- =============================================================================

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'colifees_app') THEN
    REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs      FROM colifees_app;
    REVOKE UPDATE, DELETE, TRUNCATE ON record_versions FROM colifees_app;
    REVOKE UPDATE, DELETE, TRUNCATE ON mattress_events FROM colifees_app;
    REVOKE UPDATE, DELETE, TRUNCATE ON claim_events    FROM colifees_app;
    -- Failed login telemetry is written and pruned by a maintenance job, never
    -- edited by request handlers.
    REVOKE UPDATE ON failed_login_attempts FROM colifees_app;
  END IF;
END;
$$;

-- =============================================================================
-- 7. SUPPORTING INDEXES
-- =============================================================================

-- Scan-by-serial and type-ahead search without exposing a sequential scan.
CREATE INDEX IF NOT EXISTS mattresses_serial_trgm_idx
  ON mattresses USING gin (serial_number gin_trgm_ops);
CREATE INDEX IF NOT EXISTS dealers_business_name_trgm_idx
  ON dealers USING gin (business_name gin_trgm_ops);
CREATE INDEX IF NOT EXISTS products_name_trgm_idx
  ON products USING gin (name gin_trgm_ops);

-- "Live" rows only: keeps dealer stock and open-claim queries small.
CREATE INDEX IF NOT EXISTS mattresses_live_stock_idx
  ON mattresses (current_dealer_id, current_status)
  WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS claims_open_idx
  ON warranty_claims (status, submitted_at DESC)
  WHERE deleted_at IS NULL AND status IN ('SUBMITTED', 'UNDER_REVIEW', 'INFO_REQUESTED');
CREATE INDEX IF NOT EXISTS warranties_expiring_idx
  ON warranties (end_date)
  WHERE deleted_at IS NULL AND status = 'ACTIVE';
CREATE INDEX IF NOT EXISTS sessions_active_idx
  ON sessions (user_id, absolute_expiry)
  WHERE revoked_at IS NULL;
