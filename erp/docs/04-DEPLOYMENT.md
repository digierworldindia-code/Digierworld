# DGR Office — Deployment guide

This guide covers **XAMPP (Windows)**, **local Apache (Linux/macOS)**,
**cPanel shared hosting** and an **Ubuntu VPS**. Every environment uses
the same steps; only the web-server and cron set-up differ.

> Golden rules
> 1. The web server's document root is the **`public/`** folder — never the
>    project folder. `.env`, `app/`, `vendor/` and `writable/` (uploads,
>    backups, Google credentials) must not be reachable from a browser.
> 2. `.env` and the **encryption key** are secrets. Back up the key
>    separately from database backups: without it, encrypted bank account
>    numbers, CIF and employee bank details cannot be read.
> 3. Run `php spark dgr:diagnostics` (or open Admin → Diagnostics) after
>    every install or upgrade; it must show no failures.

---

## 1. What you deploy

| Option | Contents | When |
| --- | --- | --- |
| **Release archive** `dgr-office-<version>.zip` | Application + production `vendor/` (no dev tools), `.env.example`, docs, empty `writable/` | Shared hosting / no Composer on the server |
| **Git checkout** of `erp/` + `composer install --no-dev --optimize-autoloader` | Same, with dependencies resolved from `composer.lock` | VPS / developer machines |

Both are identical at runtime: `composer.lock` pins every dependency, and
the release archive is built from that lock file by
`scripts/build-release.sh` (see §10).

## 2. Common steps (all environments)

1. **Create the database and a dedicated user** (MySQL 8, utf8mb4):

   ```sql
   CREATE DATABASE dgr_office CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'dgr_office_user'@'localhost' IDENTIFIED BY 'a-long-random-password';
   GRANT ALL PRIVILEGES ON dgr_office.* TO 'dgr_office_user'@'localhost';
   ```

   The app needs `CREATE/ALTER/INDEX/REFERENCES` for migrations and
   `SELECT/INSERT/UPDATE/DELETE/LOCK TABLES` at runtime.

2. **Configure** — copy `.env.example` to `.env` (same folder as `spark`)
   and set at least:

   | Key | Value |
   | --- | --- |
   | `CI_ENVIRONMENT` | `production` (use `development` only on your own PC) |
   | `app.baseURL` | Full public URL with trailing slash, e.g. `https://office.example.in/` |
   | `app.appTimezone` | `Asia/Kolkata` (the DB session time zone follows it automatically) |
   | `database.default.*` | Host, database, user, password from step 1 |
   | `encryption.key` | Generate with `php spark key:generate` (writes it into `.env`) |

   **PHP settings** (php.ini / host panel): `upload_max_filesize = 20M`,
   `post_max_size = 25M`, `memory_limit = 256M`. The defaults (often 2M)
   are smaller than the app's own limits (CV 5 MB, documents 8 MB,
   spreadsheets 10 MB), and Admin → Diagnostics warns about them.

3. **Create tables and base data**: `php spark dgr:install` (idempotent;
   migrations are tracked, seeders use `INSERT IGNORE`).
4. **Create the owner login**:
   `php spark dgr:create-owner --email owner@your-domain.in --name "Owner Name"`
   (without `--password` a temporary password is printed once and must be
   changed at first sign-in).
5. **Point the document root at `public/`** (per environment below).
6. **Add the cron jobs** (§6).
7. **Sign in → Settings → Billing setup** (`/settings/setup`) and enter
   the company identity, GSTIN, bank accounts, default payment bank, the
   official logo (original file), signature, stamp and the invoice series'
   next numbers. Until the identity is complete, tax documents can be
   drafted but not finalised.
8. Optional: SMTP (§8), Google Sheets ([05-GOOGLE-SHEETS.md](05-GOOGLE-SHEETS.md)),
   AI review ([06-AI-BOUNDARY.md](06-AI-BOUNDARY.md)).
9. `php spark dgr:diagnostics` → all green (warnings for optional
   integrations you have not configured are expected).

## 3. XAMPP on Windows (single PC / office LAN)

1. Install a XAMPP build that ships **PHP 8.3 or newer** (check with
   `C:\xampp\php\php.exe -v`). In `C:\xampp\php\php.ini` make sure these
   lines are **not** commented out: `extension=intl`, `extension=gd`,
   `extension=zip`, `extension=sodium`, `extension=mysqli`,
   `extension=fileinfo`, `extension=curl`, `extension=openssl`,
   `extension=mbstring`, and set the PHP limits from §2. Restart Apache.
2. **Database:** XAMPP bundles **MariaDB**, which is *not tested* with this
   app. Install **MySQL 8 Community Server** (Windows installer) and use
   it instead, or stop XAMPP's MySQL module and run MySQL 8 on port 3306.
