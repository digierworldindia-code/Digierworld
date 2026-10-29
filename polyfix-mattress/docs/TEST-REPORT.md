# POLYFIZZ — test report

What was tested, what passed, what was found and fixed, and what is still
unproven. Everything below was run on this build; nothing is carried over from
an earlier one.

**Built and tested on:** PHP 8.3.6, MySQL 8.0.46, CodeIgniter 4.7.4.
**Automated suite:** 437 tests, 2,173 assertions, all passing.

---

## 1. The package

| File | Size | MD5 |
|---|---|---|
| `POLYFIZZ-CI4-UPDATED-COMPLETE.zip` | 12 MB, 2,395 files | `af170d567f7f3ec5d2118987ca7674e1` |
| `POLYFIZZ-FRESH-DATABASE.sql` | 120 KB | `0fda5ab2c07f18a7814ebb7dc5acd3c6` |
| `POLYFIZZ-EXISTING-DATABASE-UPGRADE.sql` | 124 KB | `2b66aa3b7d0b1b6c60428f3c092febed` |
| `POLYFIZZ-HOSTINGER-INSTALLATION.md` | 22 KB | — |

The archive was checked for `.env`, a `.git` directory, a private key, a real
value against any secret setting, a password hash in either `.sql` file, an
`INSERT INTO users`, and any `DROP TABLE` / `DROP DATABASE` / `TRUNCATE`. None
were present. It carries `.env.example` with its eight `CHANGE_ME` placeholders
and nothing else resembling a credential.

Both SQL files ship inside the archive as well as beside it.

---

## 2. Installed from the archive, as a client would

The ZIP was unpacked into an empty directory on a machine that had never run
the application, and installed by following `INSTALL.txt`:

| Step | Result |
|---|---|
| Import `POLYFIZZ-FRESH-DATABASE.sql` into an empty database | 52 tables, 1.8 s |
| `cp .env.example .env`, fill in the database | — |
| `php spark polyfix:keys --write` | three secrets generated and written |
| `chmod -R 770 writable` | — |
| `php spark polyfix:doctor` with `CI_ENVIRONMENT = production` | **all essential checks passed** |
| `php spark polyfix:create-user --role SUPER_ADMIN` | one-time password printed once |
| Sign in at `/admin/login` | forced straight to the password change |
| Change the password | console opened |

No hard-coded credential exists anywhere in the package; the first
administrator is created by the command above, which prints a password once and
does not store it readably.

`polyfix:doctor` was also run deliberately half-configured: it names the single
failing thing (a `127.0.0.1` base URL in production, a missing secret, an
unwritable directory) rather than answering HTTP 500.

**On the installed copy, in production mode:** every public page returned 200;
an unknown admin URL returned a 404 page carrying no stack trace, file path,
SQL or framework class name; and all seven security headers were present:
Content-Security-Policy (with `script-src 'self'`, so no inline script runs),
Strict-Transport-Security, `X-Frame-Options: DENY`, `X-Content-Type-Options:
nosniff`, Referrer-Policy, Cross-Origin-Opener-Policy, and Permissions-Policy
with `camera=(self)` and everything else switched off.

---

## 3. The database files

### Fresh install

Generated from the migrations and the two seeders, then verified by importing
it into an empty database and comparing the result with the migrated source:
**schema byte-identical** (52 tables, 74 foreign keys, 41 CHECK constraints,
76 generated columns) and **reference data identical** (335 rows: 64
permissions, 8 roles, 210 role grants, 21 settings, 7 identifier counters, 1
warehouse, 6 website pages, 8 FAQs, 10 migration records).

It contains no `CREATE DATABASE`, no `USE` and no `DROP TABLE`, so pointed at a
database that already holds these tables it stops on the first one and changes
nothing.

**The whole automated suite was then run twice: once against a schema built by
the migrations, and once against a schema built by importing this file.** Both
runs: 437 tests, 2,173 assertions, passing. The delivered file therefore
produces a schema the entire application works against, not merely one that
imports.

### Upgrading an existing database

