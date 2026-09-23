# POLYFIX MATTRESS — platform

A public website and a private operations console over one PostgreSQL database,
tracking every mattress from the production line to a warranty claim.

**Manesar, Noranpur Chowk, Haryana, India**

---

## What this is

Two applications and one API, deliberately separated:

| | | |
|---|---|---|
| **Public website** | `apps/web` | Server-rendered marketing site, product catalogue, dealer locator, warranty lookup. Indexed by search engines. Holds no database credential and no private data. |
| **Private console** | `apps/app` | Admin, warehouse, warranty and dealer portal. `noindex`. Authenticated on every route. |
| **API** | `apps/api` | Fastify. The only process that holds database credentials. |

The browser never reaches the API directly. The public site renders on the
server and calls the API over the private network; the console proxies through
its own `/api/bff/*` route. Nothing in either client bundle knows an internal
address, and neither app can open a database connection.

```
     internet                         private network
        │
        ├── polyfixmattress.com ──────► web ──────┐
        │                                          ├──► api ──► PostgreSQL
        └── app.polyfixmattress.com ──► console ──┘            (never published)
                                        (BFF proxy)
```

### Shared packages

| Package | Holds |
|---|---|
| `@polyfix/brand` | The company name, short name, wordmark and location. One source of truth. |
| `@polyfix/auth` | Argon2id hashing, TOTP, JWT access tokens, the RBAC catalogue. |
| `@polyfix/config` | Validated environment. Fails at boot on a missing or weak secret. |
| `@polyfix/database` | Prisma schema, checked-in SQL migrations, seed, RLS context helpers. |
| `@polyfix/validation` | Zod schemas shared by the API and both front ends. |
| `@polyfix/ui` | Design tokens and shared primitives. |

---

## Quick start

Requires **Node 22**, **pnpm 10** and **PostgreSQL 16**.

```bash
pnpm install
cp .env.example .env          # then fill in every CHANGE_ME
```

Generate the secrets — do not invent them by hand:

```bash
openssl rand -base64 48       # AUTH_SECRET, SIGNING_SECRET, INTERNAL_API_TOKEN
openssl rand -base64 32       # ENCRYPTION_KEY (must decode to exactly 32 bytes)
openssl rand -base64 24       # each database role password
```

Bring up PostgreSQL, create the roles, migrate and seed:

```bash
docker compose --profile db up -d           # or use your own PostgreSQL 16
pnpm db:deploy                              # apply migrations
psql "$DIRECT_DATABASE_URL" -f scripts/sql/02_grants.sql
pnpm db:seed
```

Run everything:

```bash
pnpm dev                      # api :4000 · web :3000 · console :3001
```

The seed prints the first administrator's credentials once. It does not store
them anywhere. See [docs/08-admin-user.md](docs/08-admin-user.md).

Full walkthrough: **[docs/01-installation.md](docs/01-installation.md)**.

---

## Everyday commands

| | |
|---|---|
| `pnpm dev` | All three applications, watching |
| `pnpm build` | Build packages, then apps |
| `pnpm typecheck` | TypeScript across the workspace |
| `pnpm test` | API suite against a real PostgreSQL database |
| `pnpm db:migrate` | Author a new migration (development) |
| `pnpm db:deploy` | Apply checked-in migrations (production) |
| `pnpm db:seed` | Idempotent reference data |
| `pnpm db:studio` | Prisma Studio |
| `./scripts/backup.sh --kind manual` | Encrypted database backup |
| `./scripts/restore.sh --file <artifact> --into <url>` | Restore, with verification |

---

## Documentation

| | |
|---|---|
| [01 — Installation](docs/01-installation.md) | From a clean machine to a running stack |
| [02 — Database setup](docs/02-database-setup.md) | Roles, privileges, row-level security |
| [03 — Database backup](docs/03-database-backup.md) | What is taken, how it is encrypted, where it goes |
| [04 — Database restore](docs/04-database-restore.md) | Rehearsed recovery, and the drill to run quarterly |
| [05 — Migrations](docs/05-migrations.md) | Writing, reviewing and reversing a schema change |
| [06 — Production deployment](docs/06-production-deployment.md) | Hardening, TLS, reverse proxy, first release |
| [07 — Security](docs/07-security.md) | The model, the controls, and the known gaps |
| [08 — Admin user guide](docs/08-admin-user.md) | Running the business from the console |
| [09 — Dealer user guide](docs/09-dealer-user.md) | Scan, sell, claim — on a phone |
| [10 — API reference](docs/10-api.md) | Every endpoint, with its permission |
| [11 — Brand configuration](docs/11-brand-configuration.md) | Where the company name lives |

---

## Design decisions worth knowing before you change anything

**The database is the system of record, not Prisma.** Every migration is
checked-in SQL under `packages/database/prisma/migrations/`. Constraints,
triggers, row-level security policies and identifier sequences live in
PostgreSQL, so they hold even for a connection that never goes through the
application. You can drop Prisma and keep using `psql`, `pg_dump`, pgAdmin,
DBeaver or Metabase against the same database.

**Four database roles, none of them the superuser.** The API connects as
`colifees_app`: DML only, no DDL, cannot disable row-level security, cannot
`UPDATE` or `DELETE` an audit row. Migrations run as `colifees_owner`. Backups
run as `colifees_backup`, the only role permitted to bypass RLS, and it is
read-only. (These role names still carry the previous company name — see
[docs/11](docs/11-brand-configuration.md) for why renaming them is not free.)

**Dealer isolation is enforced by PostgreSQL, not by a `where` clause.**
Every dealer-scoped table has `FORCE ROW LEVEL SECURITY` and a policy keyed on
a transaction-local setting. A missing scope fails closed: it matches nothing
rather than everything. Forgetting a filter in a query cannot leak another
dealer's data.

**The audit log is append-only and hash-chained.** A database trigger computes
each row's SHA-256 over its own content plus its predecessor's hash. Further
triggers deny `UPDATE`, `DELETE` and `TRUNCATE`, and the application role holds
no privilege to do any of them. `colifees_verify_audit_chain()` re-walks the
chain and returns a row for every break. Tampering is detectable; it is not
prevented by hope.

**Nothing trusts the browser.** Not a QR code, not a URL parameter, not a
hidden field, not client-side validation. Authorisation is decided on the
server, against the session, on every request.

---

## Security

Read [docs/07-security.md](docs/07-security.md) before deploying. In short:

- Argon2id password hashing; TOTP second factor, required for the roles in
  `MFA_REQUIRED_ROLES` and for every privileged operation
- AES-256-GCM column encryption with keyed-HMAC blind indexes for customer
  contact data, so an encrypted column is still searchable
- Access tokens are short-lived; refresh tokens are opaque, database-backed and
  rotated on use, with reuse detection that revokes the whole session family
- CSRF: `SameSite=Strict` cookies, a double-submit token, and an `Origin` check
- Rate limits on login, password reset, public forms and warranty verification
- No stack trace, SQL fragment or internal identifier ever reaches a caller

No software is unbreakable, and nothing here claims to be. These are controls
that raise cost and make compromise visible, not a guarantee.

**If you find a security problem**, do not open a public issue — contact the
platform owner directly.

---

## Ownership

Source code, PostgreSQL database, migrations, backups and infrastructure all
belong to POLYFIX MATTRESS. There is no runtime dependency on any AI service,
no vendor lock-in in the data layer, and no hosted component that cannot be
replaced. The database is plain PostgreSQL 16 and the backups are plain
`pg_dump` artifacts.

---

## Licence

UNLICENSED — proprietary. All rights reserved.
