-- =============================================================================
--  QMS - DEMO data: how the three paper formats map onto the template model
-- =============================================================================
--  !! ILLUSTRATIVE ONLY. Every specification value below (LSL/USL, nominal,
--  !! thread sizes, pressures, weights) is a placeholder. Replace with the
--  !! values from the controlled drawings / control plan before use.
--  !! Never load this file into production.
--
--  Load after qms_schema.sql and qms_reference_data.sql. Templates are created
--  as DRAFT; a QA Admin publishes them from the Template Management screen
--  (publishing needs a real user as published_by).
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -----------------------------------------------------------------------------
-- Organisation and masters
-- -----------------------------------------------------------------------------
INSERT INTO departments (code, name) VALUES
 ('PROD',  'Production'),
 ('QA',    'Quality Assurance'),
 ('MAINT', 'Maintenance');

INSERT INTO employees (employee_code, full_name, department_id, designation, skill_level)
SELECT x.code, x.name, d.id, x.designation, x.skill
FROM (SELECT 'E1001' code, 'Demo Operator'            name, 'PROD' dept, 'Machine Operator'    designation, 3    skill UNION ALL
      SELECT 'E1002',      'Demo Setter',                    'PROD',      'Setter',                          4          UNION ALL
      SELECT 'E2001',      'Demo Quality Engineer',          'QA',        'Quality Engineer',                NULL       UNION ALL
      SELECT 'E3001',      'Demo Production Engineer',       'PROD',      'Production Engineer',             NULL       UNION ALL
      SELECT 'E4001',      'Demo QA Head',                   'QA',        'QA Manager',                      NULL) x
JOIN departments d ON d.code = x.dept;

INSERT INTO machines (machine_code, machine_name, department_id, make, pm_due_date)
SELECT x.code, x.name, d.id, x.make, x.pm
FROM (SELECT 'CNC-01' code, 'CNC Turning Centre 01' name, 'DemoMake' make, DATE('2026-12-31') pm UNION ALL
      SELECT 'CNC-02',      'CNC Turning Centre 02',      'DemoMake',      DATE('2026-11-30')    UNION ALL
      SELECT 'VMC-01',      'Vertical Machining Centre 01','DemoMake',     DATE('2026-10-15')) x
JOIN departments d ON d.code = 'PROD';

INSERT INTO parts (part_number, part_name, drawing_number, drawing_revision, material) VALUES
 ('BF-1001', 'Brass Fitting 3/4" NPSM (DEMO)', 'DRG-BF-1001', 'C', 'Brass CW617N');

INSERT INTO gauges (gauge_code, gauge_name, gauge_type_id, measuring_range, least_count, unit_id,
                    calibration_frequency_days, last_calibration_date, calibration_due_date, status)
SELECT x.code, x.name, gt.id, x.rng, x.lc, u.id, x.freq, x.last_cal, x.due, 'ACTIVE'
FROM (SELECT 'DVC-001' code, 'Digital Vernier 0-150'   name, 'DVC' gt, '0-150 mm' rng, 0.01 lc, 'MM' unit, 365 freq, DATE('2026-04-01') last_cal, DATE('2027-03-31') due UNION ALL
      SELECT 'DVC-002',      'Digital Vernier 0-150',        'DVC',     '0-150 mm',     0.01,    'MM',      365,      DATE('2025-10-01'),          DATE('2026-09-30')     UNION ALL
      SELECT 'TPG-NPSM-01',  'Thread Plug 3/4-14 NPSM',      'TPG',     NULL,           NULL,    NULL,      180,      DATE('2026-06-01'),          DATE('2026-11-28')     UNION ALL
      SELECT 'TPG-UNC-01',   'Thread Plug 1/4-20 UNC-2B',    'TPG',     NULL,           NULL,    NULL,      180,      DATE('2026-06-01'),          DATE('2026-11-28')     UNION ALL
      SELECT 'RING-01',      'Fitment Ring Gauge',           'RING_GAUGE', NULL,        NULL,    NULL,      180,      DATE('2026-05-15'),          DATE('2026-11-11')     UNION ALL
      SELECT 'PG-01',        'Chuck Pressure Gauge',         'PRESSURE_GAUGE','0-40 bar',0.5,    'BAR',     365,      DATE('2026-01-10'),          DATE('2027-01-09')     UNION ALL
      SELECT 'PG-02',        'Hydraulic System Gauge',       'PRESSURE_GAUGE','0-100 bar',1.0,   'BAR',     365,      DATE('2026-01-10'),          DATE('2027-01-09')     UNION ALL
      SELECT 'REF-01',       'Coolant Refractometer',        'REFRACTOMETER','0-32 %',  0.2,     'PCT',     365,      DATE('2026-02-01'),          DATE('2027-01-31')     UNION ALL
      SELECT 'WS-01',        'Weighing Scale 0-3 kg',        'WEIGH_SCALE','0-3000 g',  0.1,     'G',       365,      DATE('2026-03-01'),          DATE('2027-02-28')) x
