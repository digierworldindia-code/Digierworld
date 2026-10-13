# User manual: buyers and suppliers

This manual has two parts: [Part A: Buyers](#part-a-buyers) and [Part B: Suppliers](#part-b-suppliers). For staff, see `ADMIN_MANUAL.md`.

# Part A: Buyers


## 1. Register (free)

`/register/buyer` creates your company account. You can search, save items, compare, send enquiries, submit up to 5 open RFQs, order, and request inspection and logistics straight away.

## 2. Find parts

**Marketplace** lets you search by part number, OEM number, cross reference, brand or keywords. Filters cover category, condition, inventory age, verified suppliers, stock country, price, quantity, dimensions (bore, outer diameter, width) and vehicle (make, model, year).

Each result shows **how it matched**:
- exact part number
- OEM number
- alternate number
- *supplier-declared cross reference, interchangeability not verified*

A matching number does **not** mean the parts are interchangeable. Check the specifications or ask the supplier.

## 3. Buy

- **Add to cart** (respecting the MOQ and lot size) → **Checkout**. Orders are created per supplier, and the stock is reserved right away.
- If a price is "on request" or you need different terms, use **Request quotation**, or **Cart → Convert to RFQ**.
- The supplier confirms the order and a proforma invoice is issued. You pay by bank transfer, confirmed by BearingCave finance. Escrow will be offered once BearingCave's licensed partner is live.
- **Optional inspection before confirmation:** at checkout, tick *Request BearingCave inspection*. Choose in-house (10 % of total invoice value) or a third-party agency (quoted), and pick the services. One inspection request is created for each supplier order. You accept its quote separately.
- Stock on product pages refreshes automatically about every 30 seconds.
- Each product page shows the **Warranty & claims** terms for that supplier's tier (unverified: no warranty; BearingCave intervention applies only when you buy inspection).
- Track status, invoices and shipments under **Orders**.

## 4. RFQs

**RFQs → New**: add items (part number, brand, quantity, specifications, optional target price), destination country, delivery terms, deadline and documents.

BearingCave reviews the RFQ and sends it to matching verified suppliers. You can compare quotations side by side, message suppliers, shortlist, then **Accept**, which creates an order, or **Reject**.

## 5. Services

- **Inspection**: request an in-house inspection, priced at 10 % of the invoice value (shown before you confirm), or a third-party inspection, quoted per request. Accept the quote. The report and evidence appear when the inspection is complete.
- **Logistics**: platform-managed, confidential, or multi-supplier consolidation shipping. Consolidation is a privilege for verified buyers and is available to other buyers at additional charges. Accept the quote, then follow tracking events.
- **Disputes**: raise a dispute from an order, with a category, description and documents. The dispute page shows BearingCave's role. If you bought a BearingCave inspection for the order, BearingCave's intervention process applies; otherwise BearingCave provides documentation only (inspection reports, supplier test reports and certificates).

## 6. Become a Verified Buyer (free)

Complete **Verification** (company, KYC, documents) and submit. Once BearingCave approves you, you get:
- the Verified Buyer badge
- your client scores (engagement, transactions, a bonus for using BearingCave logistics and inspection, and an overall score)
- advanced comparison
- consolidation
- unlimited open RFQs
- search and engagement analytics

**Restricted inventory** (verified-buyer-only listings) needs a separate, explicit approval by BearingCave.

## 7. Team and API

- **Team**: invite colleagues with granular permissions.
- **Account → API tokens**: create a personal token for the REST API (see `API.md`).

# Part B: Suppliers


## 1. Register (free)

`/register/supplier` creates your company and owner login on the free **Unverified Supplier** plan. Your profile stays private, and buyers see your listings under an alias such as "Supplier BC-S-XXXXXX".

## 2. List inventory

**Inventory → Add inventory**:
- Part number, OEM number, brand, category, condition, specifications, dimensions, cross references and vehicle fitments.
- MOQ, sale mode, lot size and lot price. Per-piece pricing and quantity tiers need the Verified plan.
- Warehouse location and shipping options.
- Who can see the listing: all buyers, approved verified buyers only, or hidden (RFQ matching only).
- Country rules: allowed or excluded countries. This needs the Verified plan.

Then:
- **Receive stock** as lots with the received date. Inventory age (less than 6 months, 6–12 months, more than 12 months) is calculated from the oldest open lot.
- Upload images (JPG, PNG or WebP, up to 8) and documents (datasheets, certificates; each with a visibility setting).
- **Submit for review**. BearingCave approves the listing before it is published.

Cross references you enter are always shown to buyers as *supplier-declared, interchangeability not verified*.

## 3. Enquiries, orders and services

- **Product enquiries**: reply to buyer questions. On the Verified plan, buyers' questions come to you directly. On the free plan they go to BearingCave first, which answers or passes them on to you; passed-on enquiries appear here.
- **Orders**: confirm or cancel new orders. After payment is confirmed, ship by your chosen method and mark the order dispatched (stock leaves your lots first-in, first-out).
- **Logistics**: platform-managed (quoted by BearingCave) or confidential. Supplier-direct shipping needs the Verified plan.
- **Inspection**: see inspection requests on your orders and their results.
- **Disputes**: respond with your evidence. Product quality and warranty remain your responsibility under the agreed terms.

## 3a. Managing many listings and clearing aged stock

On **Inventory**, tick listings (or the header box for the whole page), choose a **Bulk action** and click **Apply**:
- **Submit / publish**, **Unpublish**, **Archive**. Each listing is checked separately. Any that cannot change, for example one with reserved stock, is reported by part number.
- **Start clearance markdown**: enter a discount (1–90 %). Unit, lot and quantity-tier prices are reduced, and buyers see a *Clearance* badge and can filter for clearance deals. Running it again with a new percentage re-bases on the original price; discounts do not stack.
- **End clearance**: restores the original prices exactly.

Tip: filter by age *More than 12 months*, select all, and start a clearance. Editing a price by hand during a clearance makes the new price the regular price and ends the clearance for that listing.

## 4. Become a Verified Supplier

1. **Verification**: complete every tab (registration, KYC, directors and UBO, bank, compliance certificates, financials, quality, documents) and **Submit**.
2. Respond to change requests. You can see each comment and the stage history.
3. Once approved, **Membership** shows an invoice for the Verified plan (₹50,000 per year plus any applicable tax; pending final client approval). Pay by the bank instructions shown. Finance confirms the payment.
4. The badge and premium features turn on as soon as the payment is recorded:
   - public profile and contact (both optional)
   - priority ranking and premium RFQ leads with bidding
   - per-piece pricing and country visibility
   - **Bulk upload**, analytics and demand insights (buyer searches and 12-month RFQ trends in your categories, for production and demand planning)
   - direct enquiries from product pages
   - optional branding: upload your logo under **Company profile** (shown only when your public profile is published)
   - a dedicated account manager
5. You get renewal reminders 30 and 7 days before expiry. When the membership expires, premium features stop until you renew. Your listings stay, with free-plan limits.

## 5. Bulk upload (Verified plan)

**Bulk upload**:
1. Download the template.
2. Fill it in, one row per SKU.
3. Upload the CSV or XLSX.
4. Check the column mapping.
5. Click **Validate & preview**. Errors are shown per row, and duplicate SKUs are flagged.
6. **Confirm import**.

Only valid rows are imported.

## 6. RFQs and quotations (Verified plan)

**RFQ leads** lists the RFQs BearingCave invited you to, with the reasons you matched. Submit a quotation with prices, lead time, Incoterms and validity. You can revise it until the deadline. Other suppliers never see your quotation. If the buyer accepts, an order is created automatically.

## 7. Team

**Team** lets you invite employees and tick their permissions: inventory, RFQs, orders, services, disputes, billing, verification, profile and team.
