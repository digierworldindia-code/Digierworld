# Installation (local / staging)

## Requirements

- PHP **8.2+** with these extensions: `intl`, `mbstring`, `mysqli`, `json`, `curl`, `gd`, `zip`, `xml`, `openssl`, `fileinfo`
- MySQL **8.0+** (MariaDB is not tested: the schema uses CHECK constraints and `ANY_VALUE`)
- Composer 2
- Optional, only for the browser E2E test: Node 18+ with Playwright and Chromium

## 1. Get the code and dependencies

```bash
cd bearingcave
composer install            # add --no-dev on production
```

## 2. Create the databases

```sql
CREATE DATABASE bearingcave      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE bearingcave_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;  -- PHPUnit only, wiped on every run
CREATE USER 'bc'@'localhost' IDENTIFIED BY 'choose-a-strong-password';
GRANT ALL PRIVILEGES ON bearingcave.*      TO 'bc'@'localhost';
GRANT ALL PRIVILEGES ON bearingcave_test.* TO 'bc'@'localhost';
```

## 3. Environment

```bash
cp .env.example .env
php spark key:generate      # writes encryption.key — back it up: encrypted KYC/bank fields cannot be read without it
```

Edit `.env`:
- `app.baseURL`
- the `database.default.*` and `database.tests.*` settings
- `email.*`, only if you have SMTP

Never commit `.env`.

## 4. Schema and reference data

```bash
php spark migrate --all     # runs Shield's and BearingCave's migrations
php spark db:seed DatabaseSeeder
```

`DatabaseSeeder` loads only reference and configuration data:
- 249 countries and 8 currencies
- 61 platform settings, each with an approval status
- 4 membership plans and their entitlements
- categories and brands
- draft CMS pages
- the 86 proposed Supplier Master fields

It creates **no** companies, users or products.

## 5. First super admin

```bash
php spark bearingcave:create-admin admin@yourcompany.com
# prompts for the password (min. 10 chars); add --group finance_manager etc. for other staff roles
```

## 6. Optional demo data (local and staging only)

```bash
php spark db:seed DemoSeeder
```

What DemoSeeder does:
- Refuses to run when `CI_ENVIRONMENT=production`.
- Creates `[SAMPLE]` companies flagged `is_sample=1`. They show a **SAMPLE DATA** badge in the UI.
- Creates staff and marketplace demo accounts with **random passwords**, written to `writable/demo/credentials.txt` (git-ignored, readable only on the server). Nothing is hard-coded.
- Builds its records through the real services (verification, sandbox payment, RFQ, order), so the data is consistent.

Delete `writable/demo/credentials.txt` when you no longer need it.

## 7. Run

```bash
php spark serve             # http://localhost:8080
```

## 8. Tests

```bash
vendor/bin/phpunit                          # 22 tests; uses bearingcave_test, wipes it each run
node tests/e2e/responsive.mjs               # needs server + DemoSeeder; screenshots in build/e2e/
python3 tests/e2e/crawl.py                  # authenticated link crawl of every demo account
```

## Troubleshooting

| Symptom | Fix |
|---|---|
| `429 Too many attempts` on login | The throttle allows 10 POSTs per minute per IP on login, register, contact and password forms. Wait one minute. |
| Blank page or 500 | Check `writable/logs/`. Make sure `writable/` is writable by the web user. |
| "Encryption key missing" | Run `php spark key:generate`. |
| Emails not arriving | Expected until SMTP is configured and `email.delivery_enabled` = 1. See Admin → Email outbox. |
