# 4. MySQL schema

## 4.1 Files

| File | Content |
|---|---|
| [`database/schema/qms_schema.sql`](../../database/schema/qms_schema.sql) | **Complete DDL**: 34 tables, 56 foreign keys, 36 CHECK constraints, 130 indexes, 27 integrity triggers |
| [`database/schema/qms_reference_data.sql`](../../database/schema/qms_reference_data.sql) | Roles, 35 permissions, default permission matrix, report types SCA/PPI/IPR, shifts, units, inspection methods, gauge types, default settings |
| [`database/samples/qms_demo_templates.sql`](../../database/samples/qms_demo_templates.sql) | **Illustrative** masters and the three templates built from the paper formats (placeholder specs, never for production) |
| [`database/security/qms_db_users.sql`](../../database/security/qms_db_users.sql) | Dedicated accounts `qms_app` / `qms_migrator` / `qms_backup` and their grants |
| [`database/verify/verify_schema.php`](../../database/verify/verify_schema.php) | Loads everything into a scratch DB and checks **112** integrity rules |

Verification run against MySQL 8.0.46 with binary logging on (`php verify_schema.php --with-grants`): **112 passed, 0 failed**.
The run covers: constraints, triggers, template versioning and resolution, the full SCA lifecycle, revisions, IPR rounds,
gap-free numbering, the outbox claim, the dashboard/report queries and the privileges of each MySQL account.

```bash
# on a NON-production MySQL 8 server, with an admin account
QMS_VERIFY_USER=root QMS_VERIFY_PASSWORD=... php qms/database/verify/verify_schema.php --with-grants
```

## 4.2 Server prerequisites (`deploy/mysql/qms.cnf`)

```ini
[mysqld]
character_set_server            = utf8mb4
collation_server                = utf8mb4_0900_ai_ci
default_time_zone               = '+00:00'      # all DATETIME values are UTC
sql_mode                        = STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY
transaction_isolation           = REPEATABLE-READ
log_bin                         = /var/lib/mysql/binlog  # point-in-time recovery
binlog_expire_logs_seconds      = 1209600       # 14 days
log_bin_trust_function_creators = 1             # lets the non-SUPER migrator create the integrity triggers
bind_address                    = 127.0.0.1     # or a private interface + REQUIRE SSL
local_infile                    = OFF
skip_name_resolve               = ON
```

`log_bin_trust_function_creators = 1` is needed because MySQL refuses `CREATE TRIGGER` from an account without
SUPER while binary logging is on (verified: error 1419). Only `qms_migrator` holds the TRIGGER privilege, and the
application account holds no TRIGGER or CREATE ROUTINE privilege.

## 4.3 Conventions

- InnoDB, `utf8mb4_0900_ai_ci` (codes compare case-insensitively, so `bf-1001` and `BF-1001` cannot both exist).
- Primary keys: `INT UNSIGNED` for master data, `BIGINT UNSIGNED` for high-volume tables.
- `created_at`, `updated_at`, `created_by`, `updated_by` everywhere it makes sense. Business actors are real foreign keys:
  report creator, submitter, approver, round signers, calibration recorder, signer. On master tables
  `created_by`/`updated_by` are plain columns: it avoids circular FKs, and the authoritative "who" is in `audit_logs`.
- Flags are `BOOLEAN` (stored as `tinyint(1)`).
- Measured values and limits: `DECIMAL(18,6)`. PHP receives them as strings and compares them exactly (`DecimalComparator`, no floats).
- Master data is retired (`is_active = 0`), never deleted once referenced. FKs default to `RESTRICT`. Only owned child rows cascade (template → sections → parameters, report → rounds → observations → readings).
- Constraint names are unique across the schema (`fk_<table>_<column>`, `chk_<table>_<rule>`, `uq_…`, `idx_…`).

## 4.4 Table catalogue

### Identity and access

