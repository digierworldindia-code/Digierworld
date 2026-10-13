# Dependency verification report

Package: `BEARINGCAVE_COMPLETE_WITH_VENDOR_AND_ALL_DEPENDENCIES.zip`, built by `tools/package_complete.sh` from the committed source on 2026-10-13. Every result below is from commands actually run. Nothing is assumed.

## 1. What was wrong in earlier packages (and is fixed)

| Problem | Effect | Fix |
|---|---|---|
| `vendor/` was not included | The application could not start without running Composer on the server | Production `vendor/` is now installed and included (§3) |
| `.gitignore` rule `vendor/` also matched **`public/assets/vendor/`** | Bootstrap 5.3.3, Bootstrap Icons and the Inter font were never committed, so every earlier ZIP lacked them. **Found by the clean-install test: all three returned 404.** | Rule anchored to `/vendor/`; the libraries and their licence files are committed. The packager now **fails** if any asset referenced by views or CSS is missing |
| `composer.json` did not declare the PHP extensions the app needs (e.g. `mysqli`) | `check-platform-reqs` passed on servers that could not run the app | `ext-mysqli`, `ext-intl`, `ext-mbstring`, `ext-gd`, `ext-zip`, `ext-openssl`, `ext-fileinfo` and `ext-json` added. Lock refreshed with **no version changes** |
| `composer.json` still had the appstarter's name | Cosmetic | Renamed `bearingcave/bearingcave` |
| `DemoSeeder` printed "Seeded" after refusing to run in production | Misleading console output | Now raises an error |

## 2. Composer checks (run inside the packaged folder)

| Command | Result |
|---|---|
| `composer validate` | `./composer.json is valid` |
| `composer install --no-dev --prefer-dist --optimize-autoloader` | 11 packages installed from `composer.lock` |
| `composer check-platform-reqs --no-dev` | all 20 requirements `success`: php 8.3.6, php-64bit, ctype, dom, fileinfo, filter, gd, iconv, intl, json, libxml, mbstring, mysqli, openssl, simplexml, xml, xmlreader, xmlwriter, zip, zlib |
| `composer audit --no-dev` | `No security vulnerability advisories found.` |
| `composer dump-autoload --no-dev --optimize` | optimised class map with **1,392 classes** |
| `php -r 'require "vendor/autoload.php"; …'` | `autoload OK`: CodeIgniter, Shield, PhpSpreadsheet, PSR-3 and app service classes resolve |

**Note on the build environment.** The build network blocks GitHub's zip-download API (HTTP 403), so Composer fell back to installing from git. The packager then converts each package with `git archive` of the exact locked commit. GitHub generates its dist zips the same way, honouring each package's export-ignore rules, so the shipped `vendor/` has the same content as a normal `--prefer-dist` install and no `.git` history. On a normal server, the same command downloads the dist zips directly.

## 3. Installed production packages (`vendor/`)

| Package | Version | Licence | Used for |
|---|---|---|---|
| codeigniter4/framework | 4.7.4 | MIT | the framework |
| codeigniter4/shield | 1.4.1 | MIT | authentication, roles, permissions, API tokens |
| codeigniter4/settings | 2.4.0 | MIT | required by Shield |
| phpoffice/phpspreadsheet | 5.10.0 | MIT | CSV/XLSX import, templates, report export |
| maennchen/zipstream-php | 3.2.2 | MIT | XLSX writing (PhpSpreadsheet) |
| markbaker/complex | 3.0.2 | MIT | PhpSpreadsheet formula engine |
| markbaker/matrix | 3.0.1 | MIT | PhpSpreadsheet formula engine |
| composer/pcre | 3.4.0 | MIT | PhpSpreadsheet |
| laminas/laminas-escaper | 2.18.0 | BSD-3-Clause | CodeIgniter output escaping |
| psr/log | 3.0.2 | MIT | logging interface |
| psr/simple-cache | 3.0.0 | MIT | cache interface |

`vendor/` is 23 MB (1,716 files). Development-only packages (PHPUnit, Faker, vfsStream) are **not** included. Install them with `composer install` (without `--no-dev`) on a development machine to run the tests.

## 4. Frontend libraries (self-hosted, no CDN)

| Library | Version | Licence | Path |
|---|---|---|---|
| Bootstrap (CSS + bundle JS) | 5.3.3 | MIT | `public/assets/vendor/bootstrap/` |
| Bootstrap Icons | 1.11.3 | MIT | `public/assets/vendor/bootstrap-icons/` |
| Inter (400/500/600/700, latin) | from the Fontsource package (exact version not recorded) | SIL OFL 1.1 | `public/assets/vendor/inter/` |

The application's own CSS and JS are `public/assets/css/bearingcave.css` and `public/assets/js/app.js` (vanilla JS with the Fetch API).

Verified on the clean Apache install: all 8 referenced asset URLs return **200** with the correct content types (`text/css`, `text/javascript`, `font/woff2`, `image/svg+xml`). The packager's check covers every `base_url('assets/…')` in the views and every `url(…)` in the CSS files.

## 5. Application checks

| Check | Result |
|---|---|
| PHP syntax (`php -l` on all 388 files in `app/`) | 0 errors |
| `php spark routes` | 323 routes; **all 262 application handlers resolve to an existing public controller method** (checked by reflection) |
| `php spark migrate:status` after importing `database/bearingcave.sql` | all application migrations listed as applied; `php spark migrate --all` had nothing to run, so the SQL file and the migrations are consistent |
| MySQL connectivity | MySQL 8.0 via `.env` credentials (dedicated DB user) |
| Bootstrapping | `spark` and the web front controller start in production mode |

## 6. Clean-installation test

Environment: Ubuntu 24.04, Apache 2.4.58 with mod_php 8.3 and `mod_rewrite`, MySQL 8.0, document root = `public/`, `CI_ENVIRONMENT = production`. Full results: `TEST_REPORT.md` §0.

1. Fresh directory, ZIP extracted. `vendor/autoload.php`, the framework, Shield, `public/.htaccess`, Bootstrap and `database/bearingcave.sql` are all present.
2. Empty database and dedicated MySQL user created; `.env` made from `.env.example`; `php spark key:generate`.
3. `database/bearingcave.sql` imported: 81 tables. `php spark migrate --all` found nothing pending.
4. `php spark bearingcave:create-admin …` created the first super admin.
5. Demo data loaded (`DemoSeeder`, after temporarily setting development mode; it refuses production).
6. Private paths are not reachable over HTTP: `/vendor/autoload.php`, `/.env` and `/../app/Config/App.php` all return 404.
7. Authenticated crawl: **439 of 439 pages OK** (guest plus 14 accounts across every role).
8. Browser test: **133 of 133 checks** (42 screens × 3 sizes, mobile menus, CSV import, logo upload, bulk clearance).
9. RFQ workflow over HTTP (`tests/e2e/rfq_flow.py`): RFQ, matching, distribution, quotation, acceptance and order, **9 of 9 steps passed**.
10. Apache error log: empty. Application log: no errors.

## 7. Not verified (stated honestly)

- **Nginx, cPanel and Windows/XAMPP** were not tested; only Apache on Ubuntu was. Their configuration is documented in `README.md` and `DEPLOYMENT.md`.
- **PHP 8.2 and 8.4** were not run. The tested version is 8.3.6; `composer.lock` and `composer.json` allow PHP 8.2 or newer.
- **MariaDB is not supported** (MySQL 8 CHECK constraints are used).
- Email (SMTP), the escrow/payment gateway and sanctions screening have no provider configured (see `07_PENDING_INTEGRATIONS.md`).
