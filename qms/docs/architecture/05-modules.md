# 5. Module list and build plan

After approval the application is generated **module by module, in this order**. Each module is delivered complete:
migration, model, service, validation, controller, routes, views, JavaScript (where needed), authorization and
tests. The application must run and the test suite must be green after every module.

| # | Module | Depends on | Main outcome |
|---|---|---|---|
| M0 | Foundation and hardening | – | CI4 4.7 project, secure configuration, layouts, error handling, test harness, CI |
| M1 | Core data: organisation, roles, audit, settings | M0 | Departments, employees, roles, permissions, users table, audit service, settings service, seeders |
| M2 | Authentication and sessions | M1 | Login/logout, lockout, rate limiting, password policy, session timeout, login log |
| M3 | Administration | M2 | Users, roles and permission matrix, employees, departments, audit trail viewer, login history |
| M4 | System settings | M3 | Company profile and logo, numbering, time zone, Google config, workflow, security settings |
| M5 | Master data | M3 | Shifts, units, inspection methods, gauge types, machines, parts, parameter library |
| M6 | Gauge management | M5 | Gauges, calibration history, certificate upload, due/overdue checks |
| M7 | Template management | M5, M6 | Templates, versions, sections, parameters, part/machine mapping, publish/retire, preview |
| M8 | Inspection entry | M7, M4 | Start inspection, template resolution, form engine, live PASS/FAIL, autosave and offline draft, IPR rounds, submit with numbering |
| M9 | Workflow and approvals | M8 | Approval inbox, verify / return / reject / approve with e-signature, cancellation, revisions |
| M10 | Printing and PDF | M9 | A4 print views (SCA, PPI, IPR), mPDF download, DRAFT / SUPERSEDED watermarks |
| M11 | Google Sheets synchronisation | M9 | Outbox worker, row mappers, back-off, sync monitor, retry |
| M12 | Dashboard and reports | M9 | KPI cards, filters, daily/monthly/machine/part/operator/rejection/OOS/gauge-due reports, CSV export |
| M13 | Operations | all | Nginx/PHP-FPM/MySQL configs, cron, backups, restore runbook, deployment script, security checklist |

---

## M0 Foundation and hardening

| Component | Content |
|---|---|
| Config | `App` (allowedHostnames, UTC, forceGlobalSecureRequests in production), `Security` (CSRF via session, randomized token, header), `Session` (DatabaseHandler), `Cookie` (Secure, HttpOnly, SameSite=Lax), `ContentSecurityPolicy`, `Filters` globals, `Routes` with auto-routing off, `Database` from env only |
| Code | `BaseController`, `RequestIdFilter`, `NoStoreFilter`, exception → HTTP mapping (QMS-LOCK → 409/423, validation → 422), `TransactionRunner`, `Clock`, enums, `qms_helper` |
| Views | `layouts/app.php`, `layouts/auth.php`, `layouts/print.php`, partials, production error pages |
| Assets | Bootstrap 5.3 + Icons copied from Composer packages, `qms.css` design tokens |
| Tests | Config assertions (CSRF on, secure cookies, CSP header, no auto-routes), error pages leak nothing, security headers on every response |
| Repo | `.env.example`, `.gitignore`, `phpunit.xml.dist`, GitHub Actions workflow (PHP lint, PHPUnit on MySQL 8.4, `composer audit`) |

## M1 Core data: organisation, roles, audit, settings

| Component | Content |
|---|---|
| Migrations | `departments`, `employees`, `roles`, `permissions`, `role_permissions`, `users`, `user_password_history`, `audit_logs` (+ append-only triggers), `system_settings` |
| Seeders | `ReferenceDataSeeder` (roles, permissions, matrix, default settings) |
| Models | `DepartmentModel`, `EmployeeModel`, `RoleModel`, `PermissionModel`, `RolePermissionModel`, `UserModel`, `AuditLogModel`, `SettingModel` |
| Services | `AuditService` (diff, redaction of secrets, request context, same-transaction writes), `SettingsService` (typed, cached, audited), `AuthorizationService` (permission set per request, super-admin rule) |
| CLI | `qms:create-admin` (interactive, enforces password policy) |
| Tests | Audit diff and redaction, append-only triggers, settings typing, permission resolution per role |

## M2 Authentication and sessions

