# DGR Office — changes

Releases 1.0.0 to 1.2.0, 1.3.1 and 1.4.0 do not change the database
schema. **1.3.0 adds three additive migrations** (one nullable column, four indexes, two
settings rows; no existing data is changed or removed). `php spark dgr:install` applies only new migrations
and, on an existing installation, first writes a backup to
`writable/backups` and reads it back to verify it; if that fails, nothing
is changed.

## 1.4.0 — administrator installation in the browser (no database changes)

Installing on shared hosting no longer needs SSH or cron-job workarounds.
Step-by-step guide: [docs/11-ADMIN-INSTALLATION.md](docs/11-ADMIN-INSTALLATION.md).
Upgrading from 1.3.x only replaces files.

- **Administrator setup page (`/setup`).** While no active Owner / Super
  Admin exists, it checks the server (PHP version and extensions, writable
  folders, `.env`, site address, encryption key, database connection and
  tables), adds a random encryption key to `.env` if none is set, creates
  the tables and base data (after a verified backup if the database already
  holds data — the same code as `dgr:install`), and creates the first
  administrator with the usual password rules.
- **Protected by a one-time code on the server.** The page asks for the
  code written to `writable/SETUP-CODE.txt` (readable only through the
  hosting File Manager, never shown on the page, valid two hours, deleted
  after use). Its form posts are CSRF-checked and limited to 10 a minute,
  wrong codes are logged, and creating the administrator is audited.
- **Gone for good afterwards.** Once an administrator exists the page and
  its actions answer 404, and `writable/installed.lock` keeps them closed
  even if the database is briefly unreachable.
- **A new installation leads to the setup page.** With an empty database
  the site address now opens the setup page instead of an error page;
  after importing the clean SQL file the sign-in page links to it.
- **Pages no longer fail when the database cannot be read for the
  security headers.** Every page asked Shield whether someone was signed
  in, which reads settings from the database; with no tables yet (or the
  database down) that made every page — including the error and setup
  pages — fail. Such pages are now simply marked private (not cached).
- `dgr:install` and the setup page share one install routine
  (`App\Services\Admin\InstallService`); `dgr:install` output is unchanged
  except that it no longer lists each seeder and now mentions the browser
  setup page when no owner exists.

## 1.3.1 — fixes from a full check of 1.3.0 (no database changes)

Found by crawling every page as each role, a browser pass over every
screen in both themes, form flows in a real browser and running every
command-line tool (details in docs/07-TEST-REPORT.md §0a). 1.3.1 has no
migrations; upgrading from 1.3.0 only replaces files.

- *Demo data could not be loaded with the documented password.* The usage
  text of `dgr:demo` suggested `--password "Demo-Pass-123"`, but passwords
  may not contain the user's name and every demo user is called
  "DEMO · …", so loading stopped part-way with a password error and was
  rolled back. The password is now checked against all nine demo users
  before anything is written, the message says why and gives a working
  example, and the usage text shows one (`Train-Ledger-2026`).
- *Password rule message read "Password must have not contain your
  name."* (new user, change password, first sign-in). Now: "Password must
  be at least 10 characters long, not contain your name."
- *Light theme: grand totals unreadable.* The grand total on invoices
  (screen and entry form) and the ticks in the permission matrix used the
  gold accent as a text colour: 2.1:1 on white. They now use the darker
  gold text colour (5.4:1); a test refuses gold-as-text from now on.
- *Dark theme: highlighted table rows were bright yellow/pink.* Bootstrap's
  `table-warning`/`table-danger` rows (audit "what changed", import
  previews, attendance day, incentives) have fixed light colours with
  black text, so in the dark theme they showed as bright bars and the gold
  field names on them were unreadable (1.9:1). They now use the theme's
  soft status tints in both themes.
- *Phones: the vacancy / JD form was 6 px wider than the screen* (the
  evaluation rubric labels could not wrap). They wrap and stack now.
- *Invoice screen: the Total column was off-screen at 1366 px.* The GST
  rate is shown under the GST amount, so all columns fit.

## 1.3.0 — stability, performance (504), PDFs, bank setup, Light/Dark theme (PHP 8.2+)

