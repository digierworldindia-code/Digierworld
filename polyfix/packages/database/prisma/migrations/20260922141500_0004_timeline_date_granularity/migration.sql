-- =============================================================================
-- 0004 — LIFECYCLE TIMELINE AT DATE GRANULARITY
-- -----------------------------------------------------------------------------
-- Migration 0002 compared the lifecycle timestamps directly:
--
--     sold_at >= received_at
--
-- That is wrong in ordinary trading. A dealer records a sale with the date from
-- the invoice, which arrives as midnight, while `received_at` is the actual
-- moment the consignment was confirmed — often the same morning. Stock received
-- at 09:40 and sold at 16:20 the same day produced sold_at (00:00) earlier than
-- received_at (09:40), and the sale was refused.
--
-- The invariant that actually matters is ordering by DAY, not by clock time:
-- a mattress must not be sold before the day it arrived, dispatched before the
-- day it was built, or received before the day it left the warehouse. Comparing
-- at date granularity keeps that guarantee and stops rejecting real business.
-- =============================================================================

ALTER TABLE mattresses DROP CONSTRAINT IF EXISTS mattresses_timeline_chk;

ALTER TABLE mattresses
  ADD CONSTRAINT mattresses_timeline_chk
  CHECK (
    (dispatched_at IS NULL OR dispatched_at::date >= manufactured_at::date)
    AND (received_at IS NULL OR dispatched_at IS NULL OR received_at::date >= dispatched_at::date)
    AND (sold_at     IS NULL OR received_at   IS NULL OR sold_at::date     >= received_at::date)
  );