**Read this part carefully, because of how it was produced.** The client's live
database was never reachable from here, so it was not inspected. Instead the
documented pre-upgrade state — migrations through `2026-09-25-000003` — was
reconstructed by checking out that release of the application from version
control and running *its* migrations and *its* seeder. That reconstruction was
then filled with a realistic dataset through the old build's own services: 3
users, 2 dealers, a product with 2 sizes, a batch of 20 mattresses numbered in
the old `CLF…` scheme, a dispatch of 10 received by a dealer, 4 sales with
encrypted customer records, 4 warranties, a claim with its history, 20 audit
entries, and website content carrying the old brand name.

The upgrade script is generated, not written: the migrations are applied to a
throwaway copy while every statement the framework sends is recorded, and the
schema changes are written out as they were issued, each wrapped in a guard
that checks `information_schema` first. Reference data cannot be copied that
way — the seeder writes `WHERE id = '<uuid>'`, and those ids belong to the
throwaway copy — so roles, permissions, their grants, the settings, the
counters, the pages and the FAQs are matched on the keys the application looks
them up by and inserted only where missing.

Verified by applying the generated file to a fresh copy of that populated
database:

| Check | Result |
|---|---|
| Schema after the SQL route vs after the migration route | **identical** |
| Running the file a second time | identical again; nothing duplicated |
| Business records before vs after (users, dealers, products, mattresses, batches, dispatches, dispatch items, receipts, receipt items, sales, warranties, claims, claim events, customers, audit entries) | **every count unchanged** |
| 20 legacy `CLF…` serials | all intact and still verifying publicly as genuine |
| New `PFZ-2026-000021` | accepted |
| Old `CLF26009999` | accepted |
| `not-a-serial`, `PFZ-26-1` | refused |
| Second scan of the same label into the same batch | refused by the database |
| `company.legal_name` and website text | corrected to POLYFIZZ, em dash and UTF-8 intact |
| Audit trail and customer text | not rewritten |
| Pointed at a non-POLYFIZZ database | aborts before doing anything, naming why |
| `grep -iE 'drop table\|drop database\|truncate'` | no matches outside comments |

Both routes are documented. Where a shell is available, running the migrations
is preferable, because that is the route the test suite exercises; the SQL file
exists for installations with only phpMyAdmin.

---

## 4. The workflow, end to end, through the panels

Run over HTTP against a database built from `POLYFIZZ-FRESH-DATABASE.sql`, with
real sign-ins, CSRF tokens and forced first-password changes — not through the
test harness.

| Step | What was done | Result |
|---|---|---|
| Setup | Product with two sizes, dealer, dealer login, agent assignment, all through the console | created |
| Batch creation | 12 Queen + 8 King, via the warehouse panel | batch `PFZ-20261029-001`, serials `PFZ-2026-000001`–`000020` |
| QR generation | Label sheet for the batch | 20 QR codes, 20 serials, brand on the sheet |
| **QR codes decoded** | The sheet's SVGs rasterised and read in Chromium with the same jsQR build the scanner ships | each decoded to that unit's `/warranty/verify?q=<token>` |
| Warehouse scan | Scanned URL posted as the camera sends it | `1 / 20 Mattresses Scanned` |
| Duplicate scan | Same label again | `Already Scanned — No Duplicate Added`, count unchanged |
| Invalid scan | `not-a-label` | `Not recognised`, nothing written |
| Full batch | Remaining 19 scanned | `20 / 20 Mattresses Scanned` |
| Stock count | Warehouse stock screen | 12 Queen + 8 King ready |
| Dispatch | 10 picked by ticking a batch, 1 added by scanning | 11 loaded |
| Dispatch confirmed | Invoice, transporter, LR and vehicle recorded | `DSP26000001` DISPATCHED, 11 units handed over |
| Dealer receiving | 10 OK, 1 damaged with a note | RECEIVED; staff notified of the exception |
| Sale | 3 sales recorded in the dealer portal | 3 sales, 3 customers |
| Warranty activation | — | 3 ACTIVE warranties, 10 years, correct dates |
| Public verification | A sold serial checked on the public site | "Genuine POLYFIZZ product… warranty is active until 29 Oct 2036" |
| Agent survey | 6 present, 1 dealer-says-sold, 1 not found | `SRV-26-000001`, 2 discrepancies, next visit scheduled |
| Invoice discrepancy | Agent reported it | `WRV-26-000001` raised; warranty untouched |
| Admin review | Taken up, invoice requested | `INFORMATION_REQUESTED` |
| **Dealer notification** | — | notice in the dealer's portal, unread |
| Invoice upload | Dealer uploaded a PDF against the review | filed as `REVIEW_INVOICE` against the case, not a claim |
| Decision | Dismissed, with a reason | `DISMISSED`; dealer notified of the outcome |