JOIN gauge_types gt ON gt.code = x.gt
LEFT JOIN units u ON u.code = x.unit;

-- -----------------------------------------------------------------------------
-- Parameter library
-- -----------------------------------------------------------------------------
INSERT INTO inspection_parameters (code, name, default_observation_type, default_unit_id, default_method_id, default_gauge_type_id)
SELECT x.code, x.name, x.otype, u.id, m.id, gt.id
FROM (SELECT 'SKILL_LEVEL' code, 'Skill Level' name, 'NUMERIC' otype, 'LEVEL' unit, 'DOC_CHECK' method, NULL gt UNION ALL
      SELECT 'DAILY_MC_CHECK',   'Daily Machine Check Sheet',      'OK_NOT_OK',  NULL,   'DOC_CHECK',  NULL UNION ALL
      SELECT 'TOOL_LIFE',        'Tool Life Monitoring',           'OK_NOT_OK',  NULL,   'DOC_CHECK',  NULL UNION ALL
      SELECT 'POKA_YOKE',        'Poka-Yoke Verification',         'OK_NOT_OK',  NULL,   'FUNCTIONAL', NULL UNION ALL
      SELECT 'PM_DUE_DATE',      'PM Due Date',                    'DATE',       NULL,   'DOC_CHECK',  NULL UNION ALL
      SELECT 'GRUB_SCREW',       'Grub Screw Verification',        'OK_NOT_OK',  NULL,   'VISUAL',     NULL UNION ALL
      SELECT 'OD_TURN_TOOL',     'OD Turning Tool / Insert',       'OK_NOT_OK',  NULL,   'VISUAL',     NULL UNION ALL
      SELECT 'OD_THREAD_TOOL',   'OD Threading Tool / Insert',     'OK_NOT_OK',  NULL,   'VISUAL',     NULL UNION ALL
      SELECT 'ID_TURN_TOOL',     'ID Turning Tool / Insert',       'OK_NOT_OK',  NULL,   'VISUAL',     NULL UNION ALL
      SELECT 'CHUCK_PRESSURE',   'Machine Chuck Pressure',         'NUMERIC',    'BAR',  'MEASURE',    'PRESSURE_GAUGE' UNION ALL
      SELECT 'SYSTEM_PRESSURE',  'Machine System Pressure',        'NUMERIC',    'BAR',  'MEASURE',    'PRESSURE_GAUGE' UNION ALL
      SELECT 'COOLANT_CONC',     'Coolant Concentration',          'PERCENTAGE', 'PCT',  'MEASURE',    'REFRACTOMETER' UNION ALL
      SELECT 'AVG_WT_CASTING',   'Average Weight of Casting',      'NUMERIC',    'G',    'MEASURE',    'WEIGH_SCALE' UNION ALL
      SELECT 'AVG_WT_MACHINED',  'Average Weight After Machining', 'NUMERIC',    'G',    'MEASURE',    'WEIGH_SCALE' UNION ALL
      SELECT 'APPEARANCE',       'Appearance',                     'VISUAL',     NULL,   'VISUAL',     'LIMIT_SAMPLE' UNION ALL
      SELECT 'NPSM_THREAD',      'NPSM Threading',                 'GO_NO_GO',   NULL,   'TPG',        'TPG' UNION ALL
      SELECT 'THROAT_DIA',       'Throat Diameter',                'NUMERIC',    'MM',   'DVC',        'DVC' UNION ALL
      SELECT 'MINOR_DIA',        'Minor Diameter',                 'NUMERIC',    'MM',   'DVC',        'DVC' UNION ALL
      SELECT 'DRILL_DIA',        'Drill Diameter',                 'NUMERIC',    'MM',   'DVC',        'DVC' UNION ALL
      SELECT 'COLLAR_THK',       'Collar Thickness',               'NUMERIC',    'MM',   'DVC',        'DVC' UNION ALL
      SELECT 'UNC_TAPPING',      'UNC Tapping',                    'GO_NO_GO',   NULL,   'TPG',        'TPG' UNION ALL
      SELECT 'CD',               'C.D.',                           'NUMERIC',    'MM',   'DVC',        'DVC' UNION ALL
      SELECT 'RING_FITMENT',     'Fitment With Ring',              'OK_NOT_OK',  NULL,   'GO_NOGO',    'RING_GAUGE' UNION ALL
      SELECT 'MARKING',          'Marking',                        'VISUAL',     NULL,   'VISUAL',     NULL) x
