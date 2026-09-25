# The port from Node and PostgreSQL

The previous platform was a Next.js website, a Next.js console and a Fastify
API over PostgreSQL 16. This one is a single CodeIgniter 4 application over
MySQL 8. The data, the business rules and the addresses people use were carried
across; the runtime underneath them changed.

Nothing in this port started from zero. Every rule below came out of the old
code, and the tests exist to show it still behaves the same way.

## What was kept, exactly

- **Serial numbers.** Existing units keep their `CLF` serials. New ones continue
  the same sequence. The prefix is in `app/Config/Brand.php` and enforced by a
  `CHECK` in the database.
- **QR labels.** A printed label encodes
  `{baseURL}/warranty/verify?q=<token>`. That route still exists and still
  answers, so labels already on mattresses in the field keep working.
- **Passwords.** Argon2id at the same parameters (memory 19456 KiB, time 2,
  threads 1). PHP verifies a hash written by Node without a rehash, so nobody
  has to reset anything.
- **Encrypted columns.** AES-256-GCM in the same `v1.iv.ct.tag` base64url
  shape, under the same `ENCRYPTION_KEY`; blind indexes are the same
  HMAC-SHA256 under the same `SIGNING_SECRET`.
- **Two-factor secrets.** SHA1 TOTP, 6 digits, 30-second step, one step of
  tolerance. An authenticator app enrolled against the old platform still works.
- **The audit hash chain.** The same field order and the same separator, so the
  chain that was built by the old platform continues unbroken and still
  verifies.
- **Roles and permissions.** The same 55 permissions and 7 roles, with the same
  ranks. A test asserts the map in PHP matches the grant rows in the database.
- **Risk indicators.** The same ten signals, the same weights, the same
  thresholds, so a score computed then means what a score computed now means.

## What the schema became

| PostgreSQL | MySQL | Why |
|---|---|---|
| `uuid` | `CHAR(36)` `ascii_bin` | MySQL has no uuid type; fixed-width ascii compares fastest |
| `timestamptz` | `DATETIME(6)` in UTC | the connection pins `time_zone = '+00:00'` |
| `jsonb` | `JSON` | equivalent, except in the audit tables (below) |
| `text[]` | `JSON` array | no array type |
| enums | `ENUM` | same values, same order |
| `updated_at` triggers | `ON UPDATE CURRENT_TIMESTAMP(6)` | native |
| sequences | `identifier_counters` rows, row-locked | gap-free and visible |
| partial indexes | plain indexes | MySQL has none; two duplicates were dropped |
| trigram indexes | `FULLTEXT` | nearest equivalent for serial search |
| row-level security | `App\Libraries\DealerScope` | **see below** |

`audit_logs.previous_value` and `new_value` are `LONGTEXT`, not `JSON`. MySQL's
`JSON` type reformats what it stores, and the hash chain is computed over the
exact text PostgreSQL held. Keeping the bytes keeps the old chain verifiable.

## The one behaviour that had to move

**PostgreSQL row-level security has no MySQL equivalent.** The old platform had
16 RLS policies that filtered every dealer query inside the database, whatever
the application asked for. MySQL cannot do that.

That rule now lives in `app/Libraries/DealerScope`, applied in every query the
dealer portal makes, and in `assertOwns()` for every record fetched by id.
Another dealer's record answers exactly as a record that does not exist — same
message, same 404.

This is a real reduction in defence in depth: the old system had the database
as a second line if the application forgot. Three things make up for it as far
as they can:

1. the portal's controllers go through one base class that carries the scope;
2. `DealerScope::apply()` throws on a table it has no rule for, so a new table
   fails loudly instead of returning everyone's rows;
3. `tests/unit/DealerIsolationTest.php` checks every scoped table, both
   directions, and that "not yours" and "not there" are indistinguishable.

It is written down here because an operator should know what changed.

## The other differences worth knowing

- **Sessions** are database-backed (`ci_sessions`) rather than signed cookies,
  and a session row can be revoked from the console immediately.
- **Two-factor lockout**: a failed second factor now counts toward the same
  lockout counter as a failed password. It did not before.
- **Rate limiting** is per address in the application cache rather than in the
  API gateway. Behind a proxy, set `app.proxyIPs` or every request looks like
  it comes from the proxy.
- **Uploads** are stored under `writable/uploads`, outside the document root,
  and served only through `/media/<id>` after an authorisation check.

## Copying the data

```bash
php spark polyfix:import-postgres --pg-dsn "host=… dbname=colifees" --pg-user colifees_backup --dry-run
php spark polyfix:import-postgres --pg-dsn "host=… dbname=colifees" --pg-user colifees_backup
```

What it does, in order:

1. opens PostgreSQL `READ ONLY REPEATABLE READ` — one consistent snapshot, and
   it cannot write to the source even by accident;
2. refuses to run if the MySQL target already holds data;
3. copies every table inside one MySQL transaction with
   `FOREIGN_KEY_CHECKS = 0`, then checks every foreign key explicitly before
   committing;
4. syncs `identifier_counters` from the PostgreSQL sequences, so the next
   serial continues rather than colliding;
5. sets the audit chain head from the last row copied;
6. compares every value it wrote, field by field (JSON normalised for
   comparison; audit values compared byte for byte);
7. verifies the audit hash chain;
8. renames `_prisma_migrations` to `legacy_prisma_migrations` so the old
   tooling's bookkeeping is kept but cannot be mistaken for this one's.

### What the real run produced

| | |
|---|---|
| Tables copied | 41 of 41, row counts equal |
| Rows | 4,017, every value compared |
| Foreign keys | 0 orphans |
| Audit chain | 701 rows, intact |
| Counters | serial 175, batch 31, claim 37, dispatch 46, dealer 62 |
| PostgreSQL | unchanged — read-only throughout |

## If you have to go back

Nothing in this port writes to PostgreSQL, so the old database is still exactly
as it was. Point the old platform at it and it runs. Anything recorded in MySQL
after the cut-over would need copying back by hand, which is the usual reason
to keep the cut-over short and to stop writes on the old system first.
