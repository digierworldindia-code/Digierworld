# DGR Office — Administrator installation guide

For the person who installs DGR Office and becomes its first administrator
(Owner / Super Admin). No SSH or command line is needed: everything is done
in the hosting panel and the browser. Plan about 20 minutes.

The full technical reference (cPanel, VPS, XAMPP, upgrades, 504 checklist)
is [04-DEPLOYMENT.md](04-DEPLOYMENT.md).

---

## What you need

| Item | Requirement |
| --- | --- |
| Hosting | Hostinger (or any cPanel/Apache/LiteSpeed host) with **PHP 8.2 or 8.3** |
| Database | **MySQL 8.0.16+** or **MariaDB 10.6+** (an empty database) |
| Files | `dgr-office-<version>.zip` (application with everything it needs; about 18 MB, 70 MB extracted) |
| Address | e.g. `https://your-domain.com/dgr/` (a folder) or `https://office.your-domain.com/` |

---

## Part A — Install (Hostinger hPanel, no SSH)

### 1. PHP settings
*Advanced → PHP Configuration*

* **PHP version:** 8.3 (8.2 also works).
* **Extensions:** make sure these are on: `intl`, `mbstring`, `mysqli`,
  `curl`, `openssl`, `gd`, `zip`, `xml`, `dom`, `xmlwriter`, `fileinfo`,
  `iconv`, `ctype`.
* **PHP options:** `memory_limit` 256M, `upload_max_filesize` 20M,
  `post_max_size` 25M.

### 2. Create the database
*Databases → Management*

Create a database and a user with a strong password. Hostinger adds a
prefix to both names, e.g. database `u123456789_dgr`, user
`u123456789_dgruser`. Write down the **full** names and the password.

### 3. Upload and extract
*Files → File Manager*

1. Open `public_html` and create a folder, e.g. `dgr`.
2. Upload `dgr-office-<version>.zip` into `public_html/dgr` and **Extract**
   it there.
3. Check that `spark`, `app`, `public`, `vendor` and `.htaccess` are
   directly inside `public_html/dgr` (not in a second sub-folder). Then
   delete the zip.

Keep the `.htaccess` file: it serves only the public part and blocks
`.env`, `app/`, `writable/` and `vendor/` from the web.