3. Unzip the release to `C:\xampp\dgr-office` (outside `htdocs`).
4. Add a virtual host — `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

   ```apache
   <VirtualHost *:80>
       ServerName dgr-office.local
       DocumentRoot "C:/xampp/dgr-office/public"
       <Directory "C:/xampp/dgr-office/public">
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```

   Add `127.0.0.1 dgr-office.local` to
   `C:\Windows\System32\drivers\etc\hosts` (as Administrator), make sure
   `LoadModule rewrite_module` is enabled in `httpd.conf`, restart Apache,
   and set `app.baseURL = 'http://dgr-office.local/'`.
5. Run the common steps from a command prompt in `C:\xampp\dgr-office`
   using `C:\xampp\php\php.exe spark …`.
6. **Scheduler:** Task Scheduler → Create Task → trigger “Daily, repeat
   every 1 minute for 1 day”, action
   `C:\xampp\php\php.exe` with arguments `spark dgr:cron` and
   “Start in” `C:\xampp\dgr-office`. Add a second daily task for
   `spark dgr:backup --files --keep 14`.

## 4. Local Apache on Linux / macOS

```apache
<VirtualHost *:80>
    ServerName dgr-office.local
    DocumentRoot /var/www/dgr-office/public
    <Directory /var/www/dgr-office/public>
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog ${APACHE_LOG_DIR}/dgr-office-error.log
    CustomLog ${APACHE_LOG_DIR}/dgr-office-access.log combined
</VirtualHost>
```

`sudo a2enmod rewrite headers && sudo a2ensite dgr-office && sudo systemctl reload apache2`.
The bundled `public/.htaccess` handles front-controller routing and
passes the `Authorization` header through (needed by the AI service-token
API).

For quick local trials without Apache: `php spark serve --port 8080` and
`app.baseURL = 'http://localhost:8080/'` (development only).

## 5. cPanel shared hosting

1. **PHP:** *MultiPHP Manager* → set the domain/subdomain to **PHP 8.3**;
   *Select PHP Version → Extensions* → enable `intl`, `gd`, `zip`,
   `sodium`, `mysqli`, `fileinfo`, `curl`, `mbstring`, `xml`, `dom`,
   `xmlwriter`, `iconv`; *MultiPHP INI Editor* → set the PHP limits from §2.
2. **Database:** *MySQL® Databases* → create the database and user, add
   the user with **ALL PRIVILEGES**. cPanel prefixes names
   (`cpuser_dgroffice`); use the full prefixed names in `.env`. Confirm the
   server runs MySQL 8 (*phpMyAdmin* shows the version).
3. **Upload:** *File Manager* → upload the release zip to your **home
   directory** (e.g. `/home/cpuser/`), extract to `/home/cpuser/dgr-office`.
   Do **not** extract inside `public_html`.
4. **Document root:** *Domains* (or *Subdomains*) → create
   `office.your-domain.in` with document root
   `/home/cpuser/dgr-office/public`.
   *If your host only allows `public_html`*: copy the **contents** of
   `dgr-office/public/` into `public_html/`, then edit
   `public_html/index.php` and change
   `require FCPATH . '../app/Config/Paths.php';` to
   `require FCPATH . '../dgr-office/app/Config/Paths.php';`
   (the application itself stays outside `public_html`).
5. **Commands:** use *Terminal* (or SSH) in `~/dgr-office`. The PHP 8.3
   CLI is usually `/opt/cpanel/ea-php83/root/usr/bin/php` (or
   `/usr/local/bin/php`; check with `php -v`). If your plan has neither
   Terminal nor SSH, add each common-step command as a **temporary** cron
   job that runs once (e.g.
   `cd ~/dgr-office && /opt/cpanel/ea-php83/root/usr/bin/php spark dgr:install >> writable/logs/setup.log 2>&1`),
   read the log in File Manager, then delete the job. The owner command
   prints the temporary password into that log — delete the log afterwards.
6. **SSL:** *SSL/TLS Status* → run AutoSSL (or install Let's Encrypt).
   Then set in `.env`: `app.baseURL = 'https://office.your-domain.in/'`,
   `app.forceGlobalSecureRequests = true`, `cookie.secure = true`.
7. **Cron:** *Cron Jobs* → “Once per minute”:
   `cd /home/cpuser/dgr-office && /opt/cpanel/ea-php83/root/usr/bin/php spark dgr:cron >> writable/logs/cron.log 2>&1`
   and a daily backup job (§7). Set the cron e-mail to your address once to
   see errors, then clear it.

## 6. Ubuntu 24.04 VPS (Nginx + PHP-FPM)

```bash
sudo apt update
sudo apt install nginx mysql-server php8.3-fpm php8.3-cli php8.3-mysql php8.3-intl php8.3-mbstring \
     php8.3-curl php8.3-gd php8.3-zip php8.3-xml php8.3-bcmath unzip certbot python3-certbot-nginx
