# 02 — Database setup

Roles, privileges and row-level security. This is the layer that holds when
application code is wrong.

---

## Why four roles

The application must never connect as a PostgreSQL superuser. A superuser
silently bypasses every row-level security policy in the database, which would
make dealer isolation depend on application code alone — exactly the assumption
this design refuses to make.

| Role | Used by | May | May not |
|---|---|---|---|
| `colifees_owner` | `prisma migrate`, release pipeline | own the schema, DDL | create databases or roles, bypass RLS |
| `colifees_app` | the running API | `SELECT/INSERT/UPDATE/DELETE` on business tables, `INSERT` only on `audit_logs` | any DDL, disable RLS, `UPDATE`/`DELETE` an audit row, touch sequences |
| `colifees_readonly` | analysts, BI tools | `SELECT`, read-only transactions | write anything; RLS still applies |
| `colifees_backup` | `pg_dump` | read every row (`BYPASSRLS`), read-only | write anything, DDL |

`colifees_owner`'s credentials live with the release pipeline and are **not**
exported into the running API process. If the API cannot run DDL, a SQL
injection that reaches the database still cannot drop a table.

### Why the backup role may bypass RLS

The dealer-isolation policies are `FORCE`d, so they apply to the table owner
too. `pg_dump` run by any role subject to them fails outright — *"query would be
affected by row-level security policy"* — and produces **no dump at all**, not a
partial one. A backup must read every row by definition, so it gets a role that
can, and that role can do nothing else. Guard its credentials exactly as you
guard the dump, because they are equivalent.

The roles still carry the previous company name. Renaming a PostgreSQL role
rewrites every grant, default ACL and policy that names it and invalidates every
stored credential at once — see [11 — Brand configuration](11-brand-configuration.md).

---

## Creating them

Once per environment, as a superuser, **before** the first migration:

```bash
psql "postgresql://postgres@db-host:5432/postgres" \
     -v owner_password="$(openssl rand -base64 24)" \
     -v app_password="$(openssl rand -base64 24)" \
     -v readonly_password="$(openssl rand -base64 24)" \
     -v backup_password="$(openssl rand -base64 24)" \
     -f scripts/sql/01_roles.sql
```

The script is idempotent: re-running it does not reset an existing password or
disturb an existing database.

It also sets guard rails: `statement_timeout = 30s` and
`idle_in_transaction_session_timeout = 60s` on the application role, so a
runaway query or a forgotten open transaction cannot pin the database.

Under Docker this runs automatically on first start, via
`infra/postgres/init/10-roles.sh`. That happens **once**, on an empty data
directory — changing a password in `.env` later and restarting does nothing.

---

## The four SQL scripts

| Script | When | As |
|---|---|---|
| `01_roles.sql` | once per environment, before the first migration | superuser |
| `02_grants.sql` | **after every migration** | schema owner |
| `03_verify_privileges.sql` | after every deployment, and after any manual change | schema owner |
| `04_sync_sequences.sql` | after a data-only restore or a bulk import | schema owner |

### `02_grants.sql` is not optional

`ALTER DEFAULT PRIVILEGES` normally covers new tables automatically, but its
entries are attached to the **schema**. Dropping and recreating `public` — a
reset, a restore into a fresh database, a manual rebuild — silently discards
them. Running this script afterwards is what guarantees the application role
ends up with exactly the rights it needs.

Order matters inside it: grant broadly first, then take back the privileges that
would let the application rewrite history.

```bash
psql "$DIRECT_DATABASE_URL" -f scripts/sql/02_grants.sql
```

### `03_verify_privileges.sql` — every row is a finding

```bash
psql "$DIRECT_DATABASE_URL" -f scripts/sql/03_verify_privileges.sql
```

It checks that:

1. no application role is a superuser or holds `BYPASSRLS`
2. every table with a `dealer_id` has RLS enabled
3. every dealer-scoped table has `FORCE ROW LEVEL SECURITY`
4. the application role holds no `UPDATE`/`DELETE` on `audit_logs`
5. nothing is readable by `PUBLIC`
6. (informational) the RLS policy inventory

