# QMS – Paperless Quality Inspection System (core PHP, no framework)

Tablet-friendly quality inspection system for machining plants, written in **plain PHP** (no framework, no
Composer), **MySQL 8** and **Bootstrap 5**:

- **Setup Change Approval (SCA)**, **Product Parameter Inspection (PPI)** and **In-Process Inspection (IPR,
  shifts A/B/C × inspection times)**. Any further report type is built from **templates** without programming:
  sections, parameters, LSL / USL, units, methods, gauges and the number of observations.
- **PASS / OUT OF SPEC** shows instantly while typing. The server re-checks every value with exact decimal
  arithmetic.
- **Gauges** with calibration due dates and certificates. Expired gauges are flagged or blocked.
- **Workflow:** Draft → Operator → Production → Quality → QA Approved.
  - Return and reject.
  - Password re-entry as an e-signature.
  - Segregation of duties.
  - **Revisions:** the approved original stays valid until the revision is approved.
- **Document numbers** like `SCA-2026-10-000001`.
- A4 **print and "Save as PDF"** laid out like the paper formats.
- **Dashboard** and 8 **reports** with CSV export.
- Complete **audit trail**.
- **Roles:** Super Admin, QA Admin, Quality Engineer, Production Engineer, Operator, Viewer.
- **Google Sheets** copy of every report, sent through a queue with retry. MySQL stays the system of record.
- **Autosave on the tablet.** Short Wi-Fi drops lose nothing.

**How it is built:**
- Every screen is an ordinary `.php` file (`login.php`, `inspection_edit.php`, `admin/users.php` …).
- Shared code is plain functions in `includes/*.php`.
- Every database query is a PDO prepared statement.
- Bootstrap and the icons are static files in `assets/`.
- There is no `vendor/` folder and no build step.
- No command line is needed on shared hosting: upload the files, open the site, fill in the setup page.

---

## Quick start (Hinglish)

1. Hosting par **PHP 8.1+** aur **MySQL 8** chahiye. MariaDB nahi chalega: phpMyAdmin ke home page par server version check karo.
2. cPanel → *MySQL Databases* mein ek **khaali database** aur user banao. User ko *ALL PRIVILEGES* do.
3. `qms-php-1.0.0.zip` ko File Manager se upload karke **Extract** karo, jaise `public_html/qms/` mein.
4. Browser mein site kholo, jaise `https://aapki-site.com/qms/`. **Setup page** khulega.
   - File Manager mein `storage/setup-key.php` kholo aur wahan likha key copy karo.
   - Database ki details, company ka naam aur admin ka password bharo.
   - **Install QMS** dabao.
5. cPanel → *Cron Jobs* mein section 3.4 wali do lines daalo.
6. Login karo, phir section 5 ke steps follow karo.

---

## Contents