| Table | Purpose | Notable rules |
|---|---|---|
| `departments` | Production areas, QA, maintenance | `code` unique |
| `employees` | Everyone who inspects, sets up, operates or approves (login optional) | `employee_code` unique; `skill_level` 0–4 pre-fills the SCA skill check |
| `roles` | Six system roles plus optional custom roles | `is_system` roles cannot be deleted; `is_super_admin` implies all permissions |
| `permissions` | 35 permission codes checked in code | Seeded by migrations only (app account: SELECT only) |
| `role_permissions` | Permission matrix | PK (role, permission) |
| `users` | Login accounts, one per employee | `username`, `email`, `employee_id` unique; lockout counters; `security_stamp` kills sessions after a password/role/status change; never deleted |
| `user_password_history` | Previous hashes, to block password reuse | Append-only for the app |

### Sessions, logs, settings

| Table | Purpose | Notable rules |
|---|---|---|
| `ci_sessions` | CI4 `DatabaseHandler` sessions | Allows multi-node later |
| `login_logs` | Every login, failure, lockout, logout, timeout | Append-only (trigger + grants) |
| `audit_logs` | User, employee, action, module, record, previous/new JSON, IP, user agent, date/time | Append-only (trigger + grants); `username` snapshot; `request_id` groups rows of one request |
| `system_settings` | Company, numbering, time zone, Google sheet ID, workflow, security, gauge, inspection settings | No secrets; app may UPDATE only |

### Master data

| Table | Purpose | Notable rules |
|---|---|---|
| `shifts` | A / B / C with times | `end_time < start_time` = crosses midnight |
| `units` | mm, µm, kg, g, bar, %, Nos … | |
| `inspection_methods` | GO/NO GO, Visual, TPG, DVC, Measurement, Record check, Trial | `short_label` = column caption on the printed PPI sheet |
| `gauge_types` | DVC, thread plug gauge, pressure gauge, refractometer … | `requires_calibration`, default frequency |
| `machines` | Machine number, name, area, PM due date | `machine_code` unique |
| `parts` | Part number, name, drawing number/revision | `part_number` unique |
| `gauges` | Gauge ID, type, range, least count, calibration dates, status | Index on `calibration_due_date` for the due report; due date > last calibration |
| `gauge_calibrations` | Calibration history and certificates | Append-only; `due_date > calibration_date`; certificate stored privately with SHA-256 |

### Templates

| Table | Purpose | Notable rules |
|---|---|---|
| `report_types` | SCA / PPI / IPR: layout, number prefix, header fields, **workflow stages** | Prefix `^[A-Z0-9]{2,10}$`; at least one approval stage; `SHIFT_GRID` has no header shift |
| `document_sequences` | Report number counters per type and period | PK (type, period); incremented in the submit transaction, so numbering is gap-free |
| `inspection_parameters` | Parameter library (Appearance, Throat Diameter, Chuck Pressure …) | Stable `code` links the same characteristic across parts and template versions |
| `inspection_templates` | Versioned templates plus the printed format's document control (doc no, rev, make/rev date) | (`template_code`, `version`) unique; generated `published_code` unique, so at most one PUBLISHED version per code; PUBLISHED/RETIRED rows frozen by trigger |
| `template_sections` | Operator Readiness, Machine Readiness … | Insert/update/delete only while the template is DRAFT (trigger) |
| `template_parameters` | Spec of one parameter: type, unit, nominal, LSL, USL, decimals, method, gauge type, gauge-ID requirement, observation count, mandatory, pre-fill | `lsl <= usl`; limits only on NUMERIC/PERCENTAGE; percentage within 0–100; nominal within limits; gauge required ⇒ gauge type; DRAFT-only edits (trigger) |
| `template_part_map` / `template_machine_map` | Which parts and machines a template applies to (no rows = all) | Editable on published templates (they do not change history) |

### Inspections