LEFT JOIN units u ON u.code = x.unit
LEFT JOIN inspection_methods m ON m.code = x.method
LEFT JOIN gauge_types gt ON gt.code = x.gt;

-- -----------------------------------------------------------------------------
-- Templates (DRAFT). Format numbers are examples of document-control numbers.
-- -----------------------------------------------------------------------------
INSERT INTO inspection_templates (report_type_id, template_code, version, name, format_doc_no, format_rev_no,
                                  format_made_date, format_rev_date, planned_rounds_per_shift)
SELECT rt.id, x.code, 1, x.name, x.doc, '00', DATE('2026-01-01'), NULL, x.rounds
FROM (SELECT 'SCA' type, 'SCA-STD'    code, 'Setup Change Approval - Standard'            name, 'QA/F/01' doc, NULL rounds UNION ALL
      SELECT 'PPI',      'PPI-BF1001',      'Product Parameter Inspection - BF-1001',            'QA/F/02',     NULL        UNION ALL
      SELECT 'IPR',      'IPR-BF1001',      'In-Process Inspection - BF-1001',                   'QA/F/03',     4) x
JOIN report_types rt ON rt.code = x.type;

INSERT INTO template_sections (template_id, title, sort_order)
SELECT t.id, x.title, x.sort
FROM (SELECT 'SCA-STD' code, 'Operator Readiness' title, 1 sort UNION ALL
      SELECT 'SCA-STD',      'Machine Readiness',        2      UNION ALL
      SELECT 'SCA-STD',      'Process Parameters',       3      UNION ALL
      SELECT 'SCA-STD',      'Other Parameters',         4      UNION ALL
      SELECT 'PPI-BF1001',   'Product Parameters',       1      UNION ALL
      SELECT 'IPR-BF1001',   'Product Parameters',       1) x
JOIN inspection_templates t ON t.template_code = x.code AND t.version = 1;

-- Staging rows: template, section, parameter, spec text, type, unit, nominal, LSL, USL,
-- decimals, method, gauge type, gauge required, observations, prefill, date rule, sort
CREATE TEMPORARY TABLE tmp_template_rows (
  template_code VARCHAR(40), section_title VARCHAR(120), parameter_code VARCHAR(40), spec VARCHAR(150),
  otype VARCHAR(20), unit_code VARCHAR(20), nominal DECIMAL(18,6), lsl DECIMAL(18,6), usl DECIMAL(18,6),
  decimals TINYINT UNSIGNED, method_code VARCHAR(20), gauge_type_code VARCHAR(30), gauge_required BOOLEAN,
  obs_count TINYINT UNSIGNED, prefill VARCHAR(30), date_rule VARCHAR(40), sort_order SMALLINT UNSIGNED
);