One row is expected on a correct install: `audit_logs` appears under check 2.
That is a known gap with a documented compensating control — see
[07 — Security](07-security.md#known-gaps).

---

## Row-level security

Every dealer-scoped table carries `FORCE ROW LEVEL SECURITY` and a policy keyed
on `app.dealer_id`, a transaction-local setting the API sets on each request
from the authenticated session — never from anything the client sent.

```
claim_events · claim_media · customers · dealer_receipt_items · dealer_receipts
dealer_users · dealers · dispatch_items · dispatches · mattress_events
mattresses · notifications · replacements · sales · warranties · warranty_claims
```

### It fails closed

A connection with no scope set matches **nothing**, not everything. Forgetting a
`where dealerId = …` in application code cannot leak another dealer's data; the
query simply returns no rows.

Staff carry the sentinel value `'ALL'`, not an empty string. That distinction
was a real bug: PostgreSQL reverts transaction-local custom settings to `''`,
so on a pooled connection an empty-string sentinel meant a later request
inherited staff-wide visibility. Migration `0005` changed it, and
`app_is_staff()` now tests `coalesce(current_setting('app.dealer_id', true), '') = 'ALL'`.

### Working in psql

Connecting as the owner and seeing nothing is the design, not a fault:

```sql
SET app.dealer_id = 'ALL';        -- staff-wide view
SET app.dealer_id = '<uuid>';     -- as one dealer sees it
```

Every maintenance script in `scripts/sql/` that touches a scoped table sets this
first. A migration or rollback that forgets it commits having changed zero rows.

---

## The audit log

`audit_logs` is append-only, and enforced in three independent places:

1. a trigger (`colifees_audit_seal`) computes each row's SHA-256 over its own
   content plus its predecessor's hash — a chain
2. further triggers deny `UPDATE`, `DELETE` and `TRUNCATE`
3. the application role holds no privilege to do any of them

Verify the chain at any time:

```sql
SELECT * FROM colifees_verify_audit_chain(0::bigint, 1000000);
```

Every row returned is a broken link. No rows means the chain is intact. The
console exposes the same check at **System → Audit → Verify**, and
`scripts/restore.sh` runs it automatically after every restore.

This makes tampering **detectable**. It does not make it impossible — anyone
with superuser access to the database can disable a trigger. What they cannot
do is leave the chain consistent afterwards.

---

## Identifier sequences

Serials, batch codes, claim numbers, dispatch codes and dealer codes are issued
by PostgreSQL functions over sequences — `colifees_next_serial()` and friends —
not by application code. Two concurrent requests cannot produce the same serial.

The application role deliberately holds `USAGE` and `SELECT` on sequences but
**not** `UPDATE`, so no request handler can rewind one.

After restoring a data-only dump or importing legacy rows that carry their own
identifiers, resynchronise:

```bash
psql "$DIRECT_DATABASE_URL" -f scripts/sql/04_sync_sequences.sql
```

Skip it and the next generated identifier collides with one already in use. The
insert fails — which is the good outcome; the unique index is what stands
between two mattresses and the same identity.

---

## Column encryption

Customer phone, email and address are encrypted with AES-256-GCM using
`ENCRYPTION_KEY`, and each has a keyed-HMAC **blind index** so an exact-match
lookup still works without decrypting the column. Range and prefix searches on
those columns do not, and that is the trade.

**`ENCRYPTION_KEY` is not recoverable.** Lose it and the encrypted columns are
lost with it. Store it in a secret manager before first use, and treat rotating
it as a migration that re-encrypts every affected row — not a config change.

---

## Connecting by hand

```bash
psql "$DIRECT_DATABASE_URL"                  # owner: DDL, maintenance
psql "$REPORTING_DATABASE_URL"               # read-only analyst
```

Do not use `DATABASE_URL` (the application role) for interactive work, and do
not use a superuser for anything the four roles can do.

> `psql`, `pg_dump` and `pg_restore` use libpq, which rejects the Prisma-only
> query parameters in these URLs (`?schema=public`, `connection_limit`,
> `pool_timeout`) with *"invalid URI query parameter"*. `scripts/backup.sh` and
> `scripts/restore.sh` strip them automatically; by hand, drop them yourself.
