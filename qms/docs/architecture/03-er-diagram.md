# 3. Database ER diagram

34 tables in four groups. The diagrams render on GitHub (Mermaid). Cardinality notation: `||` exactly one,
`|o` zero or one, `o{` zero or many, `|{` one or many.

## 3.1 Identity, access control and logs

```mermaid
erDiagram
    departments ||--o{ employees : employs
    employees |o--o| users : "has login"
    roles ||--o{ users : "assigned to"
    roles ||--o{ role_permissions : grants
    permissions ||--o{ role_permissions : "granted by"
    users ||--o{ user_password_history : "previous hashes"
    users |o--o{ login_logs : "auth events"
    users |o--o{ audit_logs : performed
    employees |o--o{ audit_logs : "acting employee"
    users ||--o{ idempotency_keys : "replay keys"

    users {
        int id PK
        int employee_id FK,UK "NULL only for bootstrap admin"
        int role_id FK
        varchar username UK
        varchar password_hash "Argon2id"
        enum status "ACTIVE, DISABLED"
        smallint failed_login_count
        datetime locked_until
        char security_stamp "invalidates sessions"
    }
    audit_logs {
        bigint id PK
        int user_id FK
        int employee_id FK
        varchar action
        varchar module
        bigint record_id
        varchar record_ref
        json previous_value
        json new_value
        varchar ip_address
        varchar user_agent
        datetime created_at "append-only"
    }
```

`system_settings` (key/value) and `ci_sessions` (CI4 session store) have no relationships.

## 3.2 Master data and templates

```mermaid
erDiagram
    departments ||--o{ machines : owns
    gauge_types ||--o{ gauges : categorises
    units |o--o{ gauges : unit
    gauges ||--o{ gauge_calibrations : "calibration history"
    report_types ||--o{ inspection_templates : "has versions"
    report_types ||--o{ document_sequences : "number counters"
    inspection_templates |o--o{ inspection_templates : "cloned from"
    inspection_templates ||--o{ template_sections : contains
    template_sections ||--o{ template_parameters : contains
    inspection_parameters ||--o{ template_parameters : "library item"
    units |o--o{ template_parameters : unit
    inspection_methods |o--o{ template_parameters : method
    gauge_types |o--o{ template_parameters : "gauge needed"
    inspection_templates ||--o{ template_part_map : "applies to"
    parts ||--o{ template_part_map : "mapped part"
    inspection_templates ||--o{ template_machine_map : "applies to"
    machines ||--o{ template_machine_map : "mapped machine"

    report_types {
        int id PK
        varchar code UK "SCA, PPI, IPR"
        enum layout "SECTIONED, METHOD_MATRIX, SHIFT_GRID"
        varchar doc_prefix UK
        bool requires_production_verification
        bool requires_quality_verification
        bool requires_qa_approval
    }
    inspection_templates {
        int id PK
        int report_type_id FK
        varchar template_code
        smallint version "UK with code"
        enum status "DRAFT, PUBLISHED, RETIRED"
        varchar format_doc_no "printed document no."
        varchar format_rev_no
        date format_made_date
        date format_rev_date
        varchar published_code UK "generated: one PUBLISHED per code"
    }
    template_parameters {
        int id PK
        int section_id FK
        int parameter_id FK
        varchar name
        enum observation_type
        decimal lsl
        decimal usl
        tinyint decimal_places
        int inspection_method_id FK
        int gauge_type_id FK
        bool gauge_required
        tinyint observation_count
    }
    gauges {
        int id PK
        varchar gauge_code UK "Gauge ID"
        int gauge_type_id FK
        date calibration_due_date
        enum status
    }
```

## 3.3 Inspection transactions

