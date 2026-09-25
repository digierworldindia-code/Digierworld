# Backups

A backup that has never been restored is a hope, not a backup. Read
`docs/restore.md` alongside this, and run the drill it describes.

## What has to be backed up

| What | Where | If it is lost |
|---|---|---|
| The database | MySQL | everything: units, sales, warranties, claims, the audit trail |
| `polyfix.encryptionKey` | `.env` | customer contact details can never be read again |
| `polyfix.signingSecret` | `.env` | blind indexes stop matching; customer lookup by phone breaks |
| `writable/uploads` | the file system | claim photographs — the evidence behind decisions |

The keys are **not** in the database, on purpose. Back them up separately, and
not in the same place as the dump: a backup that contains both the ciphertext
and the key protects against a disk failure and nothing else.

## Nightly

`scripts/backup.sh` takes a consistent dump, compresses it, writes a checksum,
and deletes backups older than the retention window.

```bash
scripts/backup.sh                      # uses the settings at the top of the script
BACKUP_DIR=/srv/backups scripts/backup.sh
```

It reads its credentials from a MySQL option file, never from the command line —
a password in `mysqldump -p…` is visible to anyone who can run `ps`.

```ini
# /etc/mysql/polyfix-backup.cnf  (chmod 600, owned by the backup user)
[client]
user = polyfix_backup
password = "…"
host = 127.0.0.1
```

In cron:

```cron
20 2 * * *  /srv/polyfix/scripts/backup.sh >> /var/log/polyfix-backup.log 2>&1
```

The uploads need a copy too — they change rarely, so a weekly mirror is usually
enough:

```cron
40 2 * * 0  rsync -a --delete /srv/polyfix/writable/uploads/ /srv/backups/uploads/
```

## What the dump contains

`mysqldump --single-transaction --routines --triggers --set-gtid-purged=OFF`

- `--single-transaction` gives one consistent snapshot without locking the site.
- `--routines --triggers` keeps the optional append-only triggers.
- The script strips `DEFINER=` clauses, so a restore does not fail because the
  account that created a trigger does not exist on the new server.

## Keeping them

A reasonable default, adjusted to what the business can stand to lose:

| Kept | How long |
|---|---|
| nightly | 14 days |
| weekly | 8 weeks |
| monthly | 12 months |

At least one copy must be somewhere the production server cannot reach. A
backup on the same machine survives a mistake; it does not survive the machine.

## What a backup is worth

The dump holds customer names and addresses, encrypted contact details, dealer
records and the whole audit trail. Treat it like the database: restricted
permissions (`chmod 600`), encrypted at rest, and never on a laptop or in a
shared drive folder.

## Before anything risky

Before a migration, an upgrade or any change you are unsure about:

```bash
scripts/backup.sh
```

It takes seconds and it is the difference between a bad afternoon and a bad
quarter.
