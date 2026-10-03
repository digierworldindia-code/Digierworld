-- =============================================================================
--  QMS - reference data (roles, permissions, permission matrix, report types,
--  shifts, units, inspection methods, gauge types, default settings)
-- =============================================================================
--  Load after qms_schema.sql. Idempotent for a fresh database only.
--  In the application these rows are written by migrations/seeders; this file
--  is the reviewed source they are generated from.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -----------------------------------------------------------------------------
-- Roles
-- -----------------------------------------------------------------------------
INSERT INTO roles (code, name, description, is_system, is_super_admin) VALUES
 ('SUPER_ADMIN',         'Super Admin',          'Complete access. Still bound by workflow and segregation-of-duties rules.', 1, 1),
 ('QA_ADMIN',            'QA Admin',             'Manages specifications, templates, gauges, approvals and reports.',        1, 0),
 ('QUALITY_ENGINEER',    'Quality Engineer',     'Inspects, reviews and verifies quality reports.',                           1, 0),
 ('PRODUCTION_ENGINEER', 'Production Engineer',  'Reviews and verifies production inspection reports.',                       1, 0),
 ('OPERATOR',            'Operator',             'Creates and submits inspection reports only.',                              1, 0),
 ('VIEWER',              'Viewer / Management',  'Read-only dashboards and reports.',                                         1, 0);

-- -----------------------------------------------------------------------------
-- Permissions (checked in code; never created from the UI)
-- -----------------------------------------------------------------------------
INSERT INTO permissions (code, module, description) VALUES
 ('dashboard.view',               'dashboard',  'View management dashboard and KPI cards'),
 ('inspection.create',            'inspection', 'Start inspections and edit own drafts / returned reports'),
 ('inspection.submit',            'inspection', 'Submit inspection reports (operator signature)'),
 ('inspection.view_own',          'inspection', 'View own inspection reports'),
 ('inspection.view_all',          'inspection', 'View all inspection reports'),
 ('inspection.cancel_own',        'inspection', 'Cancel own unsubmitted drafts'),
 ('inspection.cancel_any',        'inspection', 'Cancel any unsubmitted draft'),
 ('inspection.round_verify',      'inspection', 'Verify in-process inspection rounds (Inspected By Quality Engineer)'),
 ('inspection.round_reopen',      'inspection', 'Reopen a signed in-process round before submission'),
 ('inspection.verify_production', 'inspection', 'Production verification stage'),
 ('inspection.verify_quality',    'inspection', 'Quality verification stage'),
 ('inspection.approve_qa',        'inspection', 'Final QA approval stage'),
 ('inspection.revise',            'inspection', 'Create a correction revision of an approved report'),
 ('inspection.print',             'inspection', 'Print and download inspection reports'),
 ('template.view',                'template',   'View inspection templates'),
 ('template.manage',              'template',   'Create and edit draft templates, sections, parameters and mappings'),
 ('template.publish',             'template',   'Publish and retire template versions'),
 ('master.view',                  'master',     'View master data'),
 ('master.machines',              'master',     'Manage machines'),
 ('master.parts',                 'master',     'Manage parts'),
 ('master.employees',             'master',     'Manage employees and departments'),
 ('master.shifts',                'master',     'Manage shifts'),
 ('master.library',               'master',     'Manage parameter library, units, inspection methods and gauge types'),
 ('gauge.view',                   'gauge',      'View gauges and calibration history'),
 ('gauge.manage',                 'gauge',      'Create and edit gauges, change gauge status'),
 ('gauge.calibrate',              'gauge',      'Record calibrations and upload certificates'),
 ('report.view',                  'report',     'View management reports'),
 ('report.export',                'report',     'Export reports (CSV / PDF)'),
 ('user.manage',                  'admin',      'Create users, reset passwords, unlock and disable accounts'),
 ('role.manage',                  'admin',      'Edit roles and the permission matrix'),
 ('setting.manage',               'admin',      'Edit system settings'),
 ('audit.view',                   'admin',      'View the audit trail'),
 ('loginlog.view',                'admin',      'View login history'),
 ('sync.view',                    'admin',      'View Google Sheets synchronisation status'),
 ('sync.manage',                  'admin',      'Retry or re-queue Google Sheets synchronisation jobs');

