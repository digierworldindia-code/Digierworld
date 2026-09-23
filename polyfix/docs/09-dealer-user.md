# 09 — Dealer user guide

For dealership staff. The portal is built for a phone in a showroom, not a desk.

**app.polyfixmattress.com**

---

## Signing in

Use the email and password sent when your dealership was approved. You will be
asked to change the password on first sign-in.

Passwords need at least 12 characters with an upper case letter, a lower case
letter, a digit and a symbol.

Five wrong attempts locks the account for fifteen minutes. It clears by itself —
wait, or use **Forgot password**.

**One login per person.** Sharing an account means the record of who sold what
and who raised which claim stops being true, and that record is what protects
you in a dispute.

---

## You only ever see your own dealership

This is enforced by the database, not by the screen you are looking at. Another
dealership's stock, sales and customers are not merely hidden from you — they
are not returned to your session at all.

If you scan a serial that belongs to another dealership, you are told it was not
found. That is the same answer you get for a serial that does not exist, and it
is deliberate.

---

## Home

Your dashboard: stock on hand, incoming consignments awaiting confirmation,
recent sales, and open claims.

---

## Incoming — confirm a consignment

When POLYFIX dispatches stock to you it appears under **Incoming**. Nothing is
yours until you confirm it.

1. Open the dispatch
2. Check each mattress against what physically arrived
3. Mark each one **OK**, **Damaged** or **Missing**
4. Confirm

**Do this on the day it arrives, before you sign the transporter's paperwork.**
A unit confirmed as OK and later found damaged is a much harder conversation
than one flagged at receipt. Damaged and missing units are raised with POLYFIX
automatically.

Only after confirmation does stock become sellable.

---

## Stock

Everything you currently hold, searchable by serial, product or size, with each
unit's status. If it is not here, you cannot sell it — check **Incoming** first.

---

## Scan

Point the camera at the QR label, or type the serial.

You get back: the product and size, current status, when it was made, when you
received it, whether it has been sold, its warranty dates, and any claims
against it.

Use it to:

- check a unit is really in your stock before promising it
- show a customer the warranty status of a mattress they already own
- look up history before raising a claim

Scanning changes nothing. It is safe to do in front of a customer.

---

## Sell — record a sale

Do this **at the point of sale**, not at the end of the week. The warranty
starts when the sale is recorded, and a customer who calls before you have
entered it has no warranty on your system.

1. **Sell**, then scan or type the serial
2. Check the product and size shown match the physical mattress
3. Enter the customer's name, phone, and address
4. Enter your invoice number and the sale date
5. Confirm

The warranty activates immediately and the customer can verify it on the public
website with the serial number.

**The customer's details matter.** Phone and address are how a warranty claim is
verified later. They are stored encrypted and are visible to you and to POLYFIX
staff — not to other dealers.

A sale can only be voided by POLYFIX, with a reason. Get it right the first
time; if it is wrong, ask straight away rather than selling the unit again.

---

## Claims — raise a warranty claim

**Claims → New claim**, scan the serial, and:

1. Pick the issue: sagging, fabric tear, foam degradation, spring failure,
   stitching, size mismatch, transit damage, or other
2. Describe what the customer reports, in their words
3. **Attach photographs** — this is the part that decides how fast it moves

Good photographs: the whole mattress in daylight, the problem close up,
something for scale beside a sag, and the serial label itself. Up to eight
attachments, 8 MB each.

Then the claim moves:

```
SUBMITTED → UNDER_REVIEW → APPROVED  → REPLACED → CLOSED
                         → REJECTED             → CLOSED
          ↕ INFO_REQUESTED
```

**INFO_REQUESTED** means POLYFIX needs something more — usually a clearer
photograph. The claim waits until you respond, so check your notifications.

An approved claim may be settled with a replacement. The replacement is
dispatched to you like any other consignment and appears under **Incoming**.

### What speeds a claim up

- Raise it while the customer is still with you, so you can photograph properly
- Photograph the serial label alongside the problem
- Give the date the customer first noticed it, not the date they complained
- Answer an information request the same day

### What slows it down

- Blurred or dark photographs
- A serial that does not match the mattress in the picture
- A description that says only "faulty"

Claims are checked against the unit's history — when it was made, dispatched,
received and sold. A timeline that does not add up gets a closer look. That is
not an accusation; it is the same check applied to every claim.

---

## Notifications

Claim decisions, information requests and incoming dispatches. Check them daily.

---

## Security

**Change your password** under **Security**, and set up two-factor
authentication if your dealership handles high volumes.

**See your sessions** — every device signed in to your account. Sign out of all
other sessions if you lose a phone. Do it immediately; a showroom phone is the
easiest thing in this system to lose.

Never enter your password on a page you reached from a link in a message. Type
**app.polyfixmattress.com** yourself.

---

## Common problems

**"Not found" when scanning a serial you are holding** — either the dispatch has
not been confirmed under **Incoming**, or the unit is assigned to a different
dealership. Confirm the dispatch first; if it still fails, contact POLYFIX with
a photograph of the label.

**Cannot record a sale** — the mattress must be in your confirmed stock and not
already sold. Check its status under **Scan**.

**The camera will not open** — the browser needs camera permission, and on most
phones it only offers it over HTTPS. Type the serial instead; it works
identically.

**Locked out** — wait fifteen minutes or use **Forgot password**.

**Wrong customer details on a sale** — contact POLYFIX. Do not record the sale a
second time.
