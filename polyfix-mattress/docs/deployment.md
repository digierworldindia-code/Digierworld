# Deployment

## What the server needs

- PHP 8.2+ with `intl`, `mbstring`, `mysqli`, `gd`, `sodium` (or `openssl`),
  `zip`, `fileinfo`
- MySQL 8.0+
- Apache with `mod_rewrite`, or nginx with PHP-FPM
- HTTPS. Not optional: sessions, sign-in and every form depend on it.

## Laying it out

Put the application outside the document root and point the web server at
`public/` only:

```
/srv/polyfix/            the application
/srv/polyfix/public/     the document root
/srv/polyfix/writable/   logs, sessions, cache, claim uploads — never served
```

Ownership: the files belong to a deploy user, and the web server user needs
write access to `writable/` and nothing else.

```bash
sudo chown -R deploy:www-data /srv/polyfix
sudo find /srv/polyfix -type d -exec chmod 750 {} \;
sudo find /srv/polyfix -type f -exec chmod 640 {} \;
sudo chmod -R 770 /srv/polyfix/writable
sudo chmod 600 /srv/polyfix/.env
sudo chmod +x /srv/polyfix/scripts/*.sh /srv/polyfix/spark
```

### nginx

```nginx
server {
    listen 443 ssl http2;
    server_name polyfixmattress.com;

    root /srv/polyfix/public;
    index index.php;

    client_max_body_size 26M;          # claim videos are up to 25 MB

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_hide_header X-Powered-By;
    }

    # Nothing outside public/ is reachable, but say so anyway.
    location ~ /\.(env|git) { deny all; }

    access_log /var/log/nginx/polyfix-access.log;
    error_log  /var/log/nginx/polyfix-error.log;
}

server {
    listen 80;
    server_name polyfixmattress.com;
    return 301 https://$host$request_uri;
}
```

### Apache

`public/.htaccess` already carries the rewrite rules. The virtual host needs:

```apache
<Directory /srv/polyfix/public>
    AllowOverride All
    Require all granted
</Directory>
```

and `AllowOverride None` — or no `<Directory>` block at all — for
`/srv/polyfix` itself.

## Settings that matter in production

`.env`:

```
CI_ENVIRONMENT = production
app.baseURL = 'https://polyfixmattress.com/'
app.forceGlobalSecureRequests = true
cookie.secure = true
```

`CI_ENVIRONMENT = production` is what stops a stack trace, a SQL statement or a
file path ever reaching a visitor. The application refuses to start in
production if the encryption key or the signing secret is missing or too short
(`app/Config/Events.php`).

PHP:

```ini
upload_max_filesize = 26M
post_max_size = 30M
memory_limit = 256M
expose_php = Off
display_errors = Off
```

Behind a load balancer or CDN, set `app.proxyIPs` to the proxy's addresses —
otherwise every request looks like it comes from the proxy, and rate limiting
throttles everyone at once.

## Releasing

```bash
cd /srv/polyfix
scripts/backup.sh                                    # before anything else

git fetch --all && git checkout <tag>
composer install --no-dev --optimize-autoloader

php spark polyfix:migrate --owner-user polyfix_owner
php spark polyfix:seed ReferenceData                 # if permissions or settings changed

mysql -u root -p polyfix_mattress < database/sql/generate-privileges.sql \
  | mysql -u root -p polyfix_mattress                # new tables need grants

php spark cache:clear
sudo systemctl reload php8.2-fpm
```

Then check the console's **System** screen: it reports the database, the
migrations, whether the application can still write history (it must not), the
upload directory, the configuration and the environment.

## Mail

Password resets are sent by SMTP, configured in `.env` under `email.*`. Until
those are set, a reset link is written to the application log as a warning and
never sent — which is safe, but means nobody can reset a password. Set them
before the first person needs one.

## Scheduled work

```cron
20 2 * * *  /srv/polyfix/scripts/backup.sh >> /var/log/polyfix-backup.log 2>&1
40 2 * * 0  rsync -a --delete /srv/polyfix/writable/uploads/ /srv/backups/uploads/
```

## Logs

`writable/logs/` rotates daily and keeps 30 days
(`app/Config/Logger.php`). In production the threshold is warnings and above.
Lines beginning `security.` are the ones worth alerting on:

| Line | What happened |
|---|---|
| `security.RATE_LIMITED` | an address hit a rate limit |
| `security.PERMISSION_REFUSED` | someone reached a route their role does not allow |
| `security.STAFF_AREA_REFUSED` | a dealer account touched the staff console |
| `security.PRIVILEGE_ESCALATION_BLOCKED` | an attempt to grant a role senior to the grantor's |
| `security.SELF_ROLE_CHANGE_BLOCKED` | someone tried to change their own roles |
| `security.AUDIT_CHAIN_BROKEN` | the audit trail no longer verifies — investigate immediately |
| `security.UPLOAD_REJECTED` | a file was refused by content inspection |

## Going live checklist

- [ ] `CI_ENVIRONMENT = production`, and a real `app.baseURL`
- [ ] Encryption key and signing secret generated fresh, backed up away from the database
- [ ] HTTPS with a valid certificate; HTTP redirects to it
- [ ] `polyfix_app` restricted by `generate-privileges.sql`, verified by `verify-privileges.sql`
- [ ] MySQL not listening on a public interface
- [ ] First administrator created with `polyfix:create-user`; no demo accounts
- [ ] Two-factor enrolled for every SUPER_ADMIN, ADMIN and WARRANTY_MANAGER
- [ ] `seo.robots_allow_indexing` on (and *off* on any staging copy)
- [ ] SMTP configured and a reset email actually received
- [ ] Backup job running, and one restore drill completed
- [ ] `writable/` not reachable by URL — check `https://…/writable/logs/`