| Table | Purpose | Notable rules |
|---|---|---|
| `inspection_reports` | Header of every report | `client_uuid` unique (idempotent creation); (`report_no`, `revision_no`) unique; composite FK (template, report type); CHECKs tie status to report number, submit and approval stamps; data frozen after submission and final states locked (trigger); never deleted |
| `inspection_rounds` | 1 round (SCA/PPI) or shift × time rounds (IPR) with lot status, quantities, operator and QE signatures | (`report_id`, `round_no`) unique; signed ⇒ operator signature; verified ⇔ QE signature; QE ≠ operator |
| `inspection_observations` | One per parameter per round: LSL/USL snapshot, gauge used + calibration snapshot, remarks, result | (`round_id`, `template_parameter_id`) unique; composite FK (`round_id`, `report_id`) so a row cannot point at another report's round |
| `inspection_readings` | Observation 1…n with one typed value | Exactly one value column; result NULL ⇔ no value; reading_no 1–10 |
| `inspection_approvals` | Signature history: stage, action, from/to status, signer, role, remarks, re-auth flag, IP, time | Append-only; remarks required for RETURNED / REJECTED / CANCELLED / REVISION_CREATED |
| `google_sheet_sync_logs` | Transactional outbox and sync log: `report_id`, `sheet_name`, `sync_status`, `attempt_count`, `last_attempt_at`, `error_message`, back-off and lock columns | `(sync_status, next_attempt_at)` index for the worker; SYNCED ⇔ `synced_at` |
| `idempotency_keys` | Replay protection for submit/approve | (`user_id`, `idem_key`) unique; request hash detects key reuse with a different payload; purged after 48 h |

## 4.5 Design decisions

### Rounds, observations, readings
A single structure serves every layout:

| Layout | Report type | Rounds | Readings per observation |
|---|---|---|---|
| `SECTIONED` | Setup Change Approval | 1 | `observation_count` (2 on the paper sheet) |
| `METHOD_MATRIX` | Product Parameter Inspection | 1 | `observation_count` (2) |
| `SHIFT_GRID` | In-Process Inspection | one per shift × inspection time (pre-created from `planned_rounds_per_shift`, more can be added) | usually 1 per time; 5 for SPC sub-groups later |

Gauge ID and remarks belong to the **observation** (one per parameter per round), as on paper. Individual values
belong to **readings**. This also makes later SPC (X̄-R charts from sub-groups) possible without a schema change.

### Typed values instead of one text column
`value_numeric` (DECIMAL), `value_choice` (ENUM OK/NOT_OK/GO/NO_GO), `value_text`, `value_date`, `value_time`.
A CHECK constraint allows at most one value per reading. Sorting, range queries and exact comparisons
then stay correct, and invalid choice codes cannot be stored.

### PASS/FAIL rules (SpecEvaluator)

| Observation type | PASS when | Otherwise |
|---|---|---|
| NUMERIC, PERCENTAGE | `LSL ≤ value ≤ USL` (limits inclusive; a missing limit is one-sided); value has at most `decimal_places` decimals; percentage 0–100 | FAIL (out of specification) |
| OK / NOT OK, VISUAL | `OK` | FAIL on `NOT_OK` |
| GO / NO GO | `GO` (accept) | FAIL on `NO_GO` |
| DATE | `date_rule = ON_OR_AFTER_INSPECTION_DATE` → value ≥ inspection date (e.g. PM not overdue) | FAIL |
| TEXT, TIME | no automatic verdict | `NOT_APPLICABLE` |

Observation result = FAIL if any reading fails, INCOMPLETE if a mandatory reading is missing, else PASS.
Report `overall_result` and `oos_count` are derived the same way inside the submit transaction.

### Specification snapshot
Templates are immutable once published, and each observation also stores the LSL/USL it was judged against.
The stored PASS/FAIL can therefore always be re-proven from the row alone. The same applies to the gauge
calibration due date at the time of use (`gauge_cal_due_date`, `gauge_cal_valid`).

### Revisions and "current" record
A correction is a new row: same `report_no`, `revision_no + 1`, `parent_report_id`, mandatory `revision_reason`,
`is_current = 0` while in review. On QA approval, the revision gets `is_current = 1` in the same statement and the
original moves to `SUPERSEDED`. Dashboards filter `is_current = 1`, so each report number is counted once.

### Statuses

| Status | Meaning | Editable |
|---|---|---|
| `DRAFT` | Being filled (an IPR day sheet stays DRAFT through all shifts) | yes |
| `PENDING_PRODUCTION` | Submitted, waiting for Production verification | no |
| `PENDING_QUALITY` | Waiting for Quality verification | no |
| `PENDING_QA` | Waiting for QA approval | no |
| `QA_APPROVED` | Final | never (revision only) |
| `RETURNED` | Sent back for correction (with remarks) | yes, by the originator |
| `REJECTED` | Final, not approved (e.g. setup rejected) | never |
| `CANCELLED` | Draft voided with a reason | never |
| `SUPERSEDED` | Replaced by an approved revision | never |