INSERT INTO tmp_template_rows VALUES
 -- A. Setup Change Approval ---------------------------------------------------
 ('SCA-STD','Operator Readiness','SKILL_LEVEL',     'Min. level 3 (skill matrix)', 'NUMERIC',   'LEVEL', NULL, 3,   4,   0, 'DOC_CHECK', NULL,            0, 1, 'OPERATOR_SKILL_LEVEL', 'NONE', 10),
 ('SCA-STD','Machine Readiness', 'DAILY_MC_CHECK',  'Filled and OK',               'OK_NOT_OK',  NULL,  NULL, NULL,NULL,0, 'DOC_CHECK', NULL,            0, 1, 'NONE', 'NONE', 10),
 ('SCA-STD','Machine Readiness', 'TOOL_LIFE',       'Within tool life',            'OK_NOT_OK',  NULL,  NULL, NULL,NULL,0, 'DOC_CHECK', NULL,            0, 1, 'NONE', 'NONE', 20),
 ('SCA-STD','Machine Readiness', 'POKA_YOKE',       'All poka-yoke working',       'OK_NOT_OK',  NULL,  NULL, NULL,NULL,0, 'FUNCTIONAL',NULL,            0, 1, 'NONE', 'NONE', 30),
 ('SCA-STD','Machine Readiness', 'PM_DUE_DATE',     'PM not overdue',              'DATE',       NULL,  NULL, NULL,NULL,0, 'DOC_CHECK', NULL,            0, 1, 'MACHINE_PM_DUE_DATE', 'ON_OR_AFTER_INSPECTION_DATE', 40),
 ('SCA-STD','Machine Readiness', 'GRUB_SCREW',      'Present and tight',           'OK_NOT_OK',  NULL,  NULL, NULL,NULL,0, 'VISUAL',    NULL,            0, 1, 'NONE', 'NONE', 50),
 ('SCA-STD','Process Parameters','OD_TURN_TOOL',    'As per tool list',            'OK_NOT_OK',  NULL,  NULL, NULL,NULL,0, 'VISUAL',    NULL,            0, 1, 'NONE', 'NONE', 10),
 ('SCA-STD','Process Parameters','OD_THREAD_TOOL',  'As per tool list',            'OK_NOT_OK',  NULL,  NULL, NULL,NULL,0, 'VISUAL',    NULL,            0, 1, 'NONE', 'NONE', 20),
 ('SCA-STD','Process Parameters','ID_TURN_TOOL',    'As per tool list',            'OK_NOT_OK',  NULL,  NULL, NULL,NULL,0, 'VISUAL',    NULL,            0, 1, 'NONE', 'NONE', 30),
 ('SCA-STD','Process Parameters','CHUCK_PRESSURE',  '18 - 25 bar',                 'NUMERIC',   'BAR',   NULL, 18,  25,  1, 'MEASURE',   'PRESSURE_GAUGE',1, 2, 'NONE', 'NONE', 40),
 ('SCA-STD','Process Parameters','SYSTEM_PRESSURE', '45 - 60 bar',                 'NUMERIC',   'BAR',   NULL, 45,  60,  1, 'MEASURE',   'PRESSURE_GAUGE',1, 2, 'NONE', 'NONE', 50),
 ('SCA-STD','Process Parameters','COOLANT_CONC',    '5 - 8 %',                     'PERCENTAGE','PCT',   NULL, 5,   8,   1, 'MEASURE',   'REFRACTOMETER', 1, 2, 'NONE', 'NONE', 60),
 ('SCA-STD','Other Parameters',  'AVG_WT_CASTING',  '450 - 480 g',                 'NUMERIC',   'G',     NULL, 450, 480, 1, 'MEASURE',   'WEIGH_SCALE',   1, 2, 'NONE', 'NONE', 10),
 ('SCA-STD','Other Parameters',  'AVG_WT_MACHINED', '320 - 340 g',                 'NUMERIC',   'G',     NULL, 320, 340, 1, 'MEASURE',   'WEIGH_SCALE',   1, 2, 'NONE', 'NONE', 20),
 -- B. Product Parameter Inspection ---------------------------------------------
 ('PPI-BF1001','Product Parameters','APPEARANCE',   'Free from dent, burr, blow holes','VISUAL',  NULL, NULL,  NULL,  NULL,  0, 'VISUAL',  'LIMIT_SAMPLE', 0, 2, 'NONE', 'NONE', 10),
 ('PPI-BF1001','Product Parameters','NPSM_THREAD',  '3/4-14 NPSM',                 'GO_NO_GO',  NULL,  NULL,  NULL,  NULL,  0, 'TPG',     'TPG',          1, 2, 'NONE', 'NONE', 20),
 ('PPI-BF1001','Product Parameters','THROAT_DIA',   '6.50 +/-0.10',                'NUMERIC',  'MM',   6.50,  6.40,  6.60,  2, 'DVC',     'DVC',          1, 2, 'NONE', 'NONE', 30),
 ('PPI-BF1001','Product Parameters','MINOR_DIA',    '24.25 +/-0.15',               'NUMERIC',  'MM',   24.25, 24.10, 24.40, 2, 'DVC',     'DVC',          1, 2, 'NONE', 'NONE', 40),
 ('PPI-BF1001','Product Parameters','DRILL_DIA',    '5.00 +0.10/-0.05',            'NUMERIC',  'MM',   5.00,  4.95,  5.10,  2, 'DVC',     'DVC',          1, 2, 'NONE', 'NONE', 50),
 ('PPI-BF1001','Product Parameters','COLLAR_THK',   '3.00 +/-0.10',                'NUMERIC',  'MM',   3.00,  2.90,  3.10,  2, 'DVC',     'DVC',          1, 2, 'NONE', 'NONE', 60),
 ('PPI-BF1001','Product Parameters','UNC_TAPPING',  '1/4-20 UNC-2B',               'GO_NO_GO',  NULL,  NULL,  NULL,  NULL,  0, 'TPG',     'TPG',          1, 2, 'NONE', 'NONE', 70),
 ('PPI-BF1001','Product Parameters','CD',           '18.10 +/-0.10',               'NUMERIC',  'MM',   18.10, 18.00, 18.20, 2, 'DVC',     'DVC',          1, 2, 'NONE', 'NONE', 80),
 ('PPI-BF1001','Product Parameters','RING_FITMENT', 'Ring fits freely',            'OK_NOT_OK', NULL,  NULL,  NULL,  NULL,  0, 'GO_NOGO', 'RING_GAUGE',   1, 2, 'NONE', 'NONE', 90),
 ('PPI-BF1001','Product Parameters','MARKING',      'Legible, as per drawing',     'VISUAL',    NULL,  NULL,  NULL,  NULL,  0, 'VISUAL',   NULL,          0, 2, 'NONE', 'NONE', 100);

