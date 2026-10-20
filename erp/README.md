# DGR Office — Business ERP

Internal ERP for **DIGIE R WORLD** (www.digierworld.com): GST billing with
independent tax-invoice and cash-memo series, receipts, cash deposits and
bank reconciliation, expenses, double-entry ledger and GST preparation
workbooks, a telecalling CRM with Google Sheets sync, recruitment with a
narrowly-scoped AI CV review, HRM (attendance, leave), payroll and
incentives — all behind an owner-editable, deny-by-default permission
matrix with an audit trail.

Stack: PHP 8.3 · CodeIgniter 4.7 · CodeIgniter Shield · MySQL 8 (InnoDB,
utf8mb4) · Bootstrap 5.3 (bundled locally) · vanilla JS · Chart.js ·
PhpSpreadsheet · Dompdf · google/apiclient (Sheets only) · smalot/pdfparser
· brick/math · anthropic-ai/sdk (optional AI review).

| Document | Contents |
| --- | --- |
| [docs/01-BLUEPRINT.md](docs/01-BLUEPRINT.md) | Requirements, assumptions, sitemap, workflows, wireframes, accounting rules, isolation matrix |
| [docs/02-PERMISSIONS.md](docs/02-PERMISSIONS.md) | Permission registry and starter-role matrix (generated) |
| [docs/03-SCHEMA.md](docs/03-SCHEMA.md) | Table-by-table schema and ERD (generated from the migrated database) |
| [docs/04-DEPLOYMENT.md](docs/04-DEPLOYMENT.md) | XAMPP, local Apache, cPanel and VPS deployment, SSL, cron, permissions, SMTP, backups, upgrades |
| [docs/05-GOOGLE-SHEETS.md](docs/05-GOOGLE-SHEETS.md) | Service account, sharing, tab mapping, retry behaviour |
| [docs/06-AI-BOUNDARY.md](docs/06-AI-BOUNDARY.md) | What the AI can and cannot see or do, provider set-up, service tokens |
| [docs/07-TEST-REPORT.md](docs/07-TEST-REPORT.md) | Automated and manual test results, evidence files, known limitations |
| [docs/08-SECURITY-CHECKLIST.md](docs/08-SECURITY-CHECKLIST.md) | Security review checklist with how each item is enforced |
| [docs/09-UI-THEME.md](docs/09-UI-THEME.md) | Approved black · white · gold theme, component reference, self-hosted asset policy, CSP, library upgrades |
| [docs/samples/](docs/samples/) | Sample import files (clients/vendors, CRM leads, bank statement) |
| [docs/evidence/](docs/evidence/) | PDFs/XLSX and the exact AI request produced by the acceptance tests |

---

## 1. Requirements

* **PHP 8.3 or newer** with extensions: `intl`, `mbstring`, `json`,
  `mysqli`, `curl`, `openssl`, `gd`, `zip`, `xml`, `dom`, `xmlwriter`,
  `fileinfo`, `iconv`, `ctype`, `sodium`.
* PHP limits: `upload_max_filesize = 20M`, `post_max_size = 25M`, `memory_limit = 256M`.
* **MySQL 8.0+** (InnoDB, `utf8mb4`). MariaDB is not tested.
* **Apache 2.4 with `mod_rewrite`** (XAMPP, cPanel) or **Nginx + PHP-FPM**.
* A **cron** entry that can run `php` every minute (cPanel “Cron Jobs” is fine).
* **Composer 2** — only if you install from git; the release archive
  already contains the production `vendor/` folder.

## 2. Install (summary)

Full, environment-specific steps are in [docs/04-DEPLOYMENT.md](docs/04-DEPLOYMENT.md).

```bash
# 1. code — either unzip the release archive (vendor/ included) …
unzip dgr-office-<version>.zip && cd dgr-office
#    … or from git
git clone <repo> && cd <repo>/erp && composer install --no-dev --optimize-autoloader

# 2. database (as MySQL root/admin)
mysql -u root -p -e "CREATE DATABASE dgr_office CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'dgr_office_user'@'localhost' IDENTIFIED BY 'a-long-random-password';
  GRANT ALL PRIVILEGES ON dgr_office.* TO 'dgr_office_user'@'localhost';"

# 3. configuration
cp .env.example .env          # edit app.baseURL and database.default.*
php spark key:generate        # writes encryption.key — back it up separately

# 4. tables + base data (safe to re-run)
php spark dgr:install

# 5. first login
php spark dgr:create-owner --email owner@your-domain.in --name "Owner Name"
#   (omit --password to get a one-time temporary password)

# 6. health check
php spark dgr:diagnostics
```

Then point the web server's document root at the **`public/`** folder,
add the cron line, sign in and complete **Settings → Billing setup**
(`/settings/setup`): legal name, GSTIN, PAN, address and state, bank
accounts and the default payment bank, the official logo (upload the
original file — it is stored byte-for-byte and never re-drawn), signature
and stamp, and the invoice series continuing from your paper book (e.g.
next number 122). Nothing tax- or bank-related is pre-filled: real tax
documents cannot be finalised until these details are entered.

### Cron (one line)

