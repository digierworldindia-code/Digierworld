# 03 — Database backup

A backup you have never restored is a hypothesis. Read
[04 — Database restore](04-database-restore.md) alongside this.

---

## Taking one

```bash
./scripts/backup.sh --kind daily
./scripts/backup.sh --kind manual --out /var/backups/polyfix
./scripts/backup.sh --kind export --plain      # also write readable SQL
```

One script serves both the nightly cron job and the **Run a backup now** button
in the console, so there is a single procedure to test and to trust.

| Option | |
|---|---|
| `--kind <daily\|weekly\|monthly\|manual\|export>` | label, and which retention rule applies |
| `--out <dir>` | destination (default `$BACKUP_DIR`, else `./backups`) |
| `--plain` | additionally write an uncompressed `.sql` |
| `--quiet` | print the artifact path and nothing else |

Produces, in `--out`:

```
polyfix-<kind>-<timestamp>.dump.enc      encrypted custom-format dump
polyfix-<kind>-<timestamp>.dump.enc.sha256   checksum of that file
```

The directory is `chmod 700` and the artifact `chmod 600`. A dump is the entire
business in one file; a world-readable one on a shared host is a breach waiting
to happen.

---

## Which credentials

```
BACKUP_DATABASE_URL=postgresql://colifees_backup:…@host:5432/colifees
```

It **must** be `colifees_backup`. The script refuses to start otherwise:

```
backup failed: the backup role cannot bypass row level security.
```

That check exists because the failure it prevents is silent-looking and total.
The dealer-isolation policies are `FORCE`d, so `pg_dump` running as any other
role — including the table owner — fails with *"query would be affected by row
level security policy"* and writes **no dump**, not a partial one.

`colifees_backup` is `BYPASSRLS` and read-only. Its credentials are equivalent
to the dump itself; store them accordingly.

---

## Format

`--format=custom --compress=9`, because it:

- restores selectively with `pg_restore` (one table, or everything)
- compresses
- tolerates a version difference between dump and target

`--plain` additionally writes readable SQL, for inspection or for loading into
something that is not PostgreSQL.

`--no-owner --no-privileges`: ownership and grants belong to the environment,
not to the backup. `scripts/sql/02_grants.sql` re-establishes least privilege on
restore.

---

## Encryption at rest

```
BACKUP_ENCRYPTION_PASSPHRASE_FILE=/etc/polyfix/backup.pass
```

With it set, the dump is encrypted with AES-256-CBC, PBKDF2, 600 000 iterations,
and the plaintext is `shred`ed. Without it:

- in development — a warning, and an unencrypted dump
- in **production** — the script **fails**

An unencrypted production dump is a finding, not a warning to be lost in a log.

Store the passphrase file somewhere the database host cannot reach. A host that
holds both the encrypted dump and the key to it has achieved nothing. `chmod
600`, owned by the backup user.

**Lose the passphrase and the backups are unrecoverable.** Put it in your secret
manager the day you create it.

---

## Integrity

Every artifact gets a SHA-256 written beside it, and the script sanity-checks
the dump before declaring success:

```
Dump contains 41 table data sections.
```

A dump that restores cleanly but holds nothing is the failure worth catching
here, rather than during an incident. Zero table-data sections fails the run.

---

## Off-site

```
BACKUP_S3_BUCKET=polyfix-backups
```

The artifact and its checksum are copied with the `aws` CLI. Without the
variable the script says plainly:

```
NOTE: BACKUP_S3_BUCKET is not set. This backup exists only on this host.
```

A backup that lives only on the machine it came from does not survive the
failure it exists for.

Use a bucket with **object lock / immutability** and versioning enabled, and
credentials that can write but not delete. Ransomware that reaches the database
host reaches the local backups too.

---

## Retention

| Kind | Default kept locally |
|---|---|
| daily | 14 (`BACKUP_RETENTION_DAILY`) |
| weekly | 8 (`BACKUP_RETENTION_WEEKLY`) |
| monthly | 12 (`BACKUP_RETENTION_MONTHLY`) |
| manual, export | never pruned |

Local pruning only. Off-site retention belongs to the bucket's lifecycle policy,
which is the right place for it: a compromised host must not be able to delete
its own history.

> Pruning matches on the `polyfix-` filename prefix. Artifacts written before
> the rename carry `colifees-` and will not be pruned automatically. Rename or
> remove them once.

---

## Scheduling

```cron
# /etc/cron.d/polyfix-backup
0  2 * * *  polyfix  /srv/polyfix/scripts/backup.sh --kind daily   --quiet >> /var/log/polyfix-backup.log 2>&1
0  3 * * 0  polyfix  /srv/polyfix/scripts/backup.sh --kind weekly  --quiet >> /var/log/polyfix-backup.log 2>&1
0  4 1 * *  polyfix  /srv/polyfix/scripts/backup.sh --kind monthly --quiet >> /var/log/polyfix-backup.log 2>&1
```

Run as a dedicated user, not root. The environment (`BACKUP_DATABASE_URL`,
`BACKUP_ENCRYPTION_PASSPHRASE_FILE`, `BACKUP_S3_BUCKET`) must be present — cron
does not read your shell profile; use an `EnvironmentFile` with systemd, or
`source` one at the top of a wrapper.

**Alert on absence, not just on failure.** A cron job that stops running emits
nothing at all, which looks identical to success.

---

## From the console

**System → Backups**, with the `system:backup` permission. That permission is
MFA-gated: a role assignment alone does not grant it; the holder must have MFA
enabled and satisfied on the current session.

The API does not reimplement `pg_dump` — it runs this same script and records
the run in `backup_runs`. The contract is that the artifact path is the final
line of stdout, which is why `--quiet` prints nothing else.

---

## Verifying a backup is real

Quarterly, at minimum — restore it. See
[04 — Database restore](04-database-restore.md#the-quarterly-drill).

```bash
# cheapest possible check: does it list?
pg_restore --list backups/polyfix-daily-*.dump | grep -c 'TABLE DATA'
```

That proves the file is a readable archive. It does **not** prove the data is
usable. Only a restore does that.

---

## What is not covered

- **Object storage** (claim photographs, invoices) lives in S3, not in the
  database. Back it up with bucket versioning and replication — this script does
  not touch it.
- **Secrets.** `ENCRYPTION_KEY`, `AUTH_SECRET` and the backup passphrase are not
  in the dump. A restore without `ENCRYPTION_KEY` gives you a database whose
  customer contact columns cannot be decrypted.
- **Point-in-time recovery.** These are periodic snapshots. To recover to an
  arbitrary moment you need WAL archiving (`archive_mode`, `archive_command`)
  or a managed provider that does it for you. Decide whether the gap between
  snapshots is an acceptable amount of lost work, and write the answer down.