**Each panel independently:** 28 screens across the four panels were opened
twice each (admin, warehouse, field agent, dealer) — all 200 both times, so
refreshing and navigating straight to a URL both work.

**Sign-in and sign-out:** each of the four roles signed in with an email address
and a password. No OTP, no six-digit code, no authenticator setup, no redirect
loop. A first sign-in goes straight to the password change and then to the role's
own panel. After signing out, going straight back to `/admin`, `/warehouse`,
`/agent` or `/dealer` returns to the sign-in page. A role that does not belong
to a panel is redirected to its own rather than shown a 403; a dealer cannot
reach `/admin`; a warehouse or agent account cannot reach the console.

**Sessions:** idle sessions expired after the configured hour and signing in
again restored everything — observed across a four-hour gap.

**Database consistency after the run:** 9 still at the plant, 8 at the dealer,
3 sold — 20 accounted for. 21 scans (20 production, 1 dispatch), 11 dispatch
items, 11 receipt items, 8 survey items, 2 discrepancies, 1 review case, 6
review events, 3 notifications. The audit trail's hash chain was verified in
the console: **61 entries checked, every hash matching its contents and the
entry before it.**

**Camera failures, in a real browser.** The scanning screen was loaded in
Chromium with `getUserMedia` replaced to fail four different ways. Each gave a
plain message — "Camera blocked", "No camera found", "Camera busy", "Camera
unavailable" — with the reason and the way round it, and in every case the
typed-serial box stayed usable and was focused automatically.

---

## 5. Faults found and fixed

The first clean-install run and the first walk through the panels exposed six
defects. Four could not have been caught by the suite as it stood.

**1. A failed write inside a transaction was silent.** CodeIgniter does not
throw on a failed query while a transaction is open — its own source comments
say so. It marked the transaction failed, `transCommit()` quietly rolled back,
and the call returned as though it had worked. Every write path here runs inside
a transaction, so a duplicate serial, a failed CHECK or a missing foreign key
would have handed an operator an id for a row that was never written, and told
them the job was done. *Proved first with a test that recorded a duplicate
serial and saw success returned.* The connection now raises; the transaction
helper verifies its commit actually committed.

**2. Two migrations did nothing on a fresh install.** A connection caches the
table list the first time anything asks, and `tableExists()` reads that cache;
the first migration primes it while the database is still empty, so two later
migrations were told their tables did not exist and silently skipped their work
— while the runner reported all ten as applied. A new database went live
**without the unique key that stops a mattress being counted twice into a
batch, and without the column that files an invoice against a warranty
review.** Upgrading an existing database hid it, because there the tables
already existed. Migrations now clear that cache, and both guards fail loudly
instead of returning quietly.

**3. A night-shift batch could not be dispatched at all.** A batch records the
day the plant worked as an India-time date; a mattress records when it was made
as a UTC instant. The code glued `' 00:00:00'` onto the local date and stored
it as UTC, moving it 5h30m into the future — so between 18:30 and midnight UTC
(after midnight in India) a unit was stamped as manufactured tomorrow and the
timeline constraint refused to let it leave the plant. The suite had passed for
weeks because it had only ever run during the Indian working day. Fixed with a
local-day-to-UTC conversion, and pinned by tests that do not depend on the
clock.