sudo mysql_secure_installation
sudo mkdir -p /var/www/dgr-office && sudo unzip dgr-office-<version>.zip -d /var/www/
# (or git clone + composer install --no-dev --optimize-autoloader)
sudo chown -R root:www-data /var/www/dgr-office
sudo chown -R www-data:www-data /var/www/dgr-office/writable
sudo -u www-data cp /var/www/dgr-office/.env.example /var/www/dgr-office/.env   # then edit
# PHP limits from §2 in /etc/php/8.3/fpm/php.ini, then: sudo systemctl restart php8.3-fpm
```

`/etc/nginx/sites-available/dgr-office`:

```nginx
server {
    listen 80;
    server_name office.your-domain.in;
    root /var/www/dgr-office/public;
    index index.php;
    client_max_body_size 20M;

    location / { try_files $uri $uri/ /index.php$is_args$args; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/dgr-office /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d office.your-domain.in      # SSL + auto-renewal
cd /var/www/dgr-office
sudo -u www-data php spark key:generate
sudo -u www-data php spark dgr:install
sudo -u www-data php spark dgr:create-owner --email owner@your-domain.in --name "Owner Name"
sudo -u www-data php spark dgr:diagnostics
sudo crontab -u www-data -e      # add the cron lines below
```

Run every `spark` command as the web-server user (`www-data`) so files it
creates in `writable/` stay readable by PHP-FPM.

## 7. Cron, file permissions and backups

**Cron** (Linux paths; adapt the PHP binary):

```cron
* * * * *  cd /var/www/dgr-office && php spark dgr:cron >> writable/logs/cron.log 2>&1
15 2 * * * cd /var/www/dgr-office && php spark dgr:backup --files --keep 14 >> writable/logs/backup.log 2>&1
```

`dgr:cron` holds a lock (`writable/cron.lock`) so runs never overlap. Each
task is recorded in `cron_runs` and shown under Admin → Diagnostics, which
warns if cron has not run recently.

**Permissions**

| Path | Owner | Mode |
| --- | --- | --- |
| project files (`app/`, `vendor/`, `public/`, `spark`) | deploy user, group web server | dirs `0755`, files `0644` |
| `writable/` and everything under it | web-server user | dirs `0750`, files `0640` (the app writes uploads as `0640`) |
| `.env` | deploy user, group web server | `0640` |
| `writable/credentials/` (Google service-account JSON) | web-server user | dir `0700`, file `0600` |

**Backups** — `php spark dgr:backup --files` writes
`writable/backups/dgr-YYYYMMDD-HHMMSS.sql.gz` (all tables, UTC timestamps,
`DROP TABLE IF EXISTS` + data) and `…-files.zip` (private uploads: logo,
signatures, CVs, payslips, statements). `--keep N` prunes older sets.
Owners can also create/download backups in Admin → Backups (downloads are
audited). Copy backups **off the server** regularly (they contain personal
and financial data — store them encrypted).

**Restore** (tested: all tables, timestamps and audit-hash chain identical):

```bash
gunzip -c writable/backups/dgr-20261018-021500.sql.gz | mysql -u dgr_office_user -p dgr_office
unzip -o writable/backups/dgr-20261018-021500-files.zip -d writable/uploads/
```

Restore onto a server that has the **same `encryption.key`**.

## 8. SMTP (optional)

Set `email.*` in `.env` (host, port 587 + `tls` or 465 + `ssl`, user,
password, from address). Credentials live only in `.env`; the browser
never stores or shows them. Test with **Settings → Integrations → Send
test email** (the result is audited). Without SMTP, reminders still appear
as in-app notifications; email 2FA and magic links stay off.

## 9. Going live checklist

- [ ] `CI_ENVIRONMENT = production`, HTTPS active, `app.forceGlobalSecureRequests = true`, `cookie.secure = true`
- [ ] Document root is `public/`; `https://…/.env` and `https://…/writable/` return 404/403
- [ ] `encryption.key` generated **and backed up offline**
- [ ] Demo data never loaded, or removed with `php spark dgr:demo --purge`
- [ ] Billing setup complete; invoice/cash-memo next numbers match your paper books
- [ ] Accountant has reviewed SAC codes/GST rates and (if used) statutory payroll rates
- [ ] Cron and nightly backup running (Admin → Diagnostics shows the last run)
- [ ] Each staff member has their own login with the narrowest role; dangerous grants reviewed in Admin → Users
- [ ] `php spark dgr:diagnostics` has no failures

## 10. Upgrades and building a release

**Upgrade:** back up (`dgr:backup --files`), replace the code but keep
`.env` and `writable/`, run `php spark dgr:install` (applies new
migrations only), `php spark cache:clear`, then `dgr:diagnostics`.

`writable/uploads/` holds every uploaded logo, signature, stamp, CV,
statement and generated payslip; the database only records where they
are. **Never delete or replace the old folder before copying
`writable/` out of it.** With only a hosting *File Manager* (Hostinger,
cPanel):

1. Upload the new zip next to the old folder and extract it (it creates
   `dgr-office/`; rename it, e.g. `dgr-office-new`).
2. Copy `.env` from the old folder into the new one, and copy the old
   `writable/` folder over the new, empty `writable/` (replace).
3. Rename the old folder (e.g. `dgr-office-old`) and the new one to the
   old name. If you copied `public/` into `public_html` (§5 step 4), copy
   the new `public/` contents there again, except `index.php` if you
   edited it.
4. Run `php spark dgr:install` and `php spark dgr:diagnostics` (§5 step 5).
   Keep `dgr-office-old` until Diagnostics shows **Active
   logo/signature/stamp files** and **Uploaded and generated files** as OK.

**Logo missing, `…/brand/logo` returns 404, or Diagnostics reports missing
files:** the database lists uploads whose files are not on this server
(folder replaced on an upgrade, database moved without `writable/uploads`,
or the demo SQL from §11). Copy `writable/uploads/` back from the old
folder or unzip the latest `…-files.zip` backup into `writable/uploads/`.
If neither exists, upload the logo, signature and stamp again in
*Settings › Branding Assets*. From 1.1.1 the app shows the company name
instead of a broken logo, flags the missing file on the Dashboard,
Branding Assets and Setup Wizard pages, and still produces PDFs, without
the missing image. (Releases up to 1.1.0 also sent users to
`…/brand/logo` right after signing in whenever a logo was uploaded,
because the sign-in page's logo needed a session. Fixed in 1.1.1.)

**Build a release archive** (on a machine with Composer):

```bash
cd erp
scripts/build-release.sh 1.1.1        # → dist/dgr-office-1.1.1.zip + .sha256
```

The script exports the committed tree (`git archive`), runs
`composer install --no-dev --optimize-autoloader` against
`composer.lock`, strips tests/docs/VCS folders from `vendor/`, refuses to
package if an asset a view references is missing or if a self-hosted
frontend library differs from its verified hash in
`public/assets/vendor/MANIFEST.json`
([09-UI-THEME.md](09-UI-THEME.md) §3),
leaves `writable/` empty (only the protective `index.html`/`.htaccess`
files) and never includes `.env`. Verify a build by unzipping it into an
empty folder, pointing a fresh `.env` at an empty database and running
`dgr:install`, `dgr:create-owner` and `dgr:diagnostics`.

## 11. Demo SQL dump (evaluation only)

`dgr-office-<version>-demo.sql.gz` is a complete database (schema,
migrations table, base data and the clearly-marked demo data) for hosts
where you can import SQL (phpMyAdmin → Import) but want to look around
before running any commands. **Never use it for real data or on a
public server.**

```bash
gunzip -c dgr-office-1.0.0-demo.sql.gz | mysql -u dgr_office_user -p dgr_office_demo
```

Point `.env` at that database, then `php spark dgr:install` (reports
nothing pending). Sign-ins, all with the password `Copper-Lantern-Field-31`:
`owner@demo.dgr.local` (Super Admin — must choose a new password at
first sign-in), `accountant@`, `billing@`, `crm.manager@`, `telecaller1@`,
`telecaller2@`, `hr@`, `recruiter@`, `payroll@`, `employee@demo.dgr.local`.

The demo bank and employee account numbers were encrypted with the key of
the install that produced the dump, so on your install only the masked
last four digits show (reveal says the value is unavailable) — that is
expected. The three demo CVs and nine September payslip PDFs are files,
not database rows: the dump lists them but cannot carry their contents, so
opening one on your install shows "Page or record not found" (from release
1.1.0; 1.0.0 showed an error page). Admin → Diagnostics flags the demo
accounts (and fails in production). For real use start from an **empty** database with
`dgr:install` and `dgr:create-owner`; alternatively `php spark dgr:demo`
creates the same demo data, including those files, with your own key.

## 12. Clean database SQL (alternative to `dgr:install`)

`dgr-office-<version>-clean.sql` contains every table and the base master
data (states, permissions, starter roles, chart of accounts, tax-rate
suggestions, lead sources) and **no users, company identity, bank details
or demo data**. Use it when it is easier to import SQL (phpMyAdmin →
Import) than to run migrations:

1. Create an **empty** MySQL 8 database (utf8mb4) and import the file. It
   drops and recreates the DGR Office tables it contains.
2. Point `.env` at the database and run `php spark key:generate`,
   `php spark dgr:install` (reports nothing pending — it is safe to run)
   and `php spark dgr:create-owner --email … --name "…"`.

Verified: imported into an empty database, then install, owner creation,
diagnostics (no blocking problems) and a 332-page crawl as the owner.