| Component | Content |
|---|---|
| Migrations | `login_logs` (+ triggers), `ci_sessions` |
| Services | `AuthService` (constant-time login, Argon2id + rehash, lockout, session regeneration, security stamp), `PasswordPolicy` (length, classes, common-password list, no username, history), `LoginAttemptService`, `ReauthService` (password re-entry for signatures) |
| Validation | `LoginRules`, `ChangePasswordRules`, custom `strong_password` rule |
| Controllers / routes | `GET/POST /login`, `POST /logout`, `GET/POST /account/password` |
| Filters | `AuthFilter`, `SessionTimeoutFilter`, `PasswordChangeFilter`, `LoginThrottleFilter` |
| Views | Login (large touch inputs, show-password toggle), change password, session-expired page |
| Tests | Lockout after N failures, throttling per IP, no user enumeration (same message and timing), session regenerated on login, stamp rotation logs out other sessions, forced password change, CSRF on login |

## M3 Administration

| Component | Content |
|---|---|
| Services | `UserService` (create, edit, reset password → must change, unlock, disable; never delete), `RoleService` (custom roles, matrix edit; SUPER_ADMIN locked), `EmployeeService`, `DepartmentService` |
| Controllers / routes | `/admin/users`, `/admin/roles`, `/masters/employees`, `/masters/departments`, `/admin/audit`, `/admin/login-logs` |
| Authorization | `user.manage`, `role.manage`, `master.employees`, `audit.view`, `loginlog.view` |
| Views | Lists with filters, forms, permission matrix grid (module groups), audit log with diff view (previous vs new) |
| Tests | Every route returns 403 for roles without the permission; a user cannot raise their own privileges; audit row for every change |

## M4 System settings

| Component | Content |
|---|---|
| Services | `SettingsService` groups, `LogoService` (PNG/JPEG ≤ 1 MB, MIME sniffing, re-encode with GD, strip metadata), numbering format validation (reset policy ⇔ tokens) |
| Controllers / routes | `/admin/settings/{company,documents,regional,google,workflow,security}`, `/media/logo` |
| Views | Tabbed settings, preview of the next report number, Google connection test button (reads spreadsheet title only) |
| Tests | Invalid formats rejected, upload validation (wrong MIME, oversized, polyglot), every change audited |

## M5 Master data

| Component | Content |
|---|---|
| Migrations | `shifts`, `units`, `inspection_methods`, `gauge_types`, `machines`, `parts`, `inspection_parameters` |
| Services | One service per master with shared `MasterDataService` behaviour (immutable codes, retire instead of delete, audit) |
| Controllers / routes | `/masters/{shifts,units,methods,gauge-types,machines,parts,parameters}` |
| JS | Searchable selects, inline activate/retire |
| Tests | Unique codes (case-insensitive), referenced rows cannot be removed, shift overlap validation |

## M6 Gauge management

| Component | Content |
|---|---|
| Migrations | `gauges`, `gauge_calibrations` (+ append-only triggers) |
| Services | `GaugeService` (status changes), `CalibrationService` (record calibration in one transaction, update gauge due dates, certificate storage with SHA-256), `GaugeAvailability` (usable on a date?) |
| Controllers / routes | `/gauges`, `/gauges/{id}/calibrations`, `/media/certificates/{id}` (authorised download) |
| CLI | `gauges:due` (daily: flag due / overdue) |
| Validation | PDF/JPEG/PNG ≤ 5 MB, MIME + extension check, due date > calibration date |
| Tests | Expired gauge detection, certificate download needs permission, history cannot be edited |

## M7 Template management

| Component | Content |
|---|---|
| Migrations | `report_types`, `document_sequences`, `inspection_templates`, `template_sections`, `template_parameters`, `template_part_map`, `template_machine_map` (+ triggers) |
| Services | `TemplateService` (draft CRUD), `TemplateVersioning` (clone to new version, publish atomically: retire previous version, check mapping conflicts), `TemplateValidator` (every rule from §4.5 plus completeness), `TemplateResolver` (part + machine + report type → most specific published template; ambiguous configuration is reported) |
| Controllers / routes | `/templates`, `/templates/{id}/sections`, `/templates/{id}/parameters`, `/templates/{id}/mapping`, `/templates/{id}/publish`, `/templates/{id}/preview` |
| JS | Template editor: drag to reorder, parameter type changes the visible fields, live preview |
| Authorization | `template.view`, `template.manage`, `template.publish` |
| Tests | Publish/retire rules, resolution specificity, ambiguous mappings blocked at publish, published content immutable |

## M8 Inspection entry