-- -----------------------------------------------------------------------------
-- Default permission matrix (editable later by SUPER_ADMIN, except SUPER_ADMIN itself)
-- -----------------------------------------------------------------------------
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.code = 'SUPER_ADMIN';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN (
  'dashboard.view',
  'inspection.create','inspection.submit','inspection.view_own','inspection.view_all',
  'inspection.cancel_own','inspection.cancel_any','inspection.round_verify','inspection.round_reopen',
  'inspection.verify_quality','inspection.approve_qa','inspection.revise','inspection.print',
  'template.view','template.manage','template.publish',
  'master.view','master.machines','master.parts','master.library',
  'gauge.view','gauge.manage','gauge.calibrate',
  'report.view','report.export',
  'audit.view','sync.view','sync.manage'
) WHERE r.code = 'QA_ADMIN';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN (
  'dashboard.view',
  'inspection.create','inspection.submit','inspection.view_own','inspection.view_all',
  'inspection.cancel_own','inspection.round_verify','inspection.verify_quality','inspection.print',
  'template.view','master.view','gauge.view','gauge.calibrate',
  'report.view','report.export'
) WHERE r.code = 'QUALITY_ENGINEER';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN (
  'dashboard.view',
  'inspection.view_all','inspection.verify_production','inspection.print',
  'template.view','master.view','gauge.view',
  'report.view','report.export'
) WHERE r.code = 'PRODUCTION_ENGINEER';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN (
  'inspection.create','inspection.submit','inspection.view_own','inspection.cancel_own','inspection.print'
) WHERE r.code = 'OPERATOR';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN (
  'dashboard.view','inspection.view_all','inspection.print','report.view','report.export'
) WHERE r.code = 'VIEWER';

-- -----------------------------------------------------------------------------
-- Report types (A, B, C) and their approval workflow
-- -----------------------------------------------------------------------------
INSERT INTO report_types (code, name, layout, doc_prefix, header_shift, header_operator, header_setter, header_timing,
                          requires_production_verification, requires_quality_verification, requires_qa_approval) VALUES
 ('SCA', 'Setup Change Approval',        'SECTIONED',     'SCA', 'REQUIRED', 'REQUIRED', 'OPTIONAL', 'REQUIRED', 1, 1, 1),
 ('PPI', 'Product Parameter Inspection', 'METHOD_MATRIX', 'PPI', 'REQUIRED', 'REQUIRED', 'REQUIRED', 'HIDDEN',   1, 1, 1),
 ('IPR', 'In-Process Inspection Report', 'SHIFT_GRID',    'IPR', 'HIDDEN',   'HIDDEN',   'HIDDEN',   'HIDDEN',   0, 1, 1);

-- -----------------------------------------------------------------------------
-- Shifts (adjust to plant timings)
-- -----------------------------------------------------------------------------
INSERT INTO shifts (code, name, start_time, end_time, sort_order) VALUES
 ('A', 'Shift A', '06:00:00', '14:00:00', 1),
 ('B', 'Shift B', '14:00:00', '22:00:00', 2),
 ('C', 'Shift C', '22:00:00', '06:00:00', 3);

-- -----------------------------------------------------------------------------
-- Units, inspection methods, gauge types
-- -----------------------------------------------------------------------------
INSERT INTO units (code, symbol, name) VALUES
 ('MM',      'mm',       'Millimetre'),
 ('MICRON',  'µm',       'Micrometre'),
 ('KG',      'kg',       'Kilogram'),
 ('G',       'g',        'Gram'),
 ('BAR',     'bar',      'Bar'),
 ('KGF_CM2', 'kgf/cm²',  'Kilogram-force per square centimetre'),
 ('PCT',     '%',        'Percent'),
 ('NOS',     'Nos',      'Numbers (count)'),
 ('MIN',     'min',      'Minutes'),
 ('LEVEL',   'Level',    'Skill level');

INSERT INTO inspection_methods (code, name, short_label, sort_order) VALUES
 ('GO_NOGO',    'GO / NO GO gauge',                'GO/NO GO', 10),
 ('VISUAL',     'Visual inspection',               'VISUAL',   20),
 ('TPG',        'Thread plug gauge',               'TPG',      30),
 ('DVC',        'Digital vernier caliper',         'DVC',      40),
 ('MEASURE',    'Measurement with instrument',     'MEASURE',  50),
 ('DOC_CHECK',  'Record / check-sheet verification','RECORD',  60),
 ('FUNCTIONAL', 'Functional / trial check',        'TRIAL',    70);

INSERT INTO gauge_types (code, name, requires_calibration, default_calibration_frequency_days) VALUES
 ('DVC',            'Digital vernier caliper',  1, 365),
 ('VC',             'Vernier caliper',          1, 365),
 ('MICROMETER',     'Micrometer',               1, 365),
 ('TPG',            'Thread plug gauge',        1, 180),
 ('TRG',            'Thread ring gauge',        1, 180),
 ('PLUG_GAUGE',     'Plain plug gauge (GO/NO GO)', 1, 180),
 ('RING_GAUGE',     'Ring gauge / fitment ring', 1, 180),
 ('PRESSURE_GAUGE', 'Pressure gauge',           1, 365),
 ('REFRACTOMETER',  'Refractometer',            1, 365),
 ('WEIGH_SCALE',    'Weighing scale',           1, 365),
 ('LIMIT_SAMPLE',   'Visual limit sample',      0, NULL);

