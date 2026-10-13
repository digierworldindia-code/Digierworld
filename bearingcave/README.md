# BearingCave

**BearingCave** is a global B2B marketplace for surplus automotive inventory: bearings, OEM and aftermarket parts. Manufacturers list dead or slow-moving stock, buyers find genuine parts, and BearingCave runs verification, RFQs, inspection, logistics and administration.

| | |
|---|---|
| Backend | PHP 8.2+ with **CodeIgniter 4.7** (MVC + service classes) |
| Database | **MySQL 8.0+** (InnoDB, utf8mb4) |
| Frontend | **Bootstrap 5.3.3**, Bootstrap Icons and the Inter font, all **stored locally** (no CDN needed) |
| Login and roles | CodeIgniter **Shield** |
| Spreadsheets | PhpSpreadsheet (Excel/CSV import and export) |

---

## 1. What is in the package

`BEARINGCAVE_COMPLETE_WITH_VENDOR_AND_ALL_DEPENDENCIES.zip` contains one folder, `BEARINGCAVE_COMPLETE/`:

```
app/          application code: controllers, models, services, views, config, migrations, seeders
public/       the ONLY folder the web server may serve (index.php, .htaccess, assets/)
vendor/       installed PHP libraries. Composer is NOT needed on the server
writable/     cache, logs, sessions, private uploads (must be writable by PHP)
database/     bearingcave.sql: complete empty database (structure + reference data)
docs/         installation, deployment, database guide, manuals, test and dependency reports
tests/        automated tests (for developers)
tools/        maintenance scripts (packaging, diagrams)
composer.json / composer.lock   exact dependency versions
spark         command-line tool (migrations, admin creation, daily jobs)
.env.example  configuration template, copied to .env
```

**Included PHP libraries (`vendor/`, installed with `composer install --no-dev --prefer-dist --optimize-autoloader`):**
- codeigniter4/framework 4.7.4
- codeigniter4/shield 1.4.1
- codeigniter4/settings 2.4.0
- phpoffice/phpspreadsheet 5.10.0
- maennchen/zipstream-php 3.2.2
- markbaker/complex 3.0.2
- markbaker/matrix 3.0.1
- composer/pcre 3.4.0
- laminas/laminas-escaper 2.18.0
- psr/log 3.0.2
- psr/simple-cache 3.0.0

The exact list, and how it was verified, is in `docs/DEPENDENCY_VERIFICATION_REPORT.md`. You do **not** need Composer or internet access on the server.

## 2. Requirements

- **PHP 8.2 or newer** (tested on 8.3) with these extensions: `intl`, `mbstring`, `mysqli`, `gd`, `zip`, `fileinfo`, `openssl`, `json`, `xml`, `dom`, `ctype`, `iconv`, `zlib`
- **MySQL 8.0 or newer**. MariaDB is not supported, because the schema uses MySQL 8 CHECK constraints.
- **Apache** with `mod_rewrite` (the included `public/.htaccess` handles URLs) **or Nginx**
- About 25 MB of disk space for the code (including `vendor/`), plus uploads and logs
- HTTPS certificate for production

Check a server quickly: `php -v` and `php -m`. In cPanel, use **Select PHP Version** → Extensions.

## 3. Quick local installation (Windows/Mac/Linux, about 10 minutes)

1. **Unzip** the package anywhere, e.g. `C:\sites\BEARINGCAVE_COMPLETE` or `~/sites/BEARINGCAVE_COMPLETE`.
2. **Create the database** in MySQL (Workbench, phpMyAdmin or the command line):
   ```sql
   CREATE DATABASE bearingcave CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'bearingcave'@'localhost' IDENTIFIED BY 'choose-a-strong-password';
   GRANT ALL PRIVILEGES ON bearingcave.* TO 'bearingcave'@'localhost';
   ```
3. **Import the database:** in phpMyAdmin, open **Import** and choose `database/bearingcave.sql`. Or from the command line:
   ```bash
   mysql -u bearingcave -p bearingcave < database/bearingcave.sql
   ```
4. **Configure:** copy `.env.example` to `.env` and edit it (see §6). Then generate the encryption key:
   ```bash
   php spark key:generate
   ```
5. **Create the first administrator** (it asks for a password, at least 10 characters):
   ```bash
   php spark bearingcave:create-admin you@yourcompany.com
   ```
6. **Start it:** `php spark serve`, then open **http://localhost:8080**. Log in at `/login`; staff are taken to `/admin`.
7. *(Optional, never on production)* **Load clearly labelled sample data** to try every dashboard. Temporarily set `CI_ENVIRONMENT = development` in `.env`, then run:
   ```bash
   php spark db:seed DemoSeeder
   ```
   It creates `[SAMPLE]` companies and demo logins with **random passwords**, written to `writable/demo/credentials.txt`. Delete that file afterwards.

> Use **either** the SQL import (step 3) **or** migrations (`php spark migrate --all` followed by `php spark db:seed DatabaseSeeder`), **never both**. The SQL file already records the migrations as applied, so running `php spark migrate --all` afterwards does nothing harmful: it only applies future updates.

