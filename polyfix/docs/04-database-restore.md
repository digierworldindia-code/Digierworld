# 04 — Database restore

The procedure nobody practises and everybody needs at the worst possible
moment. `scripts/restore.sh` is deliberately loud, refuses to guess, and will
not touch a populated database unless you make it.

---

## The command

```bash
./scripts/restore.sh \
  --file backups/polyfix-daily-20260923T020000Z.dump.enc \
  --into "postgresql://colifees_owner:…@127.0.0.1:5432/polyfix_verify"
```

| Option | |
|---|---|
| `--file <path>` | the artifact. Required. |
| `--into <url>` | target, **as the schema owner** (default `$DIRECT_DATABASE_URL`) |
| `--force` | permit restoring over an existing database. **Destroys what is there.** |
| `--jobs <n>` | parallel workers (default 4) |
| `--dry-run` | verify, decrypt, list contents — restore nothing |
| `--keep-plaintext` | leave the decrypted temp file (debugging only) |

`BACKUP_ENCRYPTION_PASSPHRASE_FILE` must be set for a `.enc` artifact.

---

## What it does, in order

1. **Verifies the SHA-256** beside the artifact. A mismatch aborts — a corrupted
   backup discovered mid-restore is a second incident on top of the first.
2. **Decrypts**, into a `chmod 700` temp directory that is `shred`ed on exit,
   however the script exits.
3. **Checks the archive holds table data** before touching the target.
4. **Refuses a populated target** unless `--force` is given.
5. **Runs `pg_restore`** as the schema owner.
6. **Reapplies `02_grants.sql`** and **`04_sync_sequences.sql`**.
7. **Verifies**: walks the audit hash chain, prints row counts and the migration
   history.

Steps 6 and 7 are the ones people skip by hand, and both matter. Default
privileges are attached to the schema, so a restore into a fresh database leaves
the application role with nothing. Sequence state is not carried by a data-only
dump, so the next generated serial would collide.

---

## Always rehearse into a scratch database first

```bash
createdb -O colifees_owner polyfix_verify

BACKUP_ENCRYPTION_PASSPHRASE_FILE=/etc/polyfix/backup.pass \
./scripts/restore.sh --file <artifact> \
  --into "postgresql://colifees_owner:…@127.0.0.1:5432/polyfix_verify"
```

Read the verification block at the end:

```
Audit hash chain (any row below is a broken link):
 id | occurred_at | problem
----+-------------+---------
(0 rows)

Row counts:
      table      | count
-----------------+-------
 audit_logs      |   580
 mattresses      |   157
 …
```

Zero rows from the chain check and row counts that match what you expect mean
the artifact is good. Then drop the scratch database.

---

## Recovering production

**Stop writing first.** A restore into a database still taking traffic produces
a mixture of two timelines.

```bash
# 1. stop the API (and anything else holding a connection)
docker compose stop api        # or: systemctl stop polyfix-api

# 2. take a dump of the CURRENT state, broken as it is.
#    You may need it, and you cannot get it back afterwards.
./scripts/backup.sh --kind manual --out /var/backups/pre-restore

# 3. restore
./scripts/restore.sh --file <artifact> --into "$DIRECT_DATABASE_URL" --force

# 4. confirm privileges
psql "$DIRECT_DATABASE_URL" -f scripts/sql/03_verify_privileges.sql

# 5. restart and check readiness
docker compose start api
curl -fsS http://127.0.0.1:4000/health/ready
```

Step 2 is not bureaucracy. "Restore last night's backup" and "lose today's
sales" are the same sentence, and the pre-restore dump is what lets you
reconcile the difference afterwards.

`--force` adds `--clean --if-exists`: existing objects are dropped and replaced.
Without it the script stops:

```
restore failed: the target already has 41 table(s). Restore into an empty
database, or pass --force to overwrite it. --force DESTROYS the data currently
there.
```

---

## After any restore

- **`ENCRYPTION_KEY` must be the same one** the data was written with. A restore
  with the wrong key gives you a database whose customer contact columns cannot
  be decrypted — and nothing will say so until someone opens a customer record.
- **Object storage is separate.** Claim photographs live in S3. A restored
  claim row whose media is gone will render a broken reference.
- **Sessions survive.** Refresh tokens are rows in the database, so a restore
  reinstates sessions valid at the time of the dump. If the restore is a
  response to a compromise, revoke everything: `TRUNCATE` is blocked, so
  `DELETE FROM refresh_tokens;` as the owner, and force a password reset for
  every user.
- **Re-run `03_verify_privileges.sql`.** One row for `audit_logs` under check 2
  is expected; anything else is a finding.

---

## Restoring one table

```bash
pg_restore --list artifact.dump | grep warranty_claims
pg_restore --dbname "$DIRECT_DATABASE_URL" \
           --data-only --table=warranty_claims artifact.dump
psql "$DIRECT_DATABASE_URL" -f scripts/sql/04_sync_sequences.sql
```

Decrypt first if the artifact is `.enc` (`restore.sh --dry-run
--keep-plaintext` will do it for you).

Selective restores bypass foreign keys and the audit chain. Check both
afterwards — a partial restore is the likeliest way to break the chain
legitimately, and you want to know it was you.

---

## The quarterly drill

Put it in the calendar. An untested backup is a belief, not a control.

1. Take the newest off-site artifact — not a fresh local one. The point is to
   test the whole path, including the copy off the host.
2. Restore it into a scratch database on a machine that is not the production
   host.
3. Confirm: zero broken links in the audit chain, row counts within expectation,
   the migration list matching the release that was live.
4. Point a local API at it and sign in. A database that restores but cannot be
   logged into is not a recovery.
5. Write down how long it took. That number is your real RTO; the one in the
   plan is a guess until you have measured it.
6. Drop the scratch database.

---

## Troubleshooting

**`checksum mismatch`** — the artifact is not the one that was written. Fetch
another copy; do not pass it a `--force`.

**`decryption failed — wrong passphrase file, or the artifact is damaged`** —
the passphrase does not match the one used at backup time. If the passphrase was
rotated, older artifacts need the older passphrase. Keep them.

**`pg_restore exited <n>`** — the script continues to the grants and sequence
steps on purpose, then warns. Read the messages. Errors about roles or
privileges are expected and harmless: the dump is `--no-owner --no-privileges`
and `02_grants.sql` is what sets them.

**Everything restored but queries return nothing** — you are connected without a
row-level-security scope. `SET app.dealer_id = 'ALL';`.

**`invalid URI query parameter: "schema"`** — a Prisma-style URL reached libpq.
`restore.sh` strips those parameters; by hand, drop `?schema=public` and friends.
