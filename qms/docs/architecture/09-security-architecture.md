# 9. Security architecture

Target: **OWASP ASVS 4.0 Level 2** for the web application, plus database-level integrity for quality records
(ALCOA+: attributable, legible, contemporaneous, original, accurate).

## 9.1 Threat model

| Asset | Threats | Main controls |
|---|---|---|
| Inspection records and signatures | Falsification after the fact, back-dating, deleting failed results, signing as someone else | Server-side PASS/FAIL, frozen data after submission (triggers), append-only signatures, revisions instead of edits, back-dating window, password re-entry to sign, segregation of duties, audit trail |
| Specifications (LSL/USL) | Operator widening limits, silent spec changes | Limits only from published, immutable template versions; LSL/USL snapshotted per observation; template changes audited |
| User accounts | Password guessing, credential sharing, unattended shared tablets | Argon2id, lockout, IP throttling, strong policy, idle timeout, re-auth for signatures, per-user drafts |
| Audit trail | Deletion or editing to hide actions | Append-only triggers + no UPDATE/DELETE grant + backups |
| Google credentials | Theft, leaking into Git or logs | Key file outside the project (0440), env path only, `.gitignore` + secret scanning, sanitised errors, one-spreadsheet scope via sharing |
| Availability | Google outage, network loss on the floor, ransomware, disk failure | Outbox (MySQL first), local drafts + idempotent replay, encrypted off-site immutable backups, tested restores |

Actors considered: operator, verifier, administrator (malicious or careless), plant-network attacker
(Wi-Fi sniffing, ARP spoofing), internet attacker if the server is ever exposed, compromised dependency.

## 9.2 Defence in depth

| Layer | Controls |
|---|---|
| Network | HTTPS only (TLS 1.2/1.3) also on the LAN; HSTS; firewall: 443 (+80 → redirect) from plant VLANs, SSH from the admin VLAN only; MySQL not on the network (socket) |
| Web server | Docroot = `public/` only; dotfiles denied; only `index.php` executes; no directory listing; request size limits; `server_tokens off` |
| PHP | `display_errors=Off`, `expose_php=Off`, `allow_url_fopen=Off`, `allow_url_include=Off`, `session.use_strict_mode=1`, `open_basedir`, dangerous functions disabled, dedicated FPM user |
| Application | CI4 CSRF, validation, `esc()`, Query Builder bindings, secure headers + CSP, auth / permission / timeout filters, record-level authorization in services, idempotency, audit |
| Database | Dedicated least-privilege accounts, triggers, CHECKs, FKs, strict SQL mode, UTC, binlog, no root use |
| Operations | Patching, backups (encrypted, off-site, immutable), monitoring and alerts, secret rotation, restore tests |

## 9.3 Authentication

| Topic | Design |
|---|---|
| Password storage | `password_hash($pw, PASSWORD_ARGON2ID)` (PHP defaults: 64 MiB memory, 4 iterations) with `password_needs_rehash()` on login. bcrypt (cost 12) fallback if Argon2 is unavailable, then max 72 bytes |
| Password policy | Min 10 characters (setting, never below 8), max 128; at least 3 of: upper, lower, digit, symbol; not in the bundled list of 10 000 common passwords; must not contain the username, employee code or name; last 5 passwords cannot be reused; optional expiry (default off, per NIST SP 800-63B); forced change on first login and after an admin reset |
| Login | One generic message ("Invalid username or password, or the account is locked") for unknown user, wrong password, locked or disabled. A dummy `password_verify` runs for unknown users so timing is the same. Every attempt goes to `login_logs` with an internal reason |
| Account lockout | 5 consecutive failures (setting) → locked for 15 min (setting). The counter resets on success. Admin can unlock (audited). The lock event is logged |
| Rate limiting | CI4 Throttler on `POST /login`: 10 requests/min per IP, plus 60/hour per IP. Exceeding → HTTP 429 and a `THROTTLED` log entry |
| Session | CI4 `DatabaseHandler`; ID regenerated on login and on privilege change (`regenerateDestroy = true`); destroyed on logout; idle timeout 30 min and absolute 12 h (settings); `security_stamp` checked on every request, so a password, role or status change ends other sessions; no "remember me"; session cookie lives until the browser closes |
| Cookies | Production name `__Host-qms_session`, `Secure`, `HttpOnly`, `SameSite=Lax`, `Path=/`, no `Domain` |
| Signatures | Verify / return / reject / approve ask for the password again (`ReauthService`, with its own throttle). Five wrong re-entries end the session |
| Shared tablets | Large **Switch user** button (logout), idle logout, drafts kept locally per user and cleared after a successful sync |
| Recovery | No e-mail self-service by default (many operators have no mailbox). An admin reset generates a one-time password shown once, which must be changed at next login |
| Bootstrap | `php spark qms:create-admin` creates the first Super Admin interactively. No default credentials exist anywhere |