### Report numbers
`{PREFIX}-{YYYY}-{MM}-{SEQ}`, e.g. `SCA-2026-10-000001`. The prefix comes from `report_types.doc_prefix` and the
period from the production date. The counter is reset monthly, yearly or never (setting). The number is allocated
at first submission inside the submit transaction:

```sql
INSERT INTO document_sequences (report_type_id, period_key, last_number) VALUES (?, ?, LAST_INSERT_ID(1))
ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1);
SELECT LAST_INSERT_ID();
```

The counter row stays locked until commit and a rollback also rolls back the increment, so there are no gaps and no
duplicates (verified). `UNIQUE (report_no, revision_no)` is the final guarantee. Drafts have no number yet, so
abandoned drafts never consume one.

### Integrity triggers (27)

| Group | Triggers | Effect |
|---|---|---|
| Append-only evidence | `audit_logs`, `login_logs`, `inspection_approvals`, `gauge_calibrations` (×2 each) | UPDATE and DELETE always fail |
| Controlled templates | `inspection_templates` (update, delete), `template_sections` (×3), `template_parameters` (×3) | Content can only change while DRAFT; PUBLISHED → RETIRED is the only status move afterwards |
| Frozen inspections | `inspection_reports` (update, delete), `inspection_rounds` (×3), `inspection_observations` (×3), `inspection_readings` (×3) | Data rows change only while DRAFT/RETURNED; afterwards only workflow columns move; final reports only QA_APPROVED → SUPERSEDED; reports are never deleted |

All raise `SQLSTATE 45000` with a message starting `QMS-LOCK:`. The application maps this to a "record locked" error.

## 4.6 Index strategy (required filters)

| Filter / query | Index |
|---|---|
| Date (dashboards, daily/monthly) | `idx_inspection_reports_date`, `idx_inspection_reports_current_date (is_current, inspection_date)` |
| Machine | `idx_inspection_reports_machine_date (machine_id, inspection_date)` (verified by EXPLAIN) |
| Part | `idx_inspection_reports_part_date (part_id, inspection_date)` |
| Operator | `idx_inspection_reports_operator_date`, `idx_inspection_reports_submitter_date`, `idx_inspection_rounds_operator` (IPR) |
| Status | `idx_inspection_reports_status_date (status, inspection_date)` |
| Template | `idx_inspection_reports_template (template_id, report_type_id)` |
| Shift | `idx_inspection_reports_shift_date`, `idx_inspection_rounds_shift` |
| "My drafts" | `idx_inspection_reports_creator_status (created_by, status)` |
| OOS report | `idx_inspection_observations_report_result (report_id, result)` + readings by observation |
| Gauge usage trace (gauge found out of calibration) | `idx_inspection_observations_gauge` |
| Calibration due report | `idx_gauges_due` |
| Sync worker | `idx_google_sheet_sync_logs_queue (sync_status, next_attempt_at)` |
| Audit search | `(module, record_id, created_at)`, `(user_id, created_at)`, `(action, created_at)`, `record_ref` |

## 4.7 Expected volumes

Example plant: 15 machines, about 30 SCA + 45 PPI + 15 IPR day sheets per day, 300 working days.

| Table | Rows / year | 5 years |
|---|---|---|
| `inspection_reports` | ~27 000 | ~135 000 |
| `inspection_observations` | ~110 000 | ~0.6 M |
| `inspection_readings` | ~1.1 M | ~5.5 M |
| `audit_logs` | ~1.5–3 M | ~10 M |

Well within a single MySQL 8 instance with these indexes. The application never deletes rows; `audit_logs` can be
archived per year by the DBA under change control if needed.

## 4.8 Migrations plan

Each module migration creates the tables it owns (plus their triggers) exactly as in `qms_schema.sql`. A CI job loads
both the reference SQL and the migrations into two scratch schemas and compares `SHOW CREATE TABLE` / trigger
bodies, so the two can never drift apart. Raw SQL is used inside migrations wherever CI4 Forge cannot express a
feature (CHECK constraints, generated columns, composite FKs, triggers).