1. [What is in the package](#1-what-is-in-the-package)
2. [Requirements](#2-requirements)
3. [Install on shared hosting (cPanel / Plesk)](#3-install-on-shared-hosting-cpanel--plesk)
4. [Install on a cloud server / VPS (Ubuntu)](#4-install-on-a-cloud-server--vps-ubuntu)
5. [After installation: first configuration](#5-after-installation-first-configuration)
6. [Daily use](#6-daily-use)
7. [Google Sheets connection](#7-google-sheets-connection)
8. [Operations: command line, backups, updates, logs](#8-operations-command-line-backups-updates-logs)
9. [Security](#9-security)
10. [Troubleshooting](#10-troubleshooting)
11. [Tests (for developers)](#11-tests-for-developers)
12. [Where to find what in the code](#12-where-to-find-what-in-the-code)

---

## 1. What is in the package

```
qms-php-1.0.0/
├── index.php  login.php  home.php …   one PHP file per screen (28 pages)
├── admin/        administration: users, roles, settings, audit trail, login history, Google sync
├── api/          three small JSON endpoints used by the tablet screen (autosave, submit, machine list)
├── assets/       CSS, JavaScript, Bootstrap 5.3 and Bootstrap Icons (static files)
├── includes/     shared PHP functions, page layout and view parts                     (private)
├── config/       config.php – written by the setup page; config.example.php           (private)
├── storage/      logs, sessions, uploaded logo and certificates – writable by PHP     (private)
├── database/     schema.sql (tables + integrity triggers), reference_data.sql, demo_data.sql  (private)
├── cron/         cron jobs and the command-line tool                                  (private)
├── tests/        PHP tests and browser tests                                          (private)
├── .htaccess     Apache / LiteSpeed rules (blocks the private folders)
├── .user.ini     PHP upload and memory limits for hosts running PHP as FastCGI / PHP-FPM
└── README.md     this guide
```

The **whole folder is the web root**:
- The private folders are blocked by `.htaccess` (Apache, LiteSpeed) or by the Nginx rules in section 4.
- None of their files does anything when opened directly: include files stop at once, and logs and keys are
  `.php` files that start with `exit`.

## 2. Requirements

| | Minimum |
|---|---|
| PHP | **8.1 or newer** (8.3 recommended). Extensions: `pdo_mysql`, `mbstring`, `json`, `fileinfo`, `gd`, `openssl`, and `curl` (only for Google Sheets). All are standard on cPanel hosting. |
| Database | **MySQL 8.0.16+**, because CHECK constraints and triggers are used. **MariaDB is not supported** (the setup page refuses it). Many shared hosts offer MariaDB: check the server version on the phpMyAdmin home page, or ask the host, before you buy. |
| Web server | Apache or LiteSpeed (any shared hosting), or Nginx + PHP-FPM. No URL rewriting is needed. |
| PHP limits | `upload_max_filesize` 8M, `post_max_size` 10M, `memory_limit` 256M. Already set by `.htaccess` / `.user.ini` where the host allows it. |
| Browser | Chrome, Edge, Safari or Firefox from the last 2 years. Android tablets and iPads are supported. |
| HTTPS | Strongly recommended. Free AutoSSL / Let's Encrypt is available on every cPanel host. |

## 3. Install on shared hosting (cPanel / Plesk)

About 15 minutes, no command line needed.

### 3.1 Create the database

cPanel → **MySQL® Databases**:

1. *Create New Database*, e.g. `youruser_qms`.
2. *Add New User*, e.g. `youruser_qms`, with a long generated password. Note it down.
3. *Add User To Database* → tick **ALL PRIVILEGES** → *Make Changes*.

For Plesk: *Databases → Add Database*, MySQL. Other control panels have a similar MySQL page.

The database must be **empty**.

### 3.2 Upload the files

1. **File Manager** → upload `qms-php-1.0.0.zip` → **Extract**.
2. Choose one layout:
   - **Subdomain (recommended).**
     - cPanel → **Domains → Create a New Domain**, e.g. `qms.yourcompany.com`. Its document root is,
       e.g., `/home/youruser/qms.yourcompany.com`.
     - Move everything *inside* `qms-php-1.0.0/` into that folder.
   - **Sub-folder of the main site.** Rename the extracted folder to `qms`, inside `public_html`. The address
     becomes `https://yourcompany.com/qms/`.
3. The hidden files `.htaccess` and `.user.ini` must come along. In File Manager → *Settings*, enable
   *Show Hidden Files* to see them.

Permissions:
- The File Manager defaults are right: folders 755, files 644.
- `config/` and `storage/` must be writable by PHP. On cPanel, PHP runs as your account, so nothing needs
  changing.

Turn on HTTPS before the next step: cPanel → **SSL/TLS Status → Run AutoSSL**.

### 3.3 Run the setup page

Open the site address in the browser, e.g. `https://qms.yourcompany.com/`. The **QMS setup** page appears.

1. **Server check.** Every red line must be fixed first.
   - If PHP is too old, choose PHP 8.1+ in cPanel → *MultiPHP Manager* (or *Select PHP Version*).
   - Yellow lines are warnings.
2. **Setup key.** In File Manager, open `storage/setup-key.php` (*View* or *Edit*) and copy the key after
   `Setup key:`. This proves that you, not a stranger, control the server. The file is deleted after setup.
3. **MySQL database.**
   - Host `localhost`, port `3306`.
   - Database name, user and password from step 3.1.
4. **Site and company.**
   - The site address, with `https://`.
   - The company name printed on reports.
   - The plant time zone.
   - Tick *Load demo data* only for a trial system.
5. **First administrator.**
   - User name, e.g. `admin`.
   - Password: at least 10 characters, with three of lower case / upper case / digits / symbols. Not a common
     password, and not containing the user name.

Press **Install QMS**. The setup:
- creates all tables and the 27 integrity triggers;
- loads the roles, permissions and settings;
- creates your administrator;
- writes `config/config.php`, readable only by your account;
- switches itself off for good (`storage/installed.lock`).

Then sign in.

> **"MySQL refused to create the integrity triggers" (error 1419 / 1227).** The host runs MySQL with binary
> logging and gives customers no trigger permission.
> - Ask support to set `log_bin_trust_function_creators = 1`, then press *Install QMS* again.
> - Or use a VPS (section 4).
>
> The triggers are part of the tamper protection and are not optional.

### 3.4 Cron jobs

cPanel → **Cron Jobs** → add the two lines below.
- Replace `/home/youruser/public_html/qms` with your folder.
- The PHP path is usually `/usr/local/bin/php`. For one PHP version use e.g. `/opt/cpanel/ea-php83/root/usr/bin/php`.

| Common setting | Command |
|---|---|
| Once per minute (`* * * * *`) | `/usr/local/bin/php /home/youruser/public_html/qms/cron/sheets_sync.php --quiet >> /home/youruser/public_html/qms/storage/logs/cron.log 2>&1` |
| Once per day (`15 2 * * *`) | `/usr/local/bin/php /home/youruser/public_html/qms/cron/maintenance.php >> /home/youruser/public_html/qms/storage/logs/cron.log 2>&1` |

- **The first job** sends reports to Google Sheets.
  - It is harmless while Sheets is not configured.
  - With `--quiet` it writes a line only when something was sent.
- **The second job** removes expired sessions, old idempotency keys and logs older than 90 days.
  - It never touches reports or the audit trail.

### 3.5 Check

- `https://your-site/config/config.php` and `https://your-site/database/schema.sql` must show an **error page**
  (403 / 404), never content.
- `https://your-site/health.php` answers `{"status":"ok"}`. You can use it for uptime monitoring.
- **Backups.**
  - Turn on the host's daily backups (cPanel *JetBackup* / *Backup*).
  - Download a full backup (database + files) regularly.
  - `storage/uploads` holds the logo and the calibration certificates.

## 4. Install on a cloud server / VPS (Ubuntu)

For AWS EC2 / Lightsail, Azure, Google Cloud, DigitalOcean, Hetzner and similar: any **Ubuntu 22.04 / 24.04**
server. 1–2 vCPU, 2 GB RAM and 20 GB disk are plenty for one plant.

1. Open ports **22, 80, 443** in the cloud firewall.
2. Point a DNS name (e.g. `qms.yourcompany.com`) to the public IP.
3. Copy the zip to the server:

```bash
scp qms-php-1.0.0.zip ubuntu@qms.yourcompany.com:/tmp/
ssh ubuntu@qms.yourcompany.com
```

### 4.1 Packages, database, files

```bash
sudo apt-get update
sudo apt-get install -y nginx mysql-server php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-gd php8.3-curl unzip
# Ubuntu 22.04 ships PHP 8.1: use php8.1-fpm php8.1-mysql … and /run/php/php8.1-fpm.sock below.

# Database and user (choose your own long password, e.g. from: openssl rand -base64 24)
sudo mysql <<'SQL'
CREATE DATABASE qms CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'qms'@'localhost' IDENTIFIED BY 'CHANGE-THIS-long-random-password';
GRANT ALL PRIVILEGES ON qms.* TO 'qms'@'localhost';
-- MySQL 8 logs binary by default; this lets a normal user create the integrity triggers
SET PERSIST log_bin_trust_function_creators = 1;
SQL

# Files: code owned by root (read-only for PHP); only config/ and storage/ writable by PHP
cd /tmp && unzip -q qms-php-1.0.0.zip
sudo mv qms-php-1.0.0 /var/www/qms
sudo chown -R root:root /var/www/qms
sudo chown -R www-data:www-data /var/www/qms/config /var/www/qms/storage

# PHP limits and no version banner
printf 'upload_max_filesize = 8M\npost_max_size = 10M\nmemory_limit = 256M\nexpose_php = Off\n' \
  | sudo tee /etc/php/8.3/fpm/conf.d/99-qms.ini
sudo systemctl restart php8.3-fpm
```

### 4.2 Web server: Nginx

Nginx ignores `.htaccess`, so the private folders are blocked here. Create `/etc/nginx/sites-available/qms`:

```nginx
server {
    listen 80;
    server_name qms.yourcompany.com;
    root /var/www/qms;
    index index.php;

    client_max_body_size 10m;
    server_tokens off;

    # Private folders and files are never served (Nginx ignores .htaccess).
    location ~ ^/(includes|config|storage|database|cron|tests)(/|$) { return 404; }
    location ~ /\. { return 404; }
    location = /robots.txt { }
    location ~* \.(sql|log|ini|sh|bak|swp|dist|md|txt|lock|json)$ { return 404; }

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~* \.(css|js|woff2?|png|jpe?g|svg|ico)$ {
        expires 7d;
        access_log off;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/qms /etc/nginx/sites-enabled/qms
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

# HTTPS with a free Let's Encrypt certificate (renews automatically)
sudo apt-get install -y certbot python3-certbot-nginx
sudo certbot --nginx -d qms.yourcompany.com --redirect -m it@yourcompany.com --agree-tos
```

**Apache instead of Nginx.** Install `apache2 libapache2-mod-php8.3` instead of `nginx php8.3-fpm`, then:
1. Run `sudo a2enmod rewrite headers`.
2. Create `/etc/apache2/sites-available/qms.conf`. The `.htaccess` files do the protection:

```apache
<VirtualHost *:80>
    ServerName qms.yourcompany.com
    DocumentRoot /var/www/qms
    <Directory /var/www/qms>
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog ${APACHE_LOG_DIR}/qms-error.log
    CustomLog ${APACHE_LOG_DIR}/qms-access.log combined
</VirtualHost>
```

3. Run `sudo a2ensite qms && sudo a2dissite 000-default && sudo systemctl reload apache2`.
4. Run `sudo apt-get install -y certbot python3-certbot-apache && sudo certbot --apache -d qms.yourcompany.com --redirect`.
5. Set `ServerTokens Prod` in `/etc/apache2/conf-enabled/security.conf`.

### 4.3 Setup page and cron

1. Open `https://qms.yourcompany.com/` and fill in the setup page as in section 3.3. Get the setup key with:

   ```bash
   sudo cat /var/www/qms/storage/setup-key.php
   ```

   - The database host is `localhost`, the database `qms` and the user `qms` with your password.
   - On Nginx the server check shows a yellow reminder about `.htaccess`. It is covered by the rules above.
2. Add the cron jobs for the web server user:

```bash
sudo crontab -u www-data -e
```

```
* * * * *  php /var/www/qms/cron/sheets_sync.php --quiet >> /var/www/qms/storage/logs/cron.log 2>&1
15 2 * * * php /var/www/qms/cron/maintenance.php >> /var/www/qms/storage/logs/cron.log 2>&1
```

3. Nightly database backup in root's crontab (`sudo crontab -e`), kept 14 days:

```
30 1 * * * mysqldump --single-transaction --triggers qms | gzip > /var/backups/qms-$(date +\%F).sql.gz && find /var/backups -name 'qms-*.sql.gz' -mtime +14 -delete
```

Also copy `/var/www/qms/storage/uploads` and `/var/www/qms/config/config.php`. Copy everything off the server
regularly (another region, S3, your office).

## 5. After installation: first configuration

Do these steps once, in this order, as the Super Admin.

| Step | Menu | What to enter |
|---|---|---|
| 1 | **Administration → Settings** | Company name, address and logo (printed on every report); document numbering; plant time zone and date format; back-dating limit; session timeout, lockout and password rules; approval options; expired-gauge blocking |
| 2 | **Settings → Approval workflow** | Report types: number prefix and approval stages. Default: SCA and PPI = Production → Quality → QA; IPR = Quality → QA |
| 3 | **Quality setup → Other masters** | Departments, shifts (A/B/C timings), units, inspection methods (GO/NO GO, Visual, TPG, DVC …), gauge types |
| 4 | **Quality setup → Machines / Parts** | Machine numbers (with PM due date) and part numbers / names / drawings |
| 5 | **Other masters → Employees** | Every operator, setter and engineer, with employee ID and skill level |
| 6 | **Quality setup → Gauges** | Gauge IDs, type, range, calibration date and due date, certificates (PDF or image) |
| 7 | **Quality setup → Parameter library** | Reusable parameters (Appearance, Throat Diameter, Chuck Pressure …) |
| 8 | **Quality setup → Templates** | One template per report type and part family: sections; parameters with LSL / USL, unit, method, gauge type and number of observations. Map it to parts and machines, then **Publish** |
| 9 | **Administration → Users** | A login for each person, linked to the employee, with one role. Custom roles are made in **Roles & permissions**. New users get a one-time password that must be changed at first login |

With demo data, the system already contains a complete example of all of this. **Replace or retire the demo data
before real use:** the demo specifications are placeholders.

## 6. Daily use

- **Operators** (tablet):
  - *New inspection* → report type → part → machine → date / shift. The right template loads automatically.
  - Values turn green (PASS) or red (OUT OF SPEC) while typing. Gauges with an expired calibration are flagged.
  - The draft saves itself every few seconds and survives a lost Wi-Fi connection: values stay on the tablet and
    are sent when the network returns.
  - *Submit* is the operator's signature.
- **In-Process sheets:**
  - There is one sheet per part, machine and production day, shared by Shifts A/B/C.
  - Each operator adds an inspection time, records readings, lot status and quantities, and signs the column.
  - The quality engineer verifies each column.
- **Engineers / QA:**
  - *Approvals* lists everything waiting for you.
  - Open a report → **Verify** (Production / Quality stage) or **Approve** (QA stage), **Return** (with remarks,
    back to the operator) or **Reject**. Each action asks for your password again.
  - Nobody can approve a report they submitted, and one person signs only one stage.
- **Corrections after approval:**
  - *Create revision* (QA).
  - The approved original stays valid until the revision is approved, then it is marked *Superseded*.
- **Print / PDF:**
  - *Print* opens an A4 page laid out like the paper format. It shows the document number, revision,
    LSL / USL, gauges, observations, remarks and the names / dates of all signatures.
  - **Print / Save as PDF** opens the browser's print dialog. Choose *Save as PDF* as the printer for a PDF file.
  - Drafts print with a DRAFT watermark.
- **Dashboard and Reports:**
  - The dashboard shows totals, passed, failed, pending approval, out-of-spec readings and pending Google sync for
    today, a day or a month, with a trend chart and machine / gauge alerts.
  - Reports: daily, monthly, machine-wise, part-wise, operator-wise, rejection, out-of-spec and gauge
    calibration due, all with CSV export.

## 7. Google Sheets connection

MySQL is always the system of record. Google Sheets receives a reporting copy through a queue:
- If Google is unreachable, jobs wait (`PENDING_SYNC`), retry with back-off and are never lost.
- QMS talks to the Sheets API with PHP's cURL and a signed service-account token. No Google library is needed.

1. In the **Google Cloud console**, create a project (e.g. `qms-sheets-sync`) and enable **Google Sheets API**
   only.
2. Create the service account and its key:
   - *IAM & Admin → Service accounts → Create*: name `qms-sheets-writer`, **no roles**.
   - Open it → *Keys → Add key → JSON*. A key file downloads. Treat it like a password.
3. Put the key on the server **outside the web folders**:
   - **Shared hosting:**
     - Create `/home/youruser/qms-secrets/` (not inside `public_html`).
     - Upload the key as `google-sa.json` and set its permissions to `0400`.
   - **VPS:**
     - `sudo mkdir -p /etc/qms`
     - `sudo install -m 0440 -o root -g www-data key.json /etc/qms/google-sa.json`

   Then point `config/config.php` to it, for example:
   `'google_credentials_file' => '/home/youruser/qms-secrets/google-sa.json',`

   Edit the file with File Manager, or with `sudo nano /var/www/qms/config/config.php` on a VPS.

   Delete the downloaded copy on your computer afterwards. Never put the key into the QMS folder or into Git.
4. Prepare the spreadsheet:
   - Create a spreadsheet in the **company** Google Drive.
   - *Share* it with the service-account e-mail (`qms-sheets-writer@<project>.iam.gserviceaccount.com`) as
     **Editor**. Nobody else should be an Editor; management can be Viewers.
   - Copy the spreadsheet ID from its address (`…/spreadsheets/d/<ID>/edit`).
5. In QMS, open **Administration → Settings → Google Sheets**:
   - Paste the spreadsheet ID.
   - Choose the observation detail: all readings, out-of-spec only, or none.
   - Switch sync **on**.
   - Press **Test connection**.
6. Watch **Administration → Google Sheets sync**. Past reports can be queued with *Queue past reports*.

Command-line check: `php cron/tool.php sheets-test` (read-only).

**Tabs written:**
- `Reports`: one row per report revision, updated in place.
- `Observations_YYYY_MM`, one per month: one row per reading, written when a report is approved or rejected.

**Capacity.** With all readings, one spreadsheet holds about 3–4 months for 15 machines (Google's limit is
10 million cells). When the capacity alert appears:
1. Create a new spreadsheet.
2. Share it the same way.
3. Enter it as *Detail spreadsheet ID*.

## 8. Operations: command line, backups, updates, logs

### Command line tool

Optional. Shared hosting needs it only for cron. On a VPS run it as the web user:
`sudo -u www-data php /var/www/qms/cron/tool.php status`.

```bash
php cron/tool.php status                    # version, database, Google Sheets queue
php cron/tool.php sheets-test               # Google key and spreadsheet access (read-only)
php cron/tool.php down                      # maintenance page on (users see "being updated")
php cron/tool.php up                        # maintenance page off
php cron/tool.php unlock <username>         # remove a login lockout
php cron/tool.php reset-password <username> # new one-time password, must be changed at next login
php cron/sheets_sync.php                    # send queued Google Sheets jobs now, with details
php cron/maintenance.php                    # housekeeping now
```

Without a terminal, maintenance mode is the file `storage/maintenance.flag`: create it in File Manager to switch
it on, and delete it to switch it off.

### Backups

- **Shared hosting:**
  - Use the host's backup tool (database + home folder) and download copies regularly.
  - A phpMyAdmin export must include the triggers: *Export → Custom → "Add CREATE TRIGGER statement"*.
- **VPS:** see the nightly `mysqldump` line in section 4.3.
- **To restore:**
  1. Import the dump into an empty database.
  2. Restore `storage/uploads` and `config/config.php`.

### Update to a new version

1. Take a backup.
2. Switch maintenance on.
3. Upload the new files over the old ones, **except** `config/config.php` and `storage/`.
4. If the release notes list a database change, import the SQL file they name.
5. Switch maintenance off.

### Logs and health

| What | Where |
|---|---|
| Application errors | `storage/logs/log-YYYY-MM-DD.php`. Every error page shows a reference to search for here |
| Cron output | `storage/logs/cron.log` |
| Audit trail, login history | In the application: *Administration → Audit trail / Login history* |
| Google Sheets worker | *Administration → Google Sheets sync* |
| Health check | `https://your-site/health.php`: HTTP 200 `{"status":"ok"}` when PHP and MySQL work |

## 9. Security

| Requirement | How it is met |
|---|---|
| Passwords never stored in plain text | `password_hash()` with Argon2id (bcrypt where Argon2 is missing). Password history and strength rules, including a list of 10,000 common passwords. Lockout after repeated failures; login rate limit per IP address |
| No database credentials in source files / Git | The setup page writes `config/config.php` on the server (mode 0600). It is git-ignored; the package only contains `config.example.php` without secrets. The Google key is referenced only by its path, outside the web folders |
| Private files not web-accessible | `includes/`, `config/`, `storage/`, `database/`, `cron/`, `tests/` are blocked by `.htaccess` or the Nginx rules. Include files output nothing when opened directly, and logs and keys are `.php` files starting with `exit`. Cron and test scripts refuse to run from the web. No directory listings |
| No user-supplied PHP / SQL | No `eval` and no include paths from input. Every value reaches MySQL as a bound parameter (PDO, multi-statements off). Table and column names come only from fixed lists in the code; sort orders are whitelisted; reports are fixed queries |
| Never trust client-side validation | Every value is validated on the server. PASS / FAIL is computed again there with exact decimals, and LSL / USL always come from the published template |
| OWASP | CSRF token on every form and fetch request, plus a same-origin check. All output is escaped. Strict Content-Security-Policy (no inline script), `X-Frame-Options: DENY`, `nosniff`, Referrer and Permissions policies, HSTS on HTTPS. HttpOnly / SameSite cookies, with Secure and the `__Host-` prefix on HTTPS. New session id at login; idle and absolute timeouts; a password change signs out other sessions. Uploads: content-type check, size and pixel limits, images re-encoded, random file names, downloads only through permission checks. CSV formula-injection protection. Generic error pages with a reference id |
| Tamper-proof records | 27 database triggers block changes to submitted readings and approved reports, deleting reports, and any edit of the audit, login, approval and calibration history. This holds even if application code were wrong |
| Exactly-once actions | Submit and approval requests carry an idempotency key, so a double tap or a retry after a network drop never acts twice |
| Audit trail | User, employee ID, action, module, record, previous and new value, IP, browser, date and time for every change |

**Go-live checklist:**
- Use a strong administrator password.
- Retire the demo data.
- Check that `https://your-site/config/config.php` shows an error.
- Keep `'environment' => 'production'` in `config/config.php`.
- Enable HTTPS.
- Set up off-site backups and test one restore.
- Keep PHP and MySQL updated.

## 10. Troubleshooting

| Problem | Solution |
|---|---|
| Setup: "MySQL refused to create the integrity triggers" | See the note in 3.3 (`log_bin_trust_function_creators`). Then run the setup again |
| Setup: "This database already contains other tables" | Create a new, empty database for QMS |
| Setup: a red "Writable folder" line | Make `config/` and `storage/` writable by PHP (VPS: `chown -R www-data:www-data config storage`) |
| `500` error without details | Look in `storage/logs/` and in the host's error log. Check the PHP version (8.1+) |
| "Something went wrong. Reference: …" | Search for the reference in `storage/logs/` |
| Forms are refused ("did not come from this application"), or you are logged out at once | `base_url` in `config/config.php` must match the address in the browser exactly: `https` vs `http`, with or without `www`, and the sub-folder |
| Page looks unstyled | The `assets/` folder is missing or incomplete. Upload it again |
| "The upload is larger than the server allows" | Raise `upload_max_filesize` / `post_max_size` (cPanel → *MultiPHP INI Editor*) |
| Wrong times on reports | Set the plant time zone in *Settings → Regional* |
| Gauge not selectable, or submission blocked | The calibration has expired. Record a new calibration, or switch off blocking in *Settings → Gauges* |
| Google sync `403` / `404` | The spreadsheet is not shared with the service-account e-mail, the ID is wrong, or the key path / permissions are wrong. Run `php cron/tool.php sheets-test` |
| Google sync stays *pending* | Sync is switched off, or the cron job is missing. Run `php cron/sheets_sync.php` once and read the output |
| A user is locked out | Wait for the lockout period, or another administrator unlocks the user (*Users → Unlock*). Or run `php cron/tool.php unlock <username>` |
| Administrator password forgotten | Run `php cron/tool.php reset-password admin`. It prints a one-time password |

## 11. Tests (for developers)

```bash
# Local web server (PHP's built-in server ignores .htaccess - use it only on your own computer)
php -S 127.0.0.1:8080 -t .
# open http://127.0.0.1:8080/ and run the setup page against a local MySQL 8 database

# 28 PHP tests on a separate, EMPTY test database (its name must contain "test"; it is reset on every run)
QMS_TEST_DB_NAME=qms_test QMS_TEST_DB_USER=qms_test QMS_TEST_DB_PASS='...' php tests/run.php

# Browser tests (Node.js 18+, Playwright with Chromium)
cd tests/e2e && npm install
QMS_URL=http://127.0.0.1:8080 QMS_DB_NAME=... QMS_DB_USER=... QMS_DB_PASS=... QMS_ADMIN_PASSWORD='...' \
  QMS_SETUP_KEY=$(grep -o '[a-f0-9]\{20\}' ../../storage/setup-key.php) node setup-flow.mjs   # fresh copy, empty DB
QMS_URL=http://127.0.0.1:8080 QMS_ADMIN_PASSWORD='...' QMS_PASSWORD='...' node full-flow.mjs
```

The tests cover these areas:
- **PHP tests:**
  - decimals, validation, numbering;
  - spec evaluation and templates;
  - the workflow rules: segregation of duties, idempotency, revisions;
  - In-Process rounds;
  - the database triggers;
  - security: passwords, CSRF, permissions;
  - the Google Sheets queue against a fake Google server.
- **Browser test** (`full-flow.mjs`):
  - creates users with one-time passwords;
  - runs an SCA through all approval stages and an In-Process sheet;
  - prints an A4 PDF and opens every management and administration page;
  - checks browser errors and access control.

## 12. Where to find what in the code

| You want to change … | Look in |
|---|---|
| A screen | The `.php` file with that name in the main folder, `admin/` or `api/`. Each starts with `require __DIR__ . '/includes/init.php';` and a permission check such as `require_permission('gauge.view')` |
| Page layout, menu | `includes/layout/` (header, menu `nav.php`, footer) |
| Database access | `includes/db.php`: `db_all()`, `db_row()`, `db_value()`, `db_insert()`, `db_update()`, `db_transaction()`. Always with `?` placeholders |
| Login, permissions, password rules | `includes/auth.php` |
| Validation rules | `includes/validate.php` (e.g. `'required|max_length[100]'`) |
| Inspection entry, autosave, In-Process rounds | `includes/inspections.php`, `assets/js/inspection-form.js` |
| Workflow (submit, approve, return, reject, revise) | `includes/workflow.php` |
| PASS / FAIL logic | `includes/spec.php` (server) and `assets/js/inspection-form.js` (instant display) |
| Templates | `includes/templates.php`, `template.php` |
| Printout | `includes/print.php`, `includes/views/print/`, `assets/css/print*.css` |
| Reports and dashboard | `includes/reports.php`, `includes/dashboard.php` |
| Google Sheets | `includes/sheets.php` (queue), `includes/sheets_worker.php` (API client and worker) |
| Tables and triggers | `database/schema.sql` |
| Roles, permissions, default settings | `database/reference_data.sql` |
