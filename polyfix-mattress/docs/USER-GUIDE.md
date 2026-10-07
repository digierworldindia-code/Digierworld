# POLYFIX MATTRESS — User guide

Everything the software does, and how to do it.

Written for the people who will actually use it: the office staff who run
production and dispatch, the warranty team who decide claims, and the dealers
who receive stock and sell it. No technical knowledge assumed. Where something
needs a server command it says so plainly, and those are the only parts meant
for whoever looks after the hosting.

For installing it, see `INSTALL.txt` and `docs/HOSTINGER-DEPLOYMENT.md`.

---

## Contents

1. [What the software is for](#1-what-the-software-is-for)
2. [The three parts](#2-the-three-parts)
3. [Signing in](#3-signing-in)
4. [Who can do what — roles](#4-who-can-do-what--roles)
5. [The life of one mattress](#5-the-life-of-one-mattress)
6. [Admin console, screen by screen](#6-admin-console-screen-by-screen)
7. [Dealer portal, screen by screen](#7-dealer-portal-screen-by-screen)
8. [The warranty claim process](#8-the-warranty-claim-process)
9. [Fraud risk indicators](#9-fraud-risk-indicators)
10. [Reports and exports](#10-reports-and-exports)
11. [The public website and the CMS](#11-the-public-website-and-the-cms)
12. [QR codes and labels](#12-qr-codes-and-labels)
13. [Settings](#13-settings)
14. [Users and passwords](#14-users-and-passwords)
15. [Optional two-factor authentication](#15-optional-two-factor-authentication)
16. [Audit trail](#16-audit-trail)
17. [Reference numbers explained](#17-reference-numbers-explained)
18. [Everyday problems and what to do](#18-everyday-problems-and-what-to-do)
19. [For whoever looks after the server](#19-for-whoever-looks-after-the-server)

---

## 1. What the software is for

Every mattress POLYFIX MATTRESS makes gets a serial number and a QR code
before it leaves the plant. From then on the software knows where that unit is
and what has happened to it:

- made, in which batch, on which date
- sent to which dealer, on which consignment, and whether it arrived
- sold to which customer, on what date, at what price, on which invoice
- what warranty it carries and when that ends
- whether a claim has been raised on it, what was decided, and whether it was
  replaced

A customer can scan the QR code on the label and check their own warranty
without ringing anybody. The warranty team can see, before deciding a claim,
whether this dealer claims unusually often or whether the same photograph has
been sent in before.

That is the whole purpose: one record per unit, from the factory floor to the
end of its warranty.

---

## 2. The three parts

| Part | Address | Who uses it |
|---|---|---|
| **Public website** | `/` | Customers and prospective dealers. No sign-in. |
| **Admin console** | `/admin` | POLYFIX MATTRESS staff. |
| **Dealer portal** | `/dealer` | Appointed dealers. Built for a phone. |

All three are the same application and the same database. A dealer signing in
cannot reach the admin console, and sees only their own dealership's
information — that separation is enforced on the server, not by hiding links.

---

## 3. Signing in

**Staff:** go to `/admin/login`. Enter your email address and your password,
then **Sign In**. The dashboard opens.

**Dealers:** go to `/dealer/login`. Same two fields.

That is the whole sign-in. There is no code to enter, no authenticator app and
no setup step, unless somebody has deliberately switched two-factor on
(section 15) and you have chosen to use it.

A few things worth knowing:

- **The eye icon** at the end of the password box shows what you have typed.
  Useful on a phone, and the quickest way to spot a stuck caps-lock.
- **A wrong password never locks your account.** You get one message —
  *Incorrect email or password. Please try again.* — your email address stays
  in the box, and you can try as many times as you need.
- **The first time you sign in** you are asked to choose your own password. The
  one you were given is temporary and works once. This is the only screen that
  stands between you and the console, and it appears once.
- **Forgot your password?** is on the sign-in page. It emails a link that
  expires. For security the page says the same thing whether or not the address
  is registered, so if no email arrives, check the address with whoever
  administers the system.
- **Sign out** from the menu with your name on it, top right.

Your session stays signed in while you work and expires after a period of
inactivity. Moving between screens, refreshing, opening a report — none of that
will ask you to sign in again.

---

## 4. Who can do what — roles

Each person is given one or more roles. A role is a bundle of permissions, and
there are **55 permissions across 7 roles**. Nobody can give themselves a role,
and nobody can create or edit an account more senior than their own.

| Role | Permissions | What it is for |
|---|---|---|
| **Super Admin** | 55 | Everything, including the database tools. Keep this to one or two people. |
| **Admin** | 45 | Runs the business day to day. Everything except the database tools. |
| **Warranty Manager** | 18 | Claims, verification and replacements. |
| **Sales Manager** | 14 | Dealer performance, sales figures and website leads. |
| **Warehouse** | 12 | Production, serials, stock and dispatch. |
| **Dealer** | 14 | Their own inventory, sales and claims. Nothing else, and nobody else's. |
| **Reporting** | 9 | Read-only. Cannot change anything at all — useful for an accountant or an auditor. |

The permissions are grouped by the part of the business they cover:
catalogue (4), manufacturing (5), logistics (6), dealer network (5), sales (5),
warranty (9), marketing and website (7), reports (2), administration (5) and
system (6).

**Console → Roles & permissions** shows the full grid of which role holds
which permission. It is worth looking at once before you decide who gets what.

If someone opens a screen they do not have permission for, they are told so
plainly. Hiding the link is not what stops them — the server refuses.

---

## 5. The life of one mattress

This is the sequence the whole system is built around. Each step is a screen,
and each step is recorded.

```
  Product            Console → Products
     │               what you make: name, sizes, warranty years
     ▼
  Batch              Console → Batches
     │               a production run at a named plant
     ▼
  Serialise          Console → Batches → Produce
     │               the batch issues serial numbers and QR codes
     ▼
  Label              Console → Mattresses → Label
     │               print and attach to the unit
     ▼
  Dispatch           Console → Dispatch
     │               put units on a consignment to a dealer, then Send
     ▼
  Receive            Dealer portal → Incoming
     │               the dealer confirms arrival, flags damage or shortage
     ▼
  Sell               Dealer portal → Sell
     │               customer, invoice, price. The warranty starts here.
     ▼
  In service         Customer can scan the QR and see their warranty
     │
     ▼
  Claim (if any)     Dealer portal → Claims  →  Console → Claims
     │               raised by the dealer, decided by POLYFIX
     ▼
  Replacement        Console → Claims → Issue replacement
                     a new unit, carrying the original warranty end date
```

A unit's status moves through: **Manufactured → In dispatch → Dispatched →
Dealer received → Sold → Claim open → Replaced**, with **Returned** and
**Scrapped** for the exceptions. You never set the status by hand; it follows
from the action you take.

---

## 6. Admin console, screen by screen

The sidebar groups these as you would expect. Every list screen filters and
searches, and every record screen shows that record's full history at the
bottom.

### Dashboard — `/admin`
What needs attention today: claims waiting on a decision, consignments not yet
received, recent sales, stock by location. What you see depends on your role.

### Products — `/admin/products`
What POLYFIX MATTRESS makes. A product carries its name, description, **warranty
years** and its sizes (variants), each with its own SKU and price.

The warranty years on the product is what decides a customer's cover. Change it
and new sales get the new figure; sales already recorded keep the terms they
were sold under.

**Publish** puts a product on the public website; unpublish takes it off
without deleting anything.

### Mattresses — `/admin/mattresses`
Every individual unit ever made. Search by serial. Each unit's page shows its
product and size, where it is now, which dealer holds it, when it was sold, its
warranty dates, any claims against it, and its complete timeline.

**Label** (on the unit's page) produces the printable label — QR code, barcode
and serial. See section 12.

**Lookup** — `/admin/lookup` — is the fast path: paste or scan a serial and go
straight to that unit.

### Batches — `/admin/batches`
A production run. Create the batch (product, size, quantity, plant, date), then
**Produce** to issue that many serial numbers at once. The serials come out in
a single unbroken run, and the batch page lists them.

Serials are never reused and never renumbered.

### Dispatch — `/admin/dispatches`
Consignments to dealers.

1. **New dispatch** — choose the dealer and the units (by serial, or pick from
   warehouse stock), set the expected arrival date and the invoice number.
2. **Send** — the consignment is now in transit and the dealer is notified.
   Units move to *Dispatched*.
3. **Cancel** needs a reason and is recorded.

Once a dealer confirms receipt, the dispatch page shows exactly what they
accepted, what they reported damaged and what never arrived.

### Dealers — `/admin/dealers`
The dealer network. Each dealer has their code, business name, contact details,
address and status. From a dealer's page you can create their **sign-in** —
which prints a one-time password to hand over — and see their stock, sales and
claim history.

**Suspend** or **terminate** stops that dealer signing in without deleting
anything they have done.

### Dealership applications — `/admin/applications`
Applications sent from the public website. Approve, reject, or request more
information. Approving is the start of creating a dealer, not an automatic one —
you still fill in the commercial details.

### Sales — `/admin/sales`
Every sale across the whole network: date, invoice, serial, product, dealer,
customer town and price, with each sale's warranty status beside it.

To undo a sale, void its **warranty** from the warranty record (below) and
raise the correction with the dealer. There is no "void sale" button on this
screen: a sale that has happened is a fact about the unit's history, and the
warranty is the part with consequences for the customer.

### Customers — `/admin/customers`
Customer records created by dealers when they sell.

**Phone numbers, email addresses and street addresses are stored encrypted**,
so they are unreadable in a raw database dump or a stolen backup. The software
can still spot that the same phone number appears on several claims, without
holding the number in readable form.

Customer **names are stored as ordinary text** — they have to be searchable and
printable on paperwork. Treat a database backup as containing customer names
and handle it accordingly.

### Warranty — `/admin/warranties`
Active and expired cover. Each record shows the unit, the customer, the start
and end dates and the terms version. **Void** needs a reason. **Override**
changes an end date — for a goodwill extension, say — and records who did it
and why.

### Warranty claims — `/admin/claims`
The heart of the warranty operation. Section 8 covers it in full.

### Replacements — `/admin/replacements`
Every unit issued against an approved claim, with the claim and the original
unit beside it.

### Website leads — `/admin/leads`
Enquiries from the public contact form, with a status you can move along and
notes you can add. Enquiries are typed — product enquiry, bulk order,
dealership, other.

### Website content — `/admin/content`
Edit the public site without touching code: page text, product copy and images,
and the FAQs. Section 11.

### SEO — `/admin/seo`
Page titles, meta descriptions and social-share images, per page and per
product.

### Users — `/admin/users`
Staff and dealer sign-ins. Create an account, assign roles, force a password
reset, clear someone's two-factor. Section 14.

### Roles & permissions — `/admin/roles`
The read-only grid of all 55 permissions against all 7 roles.

### Notifications — `/admin/notifications`
What the system has raised for you: a claim opened, a consignment received, a
dealership application arrived.

### Audit log — `/admin/audit`
Who did what, when, and what the value was before. Section 16.

### System — `/admin/system`
Health checks, database and storage usage, and the technical console. Super
Admin only.

### Settings — `/admin/settings`
Company details, warranty policy, risk thresholds, website switches and
security. Section 13.

---

## 7. Dealer portal, screen by screen

Designed for a phone in a shop. Large targets, no sideways scrolling, works on
a slow connection.

### Home — `/dealer`
Stock on hand, consignments on the way, claims in progress, recent sales.

### Scan — `/dealer/scan`
Point the camera at a label, or type the serial. The portal shows that unit and
**offers the next thing to do with it** — receive it, sell it, or raise a claim
— depending on where it is in its life. This is the screen a dealer uses most.

### Incoming — `/dealer/incoming`
Consignments POLYFIX has sent. Open one and confirm what arrived:

- accept the units that are fine
- mark anything **damaged**
- mark anything **missing**

Confirm, and the units accepted become the dealer's stock. Damage and shortages
go straight back to POLYFIX — nobody has to telephone about it.

### Inventory — `/dealer/inventory`
What this dealer holds and can sell, by product and size.

### Sell — `/dealer/sell`
Record a sale: serial, customer name and phone, address, invoice number, price,
date. On saving, **the warranty starts** and the customer's cover is live
immediately — the QR code on their mattress will show it.

### My sales — `/dealer/sales`
This dealer's sales, with each one's warranty status.

### Claims — `/dealer/claims`
Raise a claim and follow it. Section 8.

### Notifications — `/dealer/notifications`
A consignment is on its way; a claim has been decided.

A dealer sees only their own dealership throughout. There is no screen, filter
or address that shows them another dealer's stock, sales or customers.

---

## 8. The warranty claim process

### What the dealer does

**Claims → New claim**:

1. **Serial number** — scanned or typed. The portal checks the unit belongs to
   this dealer, that it has been sold, that it has a warranty record which has
   not been voided, and that there is no claim already open on it.
2. **What is wrong** — chosen from: sagging or body impressions, fabric tear,
   foam breaking down, spring failure, stitching coming apart, wrong size,
   damaged in transit, or something else.
3. **In one line** — a short summary, e.g. *"Dip on the left side, about 4 cm"*.
4. **What the customer says** — when it started, how the mattress is used, what
   base it sits on.
5. **Photographs** — this matters. Clear pictures of the fault, and of the
   label. Photographs and short videos are both accepted.

The claim gets a number (`CLM…`) and the dealer can watch its progress and
reply if more is asked for.

**An expired warranty does not stop a claim being raised.** That is deliberate:
an out-of-warranty claim is a business decision for you to make, and refusing
to record it only moves the conversation to the telephone where nothing is
written down. The claim is accepted and flagged with a *warranty expired*
risk indicator, and you decide.

If your trade needs a minimum period between a sale and a claim, set
`warranty.claim_window_days` in **Settings → Warranty**. It is 0 by default,
meaning no restriction.

### What POLYFIX does

Open the claim in **Console → Claims**. You see the reported issue, the
photographs, the unit's full history, the customer, the dealer, and the **risk
indicators** (section 9).

A claim moves through these states:

```
  SUBMITTED ─┬─▶ UNDER REVIEW ─┬─▶ APPROVED ─┬─▶ REPLACED ─▶ CLOSED
             ├─▶ INFO REQUESTED┤             └─▶ CLOSED
             └─▶ WITHDRAWN     └─▶ REJECTED ─────▶ CLOSED
```

The actions available on the claim page:

- **Add a note** — internal, or visible to the dealer.
- **Request information** — asks the dealer for more, and tells them what.
- **Record an inspection** — date and findings, when someone has been to look.
- **Add media** — your own photographs from an inspection.
- **Record the decision** — Approve or Reject, with the reason. If approved,
  choose the resolution: **replace the mattress**, **repair**, or a **goodwill
  settlement**.
- **Issue the replacement** — for an approved replacement. You pick the new
  unit by serial, and the replacement **carries what is left of the original
  term**, not a fresh one: its warranty ends on the original end date. (If the
  original had already expired, the replacement gets a single day, so there is
  always a dated record rather than a blank.) The old unit becomes *Replaced*
  and says so publicly if anyone scans its label.
- **Close** the claim when it is finished.
- **Re-check risk** recalculates the indicators, after new photographs arrive.

Every one of these is recorded with who did it and when. A closed claim cannot
be quietly reopened or edited.

---

## 9. Fraud risk indicators

When a claim arrives the software looks for patterns worth a second glance and
gives the claim a score — **Low**, **Medium** or **High**.

The ten things it checks:

| Indicator | What it noticed |
|---|---|
| Early claim | Raised within a few days of the sale |
| Warranty expired | The cover had already ended when the claim came in |
| Near expiry | Raised in the last 60 days of cover |
| Dealer claim ratio | This dealer claims on an unusual share of their sales |
| Dealer claim burst | Several claims from this dealer in the last 30 days |
| Repeat customer | This phone number appears on other claims |
| Repeat address | Other customers' claims share this delivery address |
| Duplicate photograph | An uploaded image is byte-identical to one on another claim |
| Timeline inconsistent | The dates do not line up — sold before it was received, and so on |
| Repeat claim, same unit | This mattress has been claimed on before |

Each indicator says what it found and shows its evidence, so you can judge it
rather than take it on faith.

**Read these as a prompt to look, never as a verdict.** A high score on a
genuine claim is perfectly possible — a mattress sold in a slow month to a
customer whose relative also bought one will trip two indicators and be
entirely honest. The thresholds for the early-claim window, the dealer ratio
and the repeat-customer count are all in **Settings → Risk**, and you should
tune them to your own trade rather than leave them at the defaults.

---

## 10. Reports and exports

**Console → Reports** — nine reports, each filterable by date range and usually
by dealer or product:

| Report | Shows |
|---|---|
| **Sales** | Every sale, with dealer, product and warranty status |
| **Sales by month** | Units and value per calendar month, with average price |
| **Inventory** | Where every unit is: warehouse, in transit, at a dealer, sold |
| **Production** | Units serialised per batch, with plant and dates |
| **Dispatches** | Consignments, what was received, what was damaged or missing |
| **Warranty claims** | Claims with issue, decision, resolution and risk level |
| **Dealer performance** | Stock, sales and claim ratio per dealer |
| **Warranty expiry** | Active warranties by the month they end |
| **Replacements** | Units issued against approved claims |

Every report exports to **CSV** (for anything) and **Excel** (.xlsx, formatted).
The export respects the filters on screen, so you export what you are looking
at.

Dealer performance deliberately shows the claim ratio next to the sales count.
A dealer with three claims out of five sales and one with three out of three
hundred are different situations, and the report lets you see which is which.

---

## 11. The public website and the CMS

The public site has: home, mattresses (and a page per product), why POLYFIX,
about, warranty, dealer locator, contact, privacy and terms, plus
`robots.txt` and `sitemap.xml`.

**Console → Website content** edits all of it:

- **Pages** — the text on each page.
- **Products** — the marketing copy and images shown publicly, separate from
  the operational product record.
- **FAQs** — questions and answers on the warranty page.

**Console → SEO** sets each page's title, meta description and social-share
image.

Three switches in **Settings → Website** turn public features on and off: the
contact form, dealership applications and the dealer locator. The site stays up
with any of them off; the section simply does not appear.

The admin console and dealer portal are never indexed by search engines — they
send a `noindex` header on every page, and are excluded in `robots.txt`.

---

## 12. QR codes and labels

Every mattress gets a label carrying:

- the **QR code**, which opens that unit's warranty page
- the **barcode** (Code 128) of the serial number, for warehouse scanners
- the **serial number** in plain text

Print it from **Console → Mattresses → [unit] → Label**.

The QR code points at `https://your-domain.com/warranty/verify?q=…` with a
token unique to that unit. A customer scans it and sees their product, their
warranty dates, and whether the unit has been replaced. They do not need an
account and they cannot see anybody else's details.

> **Important.** The address baked into every printed QR code comes from the
> `app.baseURL` setting. **Once labels are printed and out in the field, do not
> change the domain.** If you ever must, keep the old domain redirecting to the
> new one, or every label already attached to a mattress stops working.

---

## 13. Settings

**Console → Settings.** Changes take effect immediately and each one is
recorded in the audit trail with its previous value. Passwords and keys are
never stored here — they live in the server's configuration.

| Group | What is in it |
|---|---|
| **Company** | Legal name, address shown in the website footer, support phone and email, GST number |
| **Security** | Two-factor authentication on or off (section 15) |
| **Warranty** | Terms version applied to new sales; minimum days after a sale before a claim may be raised (0 = no restriction) |
| **Risk** | Early-claim window in days; dealer claim-ratio threshold; repeat-customer claim threshold |
| **Website** | Contact form, dealership applications, dealer locator — each on or off |
| **SEO** | Allow search engines to index the site; serve the sitemap |
| **Social** | Instagram, Facebook, LinkedIn, YouTube links — blank hides them |

Leave `seo.robots_allow_indexing` **off** on any staging or test copy, or two
versions of your site will compete in search results.

---

## 14. Users and passwords

### Creating someone

**Console → Users → New user.** Enter their name and email, choose their roles,
save. The screen shows a **temporary password once** — it is not stored
readably and cannot be shown again. Hand it over in person or by telephone,
never by email.

They will be asked to choose their own password the first time they sign in.

For a dealer sign-in, create it from that dealer's page instead, so the account
is tied to the dealership.

### Looking after accounts

From a user's page you can edit their details, change their roles, **force a
password reset** (their next sign-in makes them choose a new one), clear their
two-factor, or suspend them.

Two things nobody can do, whatever their role: change their own roles, or
create or edit an account more senior than their own.

### Your own password

**Password & security**, in the menu with your name on it. Change your
password, see every device signed in as you, and **sign out other sessions** —
which is what to do the moment you suspect someone else has your password.

Passwords must be at least 12 characters with upper and lower case, a digit and
a symbol. They cannot contain your own name, email address or the company name,
and a list of twenty obvious ones is refused outright. They are stored hashed with
Argon2id and cannot be recovered by anyone, including whoever runs the server —
only reset.

---

## 15. Optional two-factor authentication

**Off by default, for everybody. Nobody is ever forced to use it.**

Two-factor means that after your password you also type a 6-digit code from an
app on your phone. It protects against somebody who has learned your password.

### Turning it on

**Settings → Security → Two-Factor Authentication** → `true`. That makes it
*available*; it does not switch it on for anyone.

Each person who wants it then goes to **Password & security**, confirms their
password, scans the QR code with Google Authenticator, Microsoft Authenticator,
1Password or similar, and enters the code it shows. They are given **ten
recovery codes** — each usable once, in place of the phone.

**Save those recovery codes.** A password manager, or printed and locked away.

### While it is off

No code is ever asked for. If an account enrolled at some point and the feature
is later switched off, that account signs in on its password alone — a leftover
setting is not allowed to lock anybody out.

### If a phone is lost

There is always a way back in:

| Situation | What to do |
|---|---|
| You have your recovery codes | Use one in place of the code. It also switches two-factor off if you want. |
| Codes gone too | Another administrator clears it from your page in **Users** |
| You are the only administrator and cannot get in | On the server: `php spark polyfix:unlock --two-factor-off` |

### Should you use it?

For most mattress businesses, no — and that is why it ships off. It adds a step
to every single sign-in, and in a shop or a warehouse that friction is real.

Turn it on for the one or two Super Admin accounts if you want, since those can
reach the database tools. Leave it off for shop-floor and dealer accounts. And
make sure anyone who enrols has saved their recovery codes before they walk
away from the screen.

---

## 16. Audit trail

**Console → Audit log** records every change: who, when, what record, and what
the value was before. Sales, claims, decisions, settings, roles, dispatches,
voids and overrides are all in it.

Entries cannot be edited or deleted by anyone, through the application or
otherwise — the application's own database account has no permission to update
or delete them.

Each entry is also **cryptographically chained** to the one before it, so
altering or removing any entry breaks the chain and shows up. **Audit log →
Verify** re-walks the whole chain and reports **Intact** or names the first
entry that does not match. Worth running occasionally, and certainly before any
audit or dispute.

Passwords, keys and two-factor secrets are stripped from audit entries before
they are written.

---

## 17. Reference numbers explained

| Looks like | Is a | Shape |
|---|---|---|
| `CLF26000001` | Mattress serial | `CLF` + 2-digit year + 6 digits |
| `BAT260001` | Production batch | `BAT` + year + 4 digits |
| `DSP26000001` | Dispatch consignment | `DSP` + year + 6 digits |
| `CLM26000001` | Warranty claim | `CLM` + year + 6 digits |
| `DLR0001` | Dealer code | `DLR` + 4 digits |

Numbers are issued in order, never reused, and never renumbered. The `CLF`
prefix is kept from the previous platform deliberately, so serials printed and
attached before the changeover still match their records.

---

## 18. Everyday problems and what to do

| What you see | What it means |
|---|---|
| *Incorrect email or password* | Mistyped, or caps-lock. Use the eye icon to check. Nothing is locked; try again. |
| *Please wait a moment* | A great many sign-in attempts came from your office's internet connection at once. Wait the seconds it names. Nothing is wrong with your account. |
| *You do not have permission to do that* | Your role does not include that action. An administrator can change your roles. |
| Asked to change your password at sign-in | Normal, the first time, or after an administrator reset it. |
| A dealer cannot see a unit they expect | They have not confirmed the consignment yet — **Incoming** on their portal. |
| *A warranty claim can only be raised on a mattress that has been sold* | The unit is still in stock or in transit. Record the sale first. |
| *There is already an open claim on this mattress* | Finish or close the existing claim before raising another. |
| *The warranty on this mattress has been voided* | Someone voided it deliberately — check the warranty record for the reason. |
| *Claims can be raised from N days after the sale* | `warranty.claim_window_days` in Settings → Warranty is set above 0. |
| A scanned QR code shows nothing | The unit was serialised but never sold, so there is no warranty yet. |
| A QR code goes to the wrong site | `app.baseURL` was changed after labels were printed. See section 12. |
| Pages load with no styling | Server configuration, not your doing — see `docs/HOSTINGER-DEPLOYMENT.md`. |
| *Setup is not finished yet* | The installation is incomplete. The page lists exactly what is missing. |

---

## 19. For whoever looks after the server

Not needed for everyday use.

```bash
php spark polyfix:doctor          # check PHP, permissions, secrets, database, migrations
php spark polyfix:create-user …   # create the first administrator
php spark polyfix:unlock          # clear stale sign-in locks
php spark polyfix:unlock --two-factor-off
php spark polyfix:unlock --clear-2fa --email someone@example.com
php spark polyfix:migrate         # apply database changes after an upgrade
php spark polyfix:seed ReferenceData
php spark polyfix:brand-check     # check the brand name is spelt correctly throughout
scripts/backup.sh                 # nightly backup
scripts/restore.sh <file.sql.gz>
```

The application log is `writable/logs/log-YYYY-MM-DD.log`. In production the
site never shows a visitor a stack trace, a file path or a database error — the
detail goes to that file.

**Take a backup and restore it once**, before you rely on it. A backup nobody
has ever restored is a hope, not a backup. `docs/backup.md` and
`docs/restore.md` cover both.

Other documentation: `docs/HOSTINGER-DEPLOYMENT.md` (deployment),
`docs/database.md` (schema and accounts), `docs/security.md` (what is protected
and the trade-offs made), `docs/migration.md` (bringing data from the previous
platform).