## 9.4 Authorization
Permission filter on every route, record-level checks in services (ownership, workflow stage, segregation of
duties), DB grants and triggers below that. Default deny: a route without an explicit permission filter fails a
test in CI. See [06 – Roles and permissions](06-roles-permissions.md).

## 9.5 CSRF and request integrity

- CI4 `csrf` filter is global for POST/PUT/PATCH/DELETE. `csrfProtection = 'session'`, `tokenRandomize = true` (BREACH mitigation), header `X-CSRF-TOKEN` for fetch requests.
- `regenerate = false`: one token per session. This keeps autosave, offline replay and multi-tab use working, and OWASP treats a per-session token as sufficient. The token is rotated at login.
- `SameSite=Lax` cookies, plus an `OriginCheckFilter` that rejects state-changing requests whose `Origin`/`Referer` is not the QMS host.
- `App::$allowedHostnames` plus Nginx `server_name` stop Host-header attacks.
- Duplicate submissions are prevented by `client_uuid` and the `Idempotency-Key` header (see 07 §7.5).

## 9.6 Input validation and output encoding

- Every request is validated server-side with CI4 rule sets. Client checks exist only for usability.
- Readings are validated against the template parameter: type, decimals ≤ `decimal_places`, percentage 0–100, allowed choice codes, date/time formats, text length. LSL/USL and results sent by the client are ignored.
- Models use `$allowedFields` whitelists and casts. Mass assignment is impossible.
- The `invalidchars` filter rejects invalid UTF-8 and control characters.
- Views escape every variable with context-aware `esc()` (html, attr, js, url). No user HTML is ever rendered. JSON responses use `setJSON()`.
- **CSV export** prefixes cells starting with `=`, `+`, `-`, `@`, tab or CR with `'` (spreadsheet formula injection). **Google Sheets** writes use `valueInputOption = RAW`.

## 9.7 SQL injection
Query Builder with bound parameters only. No string concatenation of request data into SQL. Sort and filter
columns are mapped through whitelists. Raw SQL is limited to reviewed, constant statements (migrations, the
sequence allocation). CI fails on patterns such as `->query("…$`.

## 9.8 File uploads

| Upload | Rules |
|---|---|
| Company logo | `uploaded`, `max_size[1024]`, `ext_in[png,jpg,jpeg]`, `mime_in[image/png,image/jpeg]`, `is_image`. Re-encoded with GD, which strips metadata and any polyglot payload. SVG not accepted |
| Calibration certificate | `max_size[5120]`, `ext_in[pdf,png,jpg,jpeg]`, MIME by `finfo` (magic bytes, `%PDF-` for PDFs); images re-encoded |
| Storage | `writable/uploads/<type>/` with a random 32-hex name, the original name only in the DB, SHA-256 stored, directory not executable and outside the docroot |
| Delivery | Through `MediaController` with permission check, `Content-Disposition: attachment` for certificates, `X-Content-Type-Options: nosniff`, `Cache-Control: private` |
| Limits | PHP `upload_max_filesize = 8M`, `post_max_size = 10M`; Nginx `client_max_body_size 10m` |

## 9.9 Security headers

