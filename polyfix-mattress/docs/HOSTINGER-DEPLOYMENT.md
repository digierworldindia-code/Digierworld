# Deploying POLYFIX MATTRESS on Hostinger

Written for Hostinger's shared and Cloud plans (hPanel), but every step applies
to any ordinary Apache or LiteSpeed host with PHP and MySQL: cPanel, Plesk,
DirectAdmin, or a plain VPS.

Work through it in order. Nothing here needs a password to be typed into a file
you did not create yourself, and no real credential appears anywhere in the
package.

> **If the site is already showing HTTP 500,** skip to
> [14. Troubleshooting](#14-troubleshooting-and-where-the-logs-are). Almost
> every first-deployment 500 is one of three things, and the application now
> tells you which.

---

## 1. Required PHP version

**PHP 8.2 or newer.** Tested on 8.3 and 8.4.

In hPanel: **Websites → your domain → Advanced → PHP Configuration → PHP
version**.

Anything older stops with a plain message rather than a blank page, because
`public/index.php` checks the version before it loads anything else.

## 2. Required PHP extensions

All of these are on by default on Hostinger. Tick them under **PHP
Configuration → PHP Extensions** if any are off:

| Extension | Used for |
|---|---|
| `mysqli` | the database (this application uses MySQLi, not PDO) |
| `intl` | CodeIgniter itself will not boot without it |
| `mbstring` | text handling throughout |
| `openssl` | password hashing, encryption of customer contact details |
| `gd` | QR codes and barcode labels |
| `fileinfo` | checking what an uploaded file really is |
| `zip` | Excel report exports |
| `json` | built into PHP 8, listed for completeness |

Also make sure `curl` is available if you intend to send email through an API.

You do not have to check these by hand. Step 13 runs `polyfix:doctor`, which
names any that are missing.

## 3. Where to upload and extract

Upload `POLYFIX-MATTRESS-CI4-FINAL-CLIENT-DELIVERY.zip` with **hPanel → Files →
File Manager**, or over SFTP, then extract it in place. Extracting on the server
is much faster than uploading ~1,900 unpacked files.

You will get a single folder, `POLYFIX-MATTRESS/`, containing `app/`, `public/`,
`vendor/`, `writable/` and the rest.

Which folder you put it in depends on the next step.

## 4. Document root — pick one of two layouts

Only `public/` should ever be reachable from the web. Everything else — your
`.env`, the database credentials in it, the uploaded claim photographs — must
not be.

### Option A (preferred): point the document root at `public/`

Best if your plan allows it. On Hostinger this is **Websites → your domain →
Advanced → Change website root** (Cloud and some Business plans; not all shared
plans have it).

* Put the project anywhere, e.g. `/home/uXXXX/polyfix/POLYFIX-MATTRESS`
* Set the website root to `/home/uXXXX/polyfix/POLYFIX-MATTRESS/public`

Nothing outside `public/` is then served at all, whatever else is
misconfigured.

### Option B: everything inside `public_html`

Use this when the document root is fixed at `public_html/`.

Extract so that the contents of `POLYFIX-MATTRESS/` sit **directly inside**
`public_html/`:

```
public_html/
├── .htaccess          ← ships with the package; do not delete it
├── .env               ← you create this in step 5
├── app/
├── public/
│   ├── .htaccess
│   └── index.php
├── vendor/
├── writable/
└── …
```

The `.htaccess` in the project root is what makes this layout safe: it forwards
every request into `public/` and refuses `.env`, `app/`, `writable/`,
`vendor/`, the `.sql` dump and the documentation. It only works if
**mod_rewrite is enabled** (step 10) — so confirm step 13 passes before you
put the site live.

Do **not** move `public/index.php` to the root and edit its paths. It is
written to find `app/` one level up, and the root `.htaccess` already does the
job.

## 5. Setting up `.env`

There is no `.env` in the package, by design — it would mean shipping secrets.

Copy the template and edit your copy:

```bash
cp .env.example .env
```

In File Manager: right-click `.env.example` → Copy → rename the copy to `.env`.

Then set, at minimum:

```ini
CI_ENVIRONMENT = production

app.baseURL = 'https://your-domain.com/'

database.default.hostname = localhost
database.default.database = uXXXX_polyfix
database.default.username = uXXXX_polyfix
database.default.password = 'the password you set in step 6'
```

Next, the three secrets. **Do not invent them and do not reuse the examples in
any documentation.** Generate them once:

```bash
php spark polyfix:keys --write
```

That fills `polyfix.encryptionKey`, `polyfix.signingSecret` and
`encryption.key` into `.env`, and refuses to overwrite one that is already set.
Without SSH, run `php spark polyfix:keys` (no `--write`) in hPanel's terminal,
or generate them anywhere and paste them in:

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'   # polyfix.encryptionKey
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'         # polyfix.signingSecret
```

**Back up `polyfix.encryptionKey` somewhere safe, outside the server.** It
decrypts customer contact details. Lose it and that data
cannot be recovered from any backup. Changing `polyfix.signingSecret` breaks
duplicate-phone detection for customers already in the database.

Finally, tighten the file so other accounts on a shared server cannot read it:

```bash
chmod 600 .env
```

## 6. MySQL database setup

hPanel → **Databases → Management → Create a New MySQL Database**. Hostinger
prefixes the names it gives you, e.g. `uXXXX_polyfix`.

* Character set **utf8mb4**, collation **utf8mb4_general_ci** (or
  `utf8mb4_0900_ai_ci` on MySQL 8). Serial numbers and customer names rely on
  utf8mb4.
* Note the hostname hPanel shows. It is usually `localhost`; on some plans it
  is a separate server name. Use exactly what hPanel says.

Use the database user hPanel creates for the application. **Never put a root or
admin account in `.env`** — the application does not need to create or drop
tables at runtime.

If you want the application's account restricted further (it cannot then alter
history tables), `database/sql/generate-privileges.sql` and
`verify-privileges.sql` do that; `docs/database.md` explains them.

## 7. Importing or migrating the database

**A brand-new installation.** Import the supplied dump — schema, roles,
permissions, settings, the website's page content and one plant. No accounts and
no customer data: those belong to a running system.

hPanel → **Databases → phpMyAdmin → Import** → choose
`POLYFIX-MATTRESS-DATABASE.sql` → Go. Over SSH:

```bash
mysql -u uXXXX_polyfix -p uXXXX_polyfix < POLYFIX-MATTRESS-DATABASE.sql
```

**An installation that already holds live data.** Do not import the dump over
it — that is what would overwrite your records. Take a backup first
(`scripts/backup.sh`, or hPanel → Backups), then apply only the schema changes:

```bash
php spark polyfix:migrate --owner-user uXXXX_polyfix_owner
php spark polyfix:seed ReferenceData
```

Both are safe to run twice. The seeder fills in reference rows that are missing
and leaves everything else alone. Existing serial numbers are never rewritten.

## 8. Base URL

`app.baseURL` in `.env` is the single place the public address is set. Nothing
in the code hard-codes a domain, and no temporary development URL is baked in
anywhere.

```ini
app.baseURL = 'https://your-domain.com/'
```

* Include the scheme, include the **trailing slash**.
* Use the address visitors actually type. It drives every stylesheet, image and
  script URL, every form action and redirect, canonical tags, the sitemap, and
  the warranty QR codes.
* If the site lives on a subdomain, use the subdomain:
  `https://app.your-domain.com/`.

**QR codes already printed on labels encode
`{baseURL}/warranty/verify?q=<token>`.** If labels are in the field, keep
`app.baseURL` stable — changing the domain later means those labels point at
the old address. If you must change it, keep the old domain redirecting to the
new one.

If you leave `app.baseURL` at the framework's default, the application stops
with a page telling you so rather than serving a site whose every asset and
form is broken.

## 9. Writable permissions

`writable/` holds logs, the cache, and uploaded claim photographs.

```bash
chmod -R 755 writable
```

If PHP runs as a different user from the one that owns the files and 755 is not
enough, use **775**:

```bash
chmod -R 775 writable
```

**Do not use 777.** It lets any account on a shared server write into your
uploads directory. If 775 is not sufficient, the file ownership is wrong — fix
that instead, or ask Hostinger support.

On Hostinger's shared plans PHP runs as your own account, so 755 is normally
correct.

Sessions and uploads need no directory of their own:

* **Sessions** are stored in the database (the `ci_sessions` table), not in
  files. That is deliberate — shared hosts often clear the system session
  directory, which would sign everybody out. No `session.save_path` to set.
* **Uploads** go to `writable/uploads/`, outside `public/`, and are served only
  through a signed, expiring URL after a permission check. They are never
  executed as code.

## 10. `.htaccess` and mod_rewrite

Two `.htaccess` files ship with the package and both must survive the upload.
File Manager hides dot-files by default — switch hidden files on before you
assume they are missing.

* **`public/.htaccess`** — sends every request that is not a real file to
  `index.php`. Required for clean URLs.
* **`.htaccess` in the project root** — only used in the Option B layout
  (step 4). Forwards requests into `public/` and blocks everything else.

**mod_rewrite is enabled by default on Hostinger** (Apache and LiteSpeed both
honour these files) and needs no action. Without it, clean URLs fail and the
Option B layout is not safe.

Two things worth knowing:

* `www` canonicalisation is deliberately switched off. The stock CodeIgniter
  rule redirects to `http://`, which with HTTPS forced sends the browser into a
  redirect loop. `app.baseURL` already declares the canonical host. If you want
  Apache to do it as well, `public/.htaccess` has both directions written out,
  commented, with the condition that keeps it working behind a CDN.
* If your host disallows `Options +FollowSymlinks` in `.htaccess` (a few do, and
  it produces a 500 for the whole site), the file already uses
  `+SymLinksIfOwnerMatch`, which is accepted everywhere.

## 11. Composer and `vendor/`

**Nothing to install.** `vendor/` ships complete inside the ZIP, with the exact
dependency versions the build was tested against. Hostinger's shared plans do
not always give you Composer, so the package does not need it.

Do not run `composer update` on the server. If you ever want to reinstall
dependencies, do it on a machine you control:

```bash
composer install --no-dev --optimize-autoloader
```

`composer.json` and `composer.lock` are included so that stays reproducible.

## 12. SSL and HTTPS

Turn SSL on **before** you set `forceGlobalSecureRequests`, or you will lock
yourself out of a site that cannot yet serve HTTPS.

1. hPanel → **Websites → your domain → Security → SSL** → install the free
   Let's Encrypt certificate and wait for it to go active.
2. Check `https://your-domain.com/` loads.
3. Then, in `.env`:

```ini
app.baseURL = 'https://your-domain.com/'
app.forceGlobalSecureRequests = true
cookie.secure = true
```

`forceGlobalSecureRequests` sends any plain-HTTP request to HTTPS.
`cookie.secure` stops the session cookie ever travelling unencrypted — it
**must** be `false` until HTTPS works, and `true` afterwards.

With HTTPS live the application also sends HSTS, so browsers stop trying HTTP
at all. Do not switch that on until the certificate is working and you intend
to keep it.

## 13. Checking the install, and the first admin login

Run the check before you try the browser. It reports in order and says which
step failed:

```bash
php spark polyfix:doctor
```

It tests the PHP version, the extensions, `.env`, the writable directories, the
secrets, `app.baseURL`, the database connection, the tables and the migrations.
It reads only, changes nothing, and prints no password or key. Do not go live
until it is clean.

Then create the first account:

```bash
php spark polyfix:create-user --email you@your-domain.com --name "Your Name" --role SUPER_ADMIN
```

It prints a one-time password **once** and does not store it readably. Hand it
over in person or by phone, never by email.

Now test in a browser:

1. Open `https://your-domain.com/` — the public site should load with styling
   and images. No styling means `app.baseURL` is wrong (step 8).
2. Open `https://your-domain.com/admin/login`.
3. Sign in with the address and the one-time password.
4. You are asked to **choose a new password** — that is the forced
   first-sign-in password change, and it is meant to happen.
5. You then land on the dashboard. **There is no second step of any kind** —
   no authenticator app, no code, no setup page. Two-factor authentication has
   been removed from this build.
6. Check the dealer panel loads at `https://your-domain.com/dealer/login`.
7. Sign out from the menu, and confirm `/admin` sends you back to the sign-in
   page.

## 14. Troubleshooting, and where the logs are

**The application's own log is the first place to look:**

```
writable/logs/log-YYYY-MM-DD.log
```

Open it in File Manager if you have no SSH. It records the real exception.
Hostinger's own PHP error log is under **hPanel → Websites → Advanced → PHP
Configuration → error log**, or `~/logs/` (`error_log` in the domain folder).

In production the site never shows a visitor a stack trace, a file path, a SQL
error or a credential. That is on purpose — the detail goes to the log.

### HTTP 500 on every page

Run `php spark polyfix:doctor` first; it usually names the cause outright.

If the install is unfinished, you get a **"Setup is not finished yet"** page
listing exactly what is wrong instead of a 500. The three usual causes:

| What you see | Cause | Fix |
|---|---|---|
| "Setup is not finished yet", mentioning `polyfix.encryptionKey` / `polyfix.signingSecret` | `.env` copied but the secrets left as `CHANGE_ME` | `php spark polyfix:keys --write` (step 5) |
| "Setup is not finished yet", mentioning `writable/` | the web server cannot write there | `chmod -R 775 writable` (step 9) |
| "Setup is not finished yet", mentioning `app.baseURL` | `.env` never edited | set `app.baseURL` (step 8) |
| A generic "Something went wrong on our side" | usually the database refusing the connection | check `database.default.*` against hPanel; the log gives MySQL's own message |

Other causes worth checking:

* **`.env` missing entirely.** File Manager hides dot-files; turn hidden files
  on and confirm it is there, next to `app/`.
* **PHP version too low.** You get a plain 503 naming the version. Step 1.
* **An extension switched off.** The setup page names it. Step 2.
* **`vendor/` incomplete** because the upload was interrupted. Re-extract the
  ZIP on the server rather than uploading the folder file by file.

### Other symptoms

| Symptom | Likely cause |
|---|---|
| Page loads but no CSS, images or JavaScript | `app.baseURL` wrong, or `http` where the site is `https` (step 8) |
| Everything 404s except the homepage | mod_rewrite off, or `public/.htaccess` missing (step 10) |
| The browser reports a redirect loop | `forceGlobalSecureRequests = true` before SSL was working (step 12), or a `www` rule fighting `app.baseURL` |
| Directory listing, or the project's files are visible | document root is not `public/` and the root `.htaccess` is missing (step 4) |
| Signed out every few minutes | the `ci_sessions` table is missing — re-import, or run the migrations (step 7) |
| QR codes on labels point at the wrong address | `app.baseURL` was changed after the labels were printed (step 8) |
| "A stored encrypted value could not be decrypted" | `polyfix.encryptionKey` is not the key the data was written with. Restore the original key; do not generate a new one |

### Before you call it done

* `php spark polyfix:doctor` is clean.
* `CI_ENVIRONMENT = production` in `.env`.
* HTTPS works and `cookie.secure = true`.
* `chmod 600 .env`, and `writable/` at 755 or 775 — not 777.
* `https://your-domain.com/.env` returns 403 or 404, **not** the file's
  contents. Check this by hand; it matters more than anything else on this
  list.
* A backup has been taken *and* restored once, so you know it works
  (`docs/backup.md`, `docs/restore.md`).
