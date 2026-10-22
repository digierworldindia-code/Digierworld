# DGR Office 1.3.0 — release report

Answer to the request "complete bug fixing, stability, performance and
UI/UX improvement". Every fix below was reproduced first (a failing test
or a measurement) and verified after; the numbers are from real runs.
Release notes in user language: [CHANGELOG.md](../CHANGELOG.md). Full test
evidence: [07-TEST-REPORT.md](07-TEST-REPORT.md).

## 1. Root cause analysis

| Reported / found | Root cause | Fix | Evidence |
| --- | --- | --- | --- |
| Login lands on the wrong page / not the role's dashboard | No per-role landing page: every role went to `/`, which not every role may open | Landing page per role (editable in *Admin › Roles*, only pages the role can open), new Billing and HR dashboards, CRM *My Day* for telecallers | `LoginLandingTest`; browser check of 6 roles (land, reload, sign out) |
| Sign-in page "just reloads" | CSRF token lives in the session; if the browser never returns the session cookie (`cookie.secure` on `http://`, address ≠ `app.baseURL`, blocked cookies) every sign-in fails and the error is stored in that lost session | `CsrfFilter` sends such requests to the sign-in page with an explanation (`?session=missing`) and logs it | `LoginLandingTest::testSignInWithoutTheSessionCookie…` |
| Invoice PDF incomplete | Dompdf never splits a table row: a long description moved to the next page (page 1 nearly empty) and text beyond one page was cut off; unbroken text (links) ran off the paper; long terms clipped; signature overlapped its caption | Long descriptions continue on follow-on rows, `overflow-wrap:anywhere` on text cells, long notes/terms flow below the totals, flexible signature box; one PDF response helper drops stray output | `DocumentPdfAuditTest` (8 document types, receipts, drafts, cancelled; old template fails it) |
| Bank edit/update not saving | Values longer than their column (e.g. full branch address) → database error under strict SQL mode; also: default bank stayed default when deactivated; opening balance without a date re-posted "today" on every save | Validation with messages + `maxlength`; inactive is never default; opening date fixed on first save | `BankSetupTest` (old code fails 3 of 6) |
| 504 Gateway Timeout | (a) one slow request held the PHP session lock and froze the user's other tabs; (b) exports built in memory (9.1 s / 256 MB for 12,000 leads); (c) "Sync now" processed 300 Google jobs in one request, reading the whole sheet column per record; (d) AI review up to 90 s × 3 attempts in the request; (e) unpaginated statements (3.2 MB); (f) N+1 queries (1,816 for leave balances); (g) `DATE(column)` filters that cannot use indexes; (h) backups paging with `OFFSET` | Lock released for downloads and around slow work; streaming XLSX writer; time-boxed batched sync; AI capped at 45 s on screen; paged statements; bulk queries; range filters + 4 indexes; streamed consistent backups; slow-request log + Diagnostics | Load copy measurements (07-TEST-REPORT.md "Performance"); `XlsxWriterTest`, `StatementPagingTest`, `HrBulkScreensTest`, `CrmLeadSyncTest` |
| HTTP 500 when several people save at once (found during testing) | Hash-chained audit log locked the newest audit row (and the gap after it) mid-transaction → deadlocks | Entries appended at commit; writers serialise on one row lock | 32/32 parallel drafts + finalizations, twice, 0 deadlocks; `AuditChainTest` |
| Audit chain "broken" on MySQL (found during testing) | MySQL rewrites JSON columns, so re-hashing never matched | Canonical JSON hashing for new entries; older entries still verified | `AuditChainTest` (incl. tamper detection) |
| Light / Dark / System theme (requested) | Only a dark theme existed | Token-based light palette, top-bar selector saved to the profile, applied before first paint, charts redraw, print stays white | `ThemeComplianceTest` (WCAG AA both modes), `ThemePreferenceTest`, browser check |
| Responsive layout (requested) | KPI amounts overflowed phone cards; search box shrank to nothing; no desktop menu hide | Container-relative KPI font, search icon on phones, desktop menu toggle, busy spinner on submit | Browser: no sideways scroll on 20 screens × 3 widths × 2 themes |
| PHP 8.2 (requested) | `maennchen/zipstream-php` 3.2.x requires PHP 8.3 | Composer platform 8.2, zipstream-php 3.1.2 | Full suite on PHP 8.2.10 |

