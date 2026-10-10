# BearingCave

Global B2B marketplace for automotive surplus inventory: supplier verification, inventory management, RFQ procurement and enterprise administration.

**Stack:** CodeIgniter 4.7 · PHP 8.2+ · MySQL 8 · CodeIgniter Shield · Bootstrap 5.3, self-hosted · server-rendered views and vanilla Fetch API.

## Quick start

```bash
composer install
cp .env.example .env && php spark key:generate      # then set DB credentials in .env
php spark migrate --all
php spark db:seed DatabaseSeeder                     # reference data and settings only
php spark bearingcave:create-admin you@example.com   # first super admin
php spark db:seed DemoSeeder                         # optional, non-production: [SAMPLE] data, passwords in writable/demo/credentials.txt
php spark serve
```

Run the tests with `vendor/bin/phpunit` (22 tests, using the separate `bearingcave_test` database). For browser checks, run `node tests/e2e/responsive.mjs`.

## Status at a glance

- Every core workflow is implemented and tested: verification, membership, catalog and search, inventory, bulk import, RFQ and matching, cart and orders, inspection, logistics, disputes, the admin modules, reports and the API. See `docs/TESTING_REPORT.md`.
- These have **no external provider yet**, so the app never claims they happen: email delivery, escrow/payment gateway, sanctions screening and SMS. See `docs/07_PENDING_INTEGRATIONS.md`.
- **Business policies** that are still open (tax, logistics base, refund terms and others) are configurable and marked *Pending approval*. See `docs/06_CLIENT_DECISIONS_REGISTER.md`.
- The source Google Sheet could not be accessed. Requirements come from the written brief. See `docs/01_REQUIREMENTS_TRACEABILITY.md`.

All documentation is indexed in [`docs/README.md`](docs/README.md).

## Layout

```
app/Config         routes, filters, Shield groups and permissions, services
app/Controllers    Web/, Account/, Buyer/, Supplier/, Admin/, Api/V1/
app/Services       business rules (visibility, entitlements, inventory, RFQ, orders, …)
app/Database       migrations, seeders (reference, settings, plans, demo)
app/Views          layouts, components, web/, buyer/, supplier/, admin/
public/            web root (assets, vendor CSS/JS, uploads/products)
writable/          logs, sessions, private uploads (KYC documents), demo credentials
tests/             PHPUnit unit and feature tests, e2e/ browser and crawl scripts
tools/             ER diagram and permissions matrix generators, packaging
```

`LICENSE` is the CodeIgniter appstarter's MIT licence, which covers the framework skeleton. The licence for the BearingCave application code is for the client to decide.