## 4. Installing on cPanel / shared hosting

1. **PHP:** cPanel → **Select PHP Version** (or MultiPHP Manager) → choose PHP 8.2 or newer. Enable `intl`, `mbstring`, `mysqli`, `gd`, `zip`, `fileinfo` and `openssl`.
2. **Upload:** File Manager → your home folder (e.g. `/home/USERNAME/`), **not** `public_html`. Upload the ZIP, then **Extract**. You get `/home/USERNAME/BEARINGCAVE_COMPLETE/`. You can rename it to `bearingcave`.
3. **Point the domain at `public/` (recommended):** cPanel → **Domains** → your domain → **Document Root** = `bearingcave/public`. Only `public/` is then visible on the web.

   **If your host does not let you change the document root:**
   - Copy the **contents** of `bearingcave/public/` (including `.htaccess`) into `public_html/`.
   - Edit `public_html/index.php`. Change
     `require FCPATH . '../app/Config/Paths.php';`
     to
     `require FCPATH . '../bearingcave/app/Config/Paths.php';`
   - Keep the application folder **outside** `public_html`. Never upload `app/`, `vendor/`, `writable/`, `database/` or `.env` into `public_html`.
4. **Database:** cPanel → **MySQL Databases**. Create a database and a user, and add the user to the database with **ALL PRIVILEGES**. Then open **phpMyAdmin**, select the database, choose **Import** and pick `database/bearingcave.sql`.
5. **Configure:** in File Manager, copy `.env.example` to `.env` (turn on *Show hidden files*) and edit it (§6). Use the full database name and user cPanel shows, e.g. `cpuser_bearingcave`.
6. **Encryption key and first admin, without SSH:**
   - If your host offers **Terminal** (cPanel → Terminal), run:
     ```bash
     cd ~/bearingcave
     php spark key:generate
     php spark bearingcave:create-admin you@yourcompany.com --password 'YourStrongPassword'
     ```
   - If there is no Terminal, use **Cron Jobs** → add a job that runs **once** a minute from now, with the command
     `cd /home/USERNAME/bearingcave && php spark bearingcave:create-admin you@yourcompany.com --password 'YourStrongPassword'`.
     Wait for it to run, then **delete the cron job** at once, because it contains the password. For the key, set `encryption.key = hex2bin:` followed by 64 random hex characters, e.g. the output of `openssl rand -hex 32` run on any computer. Keep a copy of it safe.
7. **Permissions:** `writable/` and its subfolders must be writable (folders 755 or 775, files 644). `public/uploads/` must be writable too.
8. **Scheduled jobs:** cPanel → **Cron Jobs** (see §8).
9. Open your domain. You should see the home page; log in at `/login`.

## 5. Installing on a VPS (Ubuntu + Apache or Nginx)

```bash
sudo apt install apache2 libapache2-mod-php8.3 php8.3-{intl,mbstring,mysql,gd,zip,xml,curl} mysql-server unzip
sudo unzip BEARINGCAVE_COMPLETE_WITH_VENDOR_AND_ALL_DEPENDENCIES.zip -d /var/www/
sudo mv /var/www/BEARINGCAVE_COMPLETE /var/www/bearingcave
sudo chown -R www-data:www-data /var/www/bearingcave/writable /var/www/bearingcave/public/uploads
```

Apache site (`/etc/apache2/sites-available/bearingcave.conf`), then `sudo a2enmod rewrite && sudo a2ensite bearingcave && sudo systemctl reload apache2`:

```apache
<VirtualHost *:80>
    ServerName www.bearingcave.com
    DocumentRoot /var/www/bearingcave/public
    <Directory /var/www/bearingcave/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Then add HTTPS (`sudo certbot --apache`). For **Nginx** and production hardening, see `docs/DEPLOYMENT.md`. The database steps are the same as §3 steps 2–5.

## 6. `.env` configuration

```ini
CI_ENVIRONMENT = production                    # development only on your own computer
app.baseURL = 'https://www.bearingcave.com/'   # your real address, with the trailing slash
app.forceGlobalSecureRequests = true           # production with HTTPS

database.default.hostname = localhost
database.default.database = bearingcave
database.default.username = bearingcave
database.default.password = your-db-password
database.default.port = 3306

encryption.key = hex2bin:....                  # created by php spark key:generate. BACK IT UP