Each item names the root cause found in the code. Performance numbers
come from a copy loaded with 6,000 invoices, 30,000 journal lines, 12,000
leads (8,000 calls, 36,000 activities), 28,000 audit rows and 120
employees, with every GET page timed and MySQL's slow-query log on.

**Gateway timeouts (504) and slow pages**
- *Exports* built the whole workbook in memory with PhpSpreadsheet: the
  CRM export took 9.1 s and 256 MB (a typical shared-hosting memory
  limit), the invoice export 3.7 s, growing with the data. A streaming
  writer (`XlsxWriter`) produces the same workbook (About sheet, header,
  Indian number formats, totals, filter): CRM 1.6 s / 38 MB, invoices 0.3 s.
- *Session lock chains:* PHP file sessions lock per user for the whole
  request, so one slow request (export, PDF, Sheets sync, AI review,
  backup) made every other tab of that user wait until the gateway gave
  up. Downloads release the lock at once; the slow actions release it
  around their work. Verified: during a running export another page of
  the same user answered in 0.01 s (it waited 0.6 s+ before).
- *Google Sheets "Sync now"* processed up to 300 jobs in one request and
  downloaded the sheet's whole ID column again for every record (two
  Google calls per record). It now claims jobs in batches of 25, stops
  after `app.webWorkSeconds` (default 20 s) and reads the ID column once
  per run; cron continues with the rest and the message says how many
  are still queued.
- *AI CV review* ran inside the request with a 90 s timeout and two
  retries (worst case several minutes). On screen it is now capped at
  `app.webAiSeconds` (default 45 s) without retries; command-line runs
  keep the configured values.
- *Ledger and bank/cash statements* rendered every entry of the period
  (3.2 MB pages for the cash account). They are now paged (100 per page)
  with correct "brought forward" / "carried forward" balances; period
  totals come from one query; exports still contain every line.
- *N+1 queries:* the leave balances screen ran ~15 queries per employee
  (1,816 for 120 employees); the attendance register one per employee.
  Both now use a fixed handful of queries.
- *Dashboard counts* filtered with `DATE(column) = …`, which cannot use an
  index; they now use date ranges, and migration
  `AddPerformanceIndexes` adds the missing indexes
  (`call_logs.called_at`, `followups(status, due_at)`,
  `lead_conversion_links.converted_at`, `audit_logs.occurred_at`).
- *Backups* paged through each table with `LIMIT … OFFSET` (re-reading
  big tables again and again) and were not checked. They now stream each
  table once inside one consistent snapshot and are verified by reading
  the file back (every table, every row count) — after `dgr:backup`, the
  Backups page and automatically before migrations.
- *Finding slow pages:* requests slower than `app.slowRequestMs` (default
  3000 ms) are logged as `SLOW REQUEST` with time, SQL query count and
  peak memory (no query text or form data). Production logging now
  includes warnings (it only kept errors, so such notes were dropped).
  Diagnostics shows `max_execution_time`, OPcache, output compression and
  today's slow requests.

**Concurrent use and the audit log**
- *Saves failing with "Deadlock found" (HTTP 500) when several people
  save at the same time:* found by creating invoices from four sessions
  at once (5 of 32 failed). Every audit entry locked the newest audit row
  in the middle of the business transaction; that lock also covers the
  gap after the row, which two writers can hold together and then
  deadlock on their inserts. Audit entries made inside a transaction are
  now appended when it commits, and writers take turns on one dedicated
  row. Re-run twice: 32/32 drafts and 32/32 finalizations, no duplicate
  or missing invoice / cash-memo numbers, no deadlocks, chain intact.
- *Audit chain reported "broken" on MySQL 8:* MySQL rewrites JSON columns
  (key order, spacing), so re-hashing the stored data never matched what
  was hashed. New entries hash their data in a canonical form; migration
  `MarkAuditCanonicalHashing` records where that starts. Older entries are
  still checked the old way; on MySQL, older entries with JSON data can
  only be checked for their place in the chain, and the Verify message
  says how many. (MariaDB keeps JSON text unchanged, so older entries are
  fully checked there.)

