# QMS – Paperless Quality Inspection System (plain PHP edition)

Tablet-friendly quality inspection system for machining plants, written in **core PHP 8.2+ (no framework)**,
**MySQL 8** and **Bootstrap 5**:

- **Setup Change Approval (SCA)**, **Product Parameter Inspection (PPI)** and **In-Process Inspection (IPR,
  shifts A/B/C × inspection times)** – and any further report type – built from **templates** (sections,
  parameters, LSL / USL, units, methods, gauges, number of observations) without programming.
- Instant **PASS / OUT OF SPEC** while typing (re-checked on the server with exact decimal arithmetic).
- Gauges with **calibration** due dates and certificates; expired gauges are flagged or blocked.
- **Workflow** Draft → Operator → Production → Quality → QA Approved, with return / reject, password re-entry
  ("e-signature"), segregation of duties and **revisions** (the approved original stays valid until the revision
  is approved).
- **Document numbers** like `SCA-2026-10-000001`, A4 **print and PDF** like the paper formats, **dashboard**,
  8 **reports** with CSV export, complete **audit trail**, roles and permissions (Super Admin, QA Admin,
  Quality Engineer, Production Engineer, Operator, Viewer).
- **Google Sheets** copy of every report (queue with retry; MySQL stays the system of record).
- Works offline for short Wi-Fi drops (autosave queue on the tablet).

This edition is functionally identical to the CodeIgniter 4 edition in `../qms/`. It is meant for **shared hosting**
(cPanel, Plesk, DirectAdmin, any Apache with PHP) as well as **cloud servers**, and needs no Composer, no
framework and no command line on the server – only PHP, MySQL and a browser.

---

## Contents

