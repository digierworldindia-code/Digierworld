# Security

What protects what, where the limits are, and what is left to whoever runs it.
Nothing here claims the system cannot be broken into. It describes the
defences, the assumptions behind them, and the places a mistake would show.

## Identity

**Passwords** are hashed with Argon2id (memory 19456 KiB, time 2, threads 1) —
the same parameters the previous platform used, so existing hashes verify
without anyone resetting anything. A password must be at least 12 characters,
mix cases, digits and symbols, use more than a handful of distinct characters,
and contain neither the person's own details nor a predictable word.

**A wrong password and an unknown address answer identically**, and take
comparable time: an unknown account still runs a dummy verify. Whether an
address has an account is not something an anonymous caller gets to learn.

**Lockout**: five failures locks the account for fifteen minutes (both figures
in `.env`). A failed second factor counts toward the same counter — a change
from the previous platform, where it did not.

**Two-factor** is TOTP (SHA1, 6 digits, 30 seconds, one step of tolerance) with
ten single-use recovery codes, stored as hashes. It is required for
`SUPER_ADMIN`, `ADMIN` and `WARRANTY_MANAGER`: until they enrol, those accounts
can reach only the security page. An authenticator app enrolled against the old
platform still works.

**Sessions** live in the database, not in the cookie. The cookie carries an
opaque id and a random token whose SHA-256 is what is stored, so reading the
session table does not let anyone impersonate a session. A session ends at 12
hours absolute, after 1 hour idle, when the password changes, when roles
change, when the account or the dealership is suspended, or when anyone revokes
it from the console. The id is regenerated at every privilege change.

## Authorisation

55 permissions, 7 roles, one definition (`app/Libraries/Rbac.php`) that a test
checks against the grant rows in the database.

Three rules are enforced in `UserService`, not in the interface:

- nobody can create or change an account whose role is senior to their own;
- nobody can change their own roles;
- a dealer login holds the `DEALER` role only, tied to one dealership.

Five abilities need two-factor satisfied *in the current session*, not merely
enrolled: `system:backup`, `system:export`, `system:sql:read`,
`system:settings:write`, `user:role:assign`.

Every route declares its permission in a filter, on the group as well as the
route, so a new admin route cannot forget the check. Hiding a link in a template
is a courtesy; the filter is the control.

## Dealer isolation

The previous platform used PostgreSQL row-level security. MySQL has no
equivalent, so the rule moved into `App\Libraries\DealerScope`, applied to
every query the portal makes. Another dealer's record answers exactly as a
record that does not exist — same message, same 404 — so nothing is learned by
probing ids.

This is the one place where defence in depth got thinner, and it is called out
in `docs/migration.md` as well as here. Three things hold it up: the portal's
controllers all go through one base class, `apply()` throws on a table it has
no rule for rather than returning everyone's rows, and the isolation tests
check every scoped table in both directions.

## Data at rest

Customer phone numbers, email addresses and addresses are encrypted with
AES-256-GCM under `polyfix.encryptionKey`; the tag is verified on every read, so
a tampered value fails rather than decrypting to something plausible. Beside
each is a blind index — HMAC-SHA256 of the normalised value under
`polyfix.signingSecret` — which is what makes "find this customer by number"
possible without decrypting the table.

Neither key is in the database. Lose the encryption key and those columns are
gone; back it up separately from the dump (`docs/backup.md`).

## The audit trail

`audit_logs` records who did what, when, from where, and why. Each row carries
the SHA-256 of the row before it, so editing or deleting one shows up when the
chain is verified — including a chain that was rewritten in a restored dump.
The check is in the console, and is itself recorded.

The application's database account holds `SELECT, INSERT` on the history tables
and nothing else: it cannot update, delete, or truncate them
(`TRUNCATE` needs `DROP`, which it never has). `database/sql/audit-triggers.sql`
adds triggers that refuse the operation even for the owner account, where the
server allows creating them.

Passwords, hashes, tokens, two-factor secrets, recovery codes, API keys and
encrypted columns are redacted before anything is written to the trail.

## Input and output

- **SQL**: Query Builder and bound parameters throughout. The report engine
  takes filters as binds and never interpolates a value into SQL; a test throws
  injection strings at every report to prove it.
- **Output**: every template escapes with `esc()`. Structured data is encoded
  with `JSON_HEX_TAG` so a stored string cannot close a `<script>` element.
- **CSRF**: on by default for every state-changing request, session-based with a
  randomised token name.
- **Uploads**: judged by their own bytes. The magic number decides the type; the
  declared type and the extension have to agree with it; images are checked for
  sensible dimensions; size limits are 8 MB for a photo or PDF and 25 MB for a
  video, at most 8 per claim. Files are stored under `writable/uploads`, outside
  the document root, with generated names, and served only through `/media/<id>`
  after an authorisation check, with `Content-Security-Policy: sandbox` and
  `X-Content-Type-Options: nosniff`.
- **Rate limits**: sign-in 5/minute, password reset 3/15 minutes, public forms
  5/hour, the public warranty check 20/minute, per address.

## Headers

Every response: a content security policy with `script-src 'self'` (no inline
script anywhere in the application), `frame-ancestors 'none'`, `object-src
'none'`, `nosniff`, `X-Frame-Options: DENY`, a referrer policy, a permissions
policy, and HSTS in production. Private areas add
`X-Robots-Tag: noindex, nofollow, noarchive` and `Cache-Control: no-store` —
including on the redirect that turns an anonymous request away.

## The public warranty check

The one route anonymous traffic uses to read mattress data. The answer is built
field by field from an allow-list, so adding a column to `mattresses` can never
start leaking it. It never returns the customer, the dealer, the price, the
invoice, claim history, risk indicators or internal ids. The QR token is treated
as an untrusted lookup key and nothing it claims is believed.

## Risk indicators are not decisions

The ten claim signals surface claims worth reading closely and carry the
evidence that triggered them. Nothing in the system rejects a claim, contacts a
customer or penalises a dealer on that basis. A person decides, writes why, and
the decision is recorded with the indicators that were in force at the time.

## What is left to the operator

The application cannot do these for you:

- **TLS.** Everything above assumes HTTPS. Without it, sessions and passwords
  cross the network in the open.
- **Network.** MySQL should not listen on a public interface. The application
  connects over `127.0.0.1` or a private network.
- **Secrets.** Generate fresh ones per environment; never commit `.env`; never
  reuse a value from the example file.
- **The database account.** Run `generate-privileges.sql` after every migration
  and check with `verify-privileges.sql`. The System screen reports it too.
- **Updates.** `composer audit` regularly, and keep PHP and MySQL patched.
- **Backups.** And a restore drill — `docs/restore.md`.
- **Accounts.** Remove people who have left; review the role matrix
  occasionally; keep demo accounts off production entirely.

## Reporting a problem

If you find a vulnerability, report it privately to the address in the console's
system settings rather than opening a public issue. Include what you did, what
happened, and what you expected.