**Sign-in, sessions, dashboards**
- Each role now lands on its own page after signing in: Owner/Super
  Admin, Accountant and CRM manager → Owner dashboard; Billing operator →
  new *Billing dashboard*; Telecaller and Sales → *CRM › My day*; HR,
  recruiter and payroll roles → new *HR dashboard*; Employee → *My
  workspace*. Editable per role in *Admin › Roles* (only pages the role
  can open are offered; a landing page the user may not open is never
  used). Migration `AddRoleLandingUrl` (nullable `roles.landing_url`).
- *Silent sign-in loop:* when the browser did not send the session cookie
  back (`cookie.secure = true` on an `http://` address, an address other
  than `app.baseURL`, blocked cookies), every sign-in failed the CSRF
  check and the error message was stored in that same lost session, so
  the sign-in page simply reappeared. The sign-in page now explains the
  cause (`?session=missing`) and the server logs it.
- After a failed form, typed passwords, bank account numbers and CIF are
  no longer kept in the session to refill the form.

**Invoice and other PDFs**
- A line description longer than the space left on a page moved the
  whole row to the next page, leaving page 1 nearly empty, and anything
  longer than one page was cut off (Dompdf never splits a table row).
  Long descriptions now continue on follow-on rows, so pages break
  between them and no text is lost; column headings repeat on each page.
- Text without spaces (links, reference codes) pushed the table past the
  right edge of the paper; it now wraps inside its column.
- Long notes / terms flow below the totals instead of being clipped
  inside the footer table; signature and stamp images no longer overlap
  the "Authorised Signatory" caption.
- All PDFs (invoices, receipts, payslip preview, template preview) are
  sent through one helper that drops anything accidentally output before
  the PDF bytes (it would make the file unreadable) and logs it.
- New test covers all eight document types, receipts, drafts, cancelled
  documents, A4 size, page count, images, filename, amount in words and
  CIF never printed.

**Bank setup (edit / update)**
- Saving failed with a database error when a value was longer than its
  column (e.g. a full branch address pasted into *Branch*; strict SQL
  mode rejects it). All fields are validated with clear messages and the
  inputs limit their length.
- Deactivating the default bank left it marked as the default; an
  inactive account is never the default now.
- An opening balance without an "as on" date was reversed and re-posted
  dated *today* on every later save, moving the opening balance in the
  ledger. The date is now fixed when the balance is first saved and the
  ledger keeps the existing entry's date.
- Covered by a new end-to-end test: edit form prefilled, changes kept
  after reload, blank number/CIF keep the stored values, masking, audit
  without secrets, one default at a time, reactivation, drafts and new
  invoices use the current details, issued invoices keep theirs,
  permission check.

**Theme and layout**
- Light / Dark / System selector in the top bar (System by default):
  saved to the user's profile (follows them to other devices) and to the
  browser, applied before the first paint (no flash), charts redraw in
  the new colours, printing always uses white paper. Approved light
  palette (`#F8F9FA` / `#FFFFFF` / `#171717` / `#52525B` / `#E4E4E7`, gold
  `#D4AF37` for fills and a darker gold for text so it stays readable);
  dark borders now `#303036`. Every text colour meets WCAG AA in both
  themes (checked by `ThemeComplianceTest`).
- The menu can be hidden on desktop (remembered); on phones search is an
  icon and large amounts shrink to fit the cards; buttons show a spinner
  while a form is being submitted. No page scrolls sideways at 390, 768
  or 1366 px (checked in a browser on 20 screens in both themes).

**Platform**
- Runs on **PHP 8.2** and newer: Composer platform pinned to 8.2
  (`maennchen/zipstream-php` 3.1.2, which still supports 8.2); the full
  suite passes on PHP 8.2 and 8.3, MySQL 8.0 and MariaDB 11.8.

## 1.2.0 — fixes from the Hostinger deployment (CodeIgniter 4.7.5, PHP 8.3, MariaDB 11.8)

Every fix below addresses a root cause found in the code and was verified
on a copy installed the way Hostinger runs it (Apache with `.htaccess`,
PHP 8.3, MariaDB 11.8.9, production mode, app in a sub-folder).