## 2. Database changes

Three additive migrations; no existing data is changed or deleted.

| Migration | Change |
| --- | --- |
| `2026-10-23-100001_AddRoleLandingUrl` | `roles.landing_url VARCHAR(120) NULL`; starter roles get their default only while empty |
| `2026-10-23-100002_AddPerformanceIndexes` | indexes `call_logs(called_at)`, `followups(status, due_at)`, `lead_conversion_links(converted_at)`, `audit_logs(occurred_at)` |
| `2026-10-23-100003_MarkAuditCanonicalHashing` | two rows in `app_settings` (where canonical audit hashing starts; the audit writers' lock row) |

`php spark dgr:install` backs up the database and verifies the backup
before applying them; if that fails it stops without changing anything.
Tested on a copy of the database dump you supplied (see 07-TEST-REPORT.md
item 18).

## 3. Modified files (since 1.2.0)

**Controllers** — `app/Controllers/Admin/AiController.php` (changed), `app/Controllers/Admin/AuditController.php` (changed), `app/Controllers/Admin/BackupsController.php` (changed), `app/Controllers/Admin/RolesController.php` (changed), `app/Controllers/BaseController.php` (changed), `app/Controllers/Billing/DocumentsController.php` (changed), `app/Controllers/Crm/SyncController.php` (changed), `app/Controllers/DashboardController.php` (changed), `app/Controllers/Finance/AccountsController.php` (changed), `app/Controllers/Finance/LedgerController.php` (changed), `app/Controllers/Finance/ReceiptsController.php` (changed), `app/Controllers/Hr/AttendanceController.php` (changed), `app/Controllers/Hr/CandidatesController.php` (changed), `app/Controllers/Hr/LeavesController.php` (changed), `app/Controllers/Hr/PayrollController.php` (changed), `app/Controllers/ProfileController.php` (changed), `app/Controllers/Settings/BanksController.php` (changed), `app/Controllers/Settings/TemplatesController.php` (changed).

**Services and libraries** — `app/Commands/BackupCommand.php` (changed), `app/Commands/InstallCommand.php` (changed), `app/Helpers/dgr_helper.php` (changed), `app/Language/en/Security.php` (new), `app/Libraries/BusinessRuleExceptionHandler.php` (changed), `app/Libraries/Landing.php` (new), `app/Libraries/PdfText.php` (new), `app/Libraries/SessionLock.php` (new), `app/Libraries/SheetIO.php` (changed), `app/Libraries/StatementPage.php` (new), `app/Libraries/XlsxWriter.php` (new), `app/Services/Admin/BackupService.php` (changed), `app/Services/Admin/Diagnostics.php` (changed), `app/Services/Ai/AiConfig.php` (changed), `app/Services/Audit/AuditLogger.php` (changed), `app/Services/Dashboard/DashboardService.php` (changed), `app/Services/Finance/LedgerService.php` (changed), `app/Services/Hr/AttendanceService.php` (changed), `app/Services/Hr/LeaveService.php` (changed), `app/Services/Sync/FakeSheetsClient.php` (changed), `app/Services/Sync/SheetsSyncService.php` (changed).

**Configuration, routes and filters** — `app/Config/Auth.php` (changed), `app/Config/Events.php` (changed), `app/Config/Filters.php` (changed), `app/Config/Landing.php` (new), `app/Config/Logger.php` (changed), `app/Config/Menu.php` (changed), `app/Config/Routes.php` (changed), `app/Filters/CsrfFilter.php` (new), `app/Filters/SessionUnlockFilter.php` (new), `app/Filters/SlowRequestFilter.php` (new).

**Database (migrations, connection, seeds)** — `app/Database/Migrations/2026-10-23-100001_AddRoleLandingUrl.php` (new), `app/Database/Migrations/2026-10-23-100002_AddPerformanceIndexes.php` (new), `app/Database/Migrations/2026-10-23-100003_MarkAuditCanonicalHashing.php` (new), `app/Database/MySQLi/Connection.php` (changed), `app/Database/Seeds/PermissionSeeder.php` (changed).

**Views, CSS and JavaScript** — `app/Views/admin/roles/form.php` (changed), `app/Views/auth/login.php` (changed), `app/Views/dashboard/index.php` (changed), `app/Views/errors/html/_themed.php` (changed), `app/Views/finance/accounts/show.php` (changed), `app/Views/finance/ledger/account.php` (changed), `app/Views/layouts/app.php` (changed), `app/Views/partials/theme_head.php` (changed), `app/Views/pdf/document.php` (changed), `app/Views/settings/banks/form.php` (changed), `public/assets/css/custom.css` (changed), `public/assets/css/dgr-theme.css` (changed), `public/assets/js/app.js` (changed), `public/assets/js/crm-performance.js` (changed), `public/assets/js/dashboard.js` (changed), `public/assets/js/theme-init.js` (new).

**Tests** — `tests/_support/DgrTestCase.php` (changed), `tests/feature/AuditChainTest.php` (new), `tests/feature/BackupVerifyTest.php` (new), `tests/feature/BankSetupTest.php` (new), `tests/feature/CrmLeadSyncTest.php` (changed), `tests/feature/DocumentPdfAuditTest.php` (new), `tests/feature/HrBulkScreensTest.php` (new), `tests/feature/LoginLandingTest.php` (new), `tests/feature/RegressionWorkflowTest.php` (new), `tests/feature/StatementPagingTest.php` (new), `tests/feature/ThemePreferenceTest.php` (new), `tests/unit/PdfTextTest.php` (new), `tests/unit/ThemeComplianceTest.php` (changed), `tests/unit/XlsxWriterTest.php` (new).

**Dependencies, docs and build** — `.env.example` (changed), `CHANGELOG.md` (changed), `README.md` (changed), `composer.json` (changed), `composer.lock` (changed), `docs/03-SCHEMA.md` (changed), `docs/04-DEPLOYMENT.md` (changed), `docs/07-TEST-REPORT.md` (changed), `docs/08-SECURITY-CHECKLIST.md` (changed), `docs/09-UI-THEME.md` (changed), `scripts/build-release.sh` (changed).

## 4. Test results

| Platform | Result |
| --- | --- |
| PHP 8.3.6 + MySQL 8.0.46 | 136 tests, 1,770 assertions — all passed |
| PHP 8.3.6 + MariaDB 11.8.9 | 136 tests, 1,770 assertions — all passed |
| PHP 8.2.10 + MySQL 8.0.46 | 136 tests, 1,734 assertions — all passed |

Plus browser checks (Chromium), the load copy, concurrency, upgrade,
backup-restore and integrity checks listed in 07-TEST-REPORT.md §0.

## 5. Deployment (Hostinger, existing installation)

1. In hPanel make a **database backup** (*Databases → phpMyAdmin → Export*)
   and download `writable/` from File Manager (it holds uploads).
2. Create a new folder next to the current one, upload
   `dgr-office-1.3.0.zip` there and extract it (files are at the top of
   the zip).
3. Copy the current `.env` into the new folder and copy the current
   `writable/` folder over the new empty one.
4. Rename folders: current → `…-old`, new → the current name.
5. SSH (or a one-time cron job) in that folder: `php spark dgr:install` —
   it writes and verifies a backup in `writable/backups`, then applies the
   three migrations. Then `php spark cache:clear` and
   `php spark dgr:diagnostics`.
6. Sign in; check *Admin › Diagnostics* (PHP 8.2+, OPcache, no FAIL) and
   that the logo, bank details and an invoice PDF look right. Keep the
   `…-old` folder until you are satisfied.

New installation: [04-DEPLOYMENT.md](04-DEPLOYMENT.md) §5a, or import
`dgr-office-1.3.0-clean.sql` into an empty database (§12).

## 6. Needs the server administrator / not verified here

* The gateway timeout, PHP worker limits and database limits on the live
  server (04-DEPLOYMENT.md §13). Nothing on your server was changed or
  tested from here.
* Real Google Sheets and Anthropic calls (tested with fakes).
* Browsers other than Chromium.