| Header | Value |
|---|---|
| `Content-Security-Policy` | `default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'; upgrade-insecure-requests` (no inline scripts or styles anywhere) |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` (Nginx) |
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` |
| `Referrer-Policy` | `same-origin` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=(), usb=()` |
| `Cross-Origin-Opener-Policy` | `same-origin` |
| `Cache-Control` | `no-store` on every authenticated page and JSON response |

All front-end libraries are served locally, so no CDN can inject code and the app works on an isolated plant network.

## 9.10 Secrets and configuration

| Secret | Where it lives | Never |
|---|---|---|
| DB password (`qms_app`) | PHP-FPM pool `env[database_default_password]` or `/var/www/qms/shared/.env` (root:qms, 0640, outside `public/`) | In Git, in `app/Config`, in logs |
| DB password (`qms_migrator`) | Supplied to the deploy command only; account locked between releases | Stored on the server permanently |
| Google service-account key | `/etc/qms/secrets/google-sa.json` (root:qms, 0440); only its path is configured | In Git, in the DB, in the web root |
| Backup encryption | Public key on the server; **private key offline** (two custodians) | On the server |

- `.env.example` holds variable names with placeholders only. `.gitignore` blocks `.env`, `*.pem`, `*service-account*.json`, `writable/*`.
- CI runs secret scanning (gitleaks) on every push.
- **This repository is public.** Everything committed is visible to anyone, which makes the "no secrets in Git" rule absolute. A private repository is recommended for the production code base.

## 9.11 Database security

- Three dedicated accounts (`database/security/qms_db_users.sql`). The application never uses `root`.
- `qms_app`: SELECT on the schema; DML only where needed; **no UPDATE/DELETE** on `audit_logs`, `login_logs`, `inspection_approvals`, `gauge_calibrations`; **no DELETE** on reports and master data; **no DDL**; no access to other schemas (all verified).
- `qms_migrator`: DDL + TRIGGER, used only during deployment, `ACCOUNT LOCK` between releases (triggers keep working while it is locked, verified).
- `qms_backup`: read-only plus what `mysqldump --source-data` needs.
- Server: unix socket or `bind_address = 127.0.0.1`; if remote, private network + `REQUIRE SSL`; `local_infile = OFF`; strict SQL mode; `validate_password` component for MySQL accounts; binlog on for point-in-time recovery.
- Every write that matters runs in a transaction. The integrity triggers (see 04 §4.5) hold even if application code is wrong.

## 9.12 Server hardening (production)

```nginx
server {
    listen 443 ssl http2;
    server_name qms.example.local;                      # real FQDN
    root /var/www/qms/current/public;                   # ONLY public/
    index index.php;
    server_tokens off;
    autoindex off;
    client_max_body_size 10m;

    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_certificate     /etc/ssl/qms/fullchain.pem;
    ssl_certificate_key /etc/ssl/qms/privkey.pem;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    location ~ /\.            { deny all; }              # .env, .git, .htaccess …
    location ^~ /assets/      { try_files $uri =404; expires 7d; }
    location /                { try_files $uri $uri/ /index.php$is_args$args; }
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param CI_ENVIRONMENT production;
        fastcgi_pass unix:/run/php/qms.sock;
    }
    location ~ \.php$         { return 404; }            # no other PHP file ever executes
}
server { listen 80; server_name qms.example.local; return 301 https://$host$request_uri; }
```

| Path | Owner : group | Mode | Note |
|---|---|---|---|
| `/var/www/qms/releases/<ts>` (code) | `deploy:qms` | 0750 dirs / 0640 files | Code is not writable by PHP |
| `/var/www/qms/shared/writable` | `qms:qms` | 0750 | Only PHP-FPM (`qms`) writes |
| `/var/www/qms/shared/.env` | `root:qms` | 0640 | Outside `public/` |
| `/etc/qms/secrets/` | `root:qms` | 0750 / files 0440 | Google key |

Other items: Ubuntu LTS with unattended security upgrades; SSH keys only, no root login; `ufw`; `fail2ban` for
SSH; `chrony` time sync (signature timestamps must be correct); `CI_ENVIRONMENT=production`; OPcache with
`validate_timestamps=0`, reset on deploy; TLS certificate from an internal CA, or Let's Encrypt via DNS-01 for a
LAN-only host, with expiry monitoring.

## 9.13 Logging, monitoring and alerting

| Source | Content | Retention |
|---|---|---|
| `audit_logs` | Every create / update / workflow action / print / export / settings change / denied access, with before/after JSON, user, employee, IP, user agent, time | Never deleted by the application |
| `login_logs` | Success, failure (internal reason), lockout, unlock, logout, timeout, password change/reset | Never deleted by the application |
| App log (`writable/logs`) | Errors with `request_id`, no secrets or personal data | 90 days |
| Nginx access/error logs | Requests (query strings with tokens are never used) | 30 days |

Alerts (e-mail or WhatsApp-gateway hook, configured at deployment): repeated lockouts or 429s from one IP, spike in
`ACCESS_DENIED`, sync queue stuck or FAILED jobs, backup failure or missing, disk above 80 %, TLS certificate
expiring within 21 days.

## 9.14 Backup and recovery strategy

| Item | Schedule | Method |
|---|---|---|
| Full logical backup | Daily 01:30 (between shifts B and C is also possible) | `mysqldump --single-transaction --routines --triggers --hex-blob --source-data=2` as `qms_backup` → `zstd` → encrypted with `age` (public key) |
| Binary logs | Continuous; shipped every 15 min | Point-in-time recovery, **RPO ≤ 15 min** |
| Uploads (`writable/uploads`) | Daily | `rsync` → encrypted archive |
| Off-site copy | After every backup | Pulled by a backup host or written to object storage with object-lock (immutable), so a compromised app server cannot delete it |
| Retention | 14 daily · 8 weekly · 12 monthly · yearly kept per record-retention policy | Script prunes local copies; off-site lifecycle rules |
| Restore test | Monthly | Restore to a staging DB, run `verify` checks and row counts, record the result; **RTO ≤ 2 h** per runbook |
| Monitoring | Every run | Exit code + checksum + size check; the "last successful backup" time is shown to the Super Admin |

## 9.15 Secure development lifecycle

- `composer.lock` committed; `composer audit` and Dependabot on every push; no runtime CDN.
- CI: `php -l`, PHPUnit (unit + database + feature) on MySQL 8.4, PHPStan, gitleaks, a route-permission test (every route has an explicit permission filter).
- Before go-live: OWASP ZAP baseline scan, manual review of authorization per role, restore drill.
- Every module is reviewed against this document before merge.

## 9.16 OWASP Top 10 (2021) mapping

| Risk | Mitigation in QMS |
|---|---|
| A01 Broken Access Control | Permission filter per route (default deny), record-level checks, segregation of duties, DB grants, triggers |
| A02 Cryptographic Failures | TLS everywhere, HSTS, Argon2id, encrypted backups, no secrets in code |
| A03 Injection | Query Builder bindings, whitelisted sort/filter, `esc()`, CSP, CSV and Sheets formula neutralisation |
| A04 Insecure Design | Threat model, outbox, immutable records, revisions, idempotency, server-side evaluation |
| A05 Security Misconfiguration | Docroot `public/`, hardened Nginx/PHP/MySQL configs, production error pages, no default accounts, security headers |
| A06 Vulnerable Components | Locked dependencies, `composer audit`, Dependabot, minimal Google client |
| A07 Identification and Authentication Failures | Lockout, throttling, strong policy, session regeneration and timeouts, re-auth for signatures, no user enumeration |
| A08 Software and Data Integrity Failures | Lockfile, local assets (no CDN), signed deploy artefacts, DB triggers on quality records |
| A09 Security Logging and Monitoring Failures | Append-only audit and login logs, alerts, request IDs |
| A10 Server-Side Request Forgery | No user-supplied URLs are ever fetched; outbound traffic only to Google API hosts |