```mermaid
erDiagram
    report_types ||--o{ inspection_reports : typed
    inspection_templates ||--o{ inspection_reports : "exact version used"
    parts ||--o{ inspection_reports : inspected
    machines ||--o{ inspection_reports : "made on"
    shifts |o--o{ inspection_reports : "header shift"
    employees |o--o{ inspection_reports : "operator / setter"
    users ||--o{ inspection_reports : "created / submitted / approved by"
    inspection_reports |o--o{ inspection_reports : "revision of"
    inspection_reports ||--|{ inspection_rounds : "1 (SCA/PPI) or n (IPR)"
    shifts |o--o{ inspection_rounds : "IPR shift"
    users |o--o{ inspection_rounds : "operator / QE signature"
    inspection_rounds ||--o{ inspection_observations : "one per parameter"
    template_parameters ||--o{ inspection_observations : specification
    gauges |o--o{ inspection_observations : "gauge used"
    inspection_observations ||--o{ inspection_readings : "Obs 1..n"
    inspection_reports ||--o{ inspection_approvals : signatures
    users ||--o{ inspection_approvals : signer
    roles ||--o{ inspection_approvals : "role when signing"
    inspection_reports ||--o{ google_sheet_sync_logs : "sync jobs"

    inspection_reports {
        bigint id PK
        char client_uuid UK "tablet idempotency"
        varchar report_no "UK with revision_no"
        tinyint revision_no
        bigint parent_report_id FK
        bool is_current
        int template_id FK
        int part_id FK
        int machine_id FK
        int shift_id FK
        date inspection_date "production date"
        enum status
        enum overall_result
        smallint oos_count
        int lock_version
    }
    inspection_rounds {
        bigint id PK
        bigint report_id FK
        smallint round_no
        int shift_id FK
        time inspection_time
        enum status "OPEN, SIGNED, VERIFIED"
        enum lot_status
        int accepted_qty
        int rejected_qty
        int rework_qty
        int operator_user_id FK
        int qe_user_id FK
    }
    inspection_observations {
        bigint id PK
        bigint round_id FK "composite FK with report_id"
        int template_parameter_id FK
        decimal lsl "snapshot"
        decimal usl "snapshot"
        int gauge_id FK
        date gauge_cal_due_date "snapshot"
        enum result
        varchar remarks
    }
    inspection_readings {
        bigint id PK
        bigint observation_id FK
        tinyint reading_no
        decimal value_numeric
        enum value_choice "OK, NOT_OK, GO, NO_GO"
        varchar value_text
        date value_date
        time value_time
        enum result "PASS, FAIL, NOT_APPLICABLE"
    }
    inspection_approvals {
        bigint id PK
        bigint report_id FK
        enum stage
        enum action
        enum from_status
        enum to_status
        int user_id FK
        int role_id FK
        varchar remarks
        bool reauthenticated
        datetime signed_at "append-only"
    }
    google_sheet_sync_logs {
        bigint id PK
        bigint report_id FK
        enum job_type
        varchar spreadsheet_id
        varchar sheet_name
        enum sync_status
        smallint attempt_count
        datetime next_attempt_at
        datetime last_attempt_at
        varchar error_message
    }
```

## 3.4 The inspection aggregate

```text
inspection_reports            header: type, template version, part, machine, (shift), production date,
│                             status, report_no + revision, overall result, OOS count
├── inspection_rounds         SCA/PPI: exactly 1 round
│   │                         IPR: one per Shift A/B/C x inspection time, with lot status,
│   │                         accepted / rejected / rework, operator + QE e-signatures
│   └── inspection_observations   one per template parameter in that round:
│       │                         gauge used (+ calibration snapshot), LSL/USL snapshot, remarks, result
│       └── inspection_readings   Observation 1 … n, one typed value each, PASS / FAIL / N.A.
├── inspection_approvals      append-only signature history (submit, verify, approve, return, reject …)
└── google_sheet_sync_logs    outbox: one job per sheet write, retried until SYNCED
```

## 3.5 How the paper sheets map to the tables

| Paper field | Stored in |
|---|---|
| **A. Setup Change Approval** | |
| Machine Number / Part Name / Part Number | `inspection_reports.machine_id` → `machines.machine_code`; `part_id` → `parts.part_number`, `part_name` |
| Shift / Date | `inspection_reports.shift_id`, `inspection_date` |
| Received Time / Finish Time | `inspection_reports.received_at`, `finish_at` |
| Sections (Operator Readiness, Machine Readiness, Process Parameters, Other Parameters) | `template_sections` of the SCA template |
| Parameter Name / LSL / USL / Inspection Method / Gauge-Instrument | `template_parameters.name`, `lsl`, `usl`, `inspection_method_id`, `gauge_type_id` (LSL/USL also snapshotted in `inspection_observations`) |
| Gauge ID / Remarks | `inspection_observations.gauge_id`, `remarks` |
| Observation 1 / Observation 2 | `inspection_readings.reading_no = 1 / 2` |
| Skill Level, PM Due Date | readings pre-filled from `employees.skill_level` and `machines.pm_due_date` (`prefill_source`) |
| **B. Product Parameter Inspection** | |
| Part Number / Machine / Date / Shift / Operator / Setter | `inspection_reports` header (`operator_employee_id`, `setter_employee_id`) |
| GO / NO GO · Visual · TPG · DVC columns | `template_parameters.inspection_method_id`. The print shows a tick in that method's column |
| Production Engineer / Quality Engineer / Operator / QA Approval | `inspection_approvals` (SUBMISSION, PRODUCTION_VERIFICATION, QUALITY_VERIFICATION, QA_APPROVAL) |
| **C. In-Process Inspection Report** | |
| Part Number / Machine Name / Date | `inspection_reports` header (no header shift) |
| Shift A / B / C, multiple inspection times | `inspection_rounds.shift_id`, `inspection_time`, `round_no` |
| Observation per parameter per time | `inspection_observations` (round × parameter) + `inspection_readings` |
| Lot Status / Accepted / Rejected / Rework | `inspection_rounds.lot_status`, `accepted_qty`, `rejected_qty`, `rework_qty` |
| Inspected By Operator / Quality Engineer | `inspection_rounds.operator_user_id` / `qe_user_id` with timestamps (e-signatures) |
| **All printed reports** | |
| Company logo / name / address | `system_settings` (`company.*`) |
| Document Number / Revision Number / Make Date / Revision Date | `inspection_templates.format_doc_no`, `format_rev_no`, `format_made_date`, `format_rev_date` (document control of the format) |
| Report number and report revision | `inspection_reports.report_no` + `revision_no` (e.g. `SCA-2026-10-000123 / Rev 1`) |
