# 07 — Security

The model, the controls that implement it, and the gaps that are known and open.

**No software is unbreakable, and nothing here claims to be.** These controls
raise the cost of an attack and make a compromise visible. They do not make one
impossible.

---

## The model

**Trust only the backend, the authenticated session, and validated database
operations.**

Never trusted: the browser, a QR code, a dealer, a customer, a URL parameter,
an uploaded file, a hidden form field, client-side validation.

Three properties, in priority order:

1. **Data security** — the database is not reachable from the internet, secrets
   live only in server-side environment, customer contact data is encrypted at
   rest.
2. **Data integrity** — the audit log is append-only and hash-chained;
   identifiers come from database sequences, not application code.
3. **Authorisation** — every endpoint declares the permission it requires; every
   dealer-scoped query is filtered by PostgreSQL, not by a `where` clause.

---

## Network

```
browser ──► web / console ──► api ──► PostgreSQL
                              ▲
                   the only process holding
                   database credentials
```

The browser has no route to the API. The public site renders on the server; the
console proxies through its own `/api/bff/*` handler. Neither client bundle
contains an internal address, an API key or a database credential.

`docker-compose.yml` puts the database on an `internal: true` network that the
web and console containers are not attached to — they have no route to it even
if compromised. Every published port binds to `127.0.0.1`.

**The database port is not publicly accessible.** On a production host the
`ports:` block is deleted entirely.

---

## Authentication

**Passwords** — Argon2id (`@node-rs/argon2`), 19 MiB memory, 2 iterations,
parallelism 1, per the OWASP cheat sheet. Parameters are recorded in the encoded
hash, so raising them later re-hashes users transparently on next login. No
plaintext, no reversible encryption, never written to a log.

Policy: ≥12 characters, upper, lower, digit, symbol, and not containing the
user's own email, name, or the brand name.

**Sessions** — a short-lived JWT access token (15 min default, HS256) plus an
opaque, database-backed refresh token (30 days default). Refresh tokens **rotate
on every use**, and presenting one twice is treated as theft: the whole session
family is revoked and `REFRESH_TOKEN_REUSE` is logged. Idle sessions expire
after `SESSION_IDLE_TIMEOUT_SECONDS`.

**Cookies** — three, each with the narrowest settings that still work:

| Cookie | `httpOnly` | Path |
|---|---|---|
| `clf_at` access token | yes | `/` |
| `clf_rt` refresh token | yes | `/auth` |
| `clf_csrf` CSRF token | **no** — the console must read it | `/` |

All `SameSite=Strict`, and `Secure` in staging and production.

**MFA** — TOTP, built on `node:crypto`. Required for every role in
`MFA_REQUIRED_ROLES`. Separately, these permissions are **MFA-gated** and are
never granted by a role assignment alone — the holder must have MFA enabled and
satisfied on the current session:

```
system:backup · system:export · system:sql:read
system:settings:write · user:role:assign
```

TOTP secrets are stored encrypted, not in plaintext.

**Brute force** — `LOGIN_MAX_ATTEMPTS` (5) then a `LOGIN_LOCKOUT_SECONDS` (15
min) lockout, plus a per-address rate limit of `RATE_LIMIT_LOGIN_PER_MINUTE`.
Attempts are recorded in `failed_login_attempts` — including the email typed,
which is why that table is never rewritten.

---

## Authorisation

`packages/auth/src/rbac.ts` is the single source of truth: the database's
`permissions` and `role_permissions` tables are seeded from it, the API's
`requirePermission` guard checks against it, and the console renders its
navigation from the permissions the signed-in user actually holds.

Seven roles — `SUPER_ADMIN`, `ADMIN`, `WAREHOUSE`, `WARRANTY_MANAGER`,
`SALES_MANAGER`, `DEALER`, `REPORTING` — over 55 explicit permissions.

Two rules the rest of the codebase depends on:

1. **A permission grants an action, never a scope.** Holding `sale:read` does
   not mean "read every sale"; dealer scoping is applied separately and
   unconditionally.
2. **Anything not listed is denied.** There is no wildcard at request time;
   `SUPER_ADMIN` is expanded to the explicit list at seed time.

There is no blanket "admin area" check. A warehouse user reaching a warranty
decision endpoint is refused by the same mechanism that refuses an anonymous
caller. Separately, the whole `/ops/*` tree sits behind one `requireStaff` hook
registered on its encapsulated context, so a new route cannot forget it — added
after testing showed dealers could reach staff endpoints through the shared
`dashboard:view` permission.

---

## Dealer isolation — enforced by PostgreSQL

Sixteen tables carry `FORCE ROW LEVEL SECURITY` and a policy keyed on
`app.dealer_id`, a transaction-local setting the API sets per request from the
**authenticated session**, never from client input.

**It fails closed.** No scope matches nothing, not everything. A forgotten
filter in application code cannot leak another dealer's data.

Staff carry the sentinel `'ALL'` — not an empty string. PostgreSQL reverts
transaction-local custom settings to `''`, so an empty-string sentinel meant a
pooled connection could inherit staff-wide visibility from a previous request.
Migration `0005` fixed it.

`FORCE` matters: without it the policies would not apply to the table owner, and
a migration or maintenance script would see everything.

---

## Data at rest

**Column encryption** — customer phone, email and address are AES-256-GCM
encrypted with `ENCRYPTION_KEY`. Each has a keyed-HMAC **blind index**, so an
exact-match lookup still works without decrypting. Range and prefix search on
those columns does not; that is the trade.

**`ENCRYPTION_KEY` is not recoverable.** Lose it and that data is gone. Back it
up before first use; rotating it is a migration that re-encrypts every affected
row, not a config change.