**Sign-in and redirects**
- After sign-in the browser could land on `…/brand/logo` (an empty 404)
  instead of the dashboard: the sign-in page shows the logo, the logo URL
  required a session, and Shield remembered that image request as the
  page to return to. The logo URL is now public (it only serves the
  official logo), and a new filter answers signed-out images and script
  calls with 401, so only real page visits are remembered. (1.1.1)
- A role without the Dashboard permission got "access denied" right after
  signing in and on every Home link. `/` now opens the first module the
  role may use, or explains that no module is assigned.
- Sub-folder installs (e.g. `https://your-domain.com/dgr/`): the project's
  new root `.htaccess` lets the whole folder sit in `public_html/dgr`
  while serving only `public/` and blocking `.env`, `app/`, `writable/`,
  `vendor/` and `tests/`. When `app.baseURL` does not match the address
  the page was opened at (the usual cause of 404s, unstyled pages and
  wrong redirects in a sub-folder), the sign-in page and Diagnostics say
  exactly what to set.

**Encryption key** ("Encrypter needs a starter key")
- Saving a bank account on a server whose `.env` had no `encryption.key`
  crashed with that exception. The bank form now warns in advance, and
  saving shows a clear message with the fix; Diagnostics distinguishes a
  missing key from a short one. The app never generates or replaces a key
  by itself.

**PDFs and file storage** ("File not found" in `FileStorage.php`)
- When the database lists an uploaded logo, signature, stamp or QR whose
  file is no longer in `writable/uploads` (folder replaced on an upgrade,
  database moved without the uploads), every PDF using it failed. PDFs
  are now produced without the missing image; new documents and signing
  ignore lost files; the Dashboard, Branding Assets and Setup Wizard name
  the missing file; Diagnostics reports it; the log records it at ERROR
  level. Downloads of a missing file answer 404 after the permission
  check. The storage-root check now also requires a path separator after
  the root and a regular file (path traversal stays blocked). (1.1.1)
- Any business-rule error that a page did not catch is now shown as its
  message (form → back to the form with your input, script call → JSON,
  page → error page) instead of the generic server-error page.

**MariaDB**
- MariaDB 11.6+ enables `innodb_snapshot_isolation` by default: when two
  users finalised invoices (or saved receipts, payroll, audited changes)
  at the same moment, all but one failed with error 1020 "Record has
  changed since last read". The app now turns it off for its own
  connection on MariaDB, giving the MySQL 8 locking behaviour the code is
  built on. The full automated test suite passes on MariaDB 11.8.9 and on
  MySQL 8.0, and the clean SQL file imports into MariaDB 11.8 without
  errors.
- The production connection removed strict SQL mode (CodeIgniter strips
  it when `strictOn` is false), so invalid values could be silently
  truncated. It now runs strict, as the tests always have.

**Diagnostics** (`php spark dgr:diagnostics`, Admin › Diagnostics)
- `sodium` is no longer reported as required: nothing in the application
  or its locked dependencies needs it (encryption uses OpenSSL).
- MariaDB 10.6+ is reported as supported; new checks for the site address
  (`app.baseURL`), HTTPS settings and, when the whole folder is inside the
  web root, that its protecting `.htaccess` files are present.
- `dgr:install` only suggests creating an owner when none exists.

**Other fixes**
- Billing pages wrote an ERROR line to the log on every view because they
  checked permissions that do not exist (`billing.note.view_own`,
  `billing.estimate.cancel`).

**Package**
- The zip has the files at its top level (no wrapper folder) and includes
  `tests/`, `phpunit.dist.xml`, the root `.htaccess`, `.env.example` with
  placeholders only, and a Hostinger quick start in `README.md`.
- New automated tests: every route points at an existing method; every
  permission the code checks is registered; sign-in/landing behaviour;
  missing-upload handling.

## 1.1.1
- Sign-in landing on `…/brand/logo`; graceful handling of uploads missing
  from `writable/uploads` (both described above).

## 1.1.0
- Black · white · gold theme on every screen; self-hosted, verified
  frontend libraries; strict Content-Security-Policy.

## 1.0.0
- First release.
