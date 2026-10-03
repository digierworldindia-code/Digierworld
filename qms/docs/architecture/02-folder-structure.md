# 2. Folder structure

The QMS lives in `qms/` inside this repository and is an independent CodeIgniter 4 project with its own
`composer.json`. Only `qms/public/` is ever exposed by the web server. The GitHub Pages workflow of the
website excludes `qms/`, so the application source is never published as part of the marketing site.

```text
qms/
├── app/
│   ├── Commands/                      # php spark …
│   │   ├── SheetsSync.php             #   sheets:sync        – process the Google Sheets outbox (cron, every minute)
│   │   ├── SheetsRequeue.php          #   sheets:requeue     – put FAILED jobs back in the queue
│   │   ├── GaugeDueCheck.php          #   gauges:due         – flag gauges due / overdue (cron, daily)
│   │   ├── PurgeIdempotencyKeys.php   #   idempotency:purge  – delete replay keys older than 48 h
│   │   └── CreateSuperAdmin.php       #   qms:create-admin   – first account, interactive, prints nothing secret
│   ├── Config/
│   │   ├── App.php                    # baseURL, allowedHostnames, appTimezone = UTC, forceGlobalSecureRequests
│   │   ├── Database.php               # values only from environment variables
│   │   ├── Filters.php                # aliases + globals (csrf, secureheaders, invalidchars, auth …)
│   │   ├── Routes.php                 # explicit routes, auto-routing OFF
│   │   ├── Security.php               # CSRF: session-based, randomized token, header X-CSRF-TOKEN
│   │   ├── Session.php                # DatabaseHandler, regenerateDestroy = true
│   │   ├── Cookie.php                 # Secure, HttpOnly, SameSite=Lax, __Host- prefix in production
│   │   ├── ContentSecurityPolicy.php  # self-only policy, no unsafe-inline
│   │   ├── Services.php               # service factories (shared instances, mockable in tests)
│   │   ├── Qms.php                    # app-specific constants (limits, upload sizes, worker settings)
│   │   └── Google.php                 # reads GOOGLE_SA_CREDENTIALS_FILE from env (path only)
│   ├── Controllers/
│   │   ├── BaseController.php
│   │   ├── Auth/            LoginController, LogoutController, PasswordController
│   │   ├── DashboardController.php
│   │   ├── Inspections/     InspectionController, RoundController, WorkflowController,
│   │   │                    RevisionController, PrintController, LookupController (parts/machines/gauges JSON)
│   │   ├── Templates/       TemplateController, SectionController, ParameterController, MappingController
│   │   ├── Masters/         MachineController, PartController, ShiftController, EmployeeController,
│   │   │                    DepartmentController, UnitController, InspectionMethodController,
│   │   │                    GaugeTypeController, ParameterLibraryController
│   │   ├── Gauges/          GaugeController, CalibrationController
│   │   ├── Reports/         ReportController, ExportController
│   │   ├── Admin/           UserController, RoleController, SettingsController, AuditLogController,
│   │   │                    LoginLogController, SyncMonitorController
│   │   └── MediaController.php        # logo + calibration certificates (authorised, never direct file URLs)
│   ├── Database/
│   │   ├── Migrations/                # one migration per module, generated from database/schema/*.sql
│   │   └── Seeds/                     # ReferenceDataSeeder (roles, permissions, matrix …), DemoSeeder (dev only)
│   ├── Entities/                      # InspectionReport, Round, Observation, Reading, Template, Gauge, User …
│   ├── Enums/                         # PHP 8.1 backed enums
│   │   ├── ReportStatus.php           #   DRAFT … SUPERSEDED (+ label(), isEditable(), isFinal())
│   │   ├── ObservationType.php        #   NUMERIC, OK_NOT_OK, GO_NO_GO, VISUAL, TEXT, PERCENTAGE, DATE, TIME
│   │   ├── ReadingResult.php, LotStatus.php, RoundStatus.php, SyncStatus.php, WorkflowStage.php
│   ├── Exceptions/                    # AuthorizationException, WorkflowException, RecordLockedException,
│   │                                  # ConcurrencyException, IdempotencyConflictException, ValidationException
│   ├── Filters/
│   │   ├── AuthFilter.php             # logged in + security_stamp still valid
│   │   ├── PermissionFilter.php       # route argument: permission:inspection.approve_qa
│   │   ├── SessionTimeoutFilter.php   # idle + absolute timeout from settings
│   │   ├── PasswordChangeFilter.php   # forces change on first login / after reset / expiry
│   │   ├── LoginThrottleFilter.php    # CI4 Throttler per IP on POST /login
│   │   ├── NoStoreFilter.php          # Cache-Control: no-store on authenticated pages
│   │   └── RequestIdFilter.php        # X-Request-Id
│   ├── Helpers/                       # qms_helper.php (format_reading, status_badge, plant_date …)
│   ├── Language/en/                   # all user-facing strings (ready for Hindi later)
│   ├── Libraries/
│   │   ├── GoogleSheets/              # SheetsClientInterface, GoogleSheetsClient (adapter), FakeSheetsClient (tests)
│   │   ├── Pdf/                       # PdfRendererInterface, MpdfRenderer
│   │   └── Storage/                   # PrivateFileStore (writable/uploads, random names, sha256)
│   ├── Models/                        # one model per table, Query Builder only, $allowedFields whitelists
│   ├── Services/
│   │   ├── Auth/                      # AuthService, PasswordPolicy, LoginAttemptService, ReauthService
│   │   ├── Access/                    # AuthorizationService (permission cache per request), UserService, RoleService
│   │   ├── Audit/                     # AuditService (diff, redaction, request context)
│   │   ├── Settings/                  # SettingsService (typed getters, cache, change audit)
│   │   ├── Masters/                   # MachineService, PartService, EmployeeService, ShiftService, LibraryService …
│   │   ├── Gauges/                    # GaugeService, CalibrationService, GaugeAvailability
│   │   ├── Templates/                 # TemplateService, TemplateVersioning, TemplateResolver, TemplateValidator
│   │   ├── Inspections/               # InspectionService, DraftService, RoundService, SubmissionService,
│   │   │                              # SpecEvaluator, DecimalComparator, ProductionCalendar
│   │   ├── Workflow/                  # WorkflowService (state machine), SignatureService, RevisionService
│   │   ├── Numbering/                 # DocumentNumberService
│   │   ├── Sync/                      # SheetSyncQueue (enqueue), SheetSyncWorker, SheetRowMapper, BackoffPolicy
│   │   ├── Reports/                   # DashboardService, ReportQueryService, CsvExporter (formula-injection safe)
│   │   ├── Print/                     # PrintViewModelBuilder, PdfService
│   │   └── Support/                   # TransactionRunner (deadlock retry), IdempotencyService, Clock (testable time)
│   ├── Validation/
│   │   ├── Rules/                     # QmsRules: decimal_places, uuid_v4, production_date_window, strong_password …
│   │   └── RuleSets/                  # LoginRules, UserRules, TemplateRules, InspectionRules, GaugeRules …
│   └── Views/
│       ├── layouts/                   # app.php (navbar + sidebar), auth.php, print.php (A4)
│       ├── partials/                  # flash, status_badge, result_badge, pagination, filters, action_bar
│       ├── auth/  dashboard/  inspections/  templates/  masters/  gauges/  reports/  admin/
│       ├── print/                     # sectioned.php (SCA), method_matrix.php (PPI), shift_grid.php (IPR)
│       └── errors/html/               # production-safe error pages
├── public/                            # ← the ONLY web root
│   ├── index.php                      # front controller
│   ├── .htaccess                      # Apache alternative: deny dotfiles, no indexes
│   ├── robots.txt                     # Disallow: /
│   └── assets/
│       ├── css/qms.css                # design tokens, components, @media print
│       ├── js/                        # form-engine.js, spec-evaluator.js, autosave.js, offline-queue.js,
│       │                              # idempotency.js, ipr-grid.js, template-editor.js, filters.js
│       ├── img/
│       └── vendor/                    # bootstrap/, bootstrap-icons/ (copied from vendor/ by composer script)
├── writable/                          # NOT web-accessible; owned by the PHP-FPM user
│   ├── cache/  logs/  session/  debugbar/
│   └── uploads/                       # logo/, certificates/  (random names, no execute permission)
├── tests/
│   ├── _support/                      # fixtures, FakeSheetsClient, factories
│   ├── unit/                          # SpecEvaluator, DecimalComparator, WorkflowService, PasswordPolicy,
│   │                                  # TemplateResolver, DocumentNumberService, BackoffPolicy …
│   ├── database/                      # models + triggers + constraints against MySQL
│   └── feature/                       # HTTP: auth, CSRF, permission per route, submit / approve flows, print
├── database/                          # reviewed SQL reference (this phase)
│   ├── schema/qms_schema.sql          # canonical DDL (tables, FKs, CHECKs, triggers)
│   ├── schema/qms_reference_data.sql  # roles, permissions, matrix, report types, shifts, units …
│   ├── samples/qms_demo_templates.sql # illustrative SCA / PPI / IPR templates (never production)
│   ├── security/qms_db_users.sql      # dedicated MySQL accounts and grants
│   └── verify/verify_schema.php       # loads everything into a scratch DB and asserts 112 rules
├── deploy/
│   ├── nginx/qms.conf                 # TLS, HSTS, docroot public/, deny dotfiles, PHP only via index.php
│   ├── php/qms-fpm-pool.conf          # dedicated pool user, env[] for secrets, php_admin_value hardening
│   ├── mysql/qms.cnf                  # utf8mb4, UTC, strict mode, binlog, log_bin_trust_function_creators
│   ├── cron/qms.cron                  # spark jobs with flock
│   └── backup/qms-backup.sh           # encrypted dump + binlog shipping + retention
├── docs/architecture/                 # this documentation
├── .env.example                       # variable NAMES with placeholders only
├── .gitignore                         # .env, vendor/, writable/*, *.pem, *service-account*.json …
├── composer.json / composer.lock
├── phpunit.xml.dist
├── spark
└── README.md
```

## Conventions

| Item | Convention |
|---|---|
| Namespaces | `App\Services\Inspections\SubmissionService` (PSR-4, `App\` → `app/`) |
| Controllers | `<Thing>Controller`, methods `index/show/create/store/edit/update` plus explicit actions (`submit`, `approve`) |
| Routes | grouped by module, each with `['filter' => 'permission:<code>']`; auto-routing disabled |
| Models | singular table concept + `Model` (`InspectionReportModel`), `$returnType` = Entity, `$protectFields = true` |
| Migrations | `YYYY-MM-DD-HHMMSS_<Module><Change>.php`, one module per migration, triggers created in the module that owns the table |
| Tests | `<ClassUnderTest>Test.php`, database tests use a dedicated `tests` DB group and refresh between classes |
| JS | one ES module per concern, no globals except `window.QMS` config, no inline scripts (CSP) |
| CSS | `qms.css` on top of Bootstrap with CSS custom properties, no inline styles |
