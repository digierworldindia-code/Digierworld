# The database

MySQL 8, `utf8mb4`, 41 tables plus the framework's own. Every timestamp is
stored in UTC with microsecond precision; the connection pins
`SET time_zone = '+00:00'` so a server's local zone cannot change what a stored
time means. Times are shown to people in IST (Asia/Kolkata).

## Accounts

Three accounts, each with only what it needs. None of them is `root`, and the
application never uses one that can change the schema.

| Account | What it is for | What it can do |
|---|---|---|
| `polyfix_owner` | migrations and schema changes | DDL on the one database |
| `polyfix_app` | the running application | SELECT/INSERT/UPDATE/DELETE per table, and only SELECT+INSERT on history |
| `polyfix_backup` | `mysqldump` | SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER |

```sql
CREATE USER 'polyfix_owner'@'127.0.0.1'  IDENTIFIED BY '…';
CREATE USER 'polyfix_app'@'127.0.0.1'    IDENTIFIED BY '…';
CREATE USER 'polyfix_backup'@'127.0.0.1' IDENTIFIED BY '…';

GRANT ALL PRIVILEGES ON polyfix_mattress.* TO 'polyfix_owner'@'127.0.0.1';
GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER ON polyfix_mattress.* TO 'polyfix_backup'@'127.0.0.1';
-- polyfix_app is granted table by table, below.
```

Use separate, long, random passwords. They live in `.env` (owner and backup
passwords do not: they are typed when needed, or held by the backup job).

## Least privilege for the application

MySQL cannot subtract a table privilege from a database-wide grant, so nothing
is granted at database level. Each table gets exactly the rights it should have:

```bash
mysql -u root -p polyfix_mattress < database/sql/generate-privileges.sql \
  | mysql -u root -p polyfix_mattress

mysql -u root -p polyfix_mattress < database/sql/verify-privileges.sql
```

Run this again after every migration that adds a table — a new table has no
grant until you do, and the application will say so rather than silently
reading it.

The System screen in the console checks this too: it reads
`SHOW GRANTS FOR CURRENT_USER()` and reports a problem if the application can
update or delete history.

## Append-only history

Four tables are the record of what happened, and nothing in the application is
allowed to rewrite them:

- `audit_logs` — who did what, when, from where, and why
- `record_versions` — a point-in-time copy of a row before it changed
- `mattress_events` — every lifecycle step of a unit
- `claim_events` — every step of a warranty claim

`polyfix_app` holds `SELECT, INSERT` on these and nothing else. It cannot
`UPDATE`, cannot `DELETE`, and cannot `TRUNCATE` — MySQL's `TRUNCATE` needs the
`DROP` privilege, which the application never has.

`database/sql/audit-triggers.sql` adds `BEFORE UPDATE`/`BEFORE DELETE` triggers
that refuse the operation for *any* account, including the owner. It is
optional because creating triggers needs `SUPER` (or
`SET GLOBAL log_bin_trust_function_creators = 1`) on a server with binary
logging on. Install it if you can: it turns a privilege mistake into an error
instead of a quiet edit.

### The hash chain

Each row in `audit_logs` carries the SHA-256 of the row before it. The console's
**Audit → Verify the chain** re-walks the whole trail and reports any row whose
hash no longer matches its contents, or whose link to the previous row is
broken — which is what a restored, doctored dump looks like. The check is
itself recorded in the trail.

The chain head sits in `audit_chain_head`, a single row that is locked while an
entry is written, so concurrent writes cannot interleave and break the chain.

## Identifiers

`identifier_counters` holds one row per sequence, locked with
`SELECT … FOR UPDATE` while a number is taken. That is what makes serials
consecutive with no gaps and no reuse, even when two people serialise a batch
at the same moment.

| Sequence | Shape | Example |
|---|---|---|
| serial | `CLF` + 2-digit year + 6 digits | `CLF26000001` |
| batch | `BAT` + 2-digit year + 4 digits | `BAT260007` |
| claim | `CLM` + 2-digit year + 6 digits | `CLM26000024` |
| dispatch | `DSP` + 2-digit year + 6 digits | `DSP26000013` |
| dealer | `DLR` + 4 digits | `DLR0009` |

The serial prefix is `CLF` because that is what the previous platform issued and
what is printed on labels already in the field. It is set in one place,
`app/Config/Brand.php`, and the database enforces the format with a `CHECK`.

## What the database enforces itself

Constraints are not decoration here; several tests exist only because the
database rejected something the application would have allowed:

- a serial number must match `^CLF[0-9]{8,}$`
- a unit in `SOLD`, `CLAIM_OPEN` or `REPLACED` must carry a sale date and a dealer
- lifecycle dates must run in order: manufactured → dispatched → received → sold
- an email address is stored lower-case (`CAST(email AS BINARY) = CAST(LOWER(email) AS BINARY)`)
- a soft-deleted row must carry a reason
- one warranty per mattress; one customer per dealer per phone number
- a dealer's invoice number is unique within that dealer

## Encrypted columns

Customer contact details are encrypted with AES-256-GCM
(`v1.<iv>.<ciphertext>.<tag>`, base64url) under `polyfix.encryptionKey`.
Alongside each is a *blind index*: an HMAC-SHA256 of the normalised value under
`polyfix.signingSecret`, which is what makes "find this customer by phone
number" possible without decrypting the table.

Lose `polyfix.encryptionKey` and those columns are unreadable — no backup of
the database alone can bring them back. Keep the key somewhere other than the
database backup, and see `docs/backup.md`.

## Character set and collation

`utf8mb4` with `utf8mb4_0900_ai_ci`. Indexed text columns are `VARCHAR(191)` or
shorter where an index would otherwise exceed MySQL's key length. Free-text
search on serial numbers uses a `FULLTEXT` index (PostgreSQL's trigram indexes
have no direct MySQL equivalent).