-- -----------------------------------------------------------------------------
-- System settings (defaults; editable by SUPER_ADMIN). No secrets here.
-- -----------------------------------------------------------------------------
INSERT INTO system_settings (setting_group, setting_key, setting_value, value_type, label, description) VALUES
 ('company',    'company.name',                     'Company Name Pvt. Ltd.', 'STRING',  'Company name',             'Printed on every report'),
 ('company',    'company.address',                  '',                       'STRING',  'Company address',          'Printed under the company name'),
 ('company',    'company.logo',                     '',                       'FILE',    'Company logo',             'PNG/JPEG, max 1 MB, re-encoded on upload'),
 ('documents',  'documents.number_format',          '{PREFIX}-{YYYY}-{MM}-{SEQ}', 'STRING', 'Report number format',  'Tokens: {PREFIX} {YYYY} {MM} {SEQ}'),
 ('documents',  'documents.sequence_padding',       '6',                      'INTEGER', 'Sequence digits',          'SEQ is zero-padded to this width'),
 ('documents',  'documents.sequence_reset',         'MONTHLY',                'STRING',  'Sequence reset',           'MONTHLY, YEARLY or NEVER (format must contain the matching tokens)'),
 ('regional',   'regional.timezone',                'Asia/Kolkata',           'STRING',  'Plant time zone',          'Used for display, "today" and shift detection; storage is UTC'),
 ('regional',   'regional.date_format',             'd-m-Y',                  'STRING',  'Date format',              'PHP date format for screens and prints'),
 ('google',     'google.sync_enabled',              '0',                      'BOOLEAN', 'Google Sheets sync',       'Credentials are read from the server environment, never from here'),
 ('google',     'google.spreadsheet_id',            '',                       'STRING',  'Spreadsheet ID',           'Spreadsheet shared (Editor) with the service account only'),
 ('google',     'google.reports_sheet',             'Reports',                'STRING',  'Reports tab',              'One row per report revision (upserted)'),
 ('google',     'google.observation_detail',        'ALL',                    'STRING',  'Observation detail',       'ALL, OOS_ONLY or NONE: which readings are copied to Sheets'),
 ('google',     'google.detail_spreadsheet_id',     '',                       'STRING',  'Detail spreadsheet ID',    'Spreadsheet for observation tabs; empty = same as the main one. Rotate when full'),
 ('google',     'google.observations_sheet_prefix', 'Observations_',          'STRING',  'Observation tab prefix',   'Monthly tabs, e.g. Observations_2026_10'),
 ('google',     'google.capacity_alert_percent',    '80',                     'INTEGER', 'Capacity alert',           'Pause detail sync and alert when a spreadsheet reaches this % of the 10 M cell limit'),
 ('google',     'google.max_attempts',              '12',                     'INTEGER', 'Max sync attempts',        'After this the job is FAILED and needs a manual retry'),
 ('workflow',   'workflow.reauth_on_sign',          '1',                      'BOOLEAN', 'Password on approval',     'Verifiers re-enter their password to sign'),
 ('workflow',   'workflow.distinct_signers',        '1',                      'BOOLEAN', 'Distinct signers',         'One person cannot sign two stages of the same report'),
 ('security',   'security.session_idle_minutes',    '30',                     'INTEGER', 'Session timeout (idle)',   'Minutes of inactivity before logout'),
 ('security',   'security.session_absolute_hours',  '12',                     'INTEGER', 'Session lifetime',         'Hard limit regardless of activity'),
 ('security',   'security.max_failed_logins',       '5',                      'INTEGER', 'Failed logins before lock','Consecutive failures that lock the account'),
 ('security',   'security.lockout_minutes',         '15',                     'INTEGER', 'Lockout duration',         'Minutes an account stays locked'),
 ('security',   'security.password_min_length',     '10',                     'INTEGER', 'Password minimum length',  'Never below 8'),
 ('security',   'security.password_history',        '5',                      'INTEGER', 'Password history',         'Previous passwords that cannot be reused'),
 ('security',   'security.password_expiry_days',    '0',                      'INTEGER', 'Password expiry',          '0 = no forced expiry (NIST SP 800-63B)'),
 ('gauge',      'gauge.block_expired_on_submit',    '1',                      'BOOLEAN', 'Block expired gauges',     'Refuse submission when a gauge is out of calibration'),
 ('gauge',      'gauge.due_warning_days',           '7',                      'INTEGER', 'Calibration warning days', 'Warn this many days before the due date'),
 ('inspection', 'inspection.max_backdate_days',     '1',                      'INTEGER', 'Back-dating limit',        'How many production days in the past a report may be dated'),
 ('inspection', 'inspection.autosave_seconds',      '20',                     'INTEGER', 'Autosave interval',        'Draft autosave interval on tablets');