-- C. The In-Process Inspection reuses the PPI characteristics with one reading
--    per inspection time (the map below copies the PPI rows into IPR-BF1001).
INSERT INTO template_parameters (section_id, parameter_id, name, specification_text, observation_type, unit_id,
                                 nominal, lsl, usl, decimal_places, date_rule, inspection_method_id, gauge_type_id,
                                 gauge_required, observation_count, is_mandatory, prefill_source, sort_order)
SELECT s.id, p.id, p.name, r.spec, r.otype, u.id, r.nominal, r.lsl, r.usl, r.decimals, r.date_rule, m.id, gt.id,
       r.gauge_required, COALESCE(map.obs_count, r.obs_count), 1, r.prefill, r.sort_order
FROM tmp_template_rows r
JOIN (SELECT 'SCA-STD' src, 'SCA-STD' dst, NULL obs_count UNION ALL
      SELECT 'PPI-BF1001',  'PPI-BF1001',  NULL           UNION ALL
      SELECT 'PPI-BF1001',  'IPR-BF1001',  1) map ON map.src = r.template_code
JOIN inspection_templates t ON t.template_code = map.dst AND t.version = 1
JOIN template_sections s ON s.template_id = t.id AND s.title = r.section_title
JOIN inspection_parameters p ON p.code = r.parameter_code
LEFT JOIN units u ON u.code = r.unit_code
LEFT JOIN inspection_methods m ON m.code = r.method_code
LEFT JOIN gauge_types gt ON gt.code = r.gauge_type_code;

DROP TEMPORARY TABLE tmp_template_rows;

-- -----------------------------------------------------------------------------
-- Mappings: SCA-STD applies to every part on the CNC machines;
-- PPI/IPR templates apply to part BF-1001 on any machine.
-- -----------------------------------------------------------------------------
INSERT INTO template_machine_map (template_id, machine_id)
SELECT t.id, m.id FROM inspection_templates t JOIN machines m ON m.machine_code IN ('CNC-01','CNC-02')
WHERE t.template_code = 'SCA-STD' AND t.version = 1;

INSERT INTO template_part_map (template_id, part_id)
SELECT t.id, p.id FROM inspection_templates t JOIN parts p ON p.part_number = 'BF-1001'
WHERE t.template_code IN ('PPI-BF1001','IPR-BF1001') AND t.version = 1;
