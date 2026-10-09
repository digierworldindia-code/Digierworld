# POLYFIX MATTRESS — Use case specification

Software documentation. Describes what the system does, who may do it, what
must be true before and after, and the rules it enforces.

This document was written from the running software — routes, permission
filters, service-layer guards and database constraints — not from a design
intention. Where a rule is quoted, the system enforces exactly that. The
companion documents are `docs/USER-GUIDE.md` (how to operate it),
`docs/security.md` (threat model and trade-offs) and `docs/database.md`
(schema).

| | |
|---|---|
| **System** | POLYFIX MATTRESS warranty and distribution platform |
| **Version** | CodeIgniter 4.7.4 · PHP 8.2+ · MySQL 8.0+ · Bootstrap 5 |
| **Scope** | Public website, admin console, dealer portal |
| **Use cases** | 92 across 13 subsystems — 29 specified in full, 63 in brief |
| **Actors** | 12 (9 primary, 2 secondary, 1 system) |
| **Business rules** | 45 |
| **Permissions** | 55, of which 51 are enforced on a route (§9) |

---

## Contents

1. [Conventions](#1-conventions)
2. [Actors](#2-actors)
3. [Use case inventory](#3-use-case-inventory)
4. [Detailed specifications](#4-detailed-specifications)
5. [Supporting use cases](#5-supporting-use-cases)
6. [Business rules catalogue](#6-business-rules-catalogue)
7. [State models](#7-state-models)
8. [System-wide constraints](#8-system-wide-constraints)
9. [Traceability matrix](#9-traceability-matrix)

---

## 1. Conventions

**Identifier format** — `UC-<subsystem>-<nn>`:

| Code | Subsystem | Code | Subsystem |
|---|---|---|---|
| `PB` | Public website | `WR` | Warranty |
| `AU` | Authentication and account | `CL` | Warranty claims |
| `PC` | Product catalogue | `NW` | Dealer network |
| `MF` | Manufacturing | `MK` | Marketing and content |
| `LG` | Logistics | `RP` | Reports |
| `SL` | Sales | `AD` | Administration |
| | | `SY` | System |

Business rules are `BR-nn` and are catalogued in section 6.

**Permission** names the single permission the route's filter requires. A user
holding it through any of their roles is admitted; the check is server-side on
every request, including direct URL entry. Absence of a permission is not
expressed by hiding a link.

**Dealer-scoped** means the request is additionally confined to the signed-in
user's own dealership (BR-02). This is applied at the query level, not by
filtering results after the fact.

**Audit** names the action written to the append-only audit trail. Where a
field is listed, the previous value is recorded alongside the new one.

---

## 2. Actors

### Primary actors

| Actor | Role key | Description |
|---|---|---|
| **Visitor** | — | Anonymous public. No account. Browses the website, verifies a warranty by QR code, submits an enquiry or a dealership application. |
| **Customer** | — | A person who owns a POLYFIX mattress. Has no account; interacts only by scanning the label on their own unit. |
| **Dealer** | `DEALER` | An appointed dealership. Receives consignments, sells units, raises warranty claims. Confined to their own dealership's data. |
| **Warehouse Operator** | `WAREHOUSE` | Production, serialisation, stock and dispatch. |
| **Warranty Manager** | `WARRANTY_MANAGER` | Reviews and decides claims, issues replacements, manages warranty records. |
| **Sales Manager** | `SALES_MANAGER` | Dealer performance, sales analysis, website leads. |
| **Administrator** | `ADMIN` | Operational management across the business. All subsystems except the database tools. |
| **Super Administrator** | `SUPER_ADMIN` | Everything, including system health, backup, export and the read-only SQL console. |
| **Analyst** | `REPORTING` | Read-only. Reports and records; cannot change anything. |

A user account may hold more than one role; its permissions are the union.

### Secondary actors

| Actor | Description |
|---|---|
| **Email service** | SMTP relay. Delivers password-reset links and claim notifications. Not required for the system to operate. |
| **Authenticator application** | TOTP app on a user's phone. Participates only when two-factor is switched on (BR-09) and that user has enrolled. |

### System actor

| Actor | Description |
|---|---|
| **Risk Assessor** | An internal process, not a person. Scores every claim against ten indicators on submission and on request (UC-CL-10, BR-26 to BR-35). |

---

## 3. Use case inventory

### Public website (no authentication)

| ID | Use case | Actor | Detail |
|---|---|---|---|
| UC-PB-01 | Browse the product catalogue | Visitor | §5 |
| UC-PB-02 | View a product page | Visitor | §5 |
| UC-PB-03 | **Verify a warranty by QR code** | Customer | §4 |
| UC-PB-04 | Find a dealer | Visitor | §5 |
| UC-PB-05 | Submit a product or bulk enquiry | Visitor | §5 |
| UC-PB-06 | **Apply for a dealership** | Visitor | §4 |
| UC-PB-07 | Read a content page | Visitor | §5 |
| UC-PB-08 | Retrieve robots.txt and sitemap.xml | Search engine | §5 |

### Authentication and account

| ID | Use case | Actor | Detail |
|---|---|---|---|
| UC-AU-01 | **Sign in** | All staff, Dealer | §4 |
| UC-AU-02 | **Change a temporary password at first sign-in** | All staff, Dealer | §4 |
| UC-AU-03 | Change own password | All staff, Dealer | §5 |
| UC-AU-04 | Request a password reset | All staff, Dealer | §5 |
| UC-AU-05 | Complete a password reset | All staff, Dealer | §5 |
| UC-AU-06 | Sign out | All staff, Dealer | §5 |
| UC-AU-07 | Sign out other sessions | All staff, Dealer | §5 |
| UC-AU-08 | **Enrol in two-factor authentication** | All staff, Dealer | §4 |
| UC-AU-09 | Provide a second factor at sign-in | All staff, Dealer | §5 |
| UC-AU-10 | **Switch own two-factor off** | All staff, Dealer | §4 |

### Product catalogue

| ID | Use case | Actor | Permission | Detail |
|---|---|---|---|---|
| UC-PC-01 | View products | Administrator | `product:read` | §5 |
| UC-PC-02 | Create a product | Administrator | `product:write` | §5 |
| UC-PC-03 | Edit a product | Administrator | `product:write` | §5 |
| UC-PC-04 | Add or edit a size variant | Administrator | `product:write` | §5 |
| UC-PC-05 | Publish or unpublish a product | Administrator | `product:publish` | §5 |
| UC-PC-06 | Delete a product | Administrator | `product:delete` | §5 |

### Manufacturing

| ID | Use case | Actor | Permission | Detail |
|---|---|---|---|---|
| UC-MF-01 | View batches | Warehouse Operator | `batch:read` | §5 |
| UC-MF-02 | Create a production batch | Warehouse Operator | `batch:write` | §5 |
| UC-MF-03 | **Serialise a batch** | Warehouse Operator | `mattress:create` | §4 |
| UC-MF-04 | **Print a unit label** | Warehouse Operator | `mattress:read` | §4 |
| UC-MF-05 | Look up a unit by serial | Warehouse Operator | `mattress:read` | §5 |
| UC-MF-06 | View a unit and its history | Warehouse Operator | `mattress:read` | §5 |
| UC-MF-07 | Delete a unit record | Administrator | `mattress:delete` | §5 |

### Logistics

| ID | Use case | Actor | Permission | Detail |
|---|---|---|---|---|
| UC-LG-01 | View dispatches | Warehouse Operator | `dispatch:read` | §5 |
| UC-LG-02 | **Create a dispatch** | Warehouse Operator | `dispatch:create` | §4 |
| UC-LG-03 | **Send a dispatch** | Warehouse Operator | `dispatch:update` | §4 |
| UC-LG-04 | Cancel a dispatch | Warehouse Operator | `dispatch:cancel` | §5 |
| UC-LG-05 | View incoming consignments | Dealer | `dispatch:read` | §5 |
| UC-LG-06 | **Confirm receipt of a consignment** | Dealer | `receipt:create` | §4 |

### Sales

| ID | Use case | Actor | Permission | Detail |
|---|---|---|---|---|
| UC-SL-01 | **Record a sale** | Dealer | `sale:create` | §4 |
| UC-SL-02 | View own sales | Dealer | `sale:read` | §5 |
| UC-SL-03 | View all sales | Sales Manager | `sale:read` | §5 |
| UC-SL-04 | View own stock | Dealer | `mattress:read` | §5 |
| UC-SL-05 | **Scan a unit and take the next action** | Dealer | `mattress:read` | §4 |
| UC-SL-06 | View customers | Sales Manager | `customer:read` | §5 |

### Warranty

| ID | Use case | Actor | Permission | Detail |
|---|---|---|---|---|
| UC-WR-01 | View warranties | Warranty Manager | `warranty:read` | §5 |
| UC-WR-02 | **Void a warranty** | Warranty Manager | `warranty:void` | §4 |
| UC-WR-03 | **Override warranty dates** | Warranty Manager | `warranty:void` | §4 |
| UC-WR-04 | View replacements issued | Warranty Manager | `claim:read` | §5 |

### Warranty claims

| ID | Use case | Actor | Permission | Detail |
|---|---|---|---|---|
| UC-CL-01 | **Raise a warranty claim** | Dealer | `claim:create` | §4 |
| UC-CL-02 | Attach evidence to own claim | Dealer | `claim:create` | §5 |
| UC-CL-03 | Reply to a request for information | Dealer | `claim:create` | §5 |
| UC-CL-04 | Track own claims | Dealer | `claim:read` | §5 |
| UC-CL-05 | **Review a claim** | Warranty Manager | `claim:read` | §4 |
| UC-CL-06 | Request further information | Warranty Manager | `claim:review` | §5 |
| UC-CL-07 | Record an inspection | Warranty Manager | `claim:review` | §5 |
| UC-CL-08 | Add a note to a claim | Warranty Manager | `claim:review` | §5 |
| UC-CL-09 | Attach own evidence to a claim | Warranty Manager | `claim:review` | §5 |
| UC-CL-10 | Recalculate risk | Warranty Manager | `risk:view` | §5 |
| UC-CL-11 | **Decide a claim** | Warranty Manager | `claim:decide` | §4 |
| UC-CL-12 | **Issue a replacement unit** | Warranty Manager | `claim:replace` | §4 |
| UC-CL-13 | Close a claim | Warranty Manager | `claim:decide` | §5 |
| UC-CL-14 | View claim evidence | Warranty Manager | `claim:media:view` | §5 |

### Dealer network

| ID | Use case | Actor | Permission | Detail |
|---|---|---|---|---|
| UC-NW-01 | View dealers | Sales Manager | `dealer:read` | §5 |
| UC-NW-02 | **Appoint a dealer** | Administrator | `dealer:write` | §4 |
| UC-NW-03 | Edit a dealer | Administrator | `dealer:write` | §5 |
| UC-NW-04 | Suspend or terminate a dealer | Administrator | `dealer:suspend` | §5 |
| UC-NW-05 | View dealership applications | Administrator | `dealer_application:read` | §5 |
| UC-NW-06 | **Review a dealership application** | Administrator | `dealer_application:review` | §4 |

### Marketing and content

| ID | Use case | Actor | Permission | Detail |
|---|---|---|---|---|
| UC-MK-01 | View website leads | Sales Manager | `lead:read` | §5 |
| UC-MK-02 | Progress a lead | Sales Manager | `lead:update` | §5 |
| UC-MK-03 | Edit a website page | Administrator | `cms:write` | §5 |
| UC-MK-04 | Edit product marketing content | Administrator | `cms:write` | §5 |
| UC-MK-05 | Edit the FAQs | Administrator | `cms:write` | §5 |
| UC-MK-06 | Edit SEO metadata | Administrator | `seo:write` | §5 |

### Reports

| ID | Use case | Actor | Permission | Detail |
|---|---|---|---|---|
| UC-RP-01 | **Run a report** | Analyst | `report:view` | §4 |
| UC-RP-02 | **Export a report** | Analyst | `report:export` | §4 |

### Administration

| ID | Use case | Actor | Permission | Detail |
|---|---|---|---|---|
| UC-AD-01 | View users | Administrator | `user:read` | §5 |
| UC-AD-02 | **Create a user account** | Administrator | `user:write` | §4 |
| UC-AD-03 | Edit a user | Administrator | `user:write` | §5 |
| UC-AD-04 | **Assign roles** | Administrator | `user:role:assign` | §4 |
| UC-AD-05 | Force a password reset | Administrator | `user:reset_password` | §5 |
| UC-AD-06 | **Clear another user's two-factor** | Administrator | `user:reset_password` | §4 |
| UC-AD-07 | View the role and permission matrix | Administrator | `user:read` | §5 |
| UC-AD-08 | Read the audit trail | Administrator | `audit:read` | §5 |
| UC-AD-09 | **Verify the audit chain** | Administrator | `audit:read` | §4 |
| UC-AD-10 | Read notifications | All staff | `dashboard:view` | §5 |

### System

| ID | Use case | Actor | Permission | Detail |
|---|---|---|---|---|
| UC-SY-01 | Open the dashboard | All staff | `dashboard:view` | §5 |
| UC-SY-02 | View system health | Super Administrator | `system:health` | §5 |
| UC-SY-03 | View settings | Administrator | `system:settings:read` | §5 |
| UC-SY-04 | **Change a setting** | Administrator | `system:settings:write` | §4 |
| UC-SY-05 | Check the installation (CLI) | Server operator | — | §5 |
| UC-SY-06 | **Recover access when nobody can sign in (CLI)** | Server operator | — | §4 |
| UC-SY-07 | Back up and restore (CLI) | Server operator | — | §5 |

---

## 4. Detailed specifications

---

### UC-PB-03 — Verify a warranty by QR code

| | |
|---|---|
| **Primary actor** | Customer |
| **Permission** | None. Public. |
| **Trigger** | The customer scans the QR code on the label attached to their mattress. |
| **Preconditions** | The unit has been serialised and labelled. `app.baseURL` is the address encoded on the label (BR-13). |
| **Frequency** | Highest-volume use case in the system. |

**Main success scenario**

1. The customer's phone opens `/warranty/verify?q=<token>`, where the token is
   unique to that single unit.
2. The system resolves the token to one mattress record.
3. The system returns, and only ever returns: the serial number; the product
   name, category, size and comfort level; the date of manufacture; and the
   warranty status, start date, end date, term in years and days remaining.
4. If the unit has been replaced under a claim, the page says so and shows the
   serial of the unit that replaced it.

The page states this limit to the customer in its own words: *this check shows
product and warranty information only. It never displays customer details,
dealer information or claim history.*

**Alternate flows**

| | |
|---|---|
| **3a. Unit not yet sold** | No warranty exists. The page states that the unit has not been registered to a customer and invites them to contact the dealer who supplied it. |
| **3b. Warranty expired** | Dates and the fact of expiry are shown. Nothing is hidden. |
| **3c. Warranty voided** | The page reports that cover is not in force and gives the support contact. The reason is not disclosed publicly. |
| **2a. Serial or token unrecognised** | *We have no record of that serial number* — with advice to check the characters on the law label or contact the supplying dealer. The reply is identical whether the token never existed or was withdrawn, so tokens cannot be enumerated. |

**Exception flows**

| | |
|---|---|
| **E1. Rate limit** | More than 20 verifications per minute from one address (BR-39) — a brief wait page. The limit is per address and is never reached by a customer scanning their own mattress. |

**Postconditions** — None. The use case is read-only and writes no audit entry.

**Business rules** — BR-13 (baseURL is immutable once labels are printed),
BR-14 (one random token per unit), BR-39 (verification throttle), BR-40 (the
response carries no customer, dealer, price or claim data).

---

### UC-PB-06 — Apply for a dealership

| | |
|---|---|
| **Primary actor** | Visitor |
| **Permission** | None. Public. |
| **Trigger** | A prospective dealer completes the form at `/dealers`. |
| **Preconditions** | `website.dealer_application_enabled` is true (BR-41). |

**Main success scenario**

1. The visitor submits business name, contact person, phone, email, city, state
   and a description of their trade.
2. The system validates the submission and records it with status `NEW`.
3. A notification is raised for staff holding `dealer_application:read`.
4. The visitor is thanked and told they will be contacted.

**Alternate flows**

| | |
|---|---|
| **1a. Feature switched off** | The form is not rendered and the route records nothing. |
| **2a. Validation fails** | The form returns with the entered values preserved and the specific fields marked. |

**Exception flows**

| | |
|---|---|
| **E1. Rate limit** | More than 5 submissions per hour from one address (BR-39). |

**Postconditions** — A `dealer_applications` row exists with status `NEW`. It is
not a dealer, and no sign-in has been created.

**Business rules** — BR-39, BR-41. Continues at UC-NW-06.

---

### UC-AU-01 — Sign in

| | |
|---|---|
| **Primary actors** | All staff roles, Dealer |
| **Permission** | None. This use case establishes identity. |
| **Trigger** | The user opens `/admin/login` (staff) or `/dealer/login` (dealer). |
| **Preconditions** | The account exists and its status is `ACTIVE` or `PENDING_ACTIVATION`. |

**Main success scenario**

1. The user enters an email address and a password.
2. The system verifies the password against the stored Argon2id hash.
3. The system regenerates the session identifier, destroying the
   pre-authentication session (BR-07).
4. The system creates a server-side session record and clears any stale lock
   state on the account (BR-05).
5. A `LOGIN` audit entry is written.
6. The user is taken to the dashboard — `/admin` for staff, `/dealer` for a
   dealer.

**Alternate flows**

| | |
|---|---|
| **2a. Wrong password** | *Incorrect email or password. Please try again.* The email address is retained in the form. The failure is counted and logged. **The account is not locked** (BR-04). The user may retry immediately and as often as needed. |
| **2b. Email not registered** | The identical message, and the same response time. Account existence is not disclosed (BR-03). |
| **2c. Account suspended or disabled** | The identical message (BR-03). |
| **2d. Dealer whose dealership is suspended or terminated** | *This dealership account is not active. Please contact POLYFIX.* The password was correct, so this discloses nothing to an attacker and tells a legitimate dealer something actionable. |
| **4a. Password is temporary** | Continues at UC-AU-02 before anything else is reachable. |
| **4b. Two-factor is on and this account has enrolled** | Continues at UC-AU-09. No session exists until the code is accepted. |
| **4c. Two-factor is off but the account's `mfa_enabled` flag is set** | The flag is ignored and sign-in completes (BR-10). A stale flag cannot strand an account. |

**Exception flows**

| | |
|---|---|
| **E1. Rate limit** | More than 30 sign-in attempts per minute from one address (BR-39). A brief wait message that states nothing is wrong with the account. |

**Postconditions** — A session row exists, bound to a hashed token in a
`HttpOnly`, `SameSite=Lax` cookie. `failed_login_count` is 0 and
`locked_until` is null.

**Business rules** — BR-03 to BR-10, BR-39.

---

### UC-AU-02 — Change a temporary password at first sign-in

| | |
|---|---|
| **Primary actors** | All staff roles, Dealer |
| **Trigger** | A user whose `must_change_password` flag is set signs in. |
| **Preconditions** | UC-AU-01 completed. |

**Main success scenario**

1. Every route except the security page, the password form and sign-out
   redirects to `/account/security` (BR-06).
2. The user enters the temporary password and a new password twice.
3. The system validates the new password against the policy (BR-08).
4. The password is rehashed, the flag is cleared, and **all other sessions for
   that account are revoked** — the current one is kept.
5. A `PASSWORD_CHANGED` audit entry is written. The console becomes reachable.

**Alternate flows**

| | |
|---|---|
| **3a. Policy not met** | The specific shortfall is named: too short, missing a character class, contains the user's own name or email, or on the blocked list. |
| **3b. The two new passwords differ** | *The two new passwords do not match.* |
| **3c. New password equals the current one** | Refused. |

**Postconditions** — `must_change_password` is false. No other session for
this account remains valid.

**Business rules** — BR-06, BR-08, BR-11. This is the only obligation that
stands between sign-in and the console (BR-12).

---

### UC-AU-08 — Enrol in two-factor authentication

| | |
|---|---|
| **Primary actors** | All staff roles, Dealer |
| **Participant** | Authenticator application |
| **Trigger** | The user chooses to set it up on `/account/security`. |
| **Preconditions** | `security.two_factor_enabled` is true (BR-09). Enrolment is **voluntary** — no role, setting or screen compels it (BR-12). |

**Main success scenario**

1. The user confirms their current password.
2. The system generates a TOTP secret, stores it encrypted, and leaves the
   account **not** enrolled.
3. The system displays a QR code and the secret in text.
4. The user scans it with an authenticator application and enters the 6-digit
   code it shows.
5. The system verifies the code, sets `mfa_enabled`, and issues **ten
   single-use recovery codes**, stored only as hashes.
6. The recovery codes are displayed once and never again. A `MFA_ENABLED`
   audit entry is written.

**Alternate flows**

| | |
|---|---|
| **1a. Feature switched off** | The panel is not rendered, and the route refuses with an explanation naming Settings → Security. |
| **4a. Code rejected** | *That code was not accepted. Check the time on your phone is set automatically, then try again.* The account remains not enrolled, so an abandoned setup locks nobody out (BR-10). |
| **4b. Setup never confirmed** | The encrypted secret remains with `mfa_enabled` false. Sign-in is unaffected. |

**Postconditions** — The account is enrolled and will be asked for a code at
each sign-in. Ten unused recovery codes exist as hashes.

**Business rules** — BR-09, BR-10, BR-12. See UC-AU-10 and UC-AD-06 for the
exits, and UC-SY-06 for the last resort.

---

### UC-AU-10 — Switch own two-factor off

| | |
|---|---|
| **Primary actor** | Any enrolled user |
| **Trigger** | The user asks to switch it off on `/account/security`. |
| **Preconditions** | The account is enrolled. **This use case is deliberately available even when the feature has been switched off centrally**, so a user can always clear their own enrolment. |

**Main success scenario**

1. The user supplies their password and **either** a current code from the app
   **or** one of their recovery codes.
2. The system verifies the password, then the code by either route.
3. `mfa_enabled`, the encrypted secret, the recovery codes and the enrolment
   date are all cleared.
4. Every **other** session for that account is revoked; the current session is
   kept, so the user stays signed in and sees the confirmation (BR-11).
5. A `MFA_DISABLED` audit entry is written.

**Alternate flows**

| | |
|---|---|
| **2a. Password wrong** | *Your password was not correct.* |
| **2b. Neither code accepted** | *That code was not accepted. Use the code from your authenticator app, or one of your recovery codes.* |
| **1a. Phone and recovery codes both lost** | This use case cannot complete. Proceed to UC-AD-06 (another administrator clears it) or UC-SY-06 (server operator). One of the three always applies (BR-10). |

**Postconditions** — The account signs in with its password alone.

**Business rules** — BR-10, BR-11.

---

### UC-MF-03 — Serialise a batch

| | |
|---|---|
| **Primary actor** | Warehouse Operator |
| **Permission** | `mattress:create` |
| **Trigger** | A production run is complete and the operator presses Produce on the batch. |
| **Preconditions** | The batch exists. A quantity is entered for at least one size. |

**Main success scenario**

1. The operator enters the quantity produced per size variant.
2. The system reserves a contiguous block of serial numbers under a row lock on
   the counter, so concurrent runs cannot collide or interleave (BR-15).
3. For each unit the system creates a mattress record with status
   `MANUFACTURED`, its serial number, a unique QR token and the batch link.
4. The serials are listed on the batch page, ready to print (UC-MF-04).
5. A `MATTRESS_CREATED` audit entry is written per unit.

**Alternate flows**

| | |
|---|---|
| **1a. No quantity entered** | *Enter a quantity for at least one size.* |
| **1b. Quantity above the per-run ceiling** | *Produce at most …* — the ceiling is stated. Split the run. |

**Postconditions** — *n* mattress records exist with status `MANUFACTURED`,
each with a unique serial and QR token. Serial numbers are never reused and
never renumbered (BR-16).

**Business rules** — BR-15, BR-16, BR-17 (serial format).

---

### UC-MF-04 — Print a unit label

| | |
|---|---|
| **Primary actor** | Warehouse Operator |
| **Permission** | `mattress:read` |
| **Trigger** | A serialised unit needs its label before leaving the plant. |

**Main success scenario**

1. The operator opens the unit and chooses Label.
2. The system renders, for that one unit: a **QR code** encoding
   `{app.baseURL}/warranty/verify?q=<token>`, a **Code 128 barcode** of the
   serial number, and the **serial number** as text.
3. The operator prints it and attaches it to the mattress.

**Postconditions** — None in the database. The printed artefact is now the
customer's route to UC-PB-03 for the life of the product.

**Business rules** — BR-13. **The address encoded in the QR code is fixed at
print time. Changing the site's domain afterwards breaks every label already in
the field.** If the domain must change, the old one has to keep redirecting.

---

### UC-LG-02 — Create a dispatch

| | |
|---|---|
| **Primary actor** | Warehouse Operator |
| **Permission** | `dispatch:create` |
| **Trigger** | Stock is to be sent to a dealer. |
| **Preconditions** | The dealer exists and is `ACTIVE`. The units are in stock. |

**Main success scenario**

1. The operator selects the destination dealer.
2. The operator adds units by serial number, or picks from warehouse stock.
3. The operator sets the expected arrival date and the invoice number.
4. The system creates the dispatch with code `DSP…` and status `DRAFT`, and
   moves each unit to `IN_DISPATCH`.
5. A `DISPATCH_CREATED` audit entry is written.

**Alternate flows**

| | |
|---|---|
| **2a. No units added** | *Add at least one serial number to the dispatch.* |
| **2b. The same serial twice** | *The same serial number appears more than once in this dispatch.* |
| **2c. A serial is not in stock records** | The count and a sample of the unrecognised serials are named. |
| **2d. A unit is not available** | *N unit(s) are not available to dispatch* with a sample and their states. Only `MANUFACTURED` and `RETURNED` units qualify (BR-18). |
| **1a. Dealer not active** | *… is not an active dealer, so stock cannot be dispatched to them.* |

**Postconditions** — A `DRAFT` dispatch exists. Its units are `IN_DISPATCH`
and are no longer available to another dispatch. **The dealer cannot see it
yet** — a draft is not visible to them until sent (BR-19).

**Business rules** — BR-18, BR-19, BR-20.

---

### UC-LG-03 — Send a dispatch

| | |
|---|---|
| **Primary actor** | Warehouse Operator |
| **Permission** | `dispatch:update` |
| **Trigger** | The consignment physically leaves. |
| **Preconditions** | The dispatch is `DRAFT` and holds at least one unit. |

**Main success scenario**

1. The operator presses Send.
2. The system sets the dispatch to `DISPATCHED`, stamps the dispatch time, and
   moves every unit from `IN_DISPATCH` to `DISPATCHED`.
3. A notification is raised for the destination dealer.
4. The consignment becomes visible to that dealer at `/dealer/incoming`.
5. A `DISPATCH_SENT` audit entry is written.

**Alternate flows**

| | |
|---|---|
| **1a. Dispatch is empty** | *Add at least one mattress before sending this dispatch.* |
| **1b. Already sent** | The action is not offered, and the route refuses. |

**Postconditions** — The dispatch is in transit and **can no longer be
cancelled** (BR-20). Correcting it after this point is a return, not a
cancellation.

**Business rules** — BR-19, BR-20.

---

### UC-LG-06 — Confirm receipt of a consignment

| | |
|---|---|
| **Primary actor** | Dealer |
| **Permission** | `receipt:create` · dealer-scoped |
| **Trigger** | The consignment arrives at the dealership. |
| **Preconditions** | The dispatch is `DISPATCHED` or `PARTIALLY_RECEIVED` and belongs to this dealer. |

**Main success scenario**

1. The dealer opens the consignment and sees every unit listed.
2. For each unit the dealer records a condition: **OK**, **DAMAGED** or
   **MISSING**.
3. The dealer confirms.
4. The system records a receipt line per unit. Units marked OK move to
   `DEALER_RECEIVED` and become the dealer's sellable stock.
5. Units marked DAMAGED or MISSING **do not** become sellable stock and are
   reported back to POLYFIX on the dispatch record (BR-21).
6. The dispatch becomes `RECEIVED` if every unit is now accounted for, or
   `PARTIALLY_RECEIVED` if some remain.
7. A notification is raised for POLYFIX staff, and a `RECEIPT_CREATED` audit
   entry is written.

**Alternate flows**

| | |
|---|---|
| **3a. Nothing selected** | *Scan or tick at least one mattress to confirm.* |
| **1a. Consignment not awaiting receipt** | *This consignment is not awaiting receipt.* Covers a draft, a cancelled dispatch and one already fully received. |
| **2a. Units arrive in instalments** | The dealer confirms what has arrived. The dispatch becomes `PARTIALLY_RECEIVED` and this use case repeats for the remainder. |

**Postconditions** — Accepted units are `DEALER_RECEIVED` and sellable
(UC-SL-01). Damage and shortages are on record without a telephone call.

**Business rules** — BR-02, BR-21, BR-22.

---

### UC-SL-01 — Record a sale

| | |
|---|---|
| **Primary actor** | Dealer |
| **Permission** | `sale:create` · dealer-scoped |
| **Trigger** | A customer buys a mattress. |
| **Preconditions** | The unit's status is **`DEALER_RECEIVED`** and it belongs to this dealer (BR-23). |
| **Significance** | This is the use case that starts a customer's warranty. |

**Main success scenario**

1. The dealer enters or scans the serial number.
2. The system confirms the unit is theirs and is `DEALER_RECEIVED`.
3. The dealer enters the customer's name, phone, address, the invoice number,
   the sale price and the sale date.
4. The system finds or creates the customer record. Phone, email and address
   are **stored encrypted**, with keyed blind indexes so duplicates can still
   be detected without holding the values in readable form (BR-24).
5. The system records the sale and **creates the warranty**: start date = sale
   date, end date = sale date + the product's warranty years, status `ACTIVE`,
   stamped with the current terms version.
6. The unit moves to `SOLD`.
7. A `SALE_RECORDED` audit entry is written. The customer's QR code now returns
   live cover (UC-PB-03).

**Alternate flows**

| | |
|---|---|
| **2a. Unit already sold** | *This mattress has already been sold.* |
| **2b. Unit in any other state** | *This mattress cannot be sold while it is …* — the state is named. A unit still in transit must be received first (UC-LG-06). |
| **2c. Unit belongs to another dealer** | Not found. The system does not confirm that the serial exists elsewhere (BR-02). |
| **3a. Sale date invalid** | *The sale date is not a valid date.* |
| **4a. Phone matches an existing customer** | The existing record is reused. If the name differs, the sale proceeds and the difference is recorded in the audit trail rather than silently overwriting. |

**Postconditions** — A sale and an `ACTIVE` warranty exist. The unit is `SOLD`
and is no longer sellable stock. The warranty terms are fixed as at the sale
date and are unaffected by later changes to the product (BR-25).

**Business rules** — BR-02, BR-23, BR-24, BR-25.

---

### UC-SL-05 — Scan a unit and take the next action

| | |
|---|---|
| **Primary actor** | Dealer |
| **Permission** | `mattress:read` · dealer-scoped |
| **Trigger** | The dealer points their phone camera at a label, or types a serial. |
| **Significance** | The dealer portal's most-used screen. |

**Main success scenario**

1. The dealer scans or types the serial.
2. The system identifies the unit **within this dealer's scope**.
3. The system shows the unit, its product, size and current state.
4. The system **offers the action appropriate to that state**:
   - `DISPATCHED` → confirm receipt (UC-LG-06)
   - `DEALER_RECEIVED` → record a sale (UC-SL-01)
   - `SOLD` → raise a claim (UC-CL-01), or view the warranty
   - `CLAIM_OPEN` → open the claim in progress
5. The dealer proceeds into the offered use case.

**Alternate flows**

| | |
|---|---|
| **2a. Serial not in this dealer's scope** | Not found, whether or not the unit exists elsewhere (BR-02). |
| **4a. No action available** | The unit is shown read-only, e.g. one already replaced. |

**Postconditions** — None of itself.

**Business rules** — BR-02.

---

### UC-CL-01 — Raise a warranty claim

| | |
|---|---|
| **Primary actor** | Dealer |
| **Permission** | `claim:create` · dealer-scoped |
| **Participant** | Risk Assessor (system) |
| **Trigger** | A customer reports a fault. |

**Preconditions — all four are enforced**

1. The unit's status is `SOLD` — *A warranty claim can only be raised on a
   mattress that has been sold.*
2. There is no claim already open on it — *There is already an open claim on
   this mattress.*
3. A warranty record exists — *No warranty record exists for this mattress.*
4. That warranty is not voided — *The warranty on this mattress has been
   voided.*

**An expired warranty does not block a claim** (BR-36). Out-of-warranty claims
are accepted, flagged, and decided by a human. Refusing to record one would
move the conversation off the system, where nothing is written down.

**Main success scenario**

1. The dealer enters the serial number.
2. The system checks the four preconditions above.
3. The dealer chooses an issue category from: sagging or body impressions,
   fabric tear, foam breaking down, spring failure, stitching coming apart,
   wrong size, damaged in transit, something else.
4. The dealer enters a one-line summary (5–200 characters) and a fuller
   description of what the customer reports (20–4,000 characters).
5. The dealer uploads photographs, and optionally a short video. Each file is
   validated by declared type, extension **and** its actual leading bytes
   (BR-37).
6. The system creates the claim with number `CLM…` and status `SUBMITTED`, and
   moves the unit to `CLAIM_OPEN`.
7. The **Risk Assessor** scores the claim against ten indicators and attaches
   the result (UC-CL-10).
8. A notification is raised for the warranty team, and a `CLAIM_RAISED` audit
   entry is written.

**Alternate flows**

| | |
|---|---|
| **2a. Within the minimum claim window** | *Claims can be raised from N days after the sale. This mattress was sold N day(s) ago.* Only when `warranty.claim_window_days` is above 0; it is 0 by default. |
| **5a. File rejected** | The reason is given: not a permitted type, the content does not match the declared type, or too large. The claim is not lost. |
| **5b. Upload ceiling reached** | At most 8 files per claim (BR-37). |

**Postconditions** — A `SUBMITTED` claim exists with a risk score. The unit is
`CLAIM_OPEN` and cannot be sold or claimed on again until the claim concludes.

**Business rules** — BR-02, BR-26 to BR-37.

---

### UC-CL-05 — Review a claim

| | |
|---|---|
| **Primary actor** | Warranty Manager |
| **Permission** | `claim:read` |
| **Trigger** | A claim is awaiting attention. |

**Main success scenario**

1. The manager opens the claim.
2. The system presents, on one screen: the reported issue and description; the
   evidence; the unit's complete lifecycle history; the customer; the dealer;
   the warranty dates; and the **risk indicators with their evidence**.
3. The manager proceeds to one of UC-CL-06 through UC-CL-13.

**Postconditions** — None. Reading a claim does not change its state. Moving
it to `UNDER_REVIEW` is a recorded transition, not a side effect of opening the
page.

**Business rules** — BR-28 (risk indicators are a prompt to look, never a
verdict).

---

### UC-CL-11 — Decide a claim

| | |
|---|---|
| **Primary actor** | Warranty Manager |
| **Permission** | `claim:decide` |
| **Trigger** | The review is complete. |
| **Preconditions** | The claim is in `SUBMITTED`, `UNDER_REVIEW` or `INFO_REQUESTED`. A closed claim cannot be decided (BR-38). |

**Main success scenario**

1. The manager selects **Approve** or **Reject**.
2. The manager enters the reason. This is **mandatory** and is retained
   permanently on the claim.
3. If approving, the manager selects the resolution: **replace the mattress**,
   **repair**, or a **goodwill settlement**.
4. The system records the decision with the deciding user and the timestamp,
   and moves the claim to `APPROVED` or `REJECTED`.
5. A notification is raised for the dealer, who can see the outcome.
6. A `CLAIM_DECIDED` audit entry is written.

**Alternate flows**

| | |
|---|---|
| **1a. Claim already closed** | Refused. A concluded claim cannot be quietly re-decided (BR-38). |
| **2a. No reason given** | Refused. |
| **3a. Resolution is replacement** | Continues at UC-CL-12. |
| **3b. Resolution is repair or goodwill** | The claim is settled outside the system; close it with UC-CL-13. |

**Postconditions** — The claim is `APPROVED` or `REJECTED` with an attributed,
permanent reason. A replacement is now possible if that was the resolution.

**Business rules** — BR-38, BR-42.

---

### UC-CL-12 — Issue a replacement unit

| | |
|---|---|
| **Primary actor** | Warranty Manager |
| **Permission** | `claim:replace` |
| **Preconditions** | The claim is `APPROVED` with resolution *replace the mattress*. No replacement has been issued for it yet. A suitable unit is available. |

**Main success scenario**

1. The manager selects the replacement unit by serial.
2. The system records the replacement, linking the claim, the original unit and
   the new one.
3. The system creates a sale and a warranty for the replacement unit against
   the same customer and dealer.
4. **The replacement carries what is left of the original term**: its warranty
   ends on the **original end date**, not a fresh full term (BR-25).
5. The original unit moves to `REPLACED` and its warranty becomes
   `SUPERSEDED`. Scanning the original's label now reports that it was
   replaced.
6. The claim moves to `REPLACED`.
7. A notification is raised for the dealer, and a `REPLACEMENT_ISSUED` audit
   entry is written.

**Alternate flows**

| | |
|---|---|
| **4a. Original term had already expired** | The replacement is given a single day of cover, so there is always a dated warranty record rather than a blank or a negative term. |
| **1a. A replacement already exists** | The action is not offered. One replacement per claim. |
| **1b. The chosen unit is not available** | Refused with its state named. |

**Postconditions** — Two units are permanently linked by this claim. The
original is never deleted, re-sold or re-warrantied. The customer's total
covered period is unchanged by the replacement.

**Business rules** — BR-25, BR-42.

---

### UC-WR-02 — Void a warranty

| | |
|---|---|
| **Primary actor** | Warranty Manager |
| **Permission** | `warranty:void` |
| **Preconditions** | The warranty's status is **`ACTIVE`**. |

**Main success scenario**

1. The manager opens the warranty and chooses Void.
2. The manager enters a reason. Mandatory.
3. The system sets the status to `VOID` and records the reason, the user and
   the time.
4. A `WARRANTY_VOIDED` audit entry is written with the previous status.

**Alternate flows**

| | |
|---|---|
| **1a. Not active** | *Only an active warranty can be voided. This one is …* — the state is named. An expired, voided or superseded warranty cannot be voided again. |
| **2a. No reason** | Refused. |

**Postconditions** — Cover is not in force. UC-PB-03 reports this to the
customer without disclosing the reason. A new claim on the unit is now refused
(UC-CL-01 precondition 4).

**Business rules** — BR-42. Note that there is **no "void sale"** use case: a
sale is a historical fact about the unit, and the warranty is the part with
consequences for the customer.

---

### UC-WR-03 — Override warranty dates

| | |
|---|---|
| **Primary actor** | Warranty Manager |
| **Permission** | `warranty:void` |
| **Trigger** | A goodwill extension, or correcting a date entered wrongly at the point of sale. |

**Main success scenario**

1. The manager enters a new start date, a new end date and a reason. All three
   are mandatory.
2. The system validates the dates and records the change.
3. A `WARRANTY_OVERRIDDEN` audit entry is written **with both previous dates**.

**Postconditions** — Cover runs to the new end date, and UC-PB-03 shows it
immediately. The original dates remain recoverable from the audit trail.

**Business rules** — BR-42. This is the sanctioned route for an out-of-warranty
goodwill decision, and it is deliberately attributable.

---

### UC-NW-02 — Appoint a dealer

| | |
|---|---|
| **Primary actor** | Administrator |
| **Permission** | `dealer:write` |
| **Trigger** | A dealership has been agreed, usually following UC-NW-06. |

**Main success scenario**

1. The administrator enters business name, contact person, phone, email,
   address, city, state and pincode.
2. The system assigns a dealer code `DLR…` and creates the dealer with status
   `PENDING`.
3. The administrator sets the status to `ACTIVE` when trading may begin.
4. The administrator creates the dealer's **sign-in** from the dealer's page.
   The system shows a temporary password **once** (BR-01).
5. A `DEALER_CREATED` audit entry is written.

**Alternate flows**

| | |
|---|---|
| **1a. Duplicate phone or business identity** | Refused, with the existing dealer named. |
| **4a. Several people at one dealership** | Repeat step 4. Each gets their own sign-in; all are scoped to the same dealership. |

**Postconditions** — The dealer exists and, once `ACTIVE` with a sign-in, can
reach the dealer portal — confined to their own data (BR-02).

**Business rules** — BR-01, BR-02.

---

### UC-NW-06 — Review a dealership application

| | |
|---|---|
| **Primary actor** | Administrator |
| **Permission** | `dealer_application:review` |
| **Preconditions** | An application exists (UC-PB-06). |

**Main success scenario**

1. The administrator opens the application.
2. The administrator records one of three outcomes with remarks:
   **approve**, **reject**, or **request information**.
3. The system updates the status to `APPROVED`, `REJECTED` or
   `INFO_REQUESTED`, and records the reviewer and the time.
4. An `APPLICATION_REVIEWED` audit entry is written.

**Alternate flows**

| | |
|---|---|
| **3a. Approved** | **Approval does not create a dealer.** It records a decision. Creating the dealership is UC-NW-02, where the commercial terms are entered deliberately. |

**Postconditions** — The application is decided and attributable. No dealer,
sign-in or stock has been created by this use case alone.

**Business rules** — BR-42.

---

### UC-RP-01 — Run a report

| | |
|---|---|
| **Primary actor** | Analyst (also Sales Manager, Administrator) |
| **Permission** | `report:view` |

**Main success scenario**

1. The user chooses one of nine reports: sales; sales by month; inventory;
   production; dispatches; warranty claims; dealer performance; warranty
   expiry; replacements.
2. The user applies filters — date range, and dealer or product where the
   report supports them.
3. The system runs the query and displays the result, with money columns
   formatted and figures right-aligned for comparison.

**Alternate flows**

| | |
|---|---|
| **1a. The user is a Dealer** | Reports are not part of the dealer portal. A dealer sees their own stock, sales and claims on their own screens. |
| **2a. No rows match** | An empty result is shown as such, not as an error. |

**Postconditions** — None. All nine reports are read-only.

**Business rules** — BR-02 applies to every query behind these reports.

---

### UC-RP-02 — Export a report

| | |
|---|---|
| **Primary actor** | Analyst |
| **Permission** | `report:export` — separate from `report:view`, so viewing may be granted without extraction |

**Main success scenario**

1. With a report on screen and filters applied, the user chooses CSV or Excel.
2. The system generates the file **from the filters currently applied**, so the
   export matches what is being looked at.
3. The browser downloads it. The filename carries the report name and the date.

**Postconditions** — A file leaves the system. `report:export` is therefore a
data-egress permission and should be granted deliberately.

---

### UC-AD-02 — Create a user account

| | |
|---|---|
| **Primary actor** | Administrator |
| **Permission** | `user:write` |

**Main success scenario**

1. The administrator enters the person's name and email and selects roles.
2. The system creates the account with status `PENDING_ACTIVATION`,
   `must_change_password` set, and a **randomly generated temporary password**.
3. The system displays that password **once**. It is stored only as an Argon2id
   hash and cannot be shown again or recovered (BR-01).
4. A `USER_CREATED` audit entry is written.
5. The administrator conveys the password in person or by telephone.

**Alternate flows**

| | |
|---|---|
| **1a. A role senior to the administrator's own** | Refused (BR-02a). Nobody can create an account outranking themselves. |
| **1b. Email already in use** | Refused. |
| **3a. The password is lost before handover** | Use UC-AD-05 to issue a fresh one. There is no recovery of the original. |

**Postconditions** — The account exists and cannot reach anything until
UC-AU-02 is complete. **There is no default or shared password anywhere in the
system** (BR-01).

**Business rules** — BR-01, BR-02a, BR-08.

---

### UC-AD-04 — Assign roles

| | |
|---|---|
| **Primary actor** | Administrator |
| **Permission** | `user:role:assign` |

**Main success scenario**

1. The administrator opens the user and selects their roles.
2. The system validates the request against two prohibitions (BR-02a):
   - nobody may change **their own** roles;
   - nobody may grant a role **senior to their own**.
3. The roles are saved, and a `ROLES_CHANGED` audit entry is written **with the
   previous set**.
4. The change applies on that user's next request.

**Alternate flows**

| | |
|---|---|
| **2a. Own account** | Refused — *privilege escalation blocked* is logged. |
| **2b. Role outranks the actor** | Refused and logged. |

**Postconditions** — Permissions are the union of the roles held. No permission
is withheld for any reason other than role (BR-12a).

**Business rules** — BR-02a, BR-12a, BR-42.

---

### UC-AD-06 — Clear another user's two-factor

| | |
|---|---|
| **Primary actor** | Administrator |
| **Permission** | `user:reset_password` |
| **Trigger** | A colleague has lost the phone they enrolled, and their recovery codes. |
| **Significance** | One of the three guaranteed exits from an enrolled account (BR-10). |

**Main success scenario**

1. The administrator opens the colleague's page, where the two-factor state is
   shown.
2. The administrator enters a reason and clears it.
3. The system clears `mfa_enabled`, the encrypted secret, the recovery codes
   and the enrolment date.
4. A `USER_MFA_RESET_BY_ADMIN` audit entry is written with the reason.

**Alternate flows**

| | |
|---|---|
| **1a. Own account** | Refused: *ask another administrator*. A self-service clear would defeat the factor. |
| **1b. The target outranks the administrator** | Refused (BR-02a). |
| **1c. The feature has been switched off centrally** | This use case still works, so a stale enrolment can always be tidied. |

**Postconditions** — The colleague signs in with their password alone, and may
enrol again on a new phone.

**Business rules** — BR-02a, BR-10.

---

### UC-AD-09 — Verify the audit chain

| | |
|---|---|
| **Primary actor** | Administrator |
| **Permission** | `audit:read` |
| **Trigger** | Before an audit or an insurance dispute, or on a schedule. |

**Main success scenario**

1. The administrator opens Audit log → Verify.
2. The system re-walks every audit entry from the beginning, recomputing each
   hash over the entry's fields and the hash of its predecessor.
3. The system reports **Intact**.

**Alternate flows**

| | |
|---|---|
| **3a. A mismatch** | The system names the **first** entry whose hash does not reconcile. Entries before it are sound; that entry or its predecessor has been altered or removed outside the application. |

**Postconditions** — None; the check is read-only.

**Business rules** — BR-42. The application's own database account holds only
`SELECT, INSERT` on `audit_logs`, `record_versions`, `mattress_events` and
`claim_events`, so the application itself cannot rewrite history. The hash
chain detects tampering by anything that bypasses it, including direct database
access.

---

### UC-SY-04 — Change a setting

| | |
|---|---|
| **Primary actor** | Administrator |
| **Permission** | `system:settings:write` |

**Main success scenario**

1. The administrator opens Settings, grouped as company, security, warranty,
   risk, website, SEO and social.
2. The administrator changes a value. Booleans are offered as a choice, not as
   free text.
3. The system validates and stores it, typed, and the change **takes effect on
   the next request** — no restart or deploy.
4. A `SETTING_CHANGED` audit entry is written **with the previous value**.

**Alternate flows**

| | |
|---|---|
| **2a. The setting is a secret** | *That setting is held outside the application.* Keys and passwords live in the server environment and are neither displayed nor settable here (BR-02b). |
| **2b. `security.two_factor_enabled` set to true** | Two-factor becomes **available**. It is not switched on for anybody; enrolment stays voluntary (UC-AU-08, BR-12). |
| **2c. `seo.robots_allow_indexing` on a staging copy** | Permitted, but it will compete with the live site in search results. Keep it off outside production. |

**Postconditions** — The new value is in force, attributable, and reversible
from the audit trail.

**Business rules** — BR-02b, BR-09, BR-12, BR-42.

---

### UC-SY-06 — Recover access when nobody can sign in (CLI)

| | |
|---|---|
| **Primary actor** | Server operator |
| **Permission** | Shell access to the server. No application permission applies. |
| **Trigger** | The only administrator has two-factor on and has lost both the phone and the recovery codes — so every route into the console, including the Settings page that would switch it off, asks for a code. |
| **Significance** | The guarantee that the system cannot lock its owner out. |

**Main success scenario**

1. The operator opens a shell in the application directory.
2. The operator runs one of:
   - `php spark polyfix:unlock --two-factor-off` — switches the feature off for
     the whole installation. Enrolments remain on file, unused.
   - `php spark polyfix:unlock --clear-2fa --email someone@example.com` —
     clears one account's enrolment.
   - `php spark polyfix:unlock` — clears any stale lock state left by an
     earlier version.
3. The command reports what it changed and states that **no password was
   touched**.
4. The affected user signs in with their password (UC-AU-01).

**Alternate flows**

| | |
|---|---|
| **2a. `--clear-2fa` without `--email`** | Refused, so it cannot clear everyone by accident. |
| **2b. Email not found** | *No account with that email address.* |

**Postconditions** — Access is restored. No password was changed, created or
displayed, so this is not a route to impersonating a colleague.

**Business rules** — BR-10. See also `docs/HOSTINGER-DEPLOYMENT.md`.

---

## 5. Supporting use cases

Specified compactly: these follow one path, enforce no rule beyond their
permission and the dealer scope, and write the audit entry named.

### Public website

| ID | Use case | Notes |
|---|---|---|
| UC-PB-01 | Browse the catalogue | Published products only. Drafts and archived products are invisible. |
| UC-PB-02 | View a product page | Marketing content and SEO metadata from the CMS; no stock or price-list data. |
| UC-PB-04 | Find a dealer | Active dealers, by city and state. Hidden when `website.dealer_locator_enabled` is false. |
| UC-PB-05 | Submit an enquiry | Typed: product enquiry, bulk order, dealership, other. Creates a lead at `NEW`. Throttled at 5 per hour per address. Hidden when `website.contact_form_enabled` is false. |
| UC-PB-07 | Read a content page | Why POLYFIX, about, warranty, privacy, terms. Text from the CMS. |
| UC-PB-08 | Retrieve robots.txt / sitemap.xml | Honours `seo.robots_allow_indexing` and `seo.sitemap_enabled`. Both disallow `/admin` and `/dealer`. |

### Account

| ID | Use case | Notes |
|---|---|---|
| UC-AU-03 | Change own password | Policy BR-08. Revokes all **other** sessions; keeps the current one. Audit: `PASSWORD_CHANGED`. |
| UC-AU-04 | Request a password reset | Emails a single-use expiring token. **The response is identical whether or not the address is registered** (BR-03). Throttled at 3 per 15 minutes. |
| UC-AU-05 | Complete a password reset | Token must be unexpired and unused. Revokes **all** sessions, including the current one. Audit: `PASSWORD_RESET`. |
| UC-AU-06 | Sign out | Revokes the current session server-side; the cookie alone is then worthless. |
| UC-AU-07 | Sign out other sessions | Lists every active session with device and address. Revokes all but the current. The first action to take on a suspected compromise. |
| UC-AU-09 | Provide a second factor | Only when two-factor is on and the account enrolled. Accepts a TOTP code (±1 step) or a recovery code, which is then consumed. The pending sign-in expires after 5 minutes. |

### Catalogue, manufacturing, logistics

| ID | Use case | Notes |
|---|---|---|
| UC-PC-01 … 04 | View, create, edit a product; add a variant | A variant carries its own SKU, size label and price. `warranty_years` on the product determines new warranties (BR-25). |
| UC-PC-05 | Publish / unpublish | `DRAFT` → `PUBLISHED` → `ARCHIVED`. Unpublishing removes it from the website and deletes nothing. Audit: `PRODUCT_STATUS_CHANGED`. |
| UC-PC-06 | Delete a product | Soft delete, reason required. Existing units, sales and warranties are unaffected. |
| UC-MF-01, 02 | View and create batches | A batch records the product, plant and dates, and is assigned `BAT…`. |
| UC-MF-05 | Look up by serial | Paste or scan; goes straight to the unit. |
| UC-MF-06 | View a unit | Product, size, custody, dates, warranty, claims and the full event timeline. |
| UC-MF-07 | Delete a unit record | Soft delete, reason required. Refused once the unit has been sold. |
| UC-LG-01 | View dispatches | Filter by dealer and status. |
| UC-LG-04 | Cancel a dispatch | Reason required. **Only before sending** — *Once sent, record a return instead* (BR-20). Units return to warehouse stock. |
| UC-LG-05 | View incoming | Dealer-scoped. `DISPATCHED` and `PARTIALLY_RECEIVED` consignments. |

### Sales, warranty, claims

| ID | Use case | Notes |
|---|---|---|
| UC-SL-02 / 03 | View own sales / all sales | Same data, different scope (BR-02). |
| UC-SL-04 | View own stock | `DEALER_RECEIVED` units only — what can actually be sold. |
| UC-SL-06 | View customers | Encrypted fields are decrypted for display to a permitted user and remain encrypted at rest (BR-24). |
| UC-WR-01 | View warranties | Filter by status and expiry window. |
| UC-WR-04 | View replacements | Each with its claim and the unit it replaced. |
| UC-CL-02 | Attach evidence to own claim | Dealer-scoped, same validation as UC-CL-01 step 5. Ceiling of 8 files per claim. |
| UC-CL-03 | Reply to a request for information | Moves the claim from `INFO_REQUESTED` back to `UNDER_REVIEW`. **Refused on a closed claim** (BR-38). |
| UC-CL-04 | Track own claims | Status, decision and any outstanding request. |
| UC-CL-06 | Request information | Moves to `INFO_REQUESTED` and tells the dealer what is needed. |
| UC-CL-07 | Record an inspection | Date and findings, when someone has physically examined the unit. |
| UC-CL-08 | Add a note | Internal, or visible to the dealer — chosen explicitly. |
| UC-CL-09 | Attach own evidence | Inspection photographs from POLYFIX's side. |
| UC-CL-10 | Recalculate risk | Re-runs the ten indicators, e.g. after new photographs arrive. Audit: `RISK_RECOMPUTED`. |
| UC-CL-13 | Close a claim | Terminal. From `APPROVED`, `REJECTED` or `REPLACED`. Nothing further may be recorded against it (BR-38). |
| UC-CL-14 | View claim evidence | Served through a **signed URL valid for 300 seconds**, after a permission check. Uploads live outside the web root and are never directly addressable (BR-37). |

### Network, marketing, administration, system

| ID | Use case | Notes |
|---|---|---|
| UC-NW-01 | View dealers | With stock, sales and claim-ratio summary. |
| UC-NW-03 | Edit a dealer | Audit records the previous values. |
| UC-NW-04 | Suspend / terminate | `ACTIVE` → `SUSPENDED` → `TERMINATED`. Stops that dealer's sign-ins (UC-AU-01, 2d) and deletes nothing. |
| UC-NW-05 | View applications | Filter by status. |
| UC-MK-01, 02 | View and progress leads | `NEW` → `CONTACTED` → `FOLLOW_UP` → `CONVERTED` / `CLOSED`, with notes. |
| UC-MK-03 … 05 | Edit pages, product content, FAQs | `cms:write`. Live on the next page load. Audit records the previous content. |
| UC-MK-06 | Edit SEO metadata | Title, meta description and social image, per page and product. |
| UC-AD-01, 03 | View and edit users | Senior accounts are not editable by juniors (BR-02a). |
| UC-AD-05 | Force a password reset | Issues a fresh one-time password, shown once, and sets `must_change_password`. |
| UC-AD-07 | View the role matrix | Read-only: 55 permissions against 7 roles. |
| UC-AD-08 | Read the audit trail | Filter by actor, entity and date. Append-only. |
| UC-AD-10 | Read notifications | Claims, receipts, applications. Marking as read is per user. |
| UC-SY-01 | Open the dashboard | Composed from what the user's roles permit. |
| UC-SY-02 | View system health | Database connectivity, table counts, upload storage, PHP build. |
| UC-SY-03 | View settings | Secrets are excluded, not masked (BR-02b). |
| UC-SY-05 | Check the installation | `php spark polyfix:doctor` — PHP build, extensions, permissions, secrets, base URL, database, migrations. Read-only. |
| UC-SY-07 | Back up and restore | `scripts/backup.sh`, `scripts/restore.sh`. **Restore once before relying on a backup.** |

---

## 6. Business rules catalogue

### Credentials and access

| ID | Rule |
|---|---|
| **BR-01** | There is no default, shared or universal password anywhere. Every account begins with a randomly generated one-time password, displayed once, stored only as an Argon2id hash, and unrecoverable. |
| **BR-02** | A dealer sees only their own dealership. Enforced at query level across 16 tables — mattresses, sales, warranties, claims, claim media and events, customers, dispatches and their items, receipts and their items, replacements, notifications, dealers, dealer users, mattress events. An unknown table raises an error rather than returning unscoped rows. |
| **BR-02a** | Nobody may change their own roles, nor create or edit an account holding a role senior to their own. Attempts are refused and logged. |
| **BR-02b** | Secrets are never stored in the database or shown in the interface. They live in the server environment. |
| **BR-03** | Credential failures are indistinguishable: an unknown address, a wrong password and a suspended account all return *Incorrect email or password. Please try again.* |
| **BR-04** | A wrong password never locks an account, at any number of attempts. Failures are counted and logged. Brute force is limited per client address (BR-39), not per account. |
| **BR-05** | A successful sign-in clears the failure count and any residual lock state. |
| **BR-06** | A temporary password must be replaced before any other screen is reachable. |
| **BR-07** | The session identifier is regenerated at sign-in and the pre-authentication session destroyed. Periodic rotation keeps the old record until garbage collection, so a request already in flight is not signed out mid-task. |
| **BR-08** | Passwords: 12 to 128 characters, with at least one lower-case letter, one upper-case letter, one digit and one symbol. They may not **contain** (as a substring, case-insensitively) the user's email address, its local part, their name or the brand short name, where that token is 4 characters or more. Nine predictable fragments — `password`, `qwerty`, `12345678`, `letmein`, `admin123`, `welcome1`, `iloveyou`, `polyfix123`, `mattress123` — are refused as substrings. A password of 12 or more characters must also use at least 6 distinct characters, so `aaaaaaaaaaaaA1!` is refused despite satisfying every other rule. Hashing is Argon2id (memory 19,456 KiB, 2 iterations, 1 thread) and a hash made under weaker parameters is upgraded on next sign-in. |
| **BR-09** | Two-factor authentication is off for the whole installation unless `security.two_factor_enabled` is true. |
| **BR-10** | No two-factor state can permanently exclude anyone. While the feature is off, a set `mfa_enabled` flag is ignored. While on, there are three exits: a recovery code, an administrator (UC-AD-06), or the server operator (UC-SY-06). |
| **BR-11** | A security change revokes the account's other sessions and keeps the current one, so the person who made the change stays signed in and sees the result. |
| **BR-12** | Two-factor enrolment is always voluntary. No role, setting or screen compels it, and nothing is withheld from an account that has not enrolled. |
| **BR-12a** | Authorisation is role and permission only. No permission depends on a second factor having been used, so switching the feature on or off never changes what anyone can do. |

### Identity of a unit

| ID | Rule |
|---|---|
| **BR-13** | `app.baseURL` is the address encoded in every printed QR code. Once labels are in the field it is effectively immutable; a domain change must leave the old domain redirecting. |
| **BR-14** | Each unit has one QR token: 24 bytes from the cryptographic random source, not derived from the serial number, so a token cannot be computed from a serial or guessed from another token. |
| **BR-15** | Serial numbers are issued from a row-locked counter, in contiguous blocks, so concurrent production runs can neither collide nor interleave. |
| **BR-16** | Serial numbers are never reused and never renumbered. The `CLF` prefix is retained from the previous platform so labels printed before the changeover still match their records. |
| **BR-17** | Reference formats: serial `CLF` + 2-digit year + 6 digits; batch `BAT` + year + 4; dispatch `DSP` + year + 6; claim `CLM` + year + 6; dealer `DLR` + 4. |

### Stock movement

| ID | Rule |
|---|---|
| **BR-18** | Only a unit in `MANUFACTURED` or `RETURNED` may be dispatched. Returned stock is therefore re-dispatchable; a unit already on an open dispatch, at a dealer, sold or scrapped is not. This is what confines a unit to one open dispatch at a time. Stock must also be dispatched to an `ACTIVE` dealer. |
| **BR-19** | A `DRAFT` dispatch is invisible to the destination dealer. It becomes visible when sent. |
| **BR-20** | A dispatch may be cancelled only before it is sent. After sending, the correction is a return. |
| **BR-21** | Units reported damaged or missing on receipt do not become sellable stock, and the report is attached to the dispatch for POLYFIX to act on. |
| **BR-22** | Receipt may be partial. The dispatch stays `PARTIALLY_RECEIVED` until every unit is accounted for. |
| **BR-23** | A unit may be sold only from `DEALER_RECEIVED`. A dealer who has not confirmed a consignment cannot sell from it. |

### Customers and warranty

| ID | Rule |
|---|---|
| **BR-24** | Customer phone, email and street address are encrypted at rest (AES-256-GCM) with keyed blind indexes for duplicate detection. **Customer names are stored as ordinary text**, because they must be searchable and printable — treat a database backup as containing customer names. |
| **BR-25** | Warranty terms are fixed at the point of sale from the product's `warranty_years` and are unaffected by later changes to the product. A replacement carries the remainder of the original term, ending on the original end date; if that date has passed it receives one day, so there is always a dated record. |

### Claims and risk

| ID | Rule |
|---|---|
| **BR-26** | Every claim is scored on submission against ten indicators, each with a weight, producing Low, Medium or High. |
| **BR-27** | Each indicator records its evidence, so a human can judge it rather than accept it. |
| **BR-28** | The score is a prompt to look, never a verdict, and never an automatic rejection. Only a person decides a claim (UC-CL-11). |
| **BR-29** | *Early claim* — raised within `risk.early_claim_days` of the sale. |
| **BR-30** | *Warranty expired* — cover had already ended when the claim was raised. Also *near expiry*, within the last 60 days. |
| **BR-31** | *Dealer claim ratio* — above `risk.dealer_claim_ratio_threshold` of that dealer's sales. Also *claim burst*, several claims from one dealer within 30 days. |
| **BR-32** | *Repeat customer* — this phone number appears on more than `risk.repeat_customer_claim_threshold` other claims. Also *repeat address*. |
| **BR-33** | *Duplicate photograph* — an uploaded image is byte-identical to one on another claim. |
| **BR-34** | *Timeline inconsistent* — the lifecycle dates do not reconcile, e.g. sold before received. |
| **BR-35** | *Repeat claim, same unit* — this mattress has been claimed on before. |
| **BR-36** | An expired warranty does not block a claim. It is accepted and flagged, because an out-of-warranty claim is a business decision and refusing to record it moves the conversation off the system. A voided warranty, an unsold unit, a missing warranty record and an already-open claim **do** block it. |
| **BR-37** | Uploads are validated three ways: declared type, file extension, and the actual leading bytes. Permitted: JPEG, PNG, WebP, PDF, MP4, QuickTime. Images and documents up to 8 MB, video up to 25 MB, 8 files per claim. Files are stored outside the web root, are never directly addressable, and are served only through a signed URL valid for 300 seconds after a permission check. |
| **BR-38** | A closed claim is terminal. No note, reply, decision or replacement may be recorded against it. |

### System-wide

| ID | Rule |
|---|---|
| **BR-39** | Rate limits per client address: sign-in 30/minute; warranty verification 20/minute; public forms 5/hour; password reset 3/15 minutes. Set so that ordinary human use never reaches them. |
| **BR-40** | The public verification response carries the serial, the product details, the manufacturing date and the warranty dates. It carries no customer name or contact details, no dealer identity, no purchase price and no claim history. |
| **BR-41** | Three public features can be switched off without taking the site down: the contact form, dealership applications and the dealer locator. |
| **BR-42** | Every change is recorded in the append-only audit trail with the actor, the time and the previous value. Entries are hash-chained, so alteration is detectable (UC-AD-09). Passwords, keys and two-factor secrets are stripped before writing. Every destructive or discretionary action — void, override, cancel, delete, suspend, reject — requires a reason, which is retained permanently. |

---

## 7. State models

### Mattress unit — `mattresses.current_status`

```
  ┌──────────────┐
  │ MANUFACTURED │◀─────────── RETURNED ◀───── (returned from a dealer;
  └──────┬───────┘  re-dispatchable            re-enters the dispatchable pool)
         │
         ▼
   IN_DISPATCH ────▶ DISPATCHED ────▶ DEALER_RECEIVED ────▶ SOLD
         │                                    ▲               │
         └── dispatch cancelled ──▶ back to   │               ▼
             MANUFACTURED                     │          CLAIM_OPEN
                                              │               │
                                    (damaged/missing     ┌─────┴─────┐
                                     units never         ▼           ▼
                                     reach here)      REPLACED     SOLD
                                                                (claim closed
                                                                 without a swap)

  SCRAPPED  ◀── terminal, from any state, for a write-off
```

Only `MANUFACTURED` and `RETURNED` units are eligible for a dispatch (BR-18),
which is what keeps a unit on one open consignment at a time.

Database constraints hold the model together: a unit in `DEALER_RECEIVED`,
`SOLD`, `CLAIM_OPEN` or `REPLACED` must have a dealer; one in `SOLD`,
`CLAIM_OPEN` or `REPLACED` must have a sale timestamp. The status is never set
by hand — it follows from the use case performed. Concurrent edits are caught:
*This mattress changed while you were working on it. Reload and try again.*

### Warranty claim — `warranty_claims.status`

```
  SUBMITTED ─┬─▶ UNDER_REVIEW ──┬─▶ APPROVED ─┬─▶ REPLACED ──▶ CLOSED
             ├─▶ INFO_REQUESTED ┤             └─▶ CLOSED
             └─▶ WITHDRAWN      └─▶ REJECTED ────▶ CLOSED
```

`INFO_REQUESTED` and `UNDER_REVIEW` pass back and forth as information is
sought and supplied. `REJECTED`, `CLOSED`, `WITHDRAWN` and `REPLACED` are
closed states (BR-38).

### Other state models

| Entity | States |
|---|---|
| **Warranty** | `ACTIVE` → `EXPIRED` (by date) / `VOID` (UC-WR-02) / `SUPERSEDED` (replaced) |
| **Dispatch** | `DRAFT` → `DISPATCHED` → `PARTIALLY_RECEIVED` → `RECEIVED`; or `DRAFT` → `CANCELLED` |
| **Receipt line** | `OK` / `DAMAGED` / `MISSING` |
| **Dealer** | `PENDING` → `ACTIVE` → `SUSPENDED` → `TERMINATED` |
| **Dealer application** | `NEW` → `INFO_REQUESTED` / `APPROVED` / `REJECTED` |
| **Lead** | `NEW` → `CONTACTED` → `FOLLOW_UP` → `CONVERTED` / `CLOSED` |
| **User** | `PENDING_ACTIVATION` → `ACTIVE` → `SUSPENDED` / `DISABLED` |
| **Product / variant / page** | `DRAFT` → `PUBLISHED` → `ARCHIVED` |

---

## 8. System-wide constraints

Applying to every use case, not repeated in each.

**Authorisation.** Every console and portal route carries a permission filter,
checked server-side on each request. Entering a URL directly is checked the
same way as following a link. Hiding a link is a courtesy, never a control.

**Dealer isolation.** Applied at query level across 16 tables (BR-02). A dealer
request for another dealership's record returns not-found, which does not
confirm that the record exists.

**Input and output.** All input is validated server-side. Database access is
through the query builder with bound parameters. All output is escaped. Forms
carry CSRF tokens. A Content-Security-Policy permitting no inline script is
sent on every response, along with HSTS in production.

**Sessions.** Server-side and revocable, with idle and absolute expiry. The
cookie carries only a token, is `HttpOnly` and `SameSite=Lax`, and is marked
`Secure` in production.

**Errors in production.** No stack trace, file path, SQL error or credential
ever reaches a visitor. Detail goes to `writable/logs/`.

**Time.** Stored in UTC, displayed in IST. The database connection pins its
time zone, so a server with a different clock setting cannot shift stored
dates.

**Known limitation, stated deliberately.** With no account lockout (BR-04), an
attacker from one address gets as many attempts per minute as BR-39 allows,
rather than five per quarter hour. The password policy (BR-08) and Argon2id
carry that weight instead. This was a considered choice: a lockout let anyone
disable a colleague's account knowing only their email address. `docs/security.md`
records the reasoning.

---

## 9. Traceability matrix

Use case → permission → route. The route column is the authoritative check:
these are the filters the application actually applies.

| Permission | Use cases | Routes |
|---|---|---|
| `dashboard:view` | UC-SY-01, UC-AD-10 | `/admin`, `/dealer`, `*/notifications` |
| `product:read` | UC-PC-01 | `/admin/products`, `/admin/products/{id}` |
| `product:write` | UC-PC-02, 03, 04 | `POST /admin/products`, `…/{id}`, `…/{id}/variants` |
| `product:publish` | UC-PC-05 | `POST /admin/products/{id}/status` |
| `product:delete` | UC-PC-06 | `POST /admin/products/{id}/delete` |
| `batch:read` | UC-MF-01 | `/admin/batches`, `…/{id}` |
| `batch:write` | UC-MF-02 | `POST /admin/batches` |
| `mattress:create` | UC-MF-03 | `POST /admin/batches/{id}/produce` |
| `mattress:read` | UC-MF-04, 05, 06, UC-SL-04, 05 | `/admin/mattresses*`, `/admin/lookup`, `/dealer/scan`, `/dealer/inventory` |
| `mattress:delete` | UC-MF-07 | `POST /admin/mattresses/{id}/delete` |
| `dispatch:read` | UC-LG-01, 05 | `/admin/dispatches*`, `/dealer/incoming*` |
| `dispatch:create` | UC-LG-02 | `POST /admin/dispatches` |
| `dispatch:update` | UC-LG-03 | `POST /admin/dispatches/{id}/send` |
| `dispatch:cancel` | UC-LG-04 | `POST /admin/dispatches/{id}/cancel` |
| `receipt:create` | UC-LG-06 | `POST /dealer/incoming/{id}/receive` |
| `sale:create` | UC-SL-01 | `/dealer/sell`, `POST /dealer/sell` |
| `sale:read` | UC-SL-02, 03 | `/admin/sales`, `/dealer/sales` |
| `customer:read` | UC-SL-06 | `/admin/customers` |
| `warranty:read` | UC-WR-01 | `/admin/warranties*` |
| `warranty:void` | UC-WR-02, 03 | `POST /admin/warranties/{id}/void`, `…/override` |
| `claim:create` | UC-CL-01, 02, 03 | `/dealer/claims/new`, `POST /dealer/claims`, `…/{id}/media`, `…/{id}/reply` |
| `claim:read` | UC-CL-04, 05, UC-WR-04 | `/admin/claims*`, `/admin/replacements`, `/dealer/claims*` |
| `claim:review` | UC-CL-06, 07, 08, 09 | `POST /admin/claims/{id}/note`, `…/request-information`, `…/inspection`, `…/media` |
| `claim:decide` | UC-CL-11, 13 | `POST /admin/claims/{id}/decision`, `…/close` |
| `claim:replace` | UC-CL-12 | `POST /admin/claims/{id}/replacement` |
| `claim:media:view` | UC-CL-14 | `/media/{id}` |
| `risk:view` | UC-CL-10 | `POST /admin/claims/{id}/recompute-risk` |
| `dealer:read` | UC-NW-01 | `/admin/dealers*` |
| `dealer:write` | UC-NW-02, 03 | `POST /admin/dealers`, `…/{id}` |
| `dealer:suspend` | UC-NW-04 | `POST /admin/dealers/{id}/status` |
| `dealer_application:read` | UC-NW-05 | `/admin/applications*` |
| `dealer_application:review` | UC-NW-06 | `POST /admin/applications/{id}/review` |
| `lead:read` | UC-MK-01 | `/admin/leads` |
| `lead:update` | UC-MK-02 | `POST /admin/leads/{id}` |
| `cms:read` | — (viewing) | `/admin/content*` |
| `cms:write` | UC-MK-03, 04, 05 | `POST /admin/content/pages/{slug}`, `…/products/{id}`, `…/faqs` |
| `cms:publish` | UC-MK-03 (publish control) | enforced **in the form**, not on a route: the page-status field is disabled without it |
| `seo:read` / `seo:write` | UC-MK-06 | `/admin/seo`, `POST /admin/seo/{id}` |
| `report:view` | UC-RP-01 | `/admin/reports`, `…/{report}` |
| `report:export` | UC-RP-02 | `/admin/reports/{report}/export/{format}` |
| `user:read` | UC-AD-01, 07 | `/admin/users*`, `/admin/roles` |
| `user:write` | UC-AD-02, 03 | `POST /admin/users`, `…/{id}` |
| `user:role:assign` | UC-AD-04 | `POST /admin/users/{id}/roles` |
| `user:reset_password` | UC-AD-05, 06 | `POST /admin/users/{id}/force-password-reset`, `…/reset-mfa` |
| `audit:read` | UC-AD-08, 09 | `/admin/audit`, `/admin/audit/verify` |
| `system:health` | UC-SY-02 | `/admin/system` |
| `system:settings:read` | UC-SY-03 | `/admin/settings` |
| `system:settings:write` | UC-SY-04 | `POST /admin/settings/{key}` |
| `system:backup` / `system:export` / `system:sql:read` | Super Admin tools | within `/admin/system` |
| — (authentication only) | UC-AU-01 … 10 | `/admin/login`, `/dealer/login`, `/login/verify`, `/forgot-password`, `/reset-password`, `/account/*`, `POST /logout` |
| — (public) | UC-PB-01 … 08 | `/`, `/mattresses*`, `/warranty`, `/warranty/verify`, `/dealers`, `POST /dealers/apply`, `/contact`, `POST /contact`, `/about`, `/why-polyfix`, `/privacy`, `/terms`, `/robots.txt`, `/sitemap.xml` |

### Coverage

51 of the 55 permissions are enforced on a route. `cms:read`, `seo:read` and
`system:settings:read` guard viewing rather than a use case of their own;
`system:backup`, `system:export` and `system:sql:read` guard the Super
Administrator tools inside UC-SY-02; `cms:publish` is enforced inside a form
rather than on a route, as noted above.

### Permissions defined but not currently enforced

Three permissions are defined, are assigned to roles, and are checked nowhere.
They are recorded here rather than quietly omitted, because **granting one of
them today has no effect** and anyone planning role assignments should know
that.

| Permission | Held by | Why it is dormant |
|---|---|---|
| `sale:void` | Super Admin, Admin | There is no void-sale use case. A sale is a historical fact about the unit; the warranty is the part with consequences, and voiding that is UC-WR-02 under `warranty:void`. |
| `customer:write` | Super Admin, Dealer | Customer records are created and updated as part of recording a sale (UC-SL-01), which is gated by `sale:create`. There is no separate customer-editing screen. |
| `receipt:read` | Super Admin, Admin, Warehouse, Dealer | Receipts are read through the dispatch they belong to, gated by `dispatch:read` (UC-LG-01, UC-LG-05). There is no standalone receipts screen. |

Each is a sound candidate for a future use case — a void-sale correction, a
customer-record editor, a receipts register — and the permission and role
assignments are already in place for them. Until such a screen exists, these
three grant nothing.