**Backups** are AES-256-CBC encrypted with PBKDF2 at 600 000 iterations before
they leave the host, and production **refuses** to produce an unencrypted one.

---

## The audit trail

Append-only, enforced three independent ways:

1. a database trigger computes each row's SHA-256 over its content plus its
   predecessor's hash
2. triggers deny `UPDATE`, `DELETE` and `TRUNCATE`
3. the application role holds no privilege to do any of them

**Application users cannot delete audit logs.** Not through the console, not
through the API, not through a crafted request — the privilege does not exist on
the connection.

```sql
SELECT * FROM colifees_verify_audit_chain(0::bigint, 1000000);
```

Any row returned is a broken link. This makes tampering **detectable**, not
impossible: someone with superuser access can disable a trigger. What they
cannot do is leave the chain consistent afterwards.

---

## Request-level protections

**CSRF** — three checks, all must pass: `SameSite=Strict` cookies, a
double-submit token (`clf_csrf` cookie must equal the `x-csrf-token` header,
compared in constant time), and an `Origin` check against the allow-list. Safe
methods are exempt because they must not change state.

**CORS** — an explicit allow-list of two origins. `origin: true` would reflect
any origin, which with credentials enabled is equivalent to no policy at all.

**Rate limiting** — a global backstop plus tighter limits where it matters:
login 5/min, password reset 3/15 min, MFA operations 5–10/5 min, public forms
5/hour, warranty verification 20/min. The key is the client address, and
`X-Forwarded-For` is honoured **only** when `TRUST_PROXY` is set — otherwise
anyone could rotate the header to reset their own limit.

**Headers** — the API serves JSON only, so its CSP is `default-src 'none'` with
every directive an allow-list. Both Next apps set their own, necessarily looser,
policies. HSTS in production, `X-Frame-Options: deny`, `Referrer-Policy:
no-referrer`, `nosniff`, a restrictive `Permissions-Policy`, `Cache-Control:
no-store`, and `X-Powered-By` removed.

**Uploads** — `MAX_UPLOAD_BYTES` (8 MiB) and `MAX_UPLOADS_PER_CLAIM` (8),
content type checked server-side, storage keys resolved and verified to stay
under the storage root. Media is served through short-lived signed URLs issued
by the API after an authorisation check — never from a public bucket.

---

## What callers never receive

No password hash, no secret, no database credential, no stack trace, no SQL
fragment, no Prisma message, no internal file path, no private token.

Errors have two audiences: the caller gets a stable machine code and one
friendly sentence; the log gets the cause, the stack and the request id.
Anything that is not a recognised `AppError` becomes a generic "Something went
wrong" with a request id to quote to support. Enforced in exactly one place, the
Fastify error handler.

Logs never contain passwords, access tokens, full sensitive personal data or
database passwords. The config validator prints variable **names** only.

---

## The technical SQL console

`system:sql:read` permits **read-only** queries, requires `SUPER_ADMIN`, and is
MFA-gated. There is no arbitrary unrestricted SQL execution for ordinary
administrators, and no path to `UPDATE`, `DELETE` or DDL through the browser.

---

## Known gaps

Stated plainly, because a security document that lists only strengths is
marketing.

### `audit_logs` has no row-level security

`scripts/sql/03_verify_privileges.sql` check 2 reports `audit_logs` on a correct
install. The table has a `dealer_id` column and RLS is not enabled on it.

*Compensating controls:* reading the audit trail requires `audit:read`, granted
only to `SUPER_ADMIN` and `ADMIN`. The endpoint lives under `/ops/*`, which is
behind the `requireStaff` hook, and `DEALER` holds neither. No dealer-facing
route reads the table.

*Residual risk:* the protection is at the permission layer only. A bug that
exposed audit rows to a dealer session would not be caught by the database, as
it would be for every other dealer-scoped table. This is a genuine deviation
from the defence-in-depth posture everywhere else and should be closed —
carefully, since dealer sessions do *write* audit rows and a `WITH CHECK` clause
that is wrong would break the audit trail itself.

### `INTERNAL_API_TOKEN` is sent but not verified

`apps/web/src/lib/api.ts` attaches `x-internal-token` to every call it makes to
`/public/*`, and `.env.example` describes it as a "shared secret proving a
request came from a trusted front-end". **The API never checks it.**

*Why it is not simply switched on:* `/public/*` backs a public website. Its
endpoints serve indexed pages, the dealer locator and a warranty lookup that
anyone can perform from a label on a mattress they own. The security suite
asserts anonymous access to them. Requiring the token would be a change to the
product, not a hardening of it.

*Residual risk:* low on its own — these endpoints return nothing private, and
`/public/verify` deliberately omits the customer's name, phone and address. But
the header reads as a control to anyone auditing the code, and it is not one.
Either wire it up on the write endpoints (`/public/leads`,
`/public/dealer-applications`), where a trusted-origin check would genuinely
reduce form spam beyond the existing rate limit, or remove it. Leaving a
decorative security header in place is the worst of the three.

### The API ships as source

`apps/api` runs TypeScript through `tsx` rather than a compiled bundle. Type
checking happens at build time in the image, so this is not a correctness gap,
but the runtime image carries source and a larger dependency surface than a
bundle would.

### No point-in-time recovery out of the box

Backups are periodic snapshots. Recovering to an arbitrary moment needs WAL
archiving or a managed provider. Decide what gap is acceptable and write it
down.

### Session cookie names

`clf_at` / `clf_rt` / `clf_csrf` derive from the previous company name. They are
a live contract with every browser holding a session, so they were left alone
during the rename. Cosmetic.

---

## Reporting a vulnerability

Do not open a public issue. Contact the platform owner directly with steps to
reproduce.
