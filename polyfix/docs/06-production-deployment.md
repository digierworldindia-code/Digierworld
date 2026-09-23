# 06 — Production deployment

What must be true before this platform faces the internet.

---

## Topology

```
                      ┌─────────────────────────────┐
   internet ──TLS──►  │  reverse proxy / CDN        │
                      │  polyfixmattress.com        │──► web     :3000
                      │  app.polyfixmattress.com    │──► console :3001
                      └─────────────────────────────┘
                                                        │  (private network)
                                                        ▼
                                                       api      :4000
                                                        │
                                                        ▼
                                                   PostgreSQL :5432
                                                   (no published port)
```

Non-negotiable:

- **PostgreSQL has no publicly reachable port.** Not firewalled-but-listening —
  not listening. In `docker-compose.yml`, delete the database's `ports:` block
  on a production host; the API reaches it by service name on the internal
  network.
- **The API is not published either.** Only `web` and `console` need to reach
  it, and they do so over the private network.
- **TLS is terminated at the proxy.** Nothing in this stack terminates TLS
  itself.
- The browser never holds an API address, a database credential or an internal
  hostname.

---

## Before the first release

### Secrets

Every one from a secret manager, injected at runtime. No `.env` file on a
production host.

| | |
|---|---|
| `AUTH_SECRET` | ≥32 chars, **must differ from** `SIGNING_SECRET` — enforced at boot |
| `SIGNING_SECRET` | ≥32 chars |
| `ENCRYPTION_KEY` | base64, exactly 32 bytes decoded. **Unrecoverable if lost.** |
| `INTERNAL_API_TOKEN` | proves a request came from a trusted front end |
| database role passwords | four of them, all different |
| `BACKUP_ENCRYPTION_PASSPHRASE_FILE` | a path, `chmod 600` |

```bash
openssl rand -base64 48    # secrets and tokens
openssl rand -base64 32    # ENCRYPTION_KEY
```

The config layer refuses to boot on a missing or weak value, and prints variable
**names** only — never values.

### What production additionally enforces

With `APP_ENV=production`, startup fails unless:

- `AUTH_SECRET != SIGNING_SECRET`
- `DATABASE_URL` carries `sslmode=require`, `verify-ca` or `verify-full`
- `STORAGE_DRIVER` is not `local`

The local storage driver writes claim media to the container's filesystem: it
disappears on redeploy and is not shared between replicas. Production uses a
**private** S3-compatible bucket with no public read; media is served through
short-lived signed URLs issued by the API after an authorisation check.

```
DATABASE_URL=postgresql://colifees_app:…@db:5432/colifees?sslmode=verify-full&sslrootcert=/etc/ssl/certs/pg-ca.crt&connection_limit=10&pool_timeout=20
```

### Environment

```
APP_ENV=production
NODE_ENV=production
LOG_LEVEL=info
PUBLIC_WEB_ORIGIN=https://polyfixmattress.com
PRIVATE_APP_ORIGIN=https://app.polyfixmattress.com
API_PUBLIC_URL=https://api.internal.polyfixmattress.com
COOKIE_DOMAIN=.polyfixmattress.com
TRUST_PROXY=true
MFA_REQUIRED_ROLES=SUPER_ADMIN,ADMIN,WARRANTY_MANAGER
```

`PUBLIC_WEB_ORIGIN` and `PRIVATE_APP_ORIGIN` are the CORS allow-list and the
CSRF `Origin` check. An origin not listed is refused.

`TRUST_PROXY=true` only when something you control terminates TLS in front of
the API. It makes rate limiting read `X-Forwarded-For`; set it with nothing in
front and anyone can rotate that header to reset their own limits.

`COOKIE_DOMAIN` set to the parent domain lets one session cover both hosts.
Leave it blank if they are unrelated domains.

---

## Reverse proxy

Minimum: TLS, HSTS, real client IP, and a body limit.

```nginx
server {
  listen 443 ssl http2;
  server_name polyfixmattress.com;

  ssl_protocols TLSv1.2 TLSv1.3;
  add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;

  client_max_body_size 10m;      # MAX_UPLOAD_BYTES is 8 MiB by default

  location / {
    proxy_pass http://127.0.0.1:3000;
    proxy_set_header Host              $host;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
  }
}
```

Repeat for `app.polyfixmattress.com` → `127.0.0.1:3001`. Redirect all port 80
traffic to 443.

The console sends `noindex` itself, but denying crawlers at the proxy costs
nothing.

---

## Release

