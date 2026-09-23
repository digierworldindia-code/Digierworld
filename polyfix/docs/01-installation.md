# 01 — Installation

From a clean machine to a running stack. Allow about twenty minutes.

---

## Prerequisites

| | Version | Check |
|---|---|---|
| Node.js | 22 LTS | `node -v` |
| pnpm | 10 | `corepack enable && pnpm -v` |
| PostgreSQL | 16 | `psql --version` |
| Docker (optional) | any recent | `docker compose version` |

Node 20.11+ satisfies `engines`, but the project is developed and tested on 22.
`corepack enable` pins pnpm to the version in `packageManager` so everyone
resolves the same dependency tree.

---

## 1. Clone and install

```bash
git clone <your-remote> polyfix
cd polyfix
pnpm install
```

`pnpm install` also runs `prisma generate`, so the typed client exists before
anything imports it.

---

## 2. Configure the environment

```bash
cp .env.example .env
```

Now open `.env` and replace **every** `CHANGE_ME`. The application refuses to
start with a placeholder still in place — that check exists because a
half-configured deployment that boots is more dangerous than one that does not.

Generate secrets; do not invent them:

```bash
openssl rand -base64 48      # AUTH_SECRET
openssl rand -base64 48      # SIGNING_SECRET      (must differ from AUTH_SECRET)
openssl rand -base64 48      # INTERNAL_API_TOKEN
openssl rand -base64 32      # ENCRYPTION_KEY      (must decode to exactly 32 bytes)
openssl rand -base64 24      # each of the four database role passwords
```

`ENCRYPTION_KEY` encrypts customer contact data and TOTP secrets at rest.
**Losing it means losing access to that data** — there is no recovery path.
Store it in your secret manager before you use it.

> `.env` is git-ignored and must stay that way. Never commit a real secret.

---

## 3. Start PostgreSQL

### Option A — Docker (fastest)

```bash
docker compose --profile db up -d
docker compose logs -f db      # wait for "database system is ready"
```

The container creates the four least-privilege roles on first start, from
`infra/postgres/init/10-roles.sh`. That runs **once**, on an empty data
directory: if you change a role password later you must change it in the
database yourself, not by editing `.env` and restarting.

Its port is published to `127.0.0.1` only.

### Option B — an existing PostgreSQL 16

Create the database and roles by hand, as a superuser:

```bash
createdb colifees

psql "postgresql://postgres@127.0.0.1:5432/postgres" \
     -v owner_password="$OWNER_DB_PASSWORD" \
     -v app_password="$APP_DB_PASSWORD" \
     -v readonly_password="$READONLY_DB_PASSWORD" \
     -v backup_password="$BACKUP_DB_PASSWORD" \
     -f scripts/sql/01_roles.sql
```

Full detail, including what each role may and may not do:
[02 — Database setup](02-database-setup.md).

---

## 4. Migrate, grant, seed

Order matters.

```bash
pnpm db:deploy                                            # 1. schema
psql "$DIRECT_DATABASE_URL" -f scripts/sql/02_grants.sql  # 2. privileges
pnpm db:seed                                              # 3. reference data
```

Step 2 is not optional and not a duplicate of step 1. `ALTER DEFAULT
PRIVILEGES` attaches its entries to the schema, so anything that drops and
recreates `public` silently discards them. Running `02_grants.sql` after every
migration is what guarantees the application role ends up with exactly the
rights it needs — and none of the rights it must not have, such as `UPDATE` on
`audit_logs`.

The seed prints the first administrator's email and a generated password
**once**, to the terminal. It is not written to a file or stored anywhere else.
Copy it now. See [08 — Admin user guide](08-admin-user.md).

Verify:

```bash
psql "$DIRECT_DATABASE_URL" -f scripts/sql/03_verify_privileges.sql
```

Every row it returns is a finding. One is expected on a correct install — see
[07 — Security](07-security.md#known-gaps).

---

## 5. Run

```bash
pnpm dev
```

| | |
|---|---|
| http://localhost:3000 | Public website |
| http://localhost:3001 | Private console |
| http://localhost:4000/health | API liveness |
| http://localhost:4000/health/ready | API + database readiness |

Sign in to the console with the seeded administrator. You will be asked to
change the password immediately, and to enrol a TOTP second factor — `ADMIN`
is in `MFA_REQUIRED_ROLES` by default.

---

## 6. Confirm the install

```bash
pnpm typecheck      # TypeScript across the workspace
pnpm test           # API suite against the real database
pnpm build          # both Next apps
```

`pnpm test` runs against the database in `DATABASE_URL` and creates real rows.
Point it at a development database, never production.

---

## Running the whole stack in Docker

```bash
docker compose --profile full up -d --build
```

This builds three images — API, website, console — and wires them onto two
networks. The database sits only on the internal one, so the web and console
containers have no route to it at all.

Two things to know:

- `NEXT_PUBLIC_*` values are **inlined into the client bundle at build time**.
  They are baked into the image and cannot be changed afterwards by the
  orchestrator. Rebuild to change them. They are public by definition — never
  pass a secret as a build argument.
- Migrations are not run automatically. Deliberately: a container that migrates
  on boot will, on the day you scale to two replicas, migrate twice. Run
  `pnpm db:deploy` as a separate step. See
  [06 — Production deployment](06-production-deployment.md).

---

## Troubleshooting

**`Invalid environment configuration`** — the message names the variables and
what is wrong with each. It never prints values. Usually a `CHANGE_ME` left in
place, or an `ENCRYPTION_KEY` that does not decode to exactly 32 bytes.

**`permission denied for table …`** — `02_grants.sql` was not run after the
migration, or was run against the wrong database.

**Queries return nothing although rows exist** — you are connected as a role
subject to row-level security without a scope set. That is the fail-closed
design working. Use `SET app.dealer_id = 'ALL';` for a staff-wide view in
`psql`.

**`prisma migrate dev` fails needing a shadow database** — `colifees_owner`
deliberately lacks `CREATEDB`. Create the shadow database once as a superuser
(`createdb -O colifees_owner colifees_shadow`) and set `SHADOW_DATABASE_URL`.
Production never needs it; `migrate deploy` applies checked-in SQL.

**`pg_dump` fails with "query would be affected by row-level security policy"**
— you are not using `colifees_backup`. `pg_dump` under a role subject to FORCE
RLS produces *no* dump, not a partial one. See
[03 — Database backup](03-database-backup.md).

**The site still shows old content after a change** — Next.js persists its
fetch cache in `.next/cache` across builds. `rm -rf apps/web/.next` and rebuild.
