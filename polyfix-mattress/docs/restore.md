# Restoring

## The short version

```bash
scripts/restore.sh /srv/backups/polyfix-2026-09-25.sql.gz polyfix_restore_check
```

That restores into a *separate* database, which is what you want almost every
time: you can look before you touch anything live.

## Restoring over production

Only when production is genuinely lost or wrong, and only after taking a backup
of the current state — even a broken database is evidence of what happened.

```bash
scripts/backup.sh                                   # first: keep what is there now
scripts/restore.sh backup.sql.gz polyfix_mattress --force
```

The script refuses to overwrite a database that has rows unless `--force` is
given. That refusal has saved more data than any feature in this application.

Afterwards:

```bash
mysql -u root -p polyfix_mattress < database/sql/generate-privileges.sql | mysql -u root -p polyfix_mattress
mysql -u root -p polyfix_mattress < database/sql/verify-privileges.sql
```

Privileges belong to the server, not to the dump, so they have to be reapplied
if you restored onto a new machine.

## Then check it is really back

1. **The chain.** Console → Audit → *Verify the chain*. It must say **Intact**.
   If it does not, the dump was altered after it was taken: do not carry on with
   it, and treat it as an incident.
2. **The counters.** `SELECT * FROM identifier_counters;` — the serial counter
   must be at least as high as the highest serial in `mattresses`. If a restore
   went back in time, the counter will reissue numbers that already exist. Fix
   it before anyone serialises anything:

   ```sql
   UPDATE identifier_counters
      SET value = (SELECT MAX(CAST(SUBSTRING(serial_number, 6) AS UNSIGNED)) FROM mattresses)
    WHERE name = 'serial';
   ```
3. **The keys.** If `polyfix.encryptionKey` on this server is not the one the
   data was written with, customer contact details will not decrypt. Check one:
   open any customer in the console — the phone number should read normally, not
   as a dash.
4. **The uploads.** Claim photographs are files, not rows. Restore
   `writable/uploads` from its own backup, keeping the same paths.
5. **The application.** Sign in, open a claim, verify a serial on the public
   page. Five minutes of this is worth an hour of reading logs.

## The drill

Once a quarter, on a machine that is not production:

```bash
scripts/restore.sh $(ls -t /srv/backups/*.sql.gz | head -1) polyfix_drill
mysql -u root -p polyfix_drill -e "SELECT COUNT(*) FROM mattresses; SELECT COUNT(*) FROM audit_logs;"
```

Then point a scratch copy of the application at `polyfix_drill` and run the
audit chain check. Write down how long the whole thing took: that number is your
actual recovery time, and it is usually longer than anyone guessed.

## When only part of it is wrong

Restoring everything to fix one table loses every change since the backup.
Instead, restore into a scratch database and copy across what you need:

```bash
scripts/restore.sh backup.sql.gz polyfix_scratch
mysqldump --single-transaction polyfix_scratch website_pages | mysql polyfix_mattress
```

Never do this with `audit_logs`, `record_versions`, `mattress_events` or
`claim_events`. Splicing history breaks the hash chain, and a chain that has
been broken deliberately is indistinguishable afterwards from one broken by an
attacker.