### 4. Create the `.env` settings file
In `public_html/dgr`, copy `.env.example` and name the copy `.env`. (If you
don't see files that start with a dot, switch on "show hidden files" in the
File Manager's settings.) Edit `.env` and set:

```ini
CI_ENVIRONMENT = production
app.baseURL = 'https://your-domain.com/dgr/'

database.default.hostname = localhost
database.default.database = u123456789_dgr
database.default.username = u123456789_dgruser
database.default.password = the-password-from-step-2
database.default.port = 3306
```

* `app.baseURL` is the exact address you will open, **with the folder and
  the trailing slash**. Until SSL works, use `http://`.
* Leave `encryption.key =` empty — the setup page fills it in.

Save the file.

### 5. Open the site → Administrator setup
Open `https://your-domain.com/dgr/setup`. (With an empty database the site
address itself leads there; after importing the clean SQL file the sign-in
page shows a link **Set up the administrator account**.)

1. **Setup code.** In File Manager open `public_html/dgr/writable/SETUP-CODE.txt`,
   copy the code (e.g. `K7QF-2MXP-9TBD`), paste it on the page and click
   **Continue**. This proves you manage the server. The code is valid for
   two hours; after that a new one is written to the same file.
2. **Checks.** The page lists PHP, extensions, folders, `.env`, the site
   address, the encryption key and the database. Fix anything marked
   **Fix** (see Troubleshooting below) and reload the page.
3. **Step 1 — Encryption key.** Click **Generate the key**. A random key is
   added to `.env`. It protects saved bank account numbers and similar
   secrets. **Download `.env` to your computer now and keep it somewhere
   safe** (with your passwords): if this key is lost, saved bank numbers
   cannot be read again. The key is never shown and never replaced.
4. **Step 2 — Database tables.** Click **Create the database tables**
   (up to a minute). This creates all tables and the base data: Indian
   states, permissions, starter roles, chart of accounts, GST rate
   suggestions, lead sources.
   *Alternative:* import `dgr-office-<version>-clean.sql` into the empty
   database with phpMyAdmin (*Databases → phpMyAdmin → Import*); this step
   then shows **OK**.
5. **Step 3 — Administrator account.** Enter your full name, your email
   (this is your sign-in name) and a password: at least 10 characters with
   letters and numbers, not containing your name or the part of your email
   before `@`. Click **Create the administrator**.

You are taken to the sign-in page. Sign in with that email and password.

From now on the setup page no longer exists (it answers "not found"), the
code file is deleted, and a file `writable/installed.lock` records that
setup is finished.

### 6. Scheduled jobs (cron)
*Advanced → Cron Jobs → Custom*. Use the full path that File Manager shows
for your folder:

| When | Command |
| --- | --- |
| Every minute | `php /home/u123456789/domains/your-domain.com/public_html/dgr/spark dgr:cron` |
| Daily, e.g. 02:00 | `php /home/u123456789/domains/your-domain.com/public_html/dgr/spark dgr:backup --files --keep 14` |

The first sends reminders and follow-ups, runs Google Sheets sync and
recurring invoice drafts; the second keeps 14 daily backups in
`writable/backups`. Download a backup to your computer every week.

### 7. SSL (https)
*Security → SSL*: install the free certificate. When `https://` opens the
site, edit `.env`:

```ini
app.baseURL = 'https://your-domain.com/dgr/'
app.forceGlobalSecureRequests = true
cookie.secure = true
```

### 8. Check
Sign in and open **Administration › Diagnostics**. Everything should be
**OK**, except warnings for things you have not set up yet (SMTP email,
Google Sheets, AI).

---

## Part B — First things to do after signing in

Do these in order before anyone issues an invoice. Enter only your own,
real registration details — nothing is pre-filled for you.

1. **Settings › Billing Setup Wizard** (details later in **Settings ›
   Company Profile**) — company legal name, trade name, GSTIN, PAN,
   registered address and state. The state decides CGST + SGST versus IGST.
2. **Settings › Branding Assets** — upload the official logo (it is used
   exactly as uploaded), the authorised signature and the company stamp.
   Then **Settings › Signature Policy**: who may sign which document types.
3. **Settings › Bank & Payment Profiles** — the bank account(s) printed on
   invoices, and the default one. Full account numbers and CIF are stored
   encrypted; CIF is never printed.
4. **Settings › Tax Rates & Treatments** — review the suggested GST rates
   with your accountant and mark them reviewed.
5. **Settings › Document Series** — invoice and cash-memo numbering. If you
   are moving from another system mid-year, set the next number to continue
   your series.
6. **Administration › Roles & Permissions** — review the starter roles
   (Accountant, Billing Operator, CRM Manager, Telecaller, Sales Executive,
   HR Manager, HR Recruiter, Payroll Manager, Employee, Auditor (Read
   Only)) and their landing pages.
7. **Administration › Users** — create an account for each staff member
   with the right role. Leave the password empty to have a temporary one
   generated (shown once); every new user must choose their own password
   at first sign-in. Add at least one more trusted administrator so the
   company is never locked out.
8. *Optional:* **Settings › Integrations** — SMTP email (reminders by email,
   email sign-in links), Google Sheets sync, AI provider for recruitment.

---

## Part C — With SSH instead (optional)

*Advanced → SSH Access*, then in the folder that contains `spark`:

```bash
php -v                              # must say 8.2 or newer
php spark key:generate              # once per installation
php spark dgr:install               # tables and base data (safe to re-run)
php spark dgr:create-owner --email you@your-domain.com --name "Your Name"
php spark dgr:diagnostics
```

`dgr:create-owner` prints a temporary password that must be changed at
first sign-in (or pass `--password "…"`).

---

## Troubleshooting

| What you see | What to do |
| --- | --- |
| Error page right after uploading | Check that `.env` exists in the same folder as `spark` and that PHP is 8.2+; then open `…/setup` directly — it lists what is wrong. |
| **Database: cannot connect** on the setup page | Re-check the four `database.default.*` lines in `.env`. On Hostinger the hostname is `localhost` and both names carry the `u123456789_` prefix; the password is the database user's, not your hPanel password. |
| **Site address does not match** | Set `app.baseURL` in `.env` exactly as the message says (http/https, `www`, folder, trailing slash). |
| **That code is wrong or has expired** | Open `writable/SETUP-CODE.txt` again and copy the current code. Upper/lower case and dashes do not matter. |
| **Too many requests** | More than 10 tries in a minute; wait one minute. |
| **Generate the key** is not offered (".env cannot be written") | Make `.env` writable (permission 644) for a moment, or run `php spark key:generate` over SSH. |
| `/setup` says "not found" | An administrator already exists: sign in at `…/login`. |
| Forgot the administrator password | Another administrator resets it in **Administration › Users**. If there is no other administrator: create a new one with `php spark dgr:create-owner --email … --name "…"` over SSH, or as a temporary cron job (`cd /home/…/public_html/dgr && php spark dgr:create-owner --email … --name "…" >> writable/logs/setup.log 2>&1`, run once, read the temporary password in that log with File Manager, then delete the cron job and the log). |
| No active administrator left at all (all deactivated) | As above with `dgr:create-owner`; or delete `writable/installed.lock` and open `…/setup` (it opens only while no active administrator exists). |

## Security notes

* The setup page exists only while there is no active administrator, and
  every step needs the code that only someone with access to the server's
  files can read. Wrong codes are logged and limited to 10 tries a minute.
* Never share `.env`, never put it in e-mail or chat, and keep a copy of it
  (and of your backups) off the server.
* Use a strong, unique administrator password and add a second
  administrator for emergencies.
