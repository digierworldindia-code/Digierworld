# 08 — Admin user guide

Running the business from the console at **app.polyfixmattress.com**.

---

## The first administrator

`pnpm db:seed` creates one `SUPER_ADMIN` and prints its email and a generated
password **once**, to the terminal. It is not written to a file or stored
anywhere else. If you lose it before first login, re-run the seed or create a
replacement with `psql`.

On first sign-in you will be required to change the password, and — because
`SUPER_ADMIN` is in `MFA_REQUIRED_ROLES` — to enrol a TOTP second factor with an
authenticator app. Do both immediately, and save the recovery information
somewhere that is not the same laptop.

Passwords must be at least 12 characters with an upper case letter, a lower case
letter, a digit and a symbol, and may not contain your own email, your name, or
the brand name.

---

## Roles

Assign the narrowest role that lets someone do their job. A permission grants an
**action**, never a scope — dealer isolation is applied separately and cannot be
granted away.

| Role | For | Holds |
|---|---|---|
| **Super Admin** | the platform owner | everything, including the technical database tools |
| **Admin** | operations management | everything except the database tools and role assignment |
| **Warranty Manager** | claims and verification | claims, warranties, replacements, risk indicators, dispatches |
| **Sales Manager** | dealer performance | dealers, applications, sales, leads, reports |
| **Warehouse** | production and stock | batches, serials, dispatches |
| **Dealer** | a dealership | own inventory, sales and claims only |
| **Reporting** | analysts | read-only across the business |

Five permissions are **MFA-gated** and are not granted by a role assignment
alone — the holder must have MFA enabled and satisfied on the current session:
backups, database export, the read-only SQL console, changing system settings,
and assigning roles.

---

## The console

Navigation renders from the permissions you actually hold, so a section you
cannot see is one you have no permission for — not a bug.

| Section | |
|---|---|
| **Dashboard** | production, dispatch, sales and claim activity at a glance |
| **Mattresses** | every serial, its current status and full lifecycle history |
| **Production** | manufacturing batches, and serial generation |
| **Dispatches** | consignments to dealers |
| **Claims** | warranty claims, risk indicators, decisions, replacements |
| **Warranties** | active, expired, void and superseded warranties |
| **Dealers** | the network, credit terms, status |
| **Applications** | dealership applications from the website |
| **Website leads** | contact form submissions |
| **Products & content** | catalogue, website pages, FAQs |
| **SEO** | titles, descriptions, robots and sitemap settings |
| **Users & roles** | platform accounts |
| **Audit trail** | who did what, when |
| **System** | health, settings, backups, database tools |

---

## The mattress lifecycle

```
MANUFACTURED → IN_DISPATCH → DISPATCHED → DEALER_RECEIVED → SOLD
                                                              ↓
                                            CLAIM_OPEN → REPLACED
                                                       → RETURNED
                                                       → SCRAPPED
```

Every transition is recorded in `mattress_events` with who caused it and when.
A mattress cannot skip a state, and the serial follows it the whole way.

### Producing stock

**Production → New batch**, then generate serials against it. Serials come from
a PostgreSQL sequence (`colifees_next_serial()`), never from application code,
so two concurrent requests cannot produce the same one. Each carries a QR code
that resolves to a verification page.

> Serials keep the `CLF` prefix. It is printed on physical labels already in the
> field and encoded in QR codes, and is referenced by warranties, claims and
> dispatches — see [11](11-brand-configuration.md). A future prefix change
> applies to new production only and never rewrites existing serials.

### Dispatching

**Dispatches → New dispatch**: pick a dealer, add mattresses by serial, send.
The dealer confirms receipt in their portal, flagging anything damaged or
missing. Until they confirm, the stock is in transit and belongs to neither
side's sellable inventory.

A dispatch can be cancelled with a reason while it is still `DRAFT` or
`DISPATCHED`. The reason is required and audited.

---

## Warranty claims

```
SUBMITTED → UNDER_REVIEW → APPROVED  → REPLACED → CLOSED
                         → REJECTED             → CLOSED
          ↕ INFO_REQUESTED
          → WITHDRAWN
```

A claim opens against a serial, so the mattress's whole history — which batch,
which dispatch, which dealer, when it sold — is on the same screen as the
decision.

