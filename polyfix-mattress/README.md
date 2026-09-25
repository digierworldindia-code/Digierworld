# POLYFIX MATTRESS

A mattress company's whole operation, in one CodeIgniter 4 application: the
public website, the staff console, the dealer portal, and the record that ties
them together — every mattress serialised at the end of the line and traceable
through dispatch, sale, warranty and any claim.

This is a port of the previous Node/TypeScript platform onto PHP 8.2+,
CodeIgniter 4, MySQL 8 and Bootstrap 5. The data came across intact: 4,017 rows
across 41 tables, value by value, with the audit hash chain still verifying.
Serial numbers issued by the previous platform keep their `CLF` prefix, printed
QR labels keep working, existing passwords still sign in, and encrypted
customer contact details still decrypt.

---

## What it does

**The website** (`/`) — the range, each mattress with its materials, densities
and firmness rating, the dealer locator, and the warranty check. Content comes
from the database, so marketing can rewrite any page without a deploy.

**The warranty check** (`/warranty/verify?q=…`) — the address printed on every
QR label. Scan it with a phone camera, or type the serial number. It answers
with the product, when it was made, and whether the warranty is running. It
never shows the customer, the dealer, the price or any claim history, because
the endpoint does not select those columns.

**The console** (`/admin`) — production batches and serialisation, dispatch to
dealers, receiving, claims with their risk indicators and evidence, warranties,
the dealer network, users and roles, website content and SEO, reports with CSV
and Excel export, system health, and the audit trail.

**The dealer portal** (`/dealer`) — built for a phone held in one hand in a
showroom: scan a label, confirm a consignment unit by unit, record a sale,
raise a claim with photographs. A dealer sees their own records and nothing
else.

---

## Requirements

| | |
|---|---|
| PHP | 8.2 or newer, with `intl`, `mbstring`, `mysqli`, `gd`, `sodium` (or `openssl`) |
| MySQL | 8.0 or newer (`utf8mb4`) |
| Composer | 2.x |
| Web server | Apache or nginx, document root at `public/` |

No Node, no build step: Bootstrap 5, the icon font and the two web fonts are
vendored in `public/assets`.

---

## Getting it running

```bash
composer install --no-dev --optimize-autoloader     # add --dev to run the tests
cp .env.example .env                                # then edit it
```

Set at least these in `.env`:

```
CI_ENVIRONMENT = production
app.baseURL = 'https://your-domain'

database.default.hostname = 127.0.0.1
database.default.database = polyfix_mattress
database.default.username = polyfix_app
database.default.password = '…'
database.default.DBDriver = App\Database\MySQLi

polyfix.encryptionKey = '…'   # 32 bytes, base64 — see below
polyfix.signingSecret = '…'
encryption.key = '…'
```

Generate the secrets (never reuse an example value):

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'   # polyfix.encryptionKey
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'         # polyfix.signingSecret
php spark key:generate                                    # encryption.key
```

Create the database and its two accounts, apply the schema, then drop the
application down to least privilege:

```bash
mysql -u root -p -e "CREATE DATABASE polyfix_mattress CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
# create polyfix_owner, polyfix_app and polyfix_backup — see docs/database.md

php spark polyfix:migrate --owner-user polyfix_owner
php spark polyfix:seed ReferenceData

mysql -u root -p polyfix_mattress < database/sql/generate-privileges.sql | mysql -u root -p polyfix_mattress
mysql -u root -p polyfix_mattress < database/sql/verify-privileges.sql
```

Make the first administrator. There is no default password: one is generated,
shown once, and must be changed at first sign-in.

```bash
php spark polyfix:create-user --email you@your-domain --name "Your Name" --role SUPER_ADMIN
```

Point the web server's document root at `public/`. Nothing above it is meant to
be reachable, and `writable/` — which holds claim photographs, logs and the
session store — must never be served.

---

## Bringing data over from the previous platform

With the old PostgreSQL database still running and read-only access to it:

```bash
php spark polyfix:import-postgres --pg-dsn "host=… port=5432 dbname=colifees" --pg-user colifees_backup --dry-run
php spark polyfix:import-postgres --pg-dsn "host=… port=5432 dbname=colifees" --pg-user colifees_backup
```

It opens a read-only, repeatable-read snapshot of PostgreSQL, refuses to run
against a non-empty target, copies every table inside one transaction, then
compares every value it wrote. It never writes to PostgreSQL.
`docs/migration.md` has the detail, including what was verified after the real
run.

---

## Day to day

```bash
php spark polyfix:migrate --owner-user polyfix_owner   # apply new migrations
php spark polyfix:create-user …                        # add a person
php spark polyfix:seed ReferenceData                   # refresh roles and permissions after an upgrade
scripts/backup.sh                                      # nightly backup (see docs/backup.md)
scripts/restore.sh <file.sql.gz>                       # restore into a named database
```

Running the tests needs the dev dependencies and a scratch database:

```bash
php spark polyfix:migrate --owner-user polyfix_test --group tests
php spark polyfix:seed ReferenceData --group tests
vendor/bin/phpunit
```

---

## How it is laid out

```
app/
  Config/            Brand.php holds the brand; Polyfix.php the policy; Routes.php every URL
  Controllers/       thin: read input, call a service, choose a view
    Site/            the public website
    Admin/           the staff console
    Dealer/          the dealer portal
  Services/          the business rules — auth, logistics, sales, claims, risk, reports
  Libraries/         crypto, audit chain, RBAC, dealer scope, identifiers, labels
  Filters/           authentication, staff/dealer areas, permissions, throttling, headers
  Database/          migrations, the generated schema, the reference-data seeder
  Views/             layouts, the website, the console, the portal
database/sql/        privilege scripts and the optional append-only triggers
docs/                database, migration, backup, restore, deployment, security
public/              the document root: index.php and the vendored assets
scripts/             backup and restore
tests/               170 tests against a real MySQL schema
writable/            logs, sessions, cache, and claim uploads — never web-served
```

Business rules live in `app/Services`. Controllers do not contain SQL, views do
not contain queries, and permissions are checked in filters rather than in
templates — a hidden link is a courtesy, the filter is the control.

---

## The documentation

| | |
|---|---|
| `docs/database.md` | schema, accounts, privileges, the append-only history |
| `docs/migration.md` | the PostgreSQL → MySQL port, what changed and what was verified |
| `docs/backup.md` | what to back up, how, and how often |
| `docs/restore.md` | restoring, including a drill you can run safely |
| `docs/deployment.md` | putting it on a server, and upgrading it |
| `docs/security.md` | what protects what, and what is left to the operator |

---

*POLYFIX MATTRESS — Manesar, Noranpur Chowk, Haryana, India*
