# DGR Office — changes

No release since 1.0.0 changes the database schema: upgrading never needs
SQL changes, and `php spark dgr:install` only applies new migrations
(there are none) without touching existing data.

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