1. [What is in the package](#1-what-is-in-the-package)
2. [Requirements](#2-requirements)
3. [Install on shared hosting (cPanel / Plesk)](#3-install-on-shared-hosting-cpanel--plesk)
4. [Install on a cloud server / VPS (Ubuntu, one command)](#4-install-on-a-cloud-server--vps-ubuntu-one-command)
5. [After installation: first configuration](#5-after-installation-first-configuration)
6. [Daily use](#6-daily-use)
7. [Google Sheets connection](#7-google-sheets-connection)
8. [Operations: cron, backup, update, logs, commands](#8-operations-cron-backup-update-logs-commands)
9. [Security](#9-security)
10. [Troubleshooting](#10-troubleshooting)
11. [Development and tests](#11-development-and-tests)
12. [How the code is organised](#12-how-the-code-is-organised)

---

## 1. What is in the package

| File | Use it for |
|---|---|
| `qms-core-deploy-1.0.0.zip` | **Installing.** Complete and ready to run: application, `vendor/` (mPDF for PDFs) and `public/assets/vendor/` (Bootstrap 5 + icons). Nothing else to download. |
| `qms-core-source-1.0.0.zip` | Source code with tests and tools, without third-party libraries (developers). |
| `SHA256SUMS-core-1.0.0.txt` | Checksums: `sha256sum -c SHA256SUMS-core-1.0.0.txt` |
| `README.md` | This guide. |

Inside the deploy zip (folder `qms-core-1.0.0/`):

```
app/              application code (never web-accessible)
  Core/           the small built-in framework: database, router, views, sessions, CSRF, installer
  Controllers/  Services/  Filters/  Views/  Config/  Libraries/  Enums/  Exceptions/
bin/qms.php       command line tool (install, cron jobs, maintenance)
database/         schema (tables + integrity triggers), reference data, demo data, MySQL accounts
deploy/           VPS installer (Nginx, PHP-FPM, MySQL, HTTPS, cron, backups)
public/           THE ONLY WEB-ACCESSIBLE FOLDER: index.php, .htaccess, CSS, JavaScript, Bootstrap
storage/          logs, uploads (logo, certificates), cache – writable by PHP, never web-accessible
vendor/           mPDF (PDF generation)
.env.example      configuration template (the setup page writes the real .env for you)
.htaccess         safety net when the whole folder is inside the web root
```

## 2. Requirements

| | Minimum |
|---|---|
| PHP | **8.2 or newer** (8.3 recommended) with `pdo_mysql`, `mbstring`, `json`, `openssl`, `fileinfo`, `gd`, and `curl` (for Google Sheets) – all standard on cPanel hosting |
| Database | **MySQL 8.0.16+** (CHECK constraints and triggers are used). MariaDB is not supported. |
| Web server | Apache with `mod_rewrite` (shared hosting), Nginx + PHP-FPM, or LiteSpeed |
| PHP limits | `upload_max_filesize` ≥ 6 MB, `post_max_size` ≥ 8 MB, `memory_limit` ≥ 128 MB |
| Browser | Chrome / Edge / Safari / Firefox from the last 2 years; tablets (Android, iPad) supported |
| HTTPS | Strongly recommended (free AutoSSL / Let's Encrypt on every cPanel host) |

## 3. Install on shared hosting (cPanel / Plesk)

About 15 minutes, no command line needed.

### 3.1 Create the database

cPanel → **MySQL® Databases**:

1. *Create New Database*, e.g. `youruser_qms`.
2. *Add New User*, e.g. `youruser_qms`, with a long generated password (note it down).
3. *Add User To Database* → tick **ALL PRIVILEGES** → *Make Changes*.

(Plesk: *Databases → Add Database*, MySQL.) The database must be **empty**.

### 3.2 Upload the files

Upload `qms-core-deploy-1.0.0.zip` with **File Manager** (or FTP) and **Extract** it. Then choose one layout:

**Layout A – subdomain (recommended).** Extract into your home folder, e.g. `/home/youruser/qms-core-1.0.0`
(rename the folder to `qms` if you like). cPanel → **Domains → Create a New Domain** (e.g. `qms.yourcompany.com`),
untick *Share document root* and set the **Document Root** to `qms/public`. Only `public/` is reachable from the web.

**Layout B – main domain, files outside `public_html`.** Extract to `/home/youruser/qms`. Copy everything *inside*
`qms/public/` (including the hidden `.htaccess`) into `public_html/`. Edit `public_html/index.php` and change the line

```php
$qmsRoot = dirname(__DIR__);
```
to
```php
$qmsRoot = '/home/youruser/qms';
```

**Layout C – everything inside `public_html` (simplest, needs `.htaccess` support).** Extract the *contents* of
`qms-core-1.0.0/` directly into `public_html/` (or into `public_html/qms/` for `https://yourdomain/qms/`). The
included `.htaccess` files send every request into `public/` and block `app/`, `storage/`, `.env` and the rest.
The setup page checks this: if the server ignores `.htaccess` it refuses to continue and tells you to use layout A.

Turn on HTTPS for the domain (cPanel → **SSL/TLS Status → Run AutoSSL**) before the next step.

### 3.3 Run the setup page

Open the site address in the browser (e.g. `https://qms.yourcompany.com/`). The **QMS setup** page appears:

1. **Server check** – every line must be green (yellow lines are warnings). If PHP is too old, select PHP 8.2+ in
   cPanel → *MultiPHP Manager* (or *Select PHP Version*).
2. **Setup key** – open `storage/setup-key.txt` in File Manager and paste its content. (This proves that you, not a
   stranger, control the server. The file is deleted after setup.)
3. **MySQL database** – host `localhost`, port `3306`, database name, user and password from step 3.1.
4. **Site and company** – the site address (with `https://`), the company name printed on reports and the plant
   time zone. Tick *Load demo data* only for a trial system.
5. **First administrator** – user name (e.g. `admin`) and a password of at least 10 characters with three of: lower
   case, upper case, digits, symbols (not containing the user name).

Press **Install QMS**. The setup creates all tables and integrity triggers, the roles and permissions, your
administrator, writes the configuration file `.env` (readable only by your account) and switches itself off for good
(`storage/installed.lock`). Then sign in.

> If setup reports that MySQL **refused to create the triggers** (error 1419/1227), the host runs MySQL with binary
> logging and no trigger permission for customers. Ask support to set `log_bin_trust_function_creators = 1`, or use a
> VPS (section 4). The triggers are part of the tamper protection and are not optional.

### 3.4 Cron jobs

cPanel → **Cron Jobs** → add (replace `/home/youruser/qms` with your folder; find the PHP path in *MultiPHP*
or use `/usr/local/bin/php`):

| Common setting | Command |
|---|---|
| Once per minute (`* * * * *`) | `/usr/local/bin/php /home/youruser/qms/bin/qms.php sheets:sync --quiet` |
| Once per day (`15 2 * * *`) | `/usr/local/bin/php /home/youruser/qms/bin/qms.php maintenance` |

The first sends reports to Google Sheets (harmless when Sheets is not configured), the second removes expired
sessions, old temporary files and logs older than 30 days.

### 3.5 Check

- `https://your-site/.env` must show an **error page**, never the file.
- `https://your-site/health` answers `{"status":"ok"}`.
- **Backups:** enable your host's daily backups (cPanel *JetBackup* / *Backup*), and download a full backup
  (database + files) regularly. The `storage/uploads` folder holds the logo and calibration certificates.

## 4. Install on a cloud server / VPS (Ubuntu, one command)

For AWS EC2 / Lightsail, Azure, Google Cloud, DigitalOcean, Hetzner, any Ubuntu 22.04 / 24.04 server
(2 vCPU, 2–4 GB RAM, 20 GB disk is plenty for one plant).

1. Create the VM, open ports **22, 80, 443** in the cloud firewall, point a DNS name (e.g. `qms.yourcompany.com`)
   to its public IP.
2. Upload and run:

```bash
scp qms-core-deploy-1.0.0.zip ubuntu@qms.yourcompany.com:/tmp/
ssh ubuntu@qms.yourcompany.com
sudo apt-get update && sudo apt-get install -y unzip
cd /tmp && unzip -q qms-core-deploy-1.0.0.zip && cd qms-core-1.0.0

# DNS name + free Let's Encrypt certificate (recommended)
sudo bash deploy/install.sh --domain qms.yourcompany.com --email it@yourcompany.com

# No DNS name yet: self-signed certificate on the IP address (browsers warn once)
sudo bash deploy/install.sh --domain 203.0.113.10 --self-signed

# add --demo to load the demo parts, machines, gauges and the three sample templates
```

The installer sets up Nginx (HTTPS only, HSTS), PHP-FPM running as the unprivileged user `qms`, MySQL 8 with three
least-privilege accounts (`qms_app` runtime – no DDL, `qms_migrator` – locked after installation, `qms_backup`),
the database schema, the first Super Admin, cron jobs (Google Sheets every minute, housekeeping daily, backup
nightly), log rotation and the UFW firewall. At the end it prints the address, the admin user and a **one-time
password** (also saved in `/root/qms-first-login.txt` – delete it after the first login). Run
`sudo bash deploy/install.sh --help` for all options. The installer is safe to run again.

## 5. After installation: first configuration

Do these steps once, in this order, as the Super Admin.

| Step | Menu | What to enter |
|---|---|---|
| 1 | **Administration → Settings** | Company name, address and logo (printed on every report); document number format (e.g. `SCA-2026-10-000001`); plant time zone and date format; back-dating limit; session timeout, lockout and password rules; approval options (password on approval, distinct signers); expired-gauge blocking; Google Sheets (later) |
| 2 | **Administration → Settings → Approval workflow** | Report types: number prefix and which approval stages each type uses (default: SCA and PPI = Production → Quality → QA, IPR = Quality → QA) |
| 3 | **Quality setup → Other masters** | Departments, shifts (A/B/C timings), units, inspection methods (GO/NO GO, Visual, TPG, DVC …), gauge types |
| 4 | **Quality setup → Machines / Parts** | Machine numbers (with PM due date) and part numbers / names / drawings |
| 5 | **Other masters → Employees** | Every operator, setter and engineer with employee ID and skill level |
| 6 | **Quality setup → Gauges** | Gauge IDs, type, range, calibration date and due date, certificates (PDF) |
| 7 | **Quality setup → Parameter library** | Reusable parameters (Appearance, Throat Diameter, Chuck Pressure …) |
| 8 | **Quality setup → Templates** | One template per report type and part family: sections, parameters with LSL / USL, unit, method, gauge type, number of observations; map to parts and machines; **Publish** |
| 9 | **Administration → Users** | A login for each person, linked to the employee, with one role: Operator, Production Engineer, Quality Engineer, QA Admin, Viewer / Management (or a custom role from **Roles & permissions**) |

With demo data, the system contains a complete example of all of this. **Replace or retire the demo data before
real use** (demo specifications are placeholders).

## 6. Daily use

- **Operators** (tablet): *Home → Start an inspection* → choose report type, part, machine, shift. The correct
  template loads automatically. Values turn green (PASS) or red (OUT OF SPEC) while typing; gauges with an expired
  calibration are flagged. Drafts save automatically every few seconds and survive a lost Wi-Fi connection
  (kept on the tablet, sent when the network returns). *Submit* = the operator's signature.
- **In-Process sheets**: one sheet per part, machine and production day, shared by Shift A/B/C. Each operator adds
  inspection times, records readings, lot status and quantities, and signs their column; the quality engineer
  verifies columns.
- **Engineers / QA**: *Approvals* lists everything waiting for you. Review → **Verify / Approve**, **Return** (with
  remarks, back to the operator) or **Reject** – each with password re-entry. Nobody can approve a report they
  submitted, and one person signs only one stage.
- **Corrections** after approval: *Create revision* (QA). The approved original stays valid until the revision is
  approved, then it is marked *Superseded*.
- **Print / PDF**: every report prints on A4 like the paper format, with document number, revision, make/revision
  date, LSL/USL, gauges, observations, remarks and the names/dates of all signatures. Drafts print with a DRAFT
  watermark.
- **Dashboard and Reports**: today's / monthly totals, passed, failed, pending approval, out-of-spec, pending
  Google sync; daily, monthly, machine-wise, part-wise, operator-wise, rejection, out-of-spec and
  gauge-calibration reports with CSV export.

## 7. Google Sheets connection

MySQL is always the system of record. Google Sheets receives a reporting copy through a queue: if Google is
unavailable, jobs wait (`PENDING_SYNC`), retry with back-off and never lose data. This edition talks to the
Sheets API with PHP's cURL and a signed service-account token – no Google SDK is needed.

1. In the **Google Cloud console** create a project (e.g. `qms-sheets-sync`) and enable **Google Sheets API** only.
2. *IAM & Admin → Service accounts → Create*: name `qms-sheets-writer`, **no roles**. Open it → *Keys → Add key →
   JSON*. A key file downloads – treat it like a password.
3. Put the key on the server **outside the web root** and point `GOOGLE_CREDENTIALS_FILE` in `.env` to it:
   - Shared hosting: create the folder `/home/youruser/qms-secrets/` (not inside `public_html`), upload the key as
     `google-sa.json`, set permissions to `0400`, then in `.env`:
     `GOOGLE_CREDENTIALS_FILE=/home/youruser/qms-secrets/google-sa.json`
   - VPS: `sudo install -m 0440 -o root -g qms key.json /etc/qms/secrets/google-sa.json` (the installer already
     points `.env` there).

   Delete the downloaded copy on your computer afterwards. Never put the key into the application folder or Git.
4. Create a spreadsheet in the **company** Google Drive. *Share* it with the service-account e-mail
   (`qms-sheets-writer@<project>.iam.gserviceaccount.com`) as **Editor** – with nobody else as Editor
   (management as Viewer). Copy the spreadsheet ID from its URL (`…/spreadsheets/d/<ID>/edit`).
5. In QMS: **Settings → Google Sheets** → paste the spreadsheet ID, choose the detail level (`ALL` readings,
   `OOS_ONLY` or `NONE`), switch sync **on**, press **Test connection**.
6. Check **Administration → Google Sheets sync**. Past reports can be queued with *Queue past reports*.

Command-line check: `php bin/qms.php sheets:test` (VPS: `sudo -u qms php /var/www/qms/bin/qms.php sheets:test`).

Tabs written: `Reports` (one row per report revision, updated in place) and monthly `Observations_YYYY_MM`
(one row per reading, written when a report is approved or rejected). With `ALL`, a detail spreadsheet holds
about 3–4 months for 15 machines (Google's 10 M cell limit): when the in-app capacity alert appears, create a new
spreadsheet, share it the same way and enter it as *Detail spreadsheet ID*.

## 8. Operations: cron, backup, update, logs, commands

### Command line tool

Everything that needs a terminal is in one script (on shared hosting it is only used by cron):

```bash
php bin/qms.php status          # version, database connection, Google Sheets queue
php bin/qms.php sheets:sync     # send queued Google Sheets jobs now (cron: every minute)
php bin/qms.php sheets:test     # check Google credentials and spreadsheet access (read-only)
php bin/qms.php maintenance     # housekeeping (cron: daily)
php bin/qms.php down | up       # maintenance page on / off
php bin/qms.php create-admin --username=admin2 --generate     # emergency administrator
php bin/qms.php migrate         # apply database updates after uploading a new version
php bin/qms.php install         # first installation from the command line (instead of the setup page)
```

On the VPS run them as the `qms` user: `sudo -u qms php /var/www/qms/bin/qms.php status`.

### Backups

- **VPS:** every night at 01:30 UTC `/usr/local/sbin/qms-backup` writes a consistent database dump (with triggers)
  and the uploaded files to `/var/backups/qms`, keeps 14 days and writes SHA-256 checksums. Copy them off the server:
  set `QMS_BACKUP_OFFSITE_CMD` in `/etc/qms/backup.conf`, e.g.
  `QMS_BACKUP_OFFSITE_CMD='rclone copy /var/backups/qms remote:qms-backups'`. Restore:
  `sudo qms-restore /var/backups/qms/qms-db-<stamp>.sql.gz /var/backups/qms/qms-files-<stamp>.tar.gz`.
- **Shared hosting:** use the host's backup tool (database + home folder) and download copies regularly. A database
  export from phpMyAdmin must include triggers (*Export → Custom → Add CREATE TRIGGER*).

### Update to a new version

- **VPS:** upload and extract the new zip, then `sudo bash deploy/update.sh` (maintenance page, backup, new code –
  keeping `.env`, uploads and secrets –, database migrations, PHP reload).
- **Shared hosting:** take a backup; `php bin/qms.php down` (or create the file `storage/maintenance.flag`); upload
  the new files over the old ones **except** `.env` and `storage/`; run `php bin/qms.php migrate` (cPanel *Terminal*,
  or a one-time cron job); `php bin/qms.php up` (or delete `storage/maintenance.flag`).

### Logs and health

| What | Where |
|---|---|
| Application errors | `storage/logs/log-YYYY-MM-DD.log` (each error has a reference shown to the user) |
| Audit trail, login history | in the application (*Administration → Audit trail / Login history*) |
| Google Sheets worker | *Administration → Google Sheets sync* (VPS also `/var/log/qms/sheets-sync.log`) |
| VPS web / PHP | `/var/log/nginx/qms.*.log`, `/var/log/qms/php-fpm.log` |
| Health check (uptime monitoring) | `https://your-site/health` – HTTP 200 when PHP and MySQL work |

## 9. Security

| Requirement | How it is met |
|---|---|
| Passwords never stored in plain text | Argon2id (or bcrypt) `password_hash()`; password history; strength rules incl. a list of 10,000 common passwords; lockout after repeated failures; login rate limiting per IP |
| No credentials in source / Git | `.env` is written on the server (mode 0600/0640, git-ignored); the Google key is referenced only by path, outside the project |
| `.env` not web-accessible | Only `public/` is the web root; `.htaccess` / Nginx refuse dot-files and every PHP file except `index.php`; the setup page refuses to run where `.htaccess` is ignored |
| No user-supplied PHP / SQL | No `eval`, no dynamic includes; every value reaches MySQL as a bound parameter (PDO, no multi-statements); column names in data are validated; ORDER BY directions are whitelisted; report filters are fixed queries |
| Never trust client-side validation | Every value is validated and PASS/FAIL is computed again on the server; LSL/USL always come from the published template snapshot |
| OWASP | CSRF token on every form and AJAX call (masked per page), same-origin check, output escaping, strict Content-Security-Policy (no inline script), `X-Frame-Options: DENY`, secure / HttpOnly / SameSite cookies with the `__Host-` prefix on HTTPS, session id regeneration on login, idle and absolute timeouts, database sessions with strict mode, HTTPS redirect + HSTS, upload type checks and image re-encoding, CSV formula-injection protection, generic error pages with a reference id |
| Least privilege | VPS: separate MySQL accounts (app / migrator / backup), PHP runs as `qms`, code read-only for PHP |
| Tamper-proof records | Database triggers block changes to submitted readings, deleting reports, and any edit of audit / login / approval / calibration history – even if application code were wrong |
| Audit trail | User, employee ID, action, module, record, previous / new value, IP, user agent, date and time for every change |

**Go-live checklist:** change the first password; remove demo data; confirm `https://your-site/.env` shows an
error; keep `APP_ENV=production`; enable HTTPS; set up off-site backups and test one restore; keep PHP updated.

## 10. Troubleshooting

| Problem | Solution |
|---|---|
| Setup page: red "Private files outside the web root" | The server ignores `.htaccess`. Use layout A or B (document root = `public/`) |
| Setup: "MySQL refused to create the integrity triggers" | See the note in 3.3 (`log_bin_trust_function_creators`); the setup can be run again afterwards |
| Setup: "This database already contains other tables" | Create a new, empty database for QMS |
| `500` error without details | Look in `storage/logs/`. Check that `storage/` is writable by PHP (755 folders / 644 files on most hosts) |
| "Something went wrong. Reference: …" | Search the reference in `storage/logs/` |
| Pages other than the start page show `404` (Apache) | `mod_rewrite` is off or `public/.htaccess` was not uploaded (hidden file – enable *Show Hidden Files* in File Manager) |
| Forms are refused ("did not come from this application") or you are logged out at once | `APP_URL` in `.env` must match the address in the browser exactly (https vs http, with or without www) |
| Logo / certificate upload fails | Raise `upload_max_filesize` / `post_max_size` (cPanel *MultiPHP INI Editor*) |
| Wrong times on reports | Set the plant time zone in *Settings → Regional* |
| Gauge not selectable / submission blocked | Calibration expired: record a new calibration, or switch off blocking in *Settings → Gauges* |
| Google sync `403` / `404` | Spreadsheet not shared with the service-account e-mail, wrong ID, or key file path / permissions wrong |
| Google sync stays *pending* | Sync switched off, or the cron job is missing – run `php bin/qms.php sheets:sync` once and read the output |
| Locked out | Wait for the lockout period, or another admin unlocks the user (*Users → Unlock*); emergency: `php bin/qms.php create-admin --username=admin2 --generate` |

## 11. Development and tests

```bash
composer install                     # mPDF, Bootstrap (published to public/assets/vendor), PHPUnit
cp .env.example .env                 # APP_ENV=development, APP_URL=http://127.0.0.1:8080/, DB_* and TEST_DB_*
php bin/qms.php install --demo       # tables, reference + demo data, admin (asks for a password; --generate prints one)
php -S 127.0.0.1:8080 -t public bin/dev-router.php

vendor/bin/phpunit                   # 88 tests: unit, database (needs TEST_DB_* - name must contain "test"), HTTP
cd tests/e2e && npm install && QMS_URL=http://127.0.0.1:8080 QMS_PASSWORD=... node full-flow.mjs   # browser test
bash bin/build-release.sh            # dist/qms-core-deploy-<version>.zip and the source zip
```

The browser test needs four users (`op1`, `pe1`, `qe1`, `qa1` with roles Operator, Production Engineer, Quality
Engineer, QA Admin) sharing one password, and demo data. `tests/e2e/setup-flow.mjs` tests the setup page on a fresh
upload.

## 12. How the code is organised

The application is plain PHP with a small, readable core (`app/Core`, about 4,600 lines including the installer) instead of a framework:

| Part | File(s) | Role |
|---|---|---|
| Front controller | `public/index.php` → `app/bootstrap.php` | Autoloading (`App\` → `app/`), `.env`, error handling, helpers |
| Kernel | `Core/App.php` | HTTPS redirect, maintenance mode, routing, same-origin + CSRF check, filters, controller, security headers |
| Routes | `app/routes.php`, `Core/Router.php` | Every URL with its permission (`permission:inspection.create` …); no automatic routing |
| Filters | `app/Filters/*` | Login, permission, guest, login rate limit, no-store |
| Database | `Core/Database.php`, `Core/QueryBuilder.php` | PDO with bound parameters, UTC session, transactions with deadlock retry |
| Sessions | `Core/Session.php`, `Core/DbSessionHandler.php` | Sessions in MySQL (`ci_sessions`), locked per request, flash messages |
| Views | `Core/View.php`, `app/Views/*` | PHP templates with layouts and sections, escaped with `esc()` |
| Business logic | `app/Services/*` | Inspections, workflow, templates, gauges, numbering, reports, Google Sheets queue and worker |
| Setup | `Core/Installer.php`, `bin/qms.php` | Web setup page and command line installer, migrations (`database/migrations/*.sql`) |

Database changes for future versions go into `database/migrations/NNNN_description.sql`; `php bin/qms.php migrate`
applies each file once (recorded in `schema_migrations`). The reviewed schema itself is
`database/schema/qms_schema.sql`.