```cron
* * * * * cd /path/to/dgr-office && php spark dgr:cron >> writable/logs/cron.log 2>&1
15 2 * * * cd /path/to/dgr-office && php spark dgr:backup --files --keep 14 >> writable/logs/backup.log 2>&1
```

`dgr:cron` runs reminders and Google Sheets sync every minute (when due),
and the daily jobs (recurring retainer drafts, applicant-data retention,
incentive proposals) once a day. No daemon or queue worker is needed.

## 3. Commands

| Command | Purpose |
| --- | --- |
| `php spark dgr:install` | Run all migrations (Shield, Settings, App) and seed base master data. Idempotent. |
| `php spark dgr:create-owner --email … --name … [--password …]` | Create an Owner / Super Admin login. |
| `php spark dgr:diagnostics` | PHP/extensions, vendor, DB, migrations, folders, cron, integrations, backups, security warnings. Exits 1 on a failure. Also in the UI at Admin → Diagnostics. |
| `php spark dgr:cron [--only reminders\|sheets\|recurring\|hr]` | Scheduled jobs (see above). |
| `php spark dgr:backup [--files] [--keep 14]` | Gzipped SQL dump (and with `--files` a zip of private uploads) in `writable/backups/`. Also Admin → Backups. |
| `php spark dgr:demo [--password …]` | Load clearly-marked demo data (names start with `DEMO ·`, logins `@demo.dgr.local`). Refuses on production, on a company with real identity, or when real records exist. |
| `php spark dgr:demo --purge` | Remove all demo data, keeping configuration, your owner login and the audit trail (asks you to type `PURGE`). |
| `php spark dgr:docs` | Regenerate `docs/02-PERMISSIONS.md` and `docs/03-SCHEMA.md`. |
| `php spark key:generate` | Create the encryption key (once). |

## 4. Trying it with demo data (evaluation only)

```bash
php spark dgr:create-owner --email you@example.com --name "Your Name" --password "…"
php spark dgr:demo            # prints the demo logins and their password
```

`dgr:demo` only runs with `CI_ENVIRONMENT = development` (never on
production). Demo users exist for the starter roles — accountant, billing
operator, CRM manager, two telecallers, HR manager, HR recruiter, payroll
manager and employee — so each role's screens can be tried next to your
owner login. The company name
shown is `DEMO COMPANY (NOT REAL)` and the demo GSTIN/bank are
placeholders. Run `php spark dgr:demo --purge` (or start with a fresh
database) before entering real data.

### Release files

`dist/dgr-office-1.1.0.zip` (application + production `vendor/`),
`dist/dgr-office-1.0.0-clean.sql` (empty database with base data for real
use, [§12](docs/04-DEPLOYMENT.md)) and `dist/dgr-office-1.0.0-demo.sql`
/ `.sql.gz` (evaluation-only demo database, [§11](docs/04-DEPLOYMENT.md)),
each with a `.sha256` checksum. Release 1.1.0 changed only the user
interface (black theme, self-hosted verified assets, strict CSP); the
database schema is unchanged, so the 1.0.0 SQL files are the correct ones
for 1.1.0.
Rebuild the archive any time with `scripts/build-release.sh <version>`.

## 5. Folder layout

```
app/Config/Routes*.php   every route, each with a perm: filter (deny by default)
app/Controllers/         thin HTTP layer (Billing, Finance, Crm, Hr, Admin, Settings, Api)
app/Services/            business rules: GST engine, ledger, receipts, payroll, AI gateway …
app/Database/            migrations and seeders
app/Views/               Bootstrap 5 screens and PDF templates
public/                  the ONLY web-exposed folder (index.php, assets)
writable/                logs, cache, sessions, private uploads, backups (never web-exposed)
tests/                   PHPUnit unit + feature tests (MySQL test database)
docs/                    documentation, samples and test evidence
```

## 6. Running the tests

Tests use a **separate, disposable** MySQL database configured in `.env`
as `database.tests.*` (see `.env.example`). They need the dev
dependencies (`composer install` without `--no-dev`).

```bash
vendor/bin/phpunit                         # whole suite
vendor/bin/phpunit tests/feature/BillingAccountingTest.php
DGR_EVIDENCE_DIR=docs/evidence vendor/bin/phpunit tests/feature/AcceptanceEvidenceTest.php tests/feature/RecruitmentAiTest.php
```

Results, coverage of the specification's must-pass cases and known
limitations are in [docs/07-TEST-REPORT.md](docs/07-TEST-REPORT.md).

## 7. Important limitations

* **GST portal / e-invoicing / e-way bill are not integrated.** The app
  produces GSTR-1 / GSTR-3B *preparation* workbooks; filing is done on the
  portal. No IRN or QR code is generated.
* **Statutory payroll deductions (PF, ESI, PT, TDS, LWF) are off** until
  HR/your accountant enters rates and ticks “reviewed”. No rates are assumed.
* Default SAC codes and GST rates for services are editable suggestions
  marked for accountant review.
* The live Google Sheets API and the live Anthropic API were **not**
  exercised with real credentials during development; both are covered by
  automated tests against fakes, and both fail safely (data stays in MySQL;
  AI shows “Analysis unavailable”). Verify them once with your own
  credentials using Admin → Diagnostics.
