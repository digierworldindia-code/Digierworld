# QMS – Paperless Quality Inspection System

A web application that replaces the paper inspection sheets of a manufacturing plant. Operators fill
**Setup Change Approval (SCA)**, **Product Parameter Inspection (PPI)** and **In-Process Inspection (IPR)** reports
on a tablet. Every reading is checked against LSL / USL immediately, reports are e-signed through
*Operator → Production → Quality → QA*, printed as A4 sheets that look like the paper formats, copied to
Google Sheets, and every action is recorded in an audit trail that nobody can edit.

**Stack:** PHP 8.2+ · CodeIgniter 4.7 · MySQL 8 · Bootstrap 5 · mPDF · Google Sheets API v4.
Runs on any cloud VM with Ubuntu 24.04 LTS (AWS, Azure, Google Cloud, DigitalOcean, Hetzner, Lightsail …).

---

## Contents

1. [What is in the package](#1-what-is-in-the-package)
2. [Server requirements](#2-server-requirements)
3. [Install on a cloud server (step by step)](#3-install-on-a-cloud-server-step-by-step)
4. [After installation: first configuration](#4-after-installation-first-configuration)
5. [Daily use](#5-daily-use)
6. [Google Sheets connection](#6-google-sheets-connection)
7. [Operations: backup, restore, update, logs](#7-operations-backup-restore-update-logs)
8. [Security](#8-security)
9. [Troubleshooting](#9-troubleshooting)
10. [Development and tests](#10-development-and-tests)
11. [Reference documentation](#11-reference-documentation)

---

## 1. What is in the package

| File | Use it for |
|---|---|
| `qms-deploy-1.0.0.zip` | **Installing on a server.** Complete application with all PHP libraries (`vendor/`) and the one-command installer. Nothing has to be downloaded from GitHub or Packagist on the server. |
| `qms-source-1.0.0.zip` | Developers: source code, tests and documentation without third-party libraries (run `composer install`). |
| `README.md` | This guide (also inside both zip files). |

Inside the deploy package (`qms-1.0.0/`):

```
app/            application code (controllers, services, views, migrations, seeders)
public/         the ONLY web root (index.php, CSS, JS, fonts, icons)
vendor/         PHP libraries (CodeIgniter, mPDF, Google API client)
writable/       logs, cache, uploads (created per server, never web-accessible)
database/       reviewed SQL schema, reference data, demo templates, DB account script
deploy/         install.sh, update.sh, nginx / PHP-FPM / MySQL / cron / backup configs
docs/           architecture documents (data model, workflow, security, UI)
spark           command line (migrations, admin creation, Sheets sync, maintenance)
.env.example    configuration keys (the installer writes the real .env)
```

---

## 2. Server requirements

| | Minimum | Recommended (≈ 15 machines, 3 shifts) |
|---|---|---|
| OS | Ubuntu 22.04 / **24.04 LTS** (64-bit) | Ubuntu 24.04 LTS |
| CPU / RAM | 1 vCPU / 2 GB | 2 vCPU / 4 GB |
| Disk | 20 GB SSD | 40 GB SSD (+ off-site backup storage) |
| Network | Public IP, ports **22, 80, 443** open | A DNS name, e.g. `qms.yourcompany.com` |

The installer adds everything else: nginx, PHP 8.3-FPM, MySQL 8, certbot (Let's Encrypt), ufw.
PHP extensions used: mysqli, mbstring, intl, curl, gd, xml, zip, fileinfo, json, openssl.

---

## 3. Install on a cloud server (step by step)

### 3.1 Create the server

Create a VM with **Ubuntu Server 24.04 LTS** at your cloud provider and an SSH key for login.
In the provider's firewall / security group allow inbound **TCP 22** (SSH, preferably only from your office IP),
**TCP 80** and **TCP 443** (HTTPS).

| Provider | Product | Where the firewall is |
|---|---|---|
| AWS | EC2 or Lightsail | Security group / Lightsail networking |
| Microsoft Azure | Virtual machine | Network security group |
| Google Cloud | Compute Engine | VPC firewall rules |
| DigitalOcean | Droplet | Cloud firewall |

### 3.2 Point a DNS name to the server

Create an **A record** such as `qms.yourcompany.com → <public IP of the VM>` at your DNS provider and wait until
`ping qms.yourcompany.com` shows the server's IP. (No DNS name yet? Use the IP with `--self-signed`, see 3.4.)

### 3.3 Upload and unpack the package

From your computer:

```bash
scp qms-deploy-1.0.0.zip ubuntu@qms.yourcompany.com:/tmp/
ssh ubuntu@qms.yourcompany.com
```

On the server:

```bash
sudo apt-get update && sudo apt-get install -y unzip
cd /tmp && unzip -q qms-deploy-1.0.0.zip && cd qms-1.0.0
```

### 3.4 Run the installer

```bash
# With a DNS name and a free Let's Encrypt certificate (recommended)
sudo bash deploy/install.sh --domain qms.yourcompany.com --email it@yourcompany.com

# Without a DNS name yet: self-signed certificate on the IP address (browsers warn once)
sudo bash deploy/install.sh --domain 203.0.113.10 --self-signed

# Add --demo to load demo parts, machines, gauges and the three sample templates for a trial
```

The installer takes 3–6 minutes. It:

1. installs nginx, PHP-FPM, MySQL 8, certbot and ufw;
2. creates the system user `qms`, copies the application to `/var/www/qms` with read-only code permissions;
3. creates the database `qms` and three MySQL accounts with **generated passwords**:
   `qms_migrator` (schema changes, locked after installation), `qms_app` (runtime, data only, no DDL, cannot delete
   reports or audit records), `qms_backup` (read-only dumps). Passwords are stored in
   `/etc/qms/secrets/database.env` (root only) and in `/var/www/qms/.env` (outside the web root, mode 0640);
4. creates all tables, the 27 integrity triggers and the reference data (roles, permissions, report types, shifts,
   units, methods, gauge types, settings);
5. creates the first **Super Admin** with a one-time password;
6. configures nginx (HTTPS only, HSTS, only `public/` reachable), a dedicated PHP-FPM pool, cron jobs
   (Google Sheets sync every minute, nightly housekeeping and **backup**), log rotation and the firewall.

At the end it prints:

```
QMS is ready.
  URL:            https://qms.yourcompany.com/
  Super Admin:    admin
  Password:       Xk4…   (one-time: you must change it at first login)
```

The one-time password is also saved in `/root/qms-first-login.txt` – delete that file after the first login.

### 3.5 First login

Open `https://qms.yourcompany.com`, log in as `admin` with the one-time password and choose your own password
(minimum 10 characters, not a common password, not containing your user name).

The installer can be run again at any time (it keeps data, passwords and certificates). Run
`sudo bash deploy/install.sh --help` for all options (`--app-dir`, `--db-name`, `--admin`, `--no-firewall`, …).

---

## 4. After installation: first configuration

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

If you installed with `--demo`, the demo data shows a complete example of all of this. **Replace or retire the
demo data before real use** (demo specifications are placeholders).

---

## 5. Daily use

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
  date, LSL/USL, gauges, observations, remarks and the names/dates of all signatures. Drafts print with a
  DRAFT watermark.
- **Dashboard and Reports**: today's / monthly totals, passed, failed, pending approval, out-of-spec, pending
  Google sync; daily, monthly, machine-wise, part-wise, operator-wise, rejection, out-of-spec and gauge-calibration
  reports with CSV export.

---

## 6. Google Sheets connection

MySQL is always the system of record. Google Sheets receives a reporting copy through a queue: if Google is
unavailable, jobs wait (`PENDING_SYNC`), retry with back-off and never lose data.

1. In the **Google Cloud console** create a project (e.g. `qms-sheets-sync`) and enable **Google Sheets API** only.
2. *IAM & Admin → Service accounts → Create*: name `qms-sheets-writer`, **no roles**. Open it → *Keys → Add key →
   JSON*. A key file downloads – treat it like a password.
3. Copy the key to the server and protect it (never into the application folder or Git):

   ```bash
   scp key.json ubuntu@qms.yourcompany.com:/tmp/google-sa.json
   sudo install -m 0440 -o root -g qms /tmp/google-sa.json /etc/qms/secrets/google-sa.json
   rm /tmp/google-sa.json            # and delete the downloaded copy on your computer
   ```

4. Create a spreadsheet in the **company** Google Drive. *Share* it with the service-account e-mail
   (`qms-sheets-writer@<project>.iam.gserviceaccount.com`) as **Editor** – with nobody else as Editor
   (management as Viewer). Copy the spreadsheet ID from its URL (`…/spreadsheets/d/<ID>/edit`).
5. In QMS: **Settings → Google Sheets** → paste the spreadsheet ID, choose the detail level (`ALL` readings,
   `OOS_ONLY` or `NONE`), switch sync **on**, press **Test connection**.
6. Check **Administration → Google Sheets sync**. Past reports can be queued with *Queue past reports*.

Command-line check: `sudo -u qms php /var/www/qms/spark sheets:test`

Tabs written: `Reports` (one row per report revision, updated in place) and monthly `Observations_YYYY_MM`
(one row per reading, written when a report is approved or rejected). With `ALL`, a detail spreadsheet holds
about 3–4 months for 15 machines (Google's 10 M cell limit): when the in-app capacity alert appears, create a new
spreadsheet, share it the same way and enter it as *Detail spreadsheet ID*.

---

## 7. Operations: backup, restore, update, logs

### Backups (automatic)

Every night at 01:30 UTC `/usr/local/sbin/qms-backup` writes a consistent database dump (with triggers) and the
uploaded files to `/var/backups/qms`, keeps 14 days and writes SHA-256 checksums. **Copy backups off the server**:
set `QMS_BACKUP_OFFSITE_CMD` in `/etc/qms/backup.conf`, for example

```bash
QMS_BACKUP_OFFSITE_CMD='aws s3 sync /var/backups/qms s3://your-bucket/qms --sse AES256'
QMS_BACKUP_OFFSITE_CMD='rclone copy /var/backups/qms remote:qms-backups'
```

Also enable your provider's VM snapshots. Manual backup: `sudo qms-backup`.

### Restore

```bash
sudo qms-restore /var/backups/qms/qms-db-20261003-013000.sql.gz /var/backups/qms/qms-files-20261003-013000.tar.gz
```

The restore asks for confirmation, shows a maintenance page meanwhile and verifies the checksum.

### Update to a new version

```bash
scp qms-deploy-1.1.0.zip ubuntu@qms.yourcompany.com:/tmp/
ssh ubuntu@qms.yourcompany.com
cd /tmp && unzip -q qms-deploy-1.1.0.zip && cd qms-1.1.0
sudo bash deploy/update.sh
```

The update switches on the maintenance page, takes a backup, installs the new code (keeping `.env`, uploads and
secrets), runs the database migrations with the temporarily unlocked `qms_migrator` account and reloads PHP.

### Logs and health

| What | Where |
|---|---|
| Application errors | `/var/www/qms/writable/logs/log-YYYY-MM-DD.log` (each error has a reference shown to the user) |
| PHP-FPM | `/var/log/qms/php-fpm.log` |
| Google Sheets worker / housekeeping / backup | `/var/log/qms/sheets-sync.log`, `maintenance.log`, `backup.log` |
| Web server | `/var/log/nginx/qms.access.log`, `qms.error.log` |
| Audit trail, login history | in the application (*Administration → Audit trail / Login history*) |
| Health check for uptime monitoring | `https://qms.yourcompany.com/health` (HTTP 200 when PHP and MySQL work) |

Maintenance page on / off: `sudo touch /var/lib/qms/maintenance` / `sudo rm /var/lib/qms/maintenance`.

Useful commands (run as the `qms` user):

```bash
sudo -u qms php /var/www/qms/spark sheets:sync            # send queued Google Sheets jobs now
sudo -u qms php /var/www/qms/spark qms:maintenance        # housekeeping
sudo -u qms php /var/www/qms/spark qms:create-admin --username admin2 --generate   # emergency admin
```

---

## 8. Security

| Requirement | How it is met |
|---|---|
| Passwords never stored in plain text | Argon2id `password_hash()`; password history; strength rules; lockout after repeated failures; login rate limiting |
| No credentials in source / Git | `.env` and `/etc/qms/secrets/*` are created on the server by the installer (git-ignored, mode 0640/0600); the Google key is only referenced by path |
| `.env` not web-accessible | Web root is `public/` only; nginx refuses dot-files and every PHP file except `index.php` |
| No user-supplied PHP / SQL, no raw SQL from forms | No eval/dynamic includes; all queries use the query builder / bound parameters; report filters are fixed, validated queries |
| Never trust client-side validation | Every value is validated and PASS/FAIL is computed again on the server; LSL/USL always come from the published template snapshot |
| OWASP | CSRF tokens on every form and AJAX call, output escaping, strict Content-Security-Policy (no inline script), secure/HttpOnly/SameSite cookies with `__Host-` prefix, session regeneration, idle and absolute timeouts, HTTPS only + HSTS, security headers, upload MIME checks and image re-encoding, CSV formula-injection protection |
| Least privilege | Separate MySQL accounts (app / migrator / backup), PHP runs as `qms`, code is read-only for PHP, `writable/` is not executable from the web |
| Tamper-proof records | Database triggers block changes to submitted readings, deleting reports, and any edit of audit / login / approval / calibration history – even if application code were wrong |
| Audit trail | User, employee ID, action, module, record, previous / new value, IP, user agent, date and time for every change |

**Go-live checklist:** change the admin password and delete `/root/qms-first-login.txt`; remove demo data;
restrict SSH to keys and your office IP; configure off-site backups and test one restore; enable
`unattended-upgrades` for OS security updates; keep the source repository private.

**Managed MySQL (e.g. AWS RDS, Azure Database for MySQL):** set `log_bin_trust_function_creators = 1` and
`time_zone = +00:00` in the parameter group, create the three accounts from `database/security/qms_db_users.sql`
(with `REQUIRE SSL` and the application server's address instead of `localhost`), and put the endpoint in `.env`
(`database.default.hostname`). Behind a load balancer set `app.proxyIPs` in `.env`.

---

## 9. Troubleshooting

| Problem | Solution |
|---|---|
| Let's Encrypt step fails | DNS name must point to this server and port 80 must be open in the cloud firewall. Re-run the installer, or use `--self-signed` first |
| `502 Bad Gateway` | PHP-FPM is not running: `sudo systemctl status php8.3-fpm`; see `/var/log/qms/php-fpm.log` |
| "Something went wrong. Reference: …" | Search the reference in `/var/www/qms/writable/logs/` |
| "This report has been submitted and can no longer be changed" | Correct behaviour: submitted data is locked. Return the report (approver) or create a revision (QA) |
| Wrong times on reports | Set the plant time zone in *Settings → Regional*; the server itself must stay on UTC |
| Gauge not selectable / submission blocked | Calibration expired: record a new calibration in *Gauges*, or switch off blocking in *Settings → Gauges* |
| Google sync `403` / `404` | Spreadsheet not shared with the service-account e-mail, wrong ID, or key file not readable (`ls -l /etc/qms/secrets`) |
| Google sync stays *pending* | Sync switched off, or cron not running: `grep sheets /var/log/qms/sheets-sync.log`; run `sheets:sync` manually |
| Locked out | Wait for the lockout period, or another admin unlocks the user (*Users → Unlock*); emergency: `qms:create-admin` |

---

## 10. Development and tests

```bash
# Requirements: PHP 8.2+ with the extensions above, Composer 2, MySQL 8
composer install                         # also publishes Bootstrap / icons into public/assets/vendor
cp .env.example .env                     # set CI_ENVIRONMENT = development and a local database account
mysql -uroot -e "SET GLOBAL log_bin_trust_function_creators = 1"   # or put it in my.cnf
php spark migrate --all
php spark db:seed ReferenceDataSeeder
php spark qms:create-admin --username admin --generate
php spark db:seed DemoSeeder             # optional demo data
php spark serve                          # http://localhost:8080
```

Automated tests (67 tests: PASS/FAIL rules, workflow, segregation of duties, revisions, In-Process sheets,
database integrity triggers, Google Sheets worker with an in-memory fake, authentication, CSRF, permissions,
security headers, schema parity with `database/schema/qms_schema.sql`):

```bash
# needs an empty database for tests, e.g. qms_tests, configured as database.tests.* in .env
vendor/bin/phpunit
```

Browser end-to-end check against a test / staging server (creates and approves a report):

```bash
cd tests/e2e && npm install && npx playwright install chromium
QMS_URL=https://staging.example.com QMS_PASSWORD='password-of-the-test-users' npm test
```

Schema verification on a scratch MySQL server: `php database/verify/verify_schema.php --with-grants`
(112 integrity checks).

---

## 11. Reference documentation

| # | Document | Content |
|---|---|---|
| 1 | [Project architecture](docs/architecture/01-project-architecture.md) | Stack, decisions, layers, transactions, time zones, deployment |
| 2 | [Folder structure](docs/architecture/02-folder-structure.md) | Project tree and conventions |
| 3 | [ER diagram](docs/architecture/03-er-diagram.md) | Data model, paper-field → table mapping |
| 4 | [MySQL schema](docs/architecture/04-database-schema.md) | Tables, PASS/FAIL rules, numbering, triggers, indexes |
| 5 | [Modules](docs/architecture/05-modules.md) | Module list and responsibilities |
| 6 | [Roles & permissions](docs/architecture/06-roles-permissions.md) | Role × permission matrix, segregation of duties |
| 7 | [Workflow](docs/architecture/07-workflow.md) | State machine, In-Process rounds, e-signatures |
| 8 | [Google Sheets integration](docs/architecture/08-google-sheets-integration.md) | Outbox, worker, back-off, capacity, key security |
| 9 | [Security architecture](docs/architecture/09-security-architecture.md) | Threat model, OWASP mapping, hardening |
| 10 | [UI pages](docs/architecture/10-ui-pages.md) | Pages, components, offline behaviour, print layouts |

Database artefacts: [`database/schema/qms_schema.sql`](database/schema/qms_schema.sql) (reviewed DDL),
[`qms_reference_data.sql`](database/schema/qms_reference_data.sql), [`qms_demo_templates.sql`](database/samples/qms_demo_templates.sql),
[`qms_db_users.sql`](database/security/qms_db_users.sql).

Version 1.0.0 · Proprietary – for internal use of the licensee.
