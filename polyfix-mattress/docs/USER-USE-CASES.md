# POLYFIZZ — End User Use Case Document

**A plain-language manual for everybody who uses the software**

| | |
|---|---|
| **Company** | POLYFIZZ — Mattress and Foam Manufacturing |
| **Location** | Manesar, Noranpur Chowk, Haryana, India |
| **Website** | www.polyfizz.in |
| **Document version** | 1.0 |
| **Written for** | Office staff, plant staff, field agents and dealers |
| **Assumes** | No technical knowledge of any kind |

> **How to read this document.** You do not need to read it all. Find your job
> title in **Section 2**, then read the modules listed beside it. Everything
> else is for somebody else.

---

## Contents

1. [Application Overview](#1-application-overview)
2. [User Roles](#2-user-roles)
3. [Module List](#3-module-list)
4. [Module-wise Use Cases](#4-module-wise-use-cases)
5. [Step-by-Step User Instructions](#5-step-by-step-user-instructions)
6. [Practical Examples](#6-practical-examples)
7. [Complete User Workflow](#7-complete-user-workflow)
8. [Frequently Asked Questions](#8-frequently-asked-questions)
9. [Things This Document Could Not Confirm](#9-things-this-document-could-not-confirm)

---

# 1. Application Overview

## 1.1 What the software does

POLYFIZZ makes mattresses. Every mattress gets a **serial number** and a **QR
code sticker** the moment it is made. From then on, the software remembers
where that exact mattress is and what has happened to it — made, sent to a
dealer, received by the dealer, sold to a customer, under warranty claim,
replaced, returned, or scrapped.

Because every single mattress is tracked individually, the business can answer
questions it otherwise could not:

- *"This customer says their mattress is under warranty. Is it?"*
- *"How many double-size Ortho mattresses are actually ready to send today?"*
- *"We sent 80 mattresses to Jaipur last month. Did they all arrive?"*
- *"The dealer says these 12 sold, but our records say they are still in
  stock. Which is right?"*

## 1.2 The five parts

The software is one system, but it shows you a different screen depending on
your job. You do not choose — it takes you to the right place when you sign in.

| Part | Web address | Who uses it | Designed for |
|---|---|---|---|
| **Public website** | `www.polyfizz.in` | Customers, people wanting a dealership | Anyone, no sign-in |
| **Admin Console** | `/admin` | Office staff, managers | Computer screen |
| **Warehouse Panel** | `/warehouse` | Plant and store staff | Mobile phone |
| **Field Agent Panel** | `/agent` | Agents who visit dealers | Mobile phone |
| **Dealer Portal** | `/dealer` | Appointed dealers | Mobile phone |

The three mobile panels are built for someone standing up, holding a phone in
one hand. Big buttons, short words, and the camera does most of the work.

## 1.3 The one idea behind everything

**The QR code sticker replaces typing.**

In the plant, you point the phone at the sticker instead of typing a serial
number. On a dealer's shop floor, the agent points the phone at the sticker.
When a lorry is loaded, you point the phone at each sticker as it goes on.

This matters because a serial number is 15 characters long. Typing it 100
times a day means mistakes. Pointing a camera at it does not.

## 1.4 What a mattress goes through

```
   MADE  ──►  ON A LORRY  ──►  SENT  ──►  RECEIVED  ──►  SOLD
   (plant)    (being picked)             (at dealer)    (customer,
                                                        warranty starts)
                                                            │
                                            problem?  ──────┘
                                                            ▼
                                                      CLAIM OPEN
                                                            │
                                                       ┌────┴────┐
                                                    REPLACED   back to SOLD
```

At every step the software knows which mattress is where. **A mattress can
never be in two places at once** — it cannot be sitting in the warehouse and
loaded on a lorry at the same time, and two lorries cannot both carry it.

---

# 2. User Roles

There are **eight roles**. A person is given one or more. Your role decides
which screens you see and what you may do.

| Role | Where you land after sign-in | What the job is | Read these modules |
|---|---|---|---|
| **Super Admin** | Admin Console | Everything, including system tools. Usually one or two people. | All |
| **Admin** | Admin Console | Runs the business day to day. | M2–M15 |
| **Warranty Manager** | Admin Console | Claims, verification, replacements, and deciding warranty rejections. | M2, M8, M9, M10, M11 |
| **Sales Manager** | Admin Console | Dealer performance, sales figures, website enquiries. Can add dealers and read applications, but not decide them. | M2, M6, M7, M12, M13 |
| **Warehouse** | Warehouse Panel | Making, labelling, counting and dispatching stock. | M16 |
| **Field Agent** | Field Agent Panel | Visiting dealers and checking their stock. | M17 |
| **Dealer** | Dealer Portal | Their own stock, sales and claims. | M18 |
| **Reporting** | Admin Console | Read-only. Can look at everything allowed, change nothing. Useful for an accountant or auditor. | M13 |

## 2.1 Two rules worth knowing

**A dealer sees only their own dealership.** There is no screen, no filter and
no web address that shows one dealer another dealer's stock, sales or
customers. This is enforced by the software, not by hiding links.

**A field agent reports; the office decides.** An agent cannot change a sale,
cancel a warranty, alter a serial number or adjust stock. When what they see
on the shelf disagrees with the records, they raise it and somebody in the
office settles it. This is deliberate — an agent's single observation should
not be able to cancel a genuine customer's ten-year warranty.

---

# 3. Module List

## Part A — Public (no sign-in)

| # | Module | What it is for |
|---|---|---|
| **M1** | Public Website & Warranty Verification | The company website, and the page a customer reaches by scanning their mattress label |

## Part B — Admin Console

| # | Module | What it is for |
|---|---|---|
| **M2** | Dashboard & Notifications | The opening screen, and messages waiting for you |
| **M3** | Product Catalogue | The mattress models and sizes the company sells |
| **M4** | Production & Mattress Register | Batches made, and the record of every individual mattress |
| **M5** | Dispatch Management | Consignments sent to dealers, seen from the office |
| **M6** | Dealer Network | Dealers, and applications from people who want to become one |
| **M7** | Sales & Customers | Every sale recorded by every dealer |
| **M8** | Warranty Claims | Claims raised by dealers, from arrival to decision |
| **M9** | Warranties & Replacements | Warranty records and replacement mattresses issued |
| **M10** | Field Survey Management | Agent rounds, survey results, and stock discrepancies |
| **M11** | Warranty Rejection Reviews | Deciding the warranty problems agents report |
| **M12** | Website Leads | Enquiries sent through the website contact form |
| **M13** | Reports & Exports | Nine reports, downloadable as Excel or CSV |
| **M14** | Website Content & SEO | The text on the public website, and its search settings |
| **M15** | Users, Roles & Administration | Staff accounts, permissions, audit log, system settings |

## Part C — Warehouse Panel

| # | Module | What it is for |
|---|---|---|
| **M16** | Warehouse Operations | Opening batches, printing labels, scanning production, stock, and loading lorries |

## Part D — Field Agent Panel

| # | Module | What it is for |
|---|---|---|
| **M17** | Field Agent Operations | The round, the stock survey, the report, and reporting warranty problems |

## Part E — Dealer Portal

| # | Module | What it is for |
|---|---|---|
| **M18** | Dealer Portal | Receiving stock, checking inventory, recording sales, claims and reviews |

## Part F — Everybody

| # | Module | What it is for |
|---|---|---|
| **M19** | My Account | Your password, and optional extra sign-in security |

---

# 4. Module-wise Use Cases

Each use case below follows the same shape: what it is for, who does it, the
steps, what happens, and a real example.

**Jump to your part:**
[A — Public](#part-a--public) ·
[B — Admin Console](#part-b--admin-console) ·
[C — Warehouse](#part-c--warehouse-panel) ·
[D — Field Agent](#part-d--field-agent-panel) ·
[E — Dealer](#part-e--dealer-portal) ·
[F — Everybody](#part-f--everybody)

---

## PART A — PUBLIC

## M1 — Public Website & Warranty Verification

**Purpose of the module.** The company's public face, plus the one page a
customer needs: proof that their mattress is genuine and still under warranty.

**Who uses it.** Anybody. No sign-in.

**Pages available:** Home · Mattresses (and a page for each model) · Why
POLYFIZZ · About · Warranty · Dealers · Contact · Privacy · Terms

---

### UC-01 — A customer checks their own warranty

| | |
|---|---|
| **Module** | M1 — Public Website |
| **Purpose** | Let a customer confirm their mattress is genuine and see how long it is covered |
| **User Role** | Customer (no account needed) |

**Steps**

1. The customer opens the camera app on their phone.
2. They point it at the **QR code sticker** on the mattress label.
3. The phone shows a link. They tap it.
4. The warranty page opens.

**Expected Result**

The page shows the model, the size, when the mattress was made, whether it has
been sold and registered, the warranty end date, and whether this unit was
issued as a replacement. It shows nothing about any other customer, any dealer's
business, or any price.

If the code is not genuine, the page says so.

**Real-Life Example**

> Mrs Sharma buys a mattress in Jaipur. Two years later it sags. Before
> telephoning the shop she scans the sticker with her phone and sees
> *"Warranty active to 14 March 2034."* She now knows she has a case before she
> makes the call.

---

### UC-02 — Somebody applies to become a dealer

| | |
|---|---|
| **Module** | M1 — Public Website |
| **Purpose** | Collect dealership enquiries without anyone telephoning |
| **User Role** | Member of the public |

**Steps**

1. Open `www.polyfizz.in` and go to the **Dealers** page.
2. Fill in the application form.
3. Press the submit button.

**Expected Result**

The application is saved and appears in the Admin Console under
**Applications**, where a manager approves, rejects or asks for more
information.

**Real-Life Example**

> A furniture shop owner in Kota fills in the form on Sunday evening. On Monday
> morning the Sales Manager sees it waiting under Applications.

---

## PART B — ADMIN CONSOLE

## M2 — Dashboard & Notifications

**Purpose of the module.** The first screen office staff see, and the place
where things needing attention are collected.

**Who uses it.** All Admin Console roles.

---

### UC-03 — See the state of the business at a glance

| | |
|---|---|
| **Module** | M2 — Dashboard |
| **Purpose** | Know what needs attention without opening ten screens |
| **User Role** | Super Admin, Admin, Warranty Manager, Sales Manager, Reporting |

**Steps**

1. Sign in at `/admin`.
2. You land on the Dashboard automatically.

**Expected Result**

The Dashboard shows summary figures, claims waiting for review, what was made
and sold over the last six months, and the top dealers of the last 30 days.
Every figure is counted fresh when the page opens.

**Real-Life Example**

> The Admin opens the console at 9:30am, sees *"4 claims waiting for review"*,
> clicks it, and starts the day on the thing that actually needs doing.

---

### UC-04 — Read notifications

| | |
|---|---|
| **Module** | M2 — Notifications |
| **Purpose** | Catch things that happened while you were away |
| **User Role** | All Admin Console roles |

**Steps**

1. In the left-hand menu, click **Notifications**. A number beside it shows
   how many are unread.
2. Read the item.
3. Mark it as read.

**Expected Result**

The item is marked read and the unread count goes down.

> **Please note.** Notifications appear **inside the software only**. The
> system does not send alert emails. The only email it ever sends is the
> password reset link. See Section 9.

---

## M3 — Product Catalogue

**Purpose of the module.** The list of mattress models the company makes, and
the sizes each comes in. Everything else refers back to this — you cannot make
a batch of a size that does not exist here.

**Who uses it.** Super Admin and Admin can change it. Others can look.

---

### UC-05 — Add a new mattress model

| | |
|---|---|
| **Module** | M3 — Product Catalogue |
| **Purpose** | Make a new model available to manufacture and sell |
| **User Role** | Super Admin, Admin |

**Steps**

1. Menu → **Products**.
2. Click **New**.
3. Enter the model name, description, and the **warranty period in years**.
4. Save.
5. Open the model you just created and add its **sizes** — each size has a
   code, the dimensions, and the printed price.
6. When the model is ready for the public website, set its status to
   **Published**.

**Expected Result**

The model and its sizes are now available everywhere: the warehouse can make
batches of them, dealers see them, and if published they appear on the public
website.

**Why the warranty years matter:** when a dealer later sells one of these
mattresses, the software works out the warranty end date from this number. Set
it correctly before the first unit is made.

**Real-Life Example**

> POLYFIZZ launches "Ortho Firm" with a 10-year warranty in four sizes. The
> Admin adds the model, adds the four sizes, and publishes it. The plant can
> now open a batch of Ortho Firm the same afternoon.

---

## M4 — Production & Mattress Register

**Purpose of the module.** The office's view of what has been made. Batches are
usually opened on the plant floor (M16); this module is where the office looks
at the result and at any individual mattress.

**Who uses it.** Super Admin, Admin, Warehouse, and read-only roles.

---

### UC-06 — Look up one mattress and see its whole history

| | |
|---|---|
| **Module** | M4 — Mattress Register |
| **Purpose** | Answer any question about one specific mattress |
| **User Role** | Super Admin, Admin, Warranty Manager, Sales Manager, Reporting |

**Steps**

1. Type the serial number into the **search box at the top of the console**
   and press Enter. (You can also paste the web address from a scanned QR
   code.)
2. The mattress page opens.

**Expected Result**

One page showing everything about that mattress:

| Section | What it tells you |
|---|---|
| **Details** | Model, size, batch, when it was made, where it is now |
| **Sale** | Invoice number, date, customer, price — if it has been sold |
| **Warranty** | Status and end date |
| **Claims** | Any warranty claims raised on it |
| **Lifecycle** | Every step it went through, in order, with who did it and when |
| **Scans** | When it was counted into production and when it was loaded onto a lorry |
| **Seen on a field visit** | What an agent found when they checked it at a dealer |
| **Discrepancies** | Anything raised about it, and how it was settled |
| **Warranty reviews** | Any question raised about its paperwork, and the decision |

**Real-Life Example**

> A dealer telephones: *"You are saying mattress PFZ-2026-000418 was delivered
> to us but we never got it."* The Admin searches the serial, opens the page,
> and sees it was loaded onto consignment DSP26000031 on 4 March, that the
> dealer confirmed receipt on 7 March, and who at the dealership pressed the
> button. The call ends in ninety seconds.

---

### UC-07 — Print a label for one mattress

| | |
|---|---|
| **Module** | M4 — Mattress Register |
| **Purpose** | Replace a damaged or missing sticker |
| **User Role** | Super Admin, Admin, Warehouse |

**Steps**

1. Open the mattress page (UC-06).
2. Click **Label**.
3. Press **Print**.

**Expected Result**

A single label with the POLYFIZZ name, the QR code, the barcode, the serial
number, the model and size, and the batch.

> **Important.** Reprinting a label does **not** give the mattress a new
> number. It prints the same serial again. Serial numbers are never reissued
> and never changed.

**Real-Life Example**

> A sticker is torn during loading. The store keeper finds the serial from the
> barcode remnant, searches it, prints a fresh label and sticks it on. The
> mattress keeps its identity.

---

## M5 — Dispatch Management (office view)

**Purpose of the module.** Consignments sent to dealers, seen from the office.
Day-to-day loading happens in the Warehouse Panel (M16); this is where the
office checks and, if needed, cancels.

**Who uses it.** Super Admin, Admin, Warehouse, Warranty Manager.

---

### UC-08 — Check what was sent to a dealer

| | |
|---|---|
| **Module** | M5 — Dispatches |
| **Purpose** | Settle a question about a delivery |
| **User Role** | Super Admin, Admin, Warranty Manager, Sales Manager |

**Steps**

1. Menu → **Dispatches**.
2. Find the consignment, or search its code (for example `DSP26000031`).
3. Open it.

**Expected Result**

You see the dealer, the date it left, the transporter and LR number if they
were entered, every mattress on it, and whether the dealer has confirmed
receipt — including anything they reported as damaged or missing.

**Real-Life Example**

> The Sales Manager is asked why a dealer is complaining. She opens the
> consignment and sees the dealer marked two units **Not here** on receipt.
> The problem is a short delivery, not a billing error.

---

### UC-09 — Cancel a consignment that has not left

| | |
|---|---|
| **Module** | M5 — Dispatches |
| **Purpose** | Release mattresses that were picked by mistake |
| **User Role** | Super Admin, Admin |

**Steps**

1. Open the consignment.
2. Click **Cancel**.
3. **Type a reason** — this is required.
4. Confirm.

**Expected Result**

Every mattress on it goes back to free stock and can be sent to somebody else.
The cancellation and your reason are kept permanently.

> **You cannot cancel a consignment that has already been sent.** Once it has
> left, the dealer must report what arrived instead.

**Real-Life Example**

> A consignment is picked for the wrong dealer. Before the lorry loads, the
> Admin cancels it with the reason *"Picked for Kota, should be Jaipur"*. All
> 40 mattresses return to stock immediately.

---

## M6 — Dealer Network

**Purpose of the module.** Who the dealers are, and who wants to become one.

**Who uses it.** Super Admin, Admin, Sales Manager.

---

### UC-10 — Add a new dealer

| | |
|---|---|
| **Module** | M6 — Dealers |
| **Purpose** | Put a new dealership on the system so stock can be sent to it |
| **User Role** | Super Admin, Admin, Sales Manager |

**Steps**

1. Menu → **Dealers** → **New**.
2. Enter the business name, owner's name, phone, full address, city, state and
   PIN code.
3. Save.

**Expected Result**

The dealer is created with a dealer code (for example `DLR0063`) and marked
**Active**. Stock can now be dispatched to them.

**Next step:** the dealer still needs a *login*. That is a separate job — see
UC-33 in M15.

**Real-Life Example**

> A new showroom opens in Udaipur. The Sales Manager adds the dealership, then
> asks the Admin to create a login for the owner.

---

### UC-11 — Review a dealership application

| | |
|---|---|
| **Module** | M6 — Applications |
| **Purpose** | Decide on enquiries from the website |
| **User Role** | Super Admin, Admin |

**Steps**

1. Menu → **Applications**.
2. Open the application.
3. Choose to **approve**, **reject**, or **request more information**.
4. Save.

**Expected Result**

The decision is recorded against the application.

> A **Sales Manager** can open and read applications but cannot decide them.
> The approve, reject and request buttons are available to Super Admin and
> Admin only.

---

### UC-12 — Suspend a dealer

| | |
|---|---|
| **Module** | M6 — Dealers |
| **Purpose** | Stop sending stock to a dealership that is in dispute or closing |
| **User Role** | Super Admin, Admin |

**Steps**

1. Open the dealer.
2. Change the status to **Suspended** or **Terminated**.
3. Save.

**Expected Result**

No new consignment can be started for that dealer — the software refuses it
and says why. Their existing records stay exactly as they are; nothing is
deleted.

**Real-Life Example**

> A dealer stops paying. The Admin suspends them. When the warehouse tries to
> start a consignment that afternoon, the screen says *"[Dealer] is not an
> active dealer, so stock cannot be sent to them."*

---

## M7 — Sales & Customers

**Purpose of the module.** Every sale every dealer has recorded, and the
customers behind them. The office does not record sales here — dealers do that
in their own portal (M18). This is the office's view.

**Who uses it.** Super Admin, Admin, Warranty Manager, Sales Manager,
Reporting.

---

### UC-13 — Find a customer's purchase

| | |
|---|---|
| **Module** | M7 — Sales |
| **Purpose** | Answer a customer enquiry when they do not have their serial number |
| **User Role** | Super Admin, Admin, Warranty Manager, Sales Manager |

**Steps**

1. Menu → **Sales** (or **Customers**).
2. Search or filter the list.
3. Open the record.

**Expected Result**

You see the mattress, the dealer, the invoice number, the date, and the
warranty position.

**Real-Life Example**

> A customer telephones the head office having lost their invoice. The staff
> member searches the customer's mobile number, finds the sale, and reads out
> the serial number and warranty end date.

---

## M8 — Warranty Claims

**Purpose of the module.** A claim starts when a dealer reports a problem with
a mattress a customer has bought. This module takes it from arrival to
decision.

**Who uses it.** Super Admin, Admin, Warranty Manager.

**The stages a claim passes through:** Submitted → Under review → (Information
requested) → Approved or Rejected → (Replaced) → Closed. A dealer may also
withdraw it.

---

### UC-14 — Review and decide a warranty claim

| | |
|---|---|
| **Module** | M8 — Claims |
| **Purpose** | Decide whether the company accepts a warranty claim |
| **User Role** | Super Admin, Admin, Warranty Manager |

**Steps**

1. Menu → **Claims**. Claims waiting are at the top.
2. Open the claim. You see what is wrong, what the customer said, the
   photographs the dealer uploaded, the mattress and its warranty, and the
   **risk indicators** (M9.1 below).
3. If you need more from the dealer, use **Request information** and say what
   you need.
4. If somebody inspects the mattress, record the inspection.
5. You may add notes and upload your own photographs.
6. When you have decided, use **Record decision** — approve or reject, with
   your reasons.
7. If approved and a replacement is due, use **Issue replacement**.
8. When everything is finished, use **Close claim**.

**Expected Result**

Each step is saved with your name and the time. The dealer sees the decision
and the reason in their portal. Nothing can be quietly changed afterwards —
the whole history stays.

**Real-Life Example**

> A dealer in Ajmer reports sagging after 14 months on a 10-year mattress. The
> Warranty Manager opens the claim, sees three clear photographs, checks the
> risk indicators (nothing unusual), approves it, and issues a replacement. The
> dealer sees the approval the same day.

---

### UC-15 — Understand the fraud risk indicators

| | |
|---|---|
| **Module** | M8 — Claims |
| **Purpose** | Spot a claim that deserves a closer look |
| **User Role** | Super Admin, Admin, Warranty Manager |

**What you will see.** Each claim carries a risk level. It is raised when, for
example, a claim arrives very soon after the sale, the dealer's claims-to-sales
ratio is unusually high, or the same customer contact number has claimed
before.

**Important:** a risk flag is **not** an accusation and it does **not** reject
anything. It only says *look more carefully*. You still decide.

You can recalculate the indicators on a claim if circumstances change.

---

## M9 — Warranties & Replacements

**Purpose of the module.** The warranty records themselves, and the
replacement mattresses issued against approved claims.

**Who uses it.** Super Admin, Admin, Warranty Manager.

---

### UC-16 — Cancel a warranty

| | |
|---|---|
| **Module** | M9 — Warranties |
| **Purpose** | Remove cover where it was created wrongly or the sale was void |
| **User Role** | Super Admin, Admin, Warranty Manager |

**Steps**

1. Menu → **Warranties**, open the record.
2. Click **Void**.
3. **Type a reason** — required.
4. Confirm.

**Expected Result**

The warranty is marked void with your reason attached. The customer's
verification page will show it is no longer active.

> This is a serious action affecting a real customer. If the problem is a
> paperwork question raised by a field agent, use the **Warranty Rejection
> Review** process (M11) instead — it keeps the warranty alive until a decision
> is properly made, and it can be undone.

---

### UC-17 — Correct warranty dates

| | |
|---|---|
| **Module** | M9 — Warranties |
| **Purpose** | Fix a start or end date that was recorded incorrectly |
| **User Role** | Super Admin, Admin, Warranty Manager |

**Steps**

1. Open the warranty record.
2. Use **Override**, enter the correct start and end dates and a reason.
3. Save.

**Expected Result**

The dates are corrected and the change is recorded with your name, the old
values and your reason.

---

## M10 — Field Survey Management

**Purpose of the module.** Agents visit dealers every six months and check the
shelf against the records. This module sets up those rounds and handles what
the agents find.

**Who uses it.** Super Admin, Admin (full); Warranty Manager, Sales Manager
and Reporting can view.

---

### UC-18 — Assign a dealer to a field agent

| | |
|---|---|
| **Module** | M10 — Agent Assignments |
| **Purpose** | Put a dealer on an agent's round so visits get scheduled |
| **User Role** | Super Admin, Admin |

**Steps**

1. Menu → **Agent assignments**.
2. On the right, choose the **Agent** from the list.
3. Choose the **Dealer**.
4. Set **Visit every** — how many days between visits. It starts at **182
   days**, which is six months.
5. Add a note if useful.
6. Click **Save assignment**.

**Expected Result**

The dealer appears on that agent's round immediately, marked as a first visit
owed. After each completed survey, the next due date is set automatically.

**Two things to know:**

- Only somebody who holds the **Field Agent** role can be assigned. If the
  person is not in the list, they need the role first (UC-33).
- Assigning the same agent and dealer again **updates** the existing round
  rather than creating a second one.

**Real-Life Example**

> POLYFIZZ appoints Ramesh as the agent for Rajasthan. The Admin assigns him
> the 14 Rajasthan dealers at 182 days each. Ramesh opens his phone and sees
> all 14 waiting for a first visit.

---

### UC-19 — Change how often a dealer is visited

| | |
|---|---|
| **Module** | M10 — Agent Assignments |
| **Purpose** | Watch a dealer more or less closely than the standard round |
| **User Role** | Super Admin, Admin |

**Steps**

1. Menu → **Agent assignments**.
2. Choose the same agent and dealer again.
3. Set a different number of days (anything from 7 to 1095).
4. Save.

**Expected Result**

The round is updated. The agent's next due date is worked out from the new
interval after their next visit.

**To change the standard for *new* assignments:** Menu → **Settings**, and
change the survey interval setting there. This affects new rounds only — it
never rewrites a schedule an agent is already working to.

---

### UC-20 — Take a dealer off an agent's round

| | |
|---|---|
| **Module** | M10 — Agent Assignments |
| **Purpose** | Stop visits when an agent changes territory |
| **User Role** | Super Admin, Admin |

**Steps**

1. Menu → **Agent assignments**.
2. Find the row and click **End**.
3. Confirm.

**Expected Result**

The dealer leaves that agent's round. **Every survey already done is kept** —
the history is not deleted.

---

### UC-21 — Read a completed survey

| | |
|---|---|
| **Module** | M10 — Surveys |
| **Purpose** | See what an agent found at a dealer |
| **User Role** | All Admin Console roles |

**Steps**

1. Menu → **Surveys**. Filter by status or agent if the list is long.
2. Open the survey.

**Expected Result**

You see the summary figures, the agent's remarks, and every mattress examined
with what the records said and what the agent found side by side. Anything that
disagreed is marked and links to the discrepancy.

Click **Report** for the printable version — the same document the agent and
the dealer signed.

**The figures do not change.** They are fixed at the moment the agent finished.
A survey read next year shows the position on the day of the visit, not today's
stock.

---

### UC-22 — Settle a stock discrepancy

| | |
|---|---|
| **Module** | M10 — Discrepancies |
| **Purpose** | Reach a conclusion on something an agent found that did not match |
| **User Role** | Super Admin, Admin, Warranty Manager |

**Steps**

1. Menu → **Discrepancies**. The four boxes at the top show how many are Open,
   Under review, Resolved and Dismissed — click one to filter.
2. Find the row. You see the mattress, the dealer, what the records said, what
   the agent found, and the agent's remark.
3. In the **Settle** column, type what was concluded.
4. Choose the outcome:
   - **Looking into it** — you have picked it up but not finished.
   - **Resolved** — the matter is settled.
   - **Dismissed** — there was nothing in it.
5. Click **Save**.

**Expected Result**

The discrepancy is updated with your name, the time and your note. The agent
who raised it sees the outcome on their phone.

> **Important — settling a discrepancy does not itself change any record.** It
> does not create a sale, cancel a warranty or move stock. If a correction is
> needed, make it on the screen that owns it (record the sale, void the
> warranty, and so on) and say in your note what you did. This is deliberate:
> every real change goes through the screen built for it, so it is checked and
> recorded properly.

**Real-Life Example**

> An agent reports mattress PFZ-2026-000291 as *"Dealer says sold"* while the
> records show it in stock. The Admin telephones the dealer, who admits they
> forgot to record the sale. The Admin asks the dealer to record it in their
> portal, then settles the discrepancy as **Resolved** with the note *"Dealer
> recorded the sale on 18 Oct, invoice INV-2291."*

---

## M11 — Warranty Rejection Reviews

**Purpose of the module.** When a field agent finds that the paperwork behind a
sale is wrong, they raise a **recommendation** that the warranty be reviewed.
They cannot cancel it. This module is where that recommendation is decided.

**Who uses it.** Super Admin, Admin and Warranty Manager can decide. Sales
Manager, Reporting and the agent who raised it can view.

**The five reasons an agent can give:** sale invoice not uploaded · sale not
registered in the system · invoice details do not match · incorrect sale
information · other.

---

### UC-23 — Decide a warranty rejection recommendation

| | |
|---|---|
| **Module** | M11 — Warranty Reviews |
| **Purpose** | Settle whether a warranty stands or is rejected |
| **User Role** | Super Admin, Admin, Warranty Manager |

**Steps**

1. Menu → **Rejection reviews**. The boxes at the top show how many are at each
   stage.
2. Open a case. The left side shows **everything you need to decide**: the
   mattress, the dealer, the reason the agent gave, their remark, whether a
   sale exists, the invoice number if there is one, the warranty position, and
   whether the dealer has uploaded anything.
3. Optionally click **Pick this up** so the agent and dealer can see somebody
   is working on it.
4. Choose one of four actions on the right:

| Action | What it does |
|---|---|
| **Request it** (Ask the dealer) | Sends the dealer a message asking for the invoice. The case waits. Nothing is decided. |
| **Uphold and void the warranty** | The warranty is cancelled. Requires a reason written in words the customer could be shown. |
| **Dismiss — warranty stands** | The recommendation is rejected. The warranty is untouched. Requires a reason. |
| **Add a note** | A comment. Tick the box if the dealer should see it; leave it unticked for an internal note. |

**Expected Result**

The decision is recorded with your name and the time, and the dealer sees it
with its reason in their portal. Every step stays in the case history and
cannot be edited.

**Real-Life Example**

> An agent reports that mattress PFZ-2026-000733 at a Kota dealer has no sale
> recorded, though it is clearly gone from the shelf. The Warranty Manager
> opens the case, sees no sale and no invoice, and uses **Ask the dealer** —
> *"Please upload the customer invoice for this serial."* Ten days later nothing
> has arrived, so she upholds it with the reason *"No sale and no invoice after
> two requests."*

---

### UC-24 — Restore a warranty rejected by mistake

| | |
|---|---|
| **Module** | M11 — Warranty Reviews |
| **Purpose** | Put back cover that was cancelled in error |
| **User Role** | Super Admin, Admin, Warranty Manager |

**Steps**

1. Open the case. It will be marked **Upheld**.
2. On the right you will see **Restore the warranty**.
3. Type why it is being put back.
4. Click **Restore eligibility**.

**Expected Result**

The warranty comes back exactly as it was. If its ten years have run out in the
meantime it comes back as **expired**, not active — restoring cover does not
hand anybody extra years. The case is marked as dismissed and the whole story
stays in the history.

**Real-Life Example**

> A warranty is upheld for rejection because no sale was recorded. A week later
> the dealer produces the invoice: the sale had been recorded against a
> mistyped serial. The Warranty Manager restores the warranty with the reason
> *"Sale was recorded against a different serial. Our error."* The customer
> keeps their cover.

---

## M12 — Website Leads

**Purpose of the module.** Enquiries from the website contact form.

**Who uses it.** Super Admin, Admin, Sales Manager.

---

### UC-25 — Follow up a website enquiry

| | |
|---|---|
| **Module** | M12 — Leads |
| **Purpose** | Make sure enquiries are answered and not lost |
| **User Role** | Super Admin, Admin, Sales Manager |

**Steps**

1. Menu → **Leads**.
2. Open the enquiry and read it.
3. After contacting the person, update its status and add a note.
4. Save.

**Expected Result**

The lead's status and your note are saved so the next person knows where it got
to.

---

## M13 — Reports & Exports

**Purpose of the module.** Nine ready-made reports, each of which can be
downloaded.

**Who uses it.** Super Admin, Admin, Sales Manager, Warehouse (limited),
Reporting.

**The nine reports:**

| Report | What it answers |
|---|---|
| **Sales** | Every sale, filterable |
| **Sales by month** | How sales are trending |
| **Inventory** | What stock exists and where |
| **Production** | What has been made, by batch |
| **Dispatches** | What has been sent out |
| **Warranty claims** | Claims and their outcomes |
| **Dealer performance** | How each dealer is doing |
| **Warranty expiry** | Which warranties are running out |
| **Replacements** | Replacement mattresses issued |

---

### UC-26 — Produce a report and download it

| | |
|---|---|
| **Module** | M13 — Reports |
| **Purpose** | Get figures out of the system for a meeting or an accountant |
| **User Role** | Super Admin, Admin, Sales Manager, Reporting |

**Steps**

1. Menu → **Reports**.
2. Click the report you want.
3. Set the filters offered — usually a date range, and sometimes a dealer or
   product.
4. Read the figures on screen.
5. To download, choose **Excel** or **CSV**.

**Expected Result**

The file downloads to your computer and opens in Excel (or any spreadsheet
program). The download is recorded in the audit log.

> Only roles with export permission see the download buttons. The Reporting
> role can both view and export; it cannot change anything.

**Real-Life Example**

> Before the quarterly dealer meeting, the Sales Manager opens **Dealer
> performance**, sets the date range to the last three months, downloads the
> Excel file, and prints one page per region.

---

## M14 — Website Content & SEO

**Purpose of the module.** Edit the words on the public website without
needing a web designer.

**Who uses it.** Super Admin, Admin.

---

### UC-27 — Change the text on a website page

| | |
|---|---|
| **Module** | M14 — Content |
| **Purpose** | Keep the public website current |
| **User Role** | Super Admin, Admin |

**Steps**

1. Menu → **Content**.
2. Choose the page (for example *Why POLYFIZZ* or *About*), or choose a
   product's public description.
3. Edit the text.
4. Save.

**Expected Result**

The public website shows the new text. You can also edit the frequently asked
questions shown on the site.

---

### UC-28 — Change how a page appears in Google

| | |
|---|---|
| **Module** | M14 — SEO |
| **Purpose** | Improve how pages look in search results |
| **User Role** | Super Admin, Admin |

**Steps**

1. Menu → **SEO**.
2. Choose the page.
3. Edit the title and description that search engines show.
4. Save.

---

## M15 — Users, Roles & Administration

**Purpose of the module.** Staff and dealer accounts, who can do what, the
permanent record of actions, and system settings.

**Who uses it.** Super Admin and Admin. The audit log needs its own permission.

---

### UC-29 — Create a login for a new member of staff

| | |
|---|---|
| **Module** | M15 — Users |
| **Purpose** | Give somebody access to the software |
| **User Role** | Super Admin, Admin |

**Steps**

1. Menu → **Users** → **New**.
2. Enter their full name and email address.
3. Choose their **role** — the list explains what each one is for.
4. Click **Create account**.
5. The screen shows a **temporary password, once**. Copy it now; it is not
   shown again.
6. Give it to the person **in person or by telephone**. Not by email.

**Expected Result**

The account is created. The first time they sign in they must choose their own
password before they can do anything else.

> **Why the password is shown only once.** The software does not keep a
> readable copy. If it is lost, use **Force password reset** (UC-31) to issue a
> new one.

**Real-Life Example**

> A new store keeper joins the Manesar plant. The Admin creates the account
> with the **Warehouse** role, reads the temporary password to him across the
> desk, and he signs in on his phone and sets his own password. He lands
> straight on the Warehouse Panel.

---

### UC-30 — Change what somebody can do

| | |
|---|---|
| **Module** | M15 — Users |
| **Purpose** | Give or take away access as a job changes |
| **User Role** | Super Admin (roles), Admin (details) |

**Steps**

1. Menu → **Users**, open the person.
2. Tick or untick the roles.
3. Save.

**Expected Result**

Their access changes. Where they land at sign-in may change too — if you give
somebody the Field Agent role and nothing else, they will land on the Field
Agent Panel.

**To see exactly what each role can do:** Menu → **Roles & permissions** shows
the full grid of every permission against every role. Worth looking at once
before deciding who gets what.

> Nobody can give themselves a role, and nobody can create or edit an account
> more senior than their own.

---

### UC-31 — Help somebody who has forgotten their password

| | |
|---|---|
| **Module** | M15 — Users |
| **Purpose** | Get a locked-out colleague working again |
| **User Role** | Super Admin, Admin |

**Steps**

1. Menu → **Users**, open the person.
2. Click **Force password reset**.
3. A new temporary password is shown once. Copy it.
4. Give it to them in person or by telephone.

**Expected Result**

Their old password stops working. They sign in with the temporary one and must
immediately choose a new password.

**They can also help themselves:** on the sign-in page there is a **Forgot
password** link. That sends a reset link to their email address. This is the
only email the software ever sends.

---

### UC-32 — Read the audit log

| | |
|---|---|
| **Module** | M15 — Audit |
| **Purpose** | Find out who did what, and when |
| **User Role** | Super Admin, Admin (with audit permission) |

**Steps**

1. Menu → **Audit log**.
2. Filter by date, person or type of action.

**Expected Result**

A list of actions with who did them and when. Entries can never be edited or
deleted by anybody, including a Super Admin. There is also a **Verify** option
that confirms the log has not been tampered with.

**Real-Life Example**

> A warranty was voided and nobody remembers agreeing to it. The Super Admin
> filters the audit log for that week, finds the entry, and sees who did it,
> exactly when, and the reason they typed.

---

### UC-33 — Create a dealer's login

| | |
|---|---|
| **Module** | M15 — Users |
| **Purpose** | Let a dealership use the Dealer Portal |
| **User Role** | Super Admin, Admin |

**Steps**

1. First make sure the dealership exists (UC-10).
2. Menu → **Users** → **New**.
3. Enter the person's name and email, and choose the **Dealer** role.
4. Link the account to the dealership.
5. Create the account and hand over the temporary password.

**Expected Result**

That person can sign in at `/dealer/login` and will see **only** their own
dealership.

---

### UC-34 — Change a company setting

| | |
|---|---|
| **Module** | M15 — Settings |
| **Purpose** | Adjust how the software behaves without technical help |
| **User Role** | **Super Admin only** (an Admin can look but not save) |

**Steps**

1. Menu → **Settings**.
2. Find the setting, grouped by area: company details, warranty policy, risk
   thresholds, survey schedule, website switches and security.
3. Change the value and press **Save**.

**Expected Result**

The change takes effect immediately and is recorded in the audit log.

> **Who can do what here.** An **Admin** can open Settings and read every
> value, but only a **Super Admin** can save a change. This is deliberate —
> settings change how the whole system behaves. The same applies to assigning
> roles (UC-30): an Admin can create and edit users, but only a Super Admin
> can change which roles somebody holds.

---

## PART C — WAREHOUSE PANEL

## M16 — Warehouse Operations

**Purpose of the module.** Everything the plant and store do: opening batches,
printing labels, counting mattresses into production, checking stock, and
loading lorries.

**Who uses it.** The **Warehouse** role. (Super Admin can also open it to see
what the operator sees.)

**Where to go.** Sign in and you land on it automatically, at `/warehouse`.

**The front screen** has six large buttons, each showing a live number:
**Batches · New batch · Stock · Prepare dispatch · Dispatch history · Find a
mattress**.

> **The golden rule of this panel: you never type a serial number.** The camera
> does it. There is a box for typing one, but it is for a torn label, not for
> the job.

---

### UC-35 — Open a new batch and issue its labels

| | |
|---|---|
| **Module** | M16 — Warehouse |
| **Purpose** | Give every mattress in a production run its serial number and QR code |
| **User Role** | Warehouse |

**Steps**

1. Tap **New batch**.
2. Set the **Date made** (it defaults to today; it cannot be in the future).
3. Choose the **Plant**.
4. Optionally type the **Line supervisor**.
5. Scroll to **How many of each**. Under each model, type the quantity beside
   each size. Leave the others blank.
6. Watch the button at the bottom — it counts up as you type:
   *"Open batch (100 mattresses)"*.
7. Tap it.

**Expected Result**

The batch is created with a code like **PFZ-20261017-001** — the prefix, the
date, and the day's run number. Every mattress in it gets a serial number and
a QR code **immediately**. A message confirms the range, for example
*"Batch PFZ-20261017-001 opened with 100 mattresses (PFZ-2026-000401 to
PFZ-2026-000500)."*

> **There is no separate "planned quantity" box.** The total is the sum of the
> sizes. Two numbers that have to agree are two numbers that will disagree.

**Real-Life Example**

> On Friday morning the plant will run 60 Ortho Firm doubles and 40 Ortho Firm
> singles. The supervisor taps New batch, types 60 and 40, and taps the button.
> One form, 100 mattresses numbered.

---

### UC-36 — Print the labels for a batch

| | |
|---|---|
| **Module** | M16 — Warehouse |
| **Purpose** | Get the stickers onto paper so they can go onto mattresses |
| **User Role** | Warehouse |

**Steps**

1. Tap **Batches** and open the batch.
2. Tap **Print labels**.
3. Tap the **Print** button, and print on plain A4.

**Expected Result**

A sheet of labels, **three across**. Each one carries the POLYFIZZ name, the QR
code, the serial number as a barcode, the serial number in readable text, the
model and size, and the batch code.

**Then:** apply the labels to the mattresses as they come off the line. **Do
this before scanning them in** — the scanning step reads these stickers.

---

### UC-37 — Scan mattresses into a batch

| | |
|---|---|
| **Module** | M16 — Warehouse |
| **Purpose** | Confirm each mattress physically exists, and count the run |
| **User Role** | Warehouse |

**Steps**

1. Tap **Batches**, open the batch, tap **Scan mattresses**.
2. Allow the camera when the phone asks. (It asks once.)
3. Hold each mattress's label inside the square on the screen.
4. Move to the next one. **Do not press anything between mattresses.**

**Expected Result**

For each good scan:

- the count at the top goes up — *"37 / 100 Mattresses Scanned"*
- you hear a **short high beep** and feel a **buzz**
- a green line appears confirming the serial, model and size
- **the camera stays open**, ready for the next one

You do not need to look at the phone between mattresses. The sound and the buzz
tell you it worked.

**What happens in the awkward cases:**

| Situation | What the screen says | What happens to the count |
|---|---|---|
| Same label scanned twice | **"Already Scanned — No Duplicate Added"** (two low beeps) | Does not move |
| Label from another batch | Names the batch it belongs to | Does not move |
| Not a POLYFIZZ label | *"Not recognised"* | Does not move |
| Mattress already sent out | *"Not in the plant"* — tell the office | Does not move |
| No signal when you scan | **"Not recorded"** — scan it again | Does not move |

> The count is **always** the real figure from the system, never a number your
> phone is keeping. If a scan does not reach the system you are told, rather
> than shown a number that is wrong.

**Real-Life Example**

> Two store keepers work the same batch from opposite ends of the stack. One of
> them scans a mattress the other already did. His phone says *"Already Scanned
> — No Duplicate Added"* and the count stays at 63. Neither of them has to
> remember who did what.

---

### UC-38 — Finish a batch that came up short

| | |
|---|---|
| **Module** | M16 — Warehouse |
| **Purpose** | Close a run honestly when not every mattress was made |
| **User Role** | Warehouse (with batch permission) |

**Steps**

1. Open the batch.
2. Expand **Finish this batch short**.
3. Type why — for example *"Four units failed inspection and were scrapped on
   the line."*
4. Tap the button and confirm.

**Expected Result**

The reason is attached to the batch and recorded permanently, so a run that
never reached its number has an explanation beside it instead of looking like
unfinished work for ever.

---

### UC-39 — Check what stock is ready to send

| | |
|---|---|
| **Module** | M16 — Warehouse |
| **Purpose** | Answer "how many can we send today?" |
| **User Role** | Warehouse |

**Steps**

1. Tap **Stock**.
2. Filter by plant, or search a model or size.

**Expected Result**

A total at the top, then a line per model and size showing how many are ready.
Units that came back from a dealer are counted and noted separately.

> **Only mattresses that can actually go out are counted here.** Anything
> already loaded on a lorry, delivered or sold is not shown — otherwise the
> floor would promise stock it cannot send.

---

### UC-40 — Prepare a dispatch

| | |
|---|---|
| **Module** | M16 — Warehouse |
| **Purpose** | Build a consignment for a dealer without typing serial numbers |
| **User Role** | Warehouse |

**Steps — starting it**

1. Tap **Prepare dispatch**.
2. Choose the **Dealer**.
3. Choose the **plant** it goes from.
4. Tap **Start picking**. The consignment opens empty, with a code like
   `DSP26000031`.

**Steps — putting mattresses on it.** Three tabs; use any of them, in any
combination:

| Tab | How it works | Best for |
|---|---|---|
| **Scan** | Camera open. Scan each label as it goes on the lorry. | Loading as you go |
| **From batch** | Choose a batch, then tick all of them, tick one size, or tick units individually. | A whole stack at once |
| **On board** | Everything loaded so far, each with a tick box to take it back off. | Fixing a mistake |

**Steps — sending it**

5. Tap **Review and send**.
6. Check the dealer, the totals by model and size, and the full serial list.
7. Optionally enter the **transporter**, **vehicle**, **LR number** and
   **invoice** number.
8. Tap **Confirm dispatch** and confirm.

**Expected Result**

Every mattress moves to the dealer in one go, and the dealer can immediately
see the consignment coming in their portal. It either all happens or none of
it does — a lorry never goes out half-recorded.

**What the software will not let you do:**

| Attempt | What happens |
|---|---|
| Put one mattress on two consignments | Refused, naming the other consignment |
| Scan the same label twice onto one consignment | *"Already Scanned — No Duplicate Added"* |
| Tick a stack where one unit is already sold | **The whole lot is refused**, naming the problem units. Nothing is added. |
| Send an empty consignment | Refused |
| Send to a suspended dealer | Refused when you try to start it |

> The "all or nothing" rule on ticking is deliberate: adding most of a stack
> and quietly leaving two behind is how a lorry goes out short.

**Real-Life Example**

> A lorry is loading for Jaipur. The store keeper starts a consignment, opens
> the **From batch** tab, picks Friday's batch, ticks all 40 doubles, and adds
> them. Then he switches to the **Scan** tab and scans 12 more singles off a
> different stack as they are carried out. He reviews 52 units, types the
> vehicle number, and confirms. The Jaipur dealer sees it on their phone before
> the lorry has left the gate.

---

### UC-41 — Look up one mattress on the floor

| | |
|---|---|
| **Module** | M16 — Warehouse |
| **Purpose** | Find out why a mattress is not where you expected |
| **User Role** | Warehouse |

**Steps**

1. Tap **Find a mattress**.
2. Type the serial, or paste what a scanner read.
3. Tap **Find**.

**Expected Result**

The mattress's model, size, batch, when it was made, where it is now, and
everything that has happened to it, newest first.

---

### UC-42 — Check what has been sent out

| | |
|---|---|
| **Module** | M16 — Warehouse |
| **Purpose** | See past consignments and whether dealers acknowledged them |
| **User Role** | Warehouse |

**Steps**

1. Tap **Dispatch history**.
2. Open any consignment to see what was on it.

**Expected Result**

Consignments newest first, each showing the dealer, the number of mattresses,
the date, the transporter, and whether the dealer has confirmed receipt.

---

## PART D — FIELD AGENT PANEL

## M17 — Field Agent Operations

**Purpose of the module.** An agent visits each dealer on their round every six
months and checks what is physically on the shelf against what the records say.

**Who uses it.** The **Field Agent** role.

**Where to go.** Sign in and you land on it automatically, at `/agent`.

> **The rule that shapes this whole panel: you report, the office decides.**
> Nothing you do changes a sale, a warranty, a serial number or a stock level.
> Where the shelf and the records disagree, you raise it and somebody in the
> office settles it.

---

### UC-43 — See what is due on your round

| | |
|---|---|
| **Module** | M17 — Field Agent |
| **Purpose** | Plan the week |
| **User Role** | Field Agent |

**Steps**

1. Sign in. You land on **My round**.

**Expected Result**

Your agent ID (for example `AGT-A1B2C3`) and four buttons with live numbers:

| Button | What the number means |
|---|---|
| **My dealers** | How many dealers you cover, with how many are due and coming up |
| **Surveys** | How many visits are part-finished and how many you have completed |
| **Discrepancies** | Things you raised that are still open |
| **Warranty reviews** | Warranty problems you reported, waiting on the office |

Below that, your next visits, each marked **Overdue**, **Due now**, **Soon** or
**Scheduled**.

---

### UC-44 — Look at a dealer's stock before visiting

| | |
|---|---|
| **Module** | M17 — Field Agent |
| **Purpose** | Know what you should expect to find |
| **User Role** | Field Agent |

**Steps**

1. Tap **My dealers**, then tap the dealer.

**Expected Result**

Four figures at the top — Total, In stock, Sold, In transit — then every
mattress the records place with that dealer. For each one you see the model and
size, when it was sent and received, whether it is sold and on what invoice,
and the state of its warranty.

**Every number is counted fresh when you open the page.** None of it is a
stored figure that could be out of date.

**To narrow the list,** tap **Filter** and use any of: serial or model, status,
warranty, model, size, or the dispatch date range.

**Real-Life Example**

> Before walking into a Kota showroom, the agent opens the dealer on his phone
> and sees 34 mattresses should be there: 28 in stock, 6 sold. He now knows
> what he is counting.

---

### UC-45 — Carry out a stock survey

| | |
|---|---|
| **Module** | M17 — Field Agent |
| **Purpose** | Check the shelf against the records, mattress by mattress |
| **User Role** | Field Agent |

**Steps**

1. Open the dealer and tap **Start stock survey**. (If you already started one,
   the button says **Carry on with the survey**.)
2. At the top of the camera, set **"What am I recording?"**. Leave it on
   **Physically available** for normal counting.
3. Scan down the shelf, label after label. The selector stays as you set it, so
   **a shelf where everything is present is one tap per mattress**.
4. When you hit an exception, change the selector, scan that one, then change
   it back.
5. For a mattress whose label will not scan, find it in the **Still to check**
   list below, choose what you found from the small dropdown, and tap
   **Record**.
6. When finished, type anything worth saying in **Anything to add?**
7. Tap **Finish the survey** and confirm.

**What you can record, and what each one means:**

| What you found | If records say "in stock" | If records say "sold" |
|---|---|---|
| **Physically available** | ✅ Matches | ⚠️ Review required |
| **Dealer says sold** | ⚠️ **Possible unrecorded sale** | ✅ Matches |
| **Not found** | ❌ **Stock discrepancy** | ✅ Matches |
| **Damaged** | ❌ Damaged — raised either way | ❌ Damaged — raised either way |
| **Other** | ⚠️ Review required | ⚠️ Review required |

**Expected Result**

- The counter shows *"12 / 34 Mattresses Checked"*.
- Each result appears in the **Checked** list with its outcome.
- Anything that does not match raises a **discrepancy** for the office.
- **Nothing else changes.** No sale is created, no mattress moves, no warranty
  is touched.

**Useful things to know:**

- **If you lose signal and come back**, tap the dealer again — it carries on
  with the same survey. It does not start a second one.
- **If you tap the wrong thing**, scan the same mattress again with the right
  answer. It replaces your earlier answer rather than adding a second line, and
  it withdraws the discrepancy it raised — unless the office has already picked
  it up, in which case your correction is noted on the case instead.
- Mattresses you did not get to are counted as **not checked**, which is
  different from *not there*. Only one of those is a discrepancy.
- **You can abandon a survey** you opened by mistake — expand *Abandon this
  survey*, type why, and confirm.

**Real-Life Example**

> At a Jaipur dealer the agent leaves the selector on *Physically available*
> and scans 26 mattresses in about four minutes. Two are missing from the
> shelf: he switches to *Not found* and records them from the list. The dealer
> says one of them sold last week, so he changes that one to *Dealer says
> sold*. He adds the remark *"Stockroom being repainted; two units could not be
> reached"* and finishes. Three items go to the office; he changed nothing.

---

### UC-46 — Produce the stock verification report

| | |
|---|---|
| **Module** | M17 — Field Agent |
| **Purpose** | Give the dealer and the office a signed record of the visit |
| **User Role** | Field Agent |

**Steps**

1. After finishing a survey you are taken to the report. (You can also reach it
   later from **Surveys**.)
2. Tap **Print / PDF**.
3. Your phone or browser offers to print or **save as PDF**. Choose either.

**Expected Result**

A **Dealer Stock Verification Report** containing:

- the POLYFIZZ heading, the dealer's name, code and address
- your name and agent ID
- the date visited and the date submitted
- the summary: on record, recorded in stock, recorded sold, checked,
  physically available, dealer says sold, not found, damaged, and how many need
  attention
- your remarks
- every mattress examined, with what the records said and what you found
- space for **two signatures** — yours and the dealer's

**The figures never change.** They are fixed at the moment you finished. Read
the same report next year and it still shows the position on the day you
visited.

> The office sees the **identical document** — there is only one version of it.

---

### UC-47 — Report a warranty problem

| | |
|---|---|
| **Module** | M17 — Field Agent |
| **Purpose** | Tell the office the paperwork behind a sale looks wrong |
| **User Role** | Field Agent |

**Steps**

1. From a dealer's stock list, tap **Report a warranty problem** beside the
   mattress. (Or tap **Warranty reviews** → **New** and find it by serial.)
2. Check you have the right mattress — the screen shows the model, size and
   dealer.
3. Choose **what is wrong** from five options:
   - Sale invoice not uploaded
   - Sale not registered in the system
   - Invoice details do not match
   - Incorrect sale information
   - Other *(you must explain this one)*
4. Add anything useful in **Anything else?** — what you saw, what the dealer
   said.
5. Tap **Send to the office for review**.

**Expected Result**

A case is created with a reference like `WRV-26-000004`. The message on screen
says plainly: *"The office will review it; the warranty is unchanged until they
decide."*

**That is exactly what happens.** The customer's warranty is untouched. You
cannot cancel it and neither can this screen. Somebody with the authority looks
at the case and decides.

**You can withdraw it.** If the dealer produces the invoice while you are still
standing there, open the case and use **Withdraw this recommendation** with a
reason — as long as nobody has decided it yet.

**Real-Life Example**

> At a Kota dealer the agent finds a mattress clearly gone from the shelf with
> no sale on the system. He reports it as *Sale not registered* with the remark
> *"Dealer says sold in August, no invoice produced on the day."* Two weeks
> later he opens his phone and sees the office asked the dealer for the
> invoice, the dealer uploaded it, and the case was dismissed — the warranty
> stands.

---

### UC-48 — Follow up what you raised

| | |
|---|---|
| **Module** | M17 — Field Agent |
| **Purpose** | See whether what you reported was acted on |
| **User Role** | Field Agent |

**Steps**

1. Tap **Discrepancies** or **Warranty reviews** from your round.

**Expected Result**

Everything you raised, with its current state and the office's conclusion once
there is one. You cannot settle any of them yourself — this is so you can see
your report was picked up.

---

## PART E — DEALER PORTAL

## M18 — Dealer Portal

**Purpose of the module.** Everything a dealership does: confirm deliveries,
check stock, record sales, raise warranty claims, and answer warranty queries.

**Who uses it.** The **Dealer** role.

**Where to go.** `/dealer/login`. You land on the home screen.

**The bottom of the screen** has five tabs: **Home · Stock · Scan · Sell ·
Claims**.

> A dealer sees only their own dealership — their own stock, sales, customers
> and claims. There is no way to see another dealer's anything.

---

### UC-49 — Confirm a delivery

| | |
|---|---|
| **Module** | M18 — Dealer Portal |
| **Purpose** | Tell POLYFIZZ what actually arrived |
| **User Role** | Dealer |

**Steps**

1. Tap **Home**, then **Arriving** (or open the menu → **Incoming stock**).
2. Tap the consignment.
3. You see every mattress on it. **Each one starts marked OK.**
4. Go down the list and change only the ones that are wrong:
   - **OK** — it arrived and is fine (already selected)
   - **Damaged** — it arrived but is damaged
   - **Not here** — it did not arrive
5. For anything marked Damaged or Not here, type a short note in the box
   beside it.
6. Add anything about the delivery as a whole in the box at the bottom.
7. Tap **Confirm consignment**.

**Expected Result**

- Mattresses marked **OK** enter your stock and can be sold.
- Mattresses marked **Damaged** or **Not here** are reported to POLYFIZZ
  straight away. They do **not** enter your sellable stock.
- If you did not account for every mattress, the consignment stays open as
  **partially received** and you can come back to the rest later.

> **To accept everything:** just tap **Confirm consignment**. Everything is
> already marked OK, so you do not have to touch each one.

**Real-Life Example**

> A consignment of 20 arrives in Ajmer. Nineteen are fine. One has a torn
> cover. The owner taps **Damaged** on that one, types *"Cover torn along the
> seam, photo taken"*, and taps Confirm. Nineteen go into his sellable stock;
> the torn one is reported to the head office without a phone call.

---

### UC-50 — Check your stock

| | |
|---|---|
| **Module** | M18 — Dealer Portal |
| **Purpose** | Know what you can sell |
| **User Role** | Dealer |

**Steps**

1. Tap **Stock** at the bottom of the screen.

**Expected Result**

Your inventory — what you hold, by model and size, and each unit's state.

---

### UC-51 — Look up one mattress

| | |
|---|---|
| **Module** | M18 — Dealer Portal |
| **Purpose** | Check a specific mattress before selling it or raising a claim |
| **User Role** | Dealer |

**Steps**

1. Tap **Scan** at the bottom of the screen.
2. **Type the serial number** printed on the label and tap **Check**.

**Expected Result**

The mattress's product, size, comfort level, printed price, when it was made,
when you received it, and — if it is sold — the sale, customer and warranty.

Underneath, the screen offers the action that makes sense for that mattress:
**Record a sale**, **Raise a warranty claim**, or **Confirm the consignment it
came on**.

> **Please read this carefully.** This screen works by **typing** the serial
> number. The Dealer Portal does **not** open your phone's camera. If you scan
> the QR sticker with your phone's own camera app, it opens the **public
> warranty page** for that mattress, not this screen. See Section 9 — the
> on-screen help text here is misleading and we have flagged it.

---

### UC-52 — Record a sale

| | |
|---|---|
| **Module** | M18 — Dealer Portal |
| **Purpose** | Register the sale so the customer's warranty starts |
| **User Role** | Dealer |

**Steps**

1. Tap **Sell** at the bottom of the screen. (Or use **Record a sale** from the
   lookup screen, which fills the serial in for you.)
2. Fill in:

| Field | What to put |
|---|---|
| **Serial number** | From the mattress label |
| **Invoice number** | Your own invoice number |
| **Sold on** | The date of sale |
| **Sale price** | What the customer paid |
| **Payment** | How they paid |
| **Name** | Customer's full name |
| **Mobile number** | Customer's mobile |
| **Email** *(optional)* | |
| **Address, City, PIN code, State** | Customer's address |
| **Remarks** *(optional)* | |

3. Tap **Record the sale**.

**Expected Result**

- The mattress is marked **Sold**.
- **The warranty starts immediately**, calculated from the model's warranty
  period. The customer can scan their sticker that same day and see it is
  active.
- The sale appears under **My sales**, and the head office sees it too.

**Why recording it matters.** If you do not record a sale, the system still
believes the mattress is on your shelf. When the field agent visits and cannot
find it, it is raised as a discrepancy and somebody will ask you about it. And
your customer has no warranty they can prove.

**Real-Life Example**

> A customer buys a double Ortho Firm on Saturday. Before they leave the shop
> the owner records the sale on his phone. The customer scans the sticker in
> the car park and sees *"Warranty active to 18 October 2036."*

---

### UC-53 — Raise a warranty claim

| | |
|---|---|
| **Module** | M18 — Dealer Portal |
| **Purpose** | Report a problem with a mattress your customer bought |
| **User Role** | Dealer |

**Steps**

1. Tap **Claims** at the bottom, then **New**. (Or use **Raise a warranty
   claim** from the lookup screen.)
2. Fill in:
   - **Mattress serial**
   - **What is wrong** — choose from the list (sagging, fabric tear, foam
     degradation, spring failure, stitching, size mismatch, transit damage, or
     other)
   - **In one line** — a short summary
   - **What the customer says** — in their words
3. Attach **photographs**. Clear pictures of the problem get claims decided
   faster.
4. Tap **Raise the claim**.

**Expected Result**

The claim is created with a number like `CLM26000038` and goes to POLYFIZZ for
review. You can follow it under **Claims**, where you will see each step: under
review, information requested, approved or rejected, replaced, closed.

**If POLYFIZZ asks for more:** open the claim, reply, and attach any further
photographs.

**Real-Life Example**

> A customer returns after 14 months with a sagging mattress. The dealer looks
> up the serial, taps *Raise a warranty claim*, picks **Sagging**, writes
> *"Visible dip on the left side, customer sleeps alone on that side"*, attaches
> three photographs, and submits. Four days later the claim shows **Approved**
> and a replacement is on its way.

---

### UC-54 — Answer a warranty review

| | |
|---|---|
| **Module** | M18 — Dealer Portal |
| **Purpose** | Supply the invoice POLYFIZZ has asked for |
| **User Role** | Dealer |

**Steps**

1. Open the account menu (top right) → **Warranty reviews**.
2. Open the case. A coloured banner tells you where it stands:

| Banner | What it means |
|---|---|
| **Being looked at** | POLYFIZZ is checking something. The warranty is unchanged. |
| **We need something from you** | They have asked for a document. |
| **The warranty stands** | The question was settled in your favour. |
| **This warranty has been rejected** | With the reason shown. |

3. If something is needed, use **Upload the invoice**: choose a PDF or a clear
   photograph, add a note if you like, and tap **Send it**.

**Expected Result**

The file reaches POLYFIZZ and the case goes back for another look. You see the
decision and its reason when it comes.

> You see the decisions and the messages addressed to you. You do not see the
> reviewer's internal notes.

**Real-Life Example**

> A dealer gets a review saying the invoice for one mattress was never
> uploaded. He finds the paper invoice, photographs it with his phone, uploads
> it, and three days later the case shows **The warranty stands**.

---

## PART F — EVERYBODY

## M19 — My Account

**Purpose of the module.** Your own password and sign-in security. Everybody
has this, whichever panel they use.

---

### UC-55 — Change your password

| | |
|---|---|
| **Module** | M19 — My Account |
| **Purpose** | Keep your account yours |
| **User Role** | Everybody |

**Steps**

1. Open the account menu (top right) → **Password & security**.
2. Enter your current password, then your new one twice.
3. Save.

**Expected Result**

Your password is changed. Use the eye icon beside the box to check what you
typed before submitting.

---

### UC-56 — Turn on extra sign-in security (optional)

| | |
|---|---|
| **Module** | M19 — My Account |
| **Purpose** | Add a second step at sign-in, if you want one |
| **User Role** | Everybody |

**Steps**

1. Account menu → **Password & security**.
2. Find the two-factor section and follow the steps with an authenticator app
   on your phone.

**Expected Result**

From then on, signing in asks for a six-digit code from your app as well as
your password.

> **This is switched OFF and completely optional.** Normal sign-in is email (or
> username) and password, and nothing else. Nobody is forced to set this up. You
> can turn it off again from the same screen, and an administrator can clear it
> for you if you lose your phone.

---

# 5. Step-by-Step User Instructions

## 5.1 Signing in for the first time

1. Open the web address you were given:
   - Office staff, warehouse and agents: **`/admin/login`**
   - Dealers: **`/dealer/login`**
2. Type your **email address** and the **temporary password** you were given.
3. Tap the **eye icon** if you want to see what you typed.
4. Press **Sign in**.
5. You are asked to **choose your own password**. This is meant to happen.
   Choose something you will remember.
6. You land on your own screen — Console, Warehouse, Agent or Dealer — chosen
   by your role.

**There is no second step.** No code, no authenticator app, no setup page
unless you choose to add one yourself (UC-56).

## 5.2 If you cannot sign in

| What you see | What it means | What to do |
|---|---|---|
| *Incorrect email or password* | A typing mistake, or caps lock | Use the eye icon and try again. **Nothing is locked.** |
| *Please wait a moment* | Many sign-in attempts came from your office connection at once | Wait the number of seconds it names. Your account is fine. |
| *Please sign in to continue* | You were away too long | Sign in again |
| *You do not have permission to do that* | Your role does not include that action | Ask an administrator |
| Forgotten password | — | Use **Forgot password** on the sign-in page. A reset link is emailed to you. |

## 5.3 Letting the camera work (warehouse and agents)

The camera is used in three places: scanning production, picking a dispatch,
and surveying a dealer's stock.

1. The first time, the browser asks **"Allow camera?"** Tap **Allow**.
2. If you tapped Block by mistake, tap the **padlock in the address bar** and
   allow the camera for this site, then press **Scan** again.

**If the camera will not open,** the screen tells you exactly why and what to
do. In every case **the box for typing a serial stays usable**, so you are
never stuck:

| What the screen says | What is wrong | What to do |
|---|---|---|
| **Camera blocked** | Permission was refused | Allow it via the padlock, then press Scan again |
| **No camera found** | The device has no usable camera | Type the serial instead |
| **Camera busy** | Another app is using it | Close that app, press Scan again |
| **Camera unavailable** — needs a secure address | The site is not on `https` | Tell your administrator |

## 5.4 Printing

| What | Where | Note |
|---|---|---|
| A batch of labels | Warehouse → Batches → *batch* → Print labels | Plain A4, three across |
| One label | Console → Mattresses → *unit* → Label | Reprints the same serial |
| A survey report | Agent or Console → Surveys → *survey* → Report | Use **Print / PDF**; your browser can save it as a PDF |
| A report | Console → Reports → *report* | Download as Excel or CSV |

## 5.5 Reference numbers you will see

| Looks like | What it is |
|---|---|
| `PFZ-2026-000001` | A mattress serial number |
| `CLF26000001` | A mattress serial from before the changeover — **still valid everywhere** |
| `PFZ-20261017-001` | A production batch — the date and the day's run |
| `DSP26000001` | A consignment sent to a dealer |
| `CLM26000001` | A warranty claim |
| `SRV-26-000001` | A dealer stock survey |
| `WRV-26-000001` | A warranty review |
| `DLR0001` | A dealer |
| `AGT-A1B2C3` | A field agent |

> Numbers are issued in order, never reused and never changed. Mattresses
> labelled `CLF…` under the old system keep those numbers for ever — they are
> on stickers in customers' homes. Both kinds work everywhere.

---

# 6. Practical Examples

## Example 1 — A hundred mattresses, from the line to a lorry

**Friday 9:00 — the plant supervisor opens the batch.**
Warehouse Panel → New batch → today's date → Manesar → 60 doubles, 40 singles.
The button reads *Open batch (100 mattresses)*. He taps it. Batch
**PFZ-20261017-001** is created and 100 serial numbers are issued.

**Friday 9:05 — labels.**
Batches → the batch → Print labels. 34 sheets of A4 come out of the office
printer. A helper takes them to the line.

**Friday, through the day — scanning.**
As each mattress is finished and labelled, a store keeper scans it. The count
climbs: 12… 47… 86. Two mattresses fail inspection and are scrapped.

**Friday 17:30 — closing short.**
The run ends at 98. The supervisor expands *Finish this batch short* and types
*"Two units failed inspection and were scrapped on the line."*

**Monday 10:00 — a lorry for Jaipur.**
Prepare dispatch → Jaipur Bedding House → Start picking. From batch → ticks all
40 doubles → Add. Then the Scan tab for 12 singles off another stack. Review
and send → vehicle RJ-14-GB-2291 → **Confirm dispatch**.

**Monday 10:01 — the dealer.**
In Jaipur, the owner's phone shows a consignment arriving: 52 mattresses.

## Example 2 — A sale, and the warranty that follows

**Day 1.** The consignment arrives. The owner taps **Confirm consignment** —
everything is already marked OK — and 52 mattresses enter his stock.

**Day 9.** A customer buys a double. The owner taps **Sell**, types the serial
from the label, enters invoice INV-2291, the price, and the customer's name,
mobile and address. **Record the sale.**

**Day 9, two minutes later.** In the car park, the customer scans the sticker
with her phone and sees *"Warranty active to 18 October 2036."*

**Day 430.** The mattress sags. The dealer raises a claim with three
photographs. The Warranty Manager approves it on day 434 and issues a
replacement. The replacement mattress carries its own serial number and is
linked to the original in the records for ever.

## Example 3 — The agent finds something that does not add up

**October, Kota.** The agent's round shows Kota Furniture **Overdue**. He
drives over.

**On the floor.** He opens the dealer, taps **Start stock survey**, leaves the
selector on *Physically available*, and scans down the shelf. Twenty-six
mattresses in about four minutes.

**Two are not there.** He switches the selector to *Not found* and records
them.

**The dealer explains.** One of them sold last week — he forgot to put it in
the system. The agent changes that one to *Dealer says sold*.

**The paperwork.** A third mattress is gone and the dealer cannot produce any
invoice at all. The agent taps **Report a warranty problem**, chooses *Sale not
registered*, and sends it. The screen tells him clearly: *the warranty is
unchanged until the office decides*.

**He finishes.** Remark: *"Stockroom being repainted; two units could not be
reached."* He prints the report, the dealer signs it, he signs it, and they
each keep a copy.

**Back at the office, next morning.** The Admin sees two discrepancies and one
warranty review.

- The *possible unrecorded sale* — she telephones the dealer, who records the
  sale that afternoon. She settles it as **Resolved** with a note.
- The *stock discrepancy* — she marks it **Looking into it** while the dealer
  searches the stockroom.
- The *warranty review* — the Warranty Manager uses **Ask the dealer** for the
  invoice. Two weeks later nothing has come, so she **upholds** it with a clear
  reason. The dealer sees the decision and the reason in their portal.

**Three weeks later.** The dealer finds the invoice — it had been filed under a
mistyped serial. The Warranty Manager uses **Restore eligibility** with the
reason *"Sale recorded against a different serial. Our error."* The customer
keeps their ten years.

---

# 7. Complete User Workflow

## 7.1 How the parts connect

```
  PLANT                    OFFICE                   DEALER
  (Warehouse Panel)        (Admin Console)          (Dealer Portal)
  ─────────────────        ────────────────         ────────────────
  Open batch
  Print labels
  Scan mattresses in
        │
        ▼
  Prepare dispatch
  Confirm dispatch ──────────────────────────────►  Confirm consignment
                                                            │
                                                            ▼
                                                     Record a sale
                                                            │
                           Sales, warranties ◄──────────────┤
                           visible to office                │
                                                            ▼
                                                     Raise a claim
                                 │                          │
                           Decide the claim ◄───────────────┘
                           Issue replacement ──────────────►  Replacement
                                                              arrives


  FIELD AGENT (Agent Panel)              every six months
  ───────────────────────────
  Visit the dealer
  Survey their stock  ──────► discrepancies ──────► OFFICE settles them
  Report warranty problems ─► reviews ────────────► OFFICE decides
                                                          │
                                        dealer sees ◄─────┘
                                        the decision
```

## 7.2 One mattress, end to end

| Step | Who | Where | What happens |
|---|---|---|---|
| 1 | Warehouse | Warehouse Panel | Batch opened; serial and QR code issued |
| 2 | Warehouse | Warehouse Panel | Label printed and applied |
| 3 | Warehouse | Warehouse Panel | Scanned in — now counted as made |
| 4 | Warehouse | Warehouse Panel | Picked onto a consignment |
| 5 | Warehouse | Warehouse Panel | Dispatch confirmed — custody passes |
| 6 | Dealer | Dealer Portal | Consignment confirmed — enters dealer stock |
| 7 | Dealer | Dealer Portal | Sale recorded — **warranty starts** |
| 8 | Customer | Public website | Scans the sticker, sees the warranty |
| 9 | Field Agent | Agent Panel | Checks it on a visit, six months later |
| 10 | Dealer | Dealer Portal | Raises a claim if something goes wrong |
| 11 | Warranty Manager | Admin Console | Decides the claim |
| 12 | Warranty Manager | Admin Console | Issues a replacement if due |

At every one of those steps, the office can open that one serial number and see
the whole story on one page (UC-06).

## 7.3 A typical day, by role

**Warehouse operator**
Sign in → the panel opens → check *Batches* for anything unfinished → scan
today's run in → check *Stock* before promising anything → *Prepare dispatch*
for the day's lorries → confirm each one.

**Field agent**
Sign in → *My round* → anything **Overdue** first → drive out → open the dealer
→ *Start stock survey* → scan the shelf → finish → print the report, both sign
→ check *Discrepancies* for outcomes on last month's visits.

**Dealer**
Sign in → *Home* → confirm anything under **Arriving** → *Sell* each sale as it
happens → *Claims* to check anything outstanding → check **Warranty reviews**
if the office has asked for something.

**Warranty Manager**
Sign in → Dashboard → *Claims waiting for review* → work through them → then
*Rejection reviews* → decide what agents have raised → *Discrepancies* for
anything warranty-related.

**Admin**
Sign in → Dashboard → *Notifications* → *Discrepancies* and *Applications* →
then whatever the day brings: a new dealer, a new product, a user account, a
report for a meeting.

---

# 8. Frequently Asked Questions

### Signing in

**Do I need a code from an app to sign in?**
No. Email (or username) and password, and that is all. An authenticator app is
optional and switched off — you can add one yourself if you want to
(UC-56), but nobody is required to.

**I typed my password wrong several times. Is my account locked?**
No. Accounts are not locked by wrong passwords. If many attempts come from your
office connection at once you may be asked to wait a few seconds; that is all.

**I have forgotten my password.**
Use **Forgot password** on the sign-in page. A reset link is sent to your email
address. Or ask an administrator to issue you a temporary one.

**Why do I land on a different screen from my colleague?**
Because you have different roles. The software takes everybody to the part they
work in. If you open somebody else's part by mistake, it returns you to yours
and says why.

### Serial numbers and labels

**Some of our mattresses have `CLF` numbers and new ones have `PFZ`. Is that a
problem?**
No. Both work everywhere — scanning, searching, surveys and the public warranty
page. The `CLF` numbers are from before the changeover and are printed on
stickers in customers' homes, so they were never changed.

**Can I reprint a label?**
Yes, and it prints the **same** serial number. Reprinting never gives a
mattress a new identity.

**A sticker is destroyed and we cannot read the number at all.**
The mattress can still be identified from the batch records by the office. Use
*Find a mattress* with anything you do have, or ask the office to look at the
batch.

### Scanning

**Which screens actually use the camera?**
Three: scanning production into a batch, picking a dispatch, and an agent's
stock survey. The Dealer Portal does **not** use the camera — see Section 9.

**The camera will not open.**
The screen tells you why and what to do (Section 5.3). In every case you can
type the serial instead, so work never stops.

**I scanned the same mattress twice. Have I counted it twice?**
No. You will see **"Already Scanned — No Duplicate Added"** and the count will
not move. This holds even if two people scan the same label at the same moment
on two phones.

**I scanned something and the screen said "Not recorded".**
The scan did not reach the system — usually a signal problem. Scan it again.
The count only ever shows what the system actually has, which is why you were
told rather than shown a wrong number.

### Stock and dispatch

**Can one mattress end up on two lorries?**
No. The moment it is put on a consignment it is reserved. A second person
trying to add it is told which consignment already has it.

**I ticked a stack and the system refused all of it because of one unit.**
That is deliberate. Adding most of a stack and quietly leaving two behind is
how a lorry goes out short. Deal with the unit it named, then add the rest.

**Can I cancel a consignment?**
Only before it is sent, and you must give a reason. Everything on it goes back
to stock. Once it has been sent, the dealer reports what arrived instead.

**The dealer says they did not get everything.**
They mark the missing units **Not here** when confirming the consignment. That
reaches the office immediately and the consignment stays open.

### Surveys

**How often does an agent visit?**
Every 182 days — six months — unless an administrator sets a different interval
for that dealer (UC-19).

**Can an agent change our records?**
No. An agent records what they found. Where it disagrees with the records, the
office is asked to look. No sale, warranty, serial number or stock level is
changed by anything an agent does.

**The agent recorded something wrongly.**
If they are still at the shop, they can scan it again with the right answer and
it replaces their earlier one. If the office has already picked it up, the
correction is noted on the case instead of erasing it.

**Can an old survey report change later?**
No. The figures are fixed when the agent finishes. The same report read in a
year still shows the position on the day of the visit.

### Warranties

**Can a field agent cancel a customer's warranty?**
No, and this is the most important rule in the system. An agent raises a
*recommendation*. Somebody with authority looks at it and decides. Until then
the warranty is completely unchanged.

**We rejected a warranty and it turns out we were wrong.**
Open the case and use **Restore eligibility** with a reason (UC-24). The
warranty comes back as it was. If its term has run out in the meantime it comes
back expired, not active.

**What does the customer see?**
Their own mattress, their warranty dates, and whether it is active. Nothing
about any dealer's business, any price, or any other customer.

### General

**Does the system email people?**
**Only one email is ever sent: the password reset link.** Everything else —
claims decided, consignments arriving, reviews needing an answer — appears
inside the software under Notifications or on the relevant screen. See
Section 9.

**Can I use this on my phone?**
The Warehouse, Agent and Dealer parts are **built for a phone**. The Admin
Console works on a phone but is designed for a computer screen.

**Is there a record of who did what?**
Yes. Every meaningful action is recorded with the person's name and the time,
in a log that nobody can edit or delete — not even a Super Admin (UC-32).

**Can I delete something by mistake?**
Very little can be deleted at all. Records are marked as removed rather than
erased, and anything that removes or cancels something asks for a written
reason first.

---

# 9. Things This Document Could Not Confirm

Everything described above was checked against the working application. These
points are stated plainly because the brief asked that nothing be assumed or
invented.

## 9.1 The Dealer Portal does not use the camera

**What exists.** The dealer's **Scan** screen is a box where you **type** the
serial number and press **Check**. Confirming a delivery is done by **ticking**
each unit OK / Damaged / Not here, not by scanning.

**What does not exist.** There is no camera scanning anywhere in the Dealer
Portal. Camera scanning is in the Warehouse Panel (two places) and the Field
Agent Panel (one place) only.

**A wording problem on that screen.** The help text on the dealer's Scan screen
reads *"Point your phone's camera at the QR label — it opens this page for that
unit."* That is **not what happens.** Scanning the sticker with a phone camera
opens the **public warranty verification page**, which is a customer-facing
page, not the dealer's lookup screen. The text should be corrected. We have not
changed it, because this request was for documentation only.

## 9.2 Notifications are in-app only

The only email the software sends is the **password reset link**. There are no
email alerts for claims, consignments, surveys or warranty reviews — those
appear inside the software. If email alerts are wanted, that is new work.

## 9.3 Not tested by us with real users

This document describes what the screens do, verified against the working
application. It has **not** been put in front of actual warehouse staff,
agents or dealers. Wording that reads clearly to us may still confuse somebody
doing the job at speed. A short trial with two or three real users before
rollout would be worth the time.

## 9.4 Figures in examples are illustrative

Serial numbers, dealer names, dates and prices used in the examples are made
up to show the shape of the task. They are not real records.

---

*POLYFIZZ — Manesar, Noranpur Chowk, Haryana, India — www.polyfizz.in*