email.protocol = smtp                          # when you have an SMTP account
email.SMTPHost = ...
```

**Never** publish `.env` or commit it anywhere. If the encryption key is lost, encrypted bank and ID numbers cannot be read.

## 7. Production settings checklist

- `CI_ENVIRONMENT = production`. This hides error details and disables the demo seeder and sandbox payments.
- HTTPS on, with `app.forceGlobalSecureRequests = true`.
- The document root is `public/` only.
- No sample data. `SELECT COUNT(*) FROM companies WHERE is_sample = 1` must return 0, and there must be no `writable/demo/` folder.
- Search engines stay off until launch: Admin → Platform settings → `seo.indexing_enabled`.
- Work through the open business decisions in `docs/06_CLIENT_DECISIONS_REGISTER.md`.

## 8. Scheduled jobs (cron)

```cron
15 2 * * *  cd /path/to/bearingcave && php spark bearingcave:daily  >> writable/logs/cron.log 2>&1
*/5 * * * * cd /path/to/bearingcave && php spark bearingcave:outbox >> writable/logs/cron.log 2>&1
```

The first handles expiries, renewal and document reminders, quotation expiry and score updates. The second sends queued emails, once email delivery is enabled.

## 9. Common errors and fixes

| What you see | Cause and fix |
|---|---|
| "Whoops! We seem to have hit a snag" | Production hides details. Read `writable/logs/log-YYYY-MM-DD.log`. |
| 500 error, nothing in the logs | `writable/` is not writable by PHP. Fix the permissions (§4.7 / §5). |
| "Unable to connect to the database" | Wrong `.env` database values, or MySQL is not running. cPanel names have a prefix (`cpuser_db`). |
| Page not found for every page except the home page | `mod_rewrite` is off or `AllowOverride` is not `All`. The `.htaccess` in `public/` must be used. |
| You see a file list, or the source code of `index.php` | The document root is wrong, or PHP is not enabled for the site. Point the root at `public/`. |
| Unstyled pages (no Bootstrap) | `app.baseURL` is wrong (http vs https, www, trailing slash). Assets are loaded from `baseURL/assets/...`. |
| "Class … not found" / "vendor/autoload.php missing" | The upload was incomplete. Re-extract the ZIP; `vendor/` must be next to `app/`. |
| "The action you requested is not allowed" | CSRF token expired. Reload the form. Also check that `app.baseURL` matches the address in the browser. |
| "Too many attempts" on login | Rate limit of 10 attempts per minute. Wait one minute. |
| MySQL error about CHECK or JSON during import | The server runs MariaDB or MySQL 5.7. MySQL 8 is required. |
| Encrypted fields show errors after moving servers | `encryption.key` changed. Restore the original key. |

**Dependency troubleshooting:** run `php spark` in the project folder. If it lists commands, `vendor/` and PHP are fine. If you ever need to reinstall the libraries, run `composer install --no-dev --prefer-dist --optimize-autoloader` (Composer 2) in the project folder. It installs exactly the versions in `composer.lock`. Check your server with `composer check-platform-reqs --no-dev`.

## 10. Backups

```bash
mysqldump --single-transaction --no-tablespaces -u USER -p bearingcave | gzip > bearingcave-$(date +%F).sql.gz
tar czf uploads-$(date +%F).tgz writable/uploads public/uploads
```

Keep `.env` (especially `encryption.key`) in a password manager. Store backups **outside** the web root and off the server. In cPanel, **Backup** → *Download a MySQL Database Backup* works as well.

## 11. Upgrading to a new version (keeps all your data)

1. Back up the database and uploads (§10).
2. Replace the code folders `app/`, `vendor/`, `public/assets/`, `spark` and `composer.*` with the new package. **Keep** `.env`, `writable/` and `public/uploads/`.
3. Run `php spark migrate --all`. Only new, additive database changes are applied; nothing is reset.
4. **Never** run `migrate:refresh`, `DemoSeeder` or import `bearingcave.sql` over a live database. Importing `bearingcave.sql` recreates empty tables.

Details: `docs/DATABASE_GUIDE.md`.

## 12. Documentation

| Read this | For |
|---|---|
| `docs/INSTALLATION.md` | step-by-step installation, local and server |
| `docs/DEPLOYMENT.md` | production: Nginx/Apache, security, cron, backups |
| `docs/DATABASE_GUIDE.md` | database structure, migrations, safe upgrades |
| `docs/ADMIN_MANUAL.md` / `docs/USER_MANUAL.md` | using the system (staff / buyers and suppliers) |
| `docs/DEPENDENCY_VERIFICATION_REPORT.md` | what is in `vendor/` and how the package was verified |
| `docs/TEST_REPORT.md` | real test results, including the clean-installation test |
| `docs/EXCEL_REQUIREMENTS_MAPPING.md`, `docs/DEVELOPMENT_PROGRESS.md` | the client workbook, row by row, and status |
| `docs/06_CLIENT_DECISIONS_REGISTER.md`, `docs/07_PENDING_INTEGRATIONS.md` | what still needs a business decision or provider |

## 13. For developers

```bash
composer install                  # includes PHPUnit (dev dependencies)
vendor/bin/phpunit                # 31 tests; uses a separate bearingcave_test database
node tests/e2e/responsive.mjs     # browser checks (Playwright), needs demo data
tools/package_complete.sh         # rebuilds the deployable ZIP with a production vendor/
```

`LICENSE` is the CodeIgniter appstarter's MIT licence, which covers the framework skeleton. The licence for the BearingCave application code is for the client to decide.