**Risk indicators** (`risk:view`) flag patterns worth a second look: a claim
raised unusually soon after the sale, a dealer whose claim-to-sale ratio is
above threshold, a customer with repeat claims, an inconsistent timeline. They
are **indicators, not verdicts** — a human decides. Thresholds live in
**System → Settings** under `risk.*`.

**Deciding** needs `claim:decide` and a reason, which is audited. Approving does
not itself issue a replacement; **Replacement** does, and that links the new
mattress to the claim and supersedes the original warranty.

**Claim media** (`claim:media:view`) is served through short-lived signed URLs
after an authorisation check. Photographs are never in a public bucket, and the
URLs expire.

---

## Dealers

**Applications** arrive from the public website. Approve, reject, or request
more information. Approving creates the dealer record and its first login; the
dealer code comes from a sequence, like every other identifier.

**Suspending** a dealer (`dealer:suspend`) stops them selling immediately. Their
historical sales, warranties and claims are untouched — suspension is not
deletion, and nothing here deletes history.

---

## Website content

**Products & content** edits the catalogue and the marketing pages. Changes are
drafts until published (`cms:publish`), and the public site revalidates within
five minutes.

**SEO** sets per-page titles, descriptions, social cards, canonical paths,
robots directives and sitemap inclusion. A page title written here is used
verbatim — the site does not append the brand name a second time.

---

## Users

**Users & roles** creates accounts and assigns roles. Assigning a role requires
`user:role:assign`, which is MFA-gated.

**Force password reset** (`user:reset_password`) requires the user to set a new
password at next sign-in. It does not reveal or set a password for them.

**Deactivate rather than delete.** A user who made decisions is referenced by
the audit trail; deactivation keeps that history readable.

Each person sees their own sessions under **Security**, and can revoke every
other session at once — the first thing to do after a lost laptop.

---

## System

**Health** — API and database status, connection counts, migration state.

**Settings** — runtime values an administrator can change without a deploy:
public phone and email, the displayed address, social links, warranty terms
version, risk thresholds, sitemap and indexing switches, and feature toggles for
the dealer locator, contact form and dealership applications. Changing them
needs `system:settings:write`, which is MFA-gated.

The company **name** and **location** are not here — they are build-time values
in `packages/brand`. See [11](11-brand-configuration.md).

**Backups** — run one on demand (`system:backup`, MFA-gated) and see the history
of runs. It runs the same `scripts/backup.sh` the nightly job uses, so there is
one procedure to trust. [03](03-database-backup.md).

**Audit trail** — every privileged action, with actor, time, entity and reason.
It is append-only and hash-chained: **nobody can delete or edit an entry**, not
through the console, not through the API, not through a crafted request. The
privilege does not exist on the application's database connection. **Verify
chain** re-walks it and reports any break.

**Database tools** — read-only SQL, `SUPER_ADMIN` only, MFA-gated. There is no
arbitrary SQL execution for ordinary administrators and no route to `UPDATE`,
`DELETE` or DDL through a browser.

---

## Good habits

- Give people the narrowest role that works, and review assignments quarterly
- Deactivate accounts the day someone leaves
- Never share a login — the audit trail is only useful if a name means a person
- Enrol MFA on every privileged account, not just the ones that are forced
- Write a real reason when one is asked for; it is what someone reads a year
  later
- Verify the audit chain on a schedule, not only after an incident
- Confirm backups are running — and that one has been restored
  ([04](04-database-restore.md#the-quarterly-drill))

---

## If something is wrong

**Locked out** — five failed attempts locks the account for fifteen minutes. It
clears on its own; another administrator can also force a password reset.

**Lost the MFA device** — another `SUPER_ADMIN` disables MFA on the account,
which is audited. Re-enrol immediately. Keep more than one administrator with
MFA enrolled, or you can lock the whole business out.

**A `REFRESH_TOKEN_REUSE` alert** — a refresh token was presented twice. That is
either a stolen token or a broken client. The session family is already revoked
automatically; investigate, and force a password reset if in doubt.

**A broken audit chain** — treat as an incident. Nothing the application can do
breaks it. Preserve the database, take a backup immediately, and investigate who
has direct database access.
