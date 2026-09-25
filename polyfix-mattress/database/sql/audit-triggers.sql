-- =============================================================================
-- POLYFIX MATTRESS — OPTIONAL owner-level protection for history tables
-- -----------------------------------------------------------------------------
-- The application account already cannot UPDATE, DELETE or TRUNCATE history
-- (see generate-privileges.sql). These triggers additionally stop the SCHEMA
-- OWNER, or anyone with broad rights, from quietly editing a history row.
--
-- Optional because MySQL refuses CREATE TRIGGER from a non-SUPER account while
-- binary logging is on (error 1419) — the default on managed and shared
-- hosting. Install when you can:
--   * run this as an account with SUPER / SET_USER_ID, or
--   * have the DBA set log_bin_trust_function_creators = 1, then run it.
--
--   mysql -u root -p polyfix_mattress < database/sql/audit-triggers.sql
--
-- Safe to re-run. Remove with the DROP TRIGGER lines at the bottom.
-- Note: anyone able to DROP TRIGGER can also remove this protection. It raises
-- the bar and makes tampering deliberate; the audit hash chain is what makes
-- tampering detectable (php spark polyfix:verify-audit).
-- =============================================================================

DELIMITER $$

DROP TRIGGER IF EXISTS audit_logs_no_update $$
CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only; insert a correcting entry instead' $$
DROP TRIGGER IF EXISTS audit_logs_no_delete $$
CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only' $$

DROP TRIGGER IF EXISTS record_versions_no_update $$
CREATE TRIGGER record_versions_no_update BEFORE UPDATE ON record_versions FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'record_versions is append-only' $$
DROP TRIGGER IF EXISTS record_versions_no_delete $$
CREATE TRIGGER record_versions_no_delete BEFORE DELETE ON record_versions FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'record_versions is append-only' $$

DROP TRIGGER IF EXISTS mattress_events_no_update $$
CREATE TRIGGER mattress_events_no_update BEFORE UPDATE ON mattress_events FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'mattress_events is append-only' $$
DROP TRIGGER IF EXISTS mattress_events_no_delete $$
CREATE TRIGGER mattress_events_no_delete BEFORE DELETE ON mattress_events FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'mattress_events is append-only' $$

DROP TRIGGER IF EXISTS claim_events_no_update $$
CREATE TRIGGER claim_events_no_update BEFORE UPDATE ON claim_events FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'claim_events is append-only' $$
DROP TRIGGER IF EXISTS claim_events_no_delete $$
CREATE TRIGGER claim_events_no_delete BEFORE DELETE ON claim_events FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'claim_events is append-only' $$

DELIMITER ;

-- To remove:
-- DROP TRIGGER IF EXISTS audit_logs_no_update;      DROP TRIGGER IF EXISTS audit_logs_no_delete;
-- DROP TRIGGER IF EXISTS record_versions_no_update; DROP TRIGGER IF EXISTS record_versions_no_delete;
-- DROP TRIGGER IF EXISTS mattress_events_no_update; DROP TRIGGER IF EXISTS mattress_events_no_delete;
-- DROP TRIGGER IF EXISTS claim_events_no_update;    DROP TRIGGER IF EXISTS claim_events_no_delete;