**4. A dealer could not sell a mattress this release had made.** The serial
validation rule kept its own pattern and it was the one from before the
numbering changed, so `PFZ-2026-000001` failed it — on the form for recording a
sale, on the form for raising a warranty claim, and on the dealer's stock
lookup, while the scanner accepted the same serial perfectly well. The rule now
asks the one place that knows both numbering schemes.

**5. A claim video could not be uploaded.** Widening `claim_media.kind` for
review invoices replaced the list of allowed values rather than adding to it,
dropping `CLAIM_VIDEO` — which the upload service still writes, the claim forms
still offer and the file-type constraint still allows. Every video a dealer
attached to a claim would have failed at the database with the file already on
disk.

**6. The warranty review never told the dealer anything.** The office could ask
a dealer for an invoice and then wait on someone who had no way of knowing they
had been asked. The claims side had always notified; the review side, added
later, did not. Now the dealer is notified when information is requested and
when the case is decided either way.

Also fixed: `polyfix:doctor` was fatally broken by a rename and crashed on its
first check; the old brand name survived in `.env.example` (including the From
name on every outgoing email), `.htaccess`, `INSTALL.txt` and the placeholder
images, because the brand checker only scanned a fixed list of code extensions;
`polyfix:migrate` and `polyfix:import-postgres` read different environment
variables for the same password; and a stale reference pointed at a
`polyfix:verify-audit` command that does not exist.

**38 tests were added** (399 before, 437 now), each written to fail against
the old code first.

---

## 6. Unresolved limitations

Stated plainly, because the brief asks for production readiness not to be
claimed beyond what was verified.

1. **The client's live database was never inspected.** It is not reachable from
   this environment. The upgrade script was built and verified against a
   faithful reconstruction of the documented pre-upgrade state, not against the
   real thing. **Take a backup and restore it once before running the upgrade,**
   and if the live database has been altered by hand since that release, have
   someone compare its schema first. The script aborts without changing
   anything if the database does not look like a POLYFIZZ one, and every schema
   step checks whether its change is already in place — but neither of those is
   a substitute for a backup.

2. **The `.htaccess` protections were not exercised under Apache or
   LiteSpeed.** No such server is installed here; the HTTP checks above ran
   against PHP's built-in server, which serves only `public/` and therefore
   cannot prove the root `.htaccess` blocks `.env`, `app/`, `writable/` and the
   `.sql` files in the shared-hosting layout. After deploying, open
   `https://your-domain/.env` by hand and confirm it returns 403 or 404. The
   installation guide says so too.

3. **Email was never sent.** No SMTP server was configured, so the password-reset
   email was not delivered end to end. Only that one message is ever sent; every
   other notification is in-app. Send yourself a password reset once SMTP is
   filled in.

4. **The camera was simulated, not used.** Scanning was verified by decoding the
   printed labels in a real browser and by driving the four camera-failure paths
   with `getUserMedia` replaced. No physical phone camera was pointed at a
   printed label. Print one sheet and scan it on the phones the staff will
   actually use, over https.

5. **The website content a fresh install starts with is a starting point, not
   finished copy.** Six pages are seeded so the public site works at all —
   before this they returned 404, including `/warranty/verify`, the address
   printed on every label. The text describes what the software does and the
   facts the company has given, and claims nothing else: no prices, no product
   range, no figures. Edit it under Console → Content and add the range under
   Console → Products.

6. **The dealer portal's Scan screen has no camera scanning,** by design — it
   relies on the phone's own camera app opening the label's URL. Its help text
   reads as though the screen itself could scan, which is misleading. This was
   flagged earlier and left unchanged pending your decision; it is a one-line
   wording fix.

7. **No real user has trialled the documentation.** The guides were checked
   against the running software, not against someone trying to follow them.

8. **Load, concurrency and backup restoration were not tested.** Two operators
   scanning the same label at once is covered by a test and by a database
   constraint, but nothing here measured behaviour under real load, and no
   backup was taken from a production host and restored.
