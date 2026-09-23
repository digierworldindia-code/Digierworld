# 05 — Migrations

Every schema change is checked-in SQL. Prisma is a convenience for writing it,
not the authority on what the database contains.

---

## Where they live

```
packages/database/prisma/
  schema.prisma                        the model, and what `prisma generate` reads
  migrations/
    20260922133447_0001_initial_schema/migration.sql
    20260922134500_0002_security_hardening/migration.sql
    20260922135500_0003_sql_portability_defaults/migration.sql
    20260922141500_0004_timeline_date_granularity/migration.sql
    20260922143000_0005_fail_closed_scope_sentinel/migration.sql
    20260923090000_0006_brand_rename_polyfix/migration.sql
    20260923120000_0007_brand_rename_residuals/migration.sql
```

The SQL is the deliverable. Review it, not the schema diff — constraints,
triggers, RLS policies and functions exist only in the SQL.

---

## Applying

```bash
pnpm db:deploy                                            # production
psql "$DIRECT_DATABASE_URL" -f scripts/sql/02_grants.sql  # ALWAYS, after
```

`migrate deploy` applies pending checked-in migrations and nothing else. It
never generates SQL, never prompts, and never resets.

**Re-run `02_grants.sql` every time.** A migration that creates a table leaves
the application role without privileges on it unless default privileges caught
it — and those are schema-scoped, so anything that recreated `public` discarded
them.

---

## Authoring one

```bash
pnpm db:migrate --name describe_the_change
```

This needs a shadow database, which `colifees_owner` cannot create for itself —
it deliberately lacks `CREATEDB`. Create it once, as a superuser:

```bash
createdb -O colifees_owner colifees_shadow
# SHADOW_DATABASE_URL=postgresql://colifees_owner:…@127.0.0.1:5432/colifees_shadow
```

Then **open the generated SQL and read it**. Prisma writes the DDL for the model
change; anything the database is actually responsible for — a check constraint,
a trigger, an RLS policy, a backfill — you write by hand into the same file.

Name migrations `NNNN_short_description` after the timestamp prefix, continuing
the existing sequence.

---

## Rules

**An applied migration is history.** Never edit one that has run anywhere but
your own machine. Corrections go in a new migration — `0007` exists because
`0006` was already applied when its defect was found.

**No `DROP DATABASE`, no `DROP TABLE`, no schema recreation on a populated
database.** Dropping a column is a two-step release: stop writing it, ship, then
drop it in a later migration once nothing reads it.

**Data migrations capture what they overwrite.** `0006` and `0007` copy every
pre-change value into `brand_rename_backup` before updating, and ship an
executable down path in `scripts/sql/`. A reverse find-and-replace is not a
rollback: after renaming `COLIFEES` to `POLYFIX` there is no way to tell which
`POLYFIX` was once `COLIFEES MATTRESS`.

**Set the RLS scope.** Any migration touching a dealer-scoped table must begin:

```sql
SET LOCAL app.dealer_id = 'ALL';
```

Without it the policies fail closed, the `UPDATE` matches nothing, and the
migration commits reporting success having changed zero rows.

**Verify inside the migration.** End with a `DO $$ … RAISE EXCEPTION … $$;`
block that asserts what should now be true. `0006` caught its own bug that way —
it had set `company.legal_name` to the short form — before anybody saw it.

**Never rewrite history.** Do not reach for `audit_logs`, `record_versions`,
`notifications`, `mattress_events` or `claim_events` in a data migration. Those
are records of what happened.

---

## A worked example

`0007` is short enough to read in full. Its shape:

```sql
-- 1. capture
INSERT INTO brand_rename_backup (migration, table_name, record_id, column_name, old_value)
SELECT '0007_brand_rename_residuals', 'warehouses', id::text, 'name', name
  FROM warehouses WHERE name ILIKE '%colifees%';

-- 2. change
UPDATE warehouses
   SET name = regexp_replace(name, 'COLIFEES', 'POLYFIX', 'g'), updated_at = now()
 WHERE name ILIKE '%colifees%';

-- 3. assert
DO $$
DECLARE leftover bigint;
BEGIN
  SELECT count(*) INTO leftover FROM warehouses WHERE name ILIKE '%colifees%';
  IF leftover > 0 THEN
    RAISE EXCEPTION '0007: % warehouse name(s) still carry the old brand', leftover;
  END IF;
END $$;
```

Its down path, `scripts/sql/rollback-0007-brand-rename-residuals.sql`, restores
from `brand_rename_backup` inside one transaction and asserts that every
captured value came back before committing.

---

## Rolling back

Prisma has no down migrations, by design. Reversal is an explicit script:

```bash
psql "$DIRECT_DATABASE_URL" -f scripts/sql/rollback-0007-brand-rename-residuals.sql
```

Run them **newest first**. `0006`'s down path restores hero JSON wholesale, so
reversing out of order would leave `0007`'s image path in place.

Each starts with `SET app.dealer_id = 'ALL';` — see above for why that is not
decoration.

Rolling back **schema** is different from rolling back **data**. A dropped
column cannot be restored by a script; that needs a backup. Which is the real
argument for the two-step release above.

---

## Checklist before merging

- [ ] The generated SQL has been read line by line
- [ ] Constraints, triggers and RLS policies written by hand where the database,
      not the application, should enforce something
- [ ] `SET LOCAL app.dealer_id = 'ALL';` if a scoped table is touched
- [ ] Overwritten values captured, if data changes
- [ ] A verification block that fails the migration rather than half-applying it
- [ ] A down path in `scripts/sql/`, executed at least once against a populated
      database — not merely written
- [ ] `pnpm db:deploy && psql … -f scripts/sql/02_grants.sql` on a copy of
      production data
- [ ] `pnpm test` green afterwards
- [ ] `psql … -f scripts/sql/03_verify_privileges.sql` clean

---

## Deployment order

```
1. back up                    ./scripts/backup.sh --kind manual
2. apply                      pnpm db:deploy
3. grant                      psql … -f scripts/sql/02_grants.sql
4. verify                     psql … -f scripts/sql/03_verify_privileges.sql
5. deploy the application
```

Migrations run **before** the new application version, so write them
backward-compatible: the old code must survive the new schema for the length of
the rollout. Adding a nullable column is safe. Renaming one is two releases.

Do not migrate from a container entrypoint. On the day you scale to two
replicas, it runs twice.