| Component | Content |
|---|---|
| Migrations | `inspection_reports`, `inspection_rounds`, `inspection_observations`, `inspection_readings`, `idempotency_keys` (+ triggers) |
| Services | `InspectionService` (start: resolve template, create report + rounds, pre-fill), `DraftService` (cell-level autosave with optimistic locking), `RoundService` (IPR add/sign/verify/reopen), `SpecEvaluator` + `DecimalComparator`, `ProductionCalendar` (shift and production date), `SubmissionService` (completeness, gauge checks, re-evaluation, numbering, signature, outbox), `DocumentNumberService`, `IdempotencyService` |
| Controllers / routes | `/inspections/new`, `/inspections/{id}/edit`, `POST /inspections/{id}/draft`, `/inspections/{id}/rounds/*`, `POST /inspections/{id}/submit`, `/lookups/{parts,machines,gauges}` |
| JS | `form-engine.js` (section stepper, sticky action bar), `spec-evaluator.js` (instant PASS/FAIL, same rules as the server), `autosave.js`, `offline-queue.js` (local draft storage, replay when online), `idempotency.js`, `ipr-grid.js` |
| Authorization | `inspection.create`, `inspection.submit`, `inspection.round_verify`, `inspection.round_reopen`. Record rules: own drafts only; IPR day sheets are shared by the shift operators of that machine |
| Tests | Boundary values (= LSL / = USL pass), decimals limit, expired gauge blocks submission, LSL/USL from the client ignored, duplicate submit returns the first result, number allocation under concurrency, offline replay idempotent |

## M9 Workflow and approvals

| Component | Content |
|---|---|
| Services | `WorkflowService` (state machine, stage skipping, segregation of duties), `SignatureService` (re-authentication, signature rows), `RevisionService` (create, copy, approve and supersede atomically), cancellation |
| Controllers / routes | `/approvals` (inbox), `POST /inspections/{id}/{verify,return,reject,approve,cancel}`, `POST /inspections/{id}/revisions` |
| Views | Inbox per stage, review screen with OOS highlights, signature modal (password + remarks), history timeline |
| Authorization | `inspection.verify_production`, `inspection.verify_quality`, `inspection.approve_qa`, `inspection.revise`, `inspection.cancel_*` |
| Tests | Every allowed and forbidden transition, the same person cannot sign two stages, submitter cannot verify, approved report immutable, revision supersedes the original |

## M10 Printing and PDF

| Component | Content |
|---|---|
| Services | `PrintViewModelBuilder` (company, format document control, header, rows, signatures), `PdfService` (mPDF, A4 portrait/landscape, repeating headers, watermarks) |
| Controllers / routes | `/inspections/{id}/print`, `/inspections/{id}/pdf` |
| Views | `print/sectioned.php` (SCA), `print/method_matrix.php` (PPI), `print/shift_grid.php` (IPR, landscape) |
| Tests | Print contains document control fields and signatures, drafts watermarked, print and download are audited |

## M11 Google Sheets synchronisation

| Component | Content |
|---|---|
| Services / libraries | `SheetSyncQueue` (enqueue in the business transaction, coalescing), `SheetSyncWorker` (claim, process, back-off, stale-lock recovery), `SheetRowMapper` (per report type), `GoogleSheetsClient` (adapter), `FakeSheetsClient` (tests) |
| CLI | `sheets:sync`, `sheets:requeue` |
| Controllers / routes | `/admin/sync` (monitor, filter by status, retry job / retry all failed) |
| Authorization | `sync.view`, `sync.manage` |
| Tests | Google down → PENDING_SYNC with back-off; ambiguous timeout does not duplicate rows; max attempts → FAILED; requeue works; credentials never appear in errors or logs |

## M12 Dashboard and reports

| Component | Content |
|---|---|
| Services | `DashboardService` (KPI cards), `ReportQueryService` (all reports with shared filters), `CsvExporter` (UTF-8 BOM, neutralises `= + - @` cells) |
| Controllers / routes | `/dashboard`, `/reports/{daily,monthly,machine,part,operator,rejection,oos,gauge-due}`, `/reports/{type}/export` |
| JS | Filter bar (date, month, part, machine, shift, operator, status), small charts (inline SVG, no external library) |
| Tests | KPI definitions (current revisions only), filter combinations, export permission and audit, CSV injection neutralised |

## M13 Operations

| Component | Content |
|---|---|
| Configs | `deploy/nginx/qms.conf`, `deploy/php/qms-fpm-pool.conf`, `deploy/mysql/qms.cnf`, `deploy/cron/qms.cron` |
| Scripts | `deploy/backup/qms-backup.sh` (encrypted dump, binlog copy, retention, off-site), restore runbook, release script (symlink switch, migrate, optimize, FPM reload) |
| Checklists | Go-live security checklist (TLS, headers, permissions, accounts, backups tested, `.env` not reachable) |
