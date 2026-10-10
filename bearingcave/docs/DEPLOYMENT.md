# Production deployment

## Checklist

1. A server with PHP 8.2+ (FPM), MySQL 8 and Nginx or Apache. HTTPS is required.
2. `composer install --no-dev --optimize-autoloader`
3. Configure `.env`:
   - `CI_ENVIRONMENT = production`
   - `app.baseURL = 'https://www.bearingcave.com/'`
   - `app.forceGlobalSecureRequests = true`
   - a strong DB password
   - `encryption.key`, generated once and **backed up offline**
   - SMTP settings
4. Run `php spark migrate --all`, then `php spark db:seed DatabaseSeeder`. Run this **only on first install**: the seeders are idempotent for reference data but must never be replaced by `DemoSeeder`.
5. Create the first admin with `php spark bearingcave:create-admin you@company.com`.
6. The **web root must be `bearingcave/public/`**. Never point it at the project root.
7. Permissions: `writable/` must be writable by PHP-FPM, and everything else read-only. `writable/uploads/private` (KYC and verification documents) is outside the web root.
8. Set up the cron jobs below.
9. Work through `06_CLIENT_DECISIONS_REGISTER.md` and `07_PENDING_INTEGRATIONS.md`. In particular, keep `seo.indexing_enabled=0` until launch and turn on email delivery once SMTP works.
10. Remove `writable/demo/` if it exists. Production must not contain sample data: `SELECT COUNT(*) FROM companies WHERE is_sample=1` must return 0.

## Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name www.bearingcave.com;
    root /var/www/bearingcave/public;
    index index.php;
    client_max_body_size 12M;           # documents.max_upload_mb = 10

    location / { try_files $uri $uri/ /index.php$is_args$args; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
    location ~ /\.(?!well-known) { deny all; }
    location ^~ /uploads/products/ { location ~ \.php$ { deny all; } }   # public product images only
}
server { listen 80; server_name bearingcave.com www.bearingcave.com; return 301 https://www.bearingcave.com$request_uri; }
```

For Apache, the CodeIgniter `public/.htaccess` is included. Set `DocumentRoot` to `/var/www/bearingcave/public` with `AllowOverride All`.

## Cron

```cron
# Daily maintenance: expire subscriptions/certifications, renewal + document reminders,
# expire quotations, recompute performance & buyer scores, flush email outbox
15 2 * * *  cd /var/www/bearingcave && php spark bearingcave:daily  >> writable/logs/cron.log 2>&1
# Deliver queued emails promptly (only does work when email.delivery_enabled = 1)
*/5 * * * * cd /var/www/bearingcave && php spark bearingcave:outbox >> writable/logs/cron.log 2>&1
```

Expiry takes effect at request time even if cron is late, because entitlements read the subscription's date range. Cron handles the notifications and status bookkeeping.

## Backups and recovery

- **Database:** run `mysqldump --single-transaction --routines bearingcave | gzip` nightly. Keep 30 daily and 12 monthly copies off-site.
- **Files:** `writable/uploads/` (private documents) and `public/uploads/products/`, nightly with rsync or object-storage sync.
- **Secrets:** keep `.env`, especially `encryption.key`, in a password manager or vault. Without the key, encrypted bank and ID fields cannot be restored.
- **Restore test:** once a quarter, restore into a staging database, run `php spark migrate --all` (it should report nothing to migrate), then log in and open a KYC record.

## Updating

```bash
git pull   # or unpack a release ZIP
composer install --no-dev --optimize-autoloader
php spark migrate --all
php spark cache:clear
```

Take a database backup before every `migrate`.

## Security hardening

- Keep `CI_ENVIRONMENT=production`. This hides error details and blocks the sandbox payment gateway and DemoSeeder.
- HTTPS only. Secure-header filters are already global. Add HSTS at the web server once HTTPS is confirmed.
- Restrict MySQL to localhost or a private network, and give the application user the privileges it needs: SELECT, INSERT, UPDATE, DELETE, plus CREATE, ALTER, INDEX and REFERENCES while migrating.
- Review `audit_logs` entries with severity `security` regularly (Admin → Audit logs, filter "security").

## Important: this repository's GitHub Pages workflow

The repository root also contains the static Digie R World site, with a GitHub Pages workflow that publishes **the whole repository** from `main`. If this branch is merged into `main` unchanged, the BearingCave PHP source and docs would be published as static files. They contain no secrets (`.env` is git-ignored), but this is not how the app is meant to be deployed. Before merging, either move BearingCave to its own repository or limit the Pages workflow to the static site's files.