```bash
# 1. back up first, always
./scripts/backup.sh --kind manual --out /var/backups/pre-release

# 2. build
docker compose --profile full build

# 3. migrate — as a separate step, never from a container entrypoint
pnpm db:deploy
psql "$DIRECT_DATABASE_URL" -f scripts/sql/02_grants.sql

# 4. verify privileges
psql "$DIRECT_DATABASE_URL" -f scripts/sql/03_verify_privileges.sql

# 5. roll out
docker compose --profile full up -d

# 6. confirm
curl -fsS https://polyfixmattress.com/health 2>/dev/null || true
curl -fsS http://127.0.0.1:4000/health/ready
```

Step 3 runs before the new application version, so migrations must be
backward-compatible for the length of the rollout — the old code has to survive
the new schema. See [05 — Migrations](05-migrations.md#deployment-order).

Migrating from an entrypoint runs it once per replica. Don't.

### `NEXT_PUBLIC_*` is baked into the image

Those values are inlined into the client bundle at **build** time. Changing them
in the orchestrator does nothing; rebuild the image. They are public by
definition — never pass a secret as a build argument.

---

## The first deploy after the brand rename

The JWT issuer and audience changed to `polyfix.auth` / `polyfix.api`. **Access
tokens issued under the old identifiers fail verification, so the first deploy
carrying that change signs every active session out once.** Nothing is lost —
refresh tokens are opaque and database-backed, so clients refresh and continue —
but expect a spike of re-authentications. Tell staff and dealers beforehand.

---

## Health checks

| | |
|---|---|
| `/health` | liveness. No database access. Use this for restart decisions. |
| `/health/ready` | readiness. Round-trips to the database. Use this for load-balancer membership. |

Using `/health/ready` as a liveness probe restarts the API every time the
database hiccups, which is the opposite of helpful.

---

## Operations

**Backups.** Schedule them on day one, off-site, encrypted, and restore one
before you believe in them. [03](03-database-backup.md), [04](04-database-restore.md).

**Logs.** Structured JSON on stdout, one line per request. They never contain
passwords, tokens, database credentials or full customer records. Ship them to a
collector; a log on a host that failed is a log you cannot read.

**Security events.** The API emits `securityEvent` lines for
`REFRESH_TOKEN_REUSE`, `CSRF_TOKEN_MISMATCH`, `CSRF_ORIGIN_REJECTED` and
`CORS_REJECTED`. `REFRESH_TOKEN_REUSE` means a refresh token was presented
twice — either a stolen token or a broken client. It revokes the session family
automatically. Alert on it.

**The audit chain.** Verify on a schedule, not only after an incident:

```sql
SELECT * FROM colifees_verify_audit_chain(0::bigint, 1000000);
```

Any row is a broken link. Investigate immediately.

**Database.** Tune `shared_buffers`, `work_mem` and `max_connections` for the
host; the defaults suit a laptop. `connection_limit` in `DATABASE_URL` is per
API process — multiply by replica count and keep the total under
`max_connections` with headroom for maintenance sessions.

---

## Hardening checklist

- [ ] Database has no published port; reachable only on the private network
- [ ] API not published to the internet
- [ ] TLS everywhere, HSTS on, HTTP redirected
- [ ] `DATABASE_URL` uses `sslmode=verify-full`
- [ ] All secrets from a secret manager; no `.env` on the host
- [ ] `AUTH_SECRET` ≠ `SIGNING_SECRET`
- [ ] `ENCRYPTION_KEY` backed up somewhere you will still have it after a fire
- [ ] `STORAGE_DRIVER=s3`, private bucket, no public read
- [ ] Backups: scheduled, encrypted, off-site, **and restored once**
- [ ] `03_verify_privileges.sql` clean (bar the one documented finding)
- [ ] `TRUST_PROXY` matches reality
- [ ] MFA enforced for privileged roles; the seeded admin has enrolled
- [ ] Containers run as non-root (the images set `USER polyfix`)
- [ ] Log shipping and alerting on security events and backup absence
- [ ] `pnpm test`, `pnpm typecheck`, `pnpm build` green on the release commit

---

## Rollback

```bash
docker compose --profile full up -d --no-deps api=<previous-tag>
```

Application rollback is cheap. **Database rollback is not** — which is the
argument for backward-compatible migrations. If a migration must be reversed,
use its down path in `scripts/sql/`, newest first, and expect to need the
pre-release backup. [04](04-database-restore.md), [05](05-migrations.md#rolling-back).
