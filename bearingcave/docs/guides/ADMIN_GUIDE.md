# Admin guide (BearingCave staff)

Sign in at `/login`. Staff land on `/admin`. The left menu shows only the modules your role allows (see `../04_PERMISSIONS_MATRIX.md`).

## Daily routine

| Role | Start here | What to do |
|---|---|---|
| Verification officer | Verification queue | Open submitted applications. Review each stage tab and either approve, request changes (with a comment the applicant sees) or reject. Assign a stage to a colleague with **Assign**. |
| Procurement manager | Product review, RFQs and matching | Approve or reject listings with a reason. Review new RFQs, run **matching**, tick suppliers and **Distribute**. |
| Inspection manager | Inspection | Quote third-party requests, assign the inspector, start the inspection, upload the report (result plus evidence), complete. |
| Logistics manager | Logistics | Quote, accept, create the shipment (carrier, tracking number), add tracking events, mark delivered. |
| Finance manager | Invoices, Payments | Match bank remittances to invoices and use **Record payment** with the UTR/reference. This activates subscriptions and moves orders to *paid*. |
| Support executive | Disputes, Contact leads | Assign disputes to yourself, message the parties (shared or internal notes), resolve with a note, close. |
| Account manager | Suppliers (assigned) | Follow up with assigned verified suppliers. View their performance. |
| Super admin | Dashboard, Settings, Staff | Everything above, plus users, staff roles, plans, settings, CMS, SEO and audit. |

## Verification workflow

1. The supplier or buyer completes the profile, KYC, directors, bank, certifications, financials, quality and documents, then clicks **Submit for verification**.
2. Stages are created from the setting `verification.supplier_stages` (or `buyer_stages`).
3. Each stage needs a decision. For **risk**, score each factor from 1 to 5 and add evidence notes; the level (LOW, MEDIUM or HIGH) is calculated from the configured weights and thresholds. For **sanctions**, record the lists you checked, the result and a reference. **Never mark "clear" without performing the check.**
4. **Management approval** (permission `verification.approve`) sets the company to *verified*. Suppliers are then invoiced for the Verified plan. The badge appears only once the invoice is paid.
5. To suspend or reactivate a company, go to Companies → company. A reason is mandatory and the action is audited.
6. For the Approved Vendor List, add category, brand or product approvals per supplier. The AVL feeds RFQ matching.
7. To give a buyer restricted inventory access, go to Companies → buyer → **Grant access** (needs `verification.approve`, held by Super Admin by default; **Revoke access** reverses it). The buyer must be verified first.

## Catalog and inventory

- Categories and brands are managed in the admin. Deactivating one hides it from new listings.
- **Imports** let you upload a CSV or XLSX on behalf of a supplier. Choose the supplier, then follow the same map → preview → confirm steps the supplier would.
- **Inventory movements** is a read-only ledger of every stock change.
- **Supplier master data** is the 86-field directory. Download the template, import it, and confirm the field labels once the real spreadsheet headings are known.

## RFQs

RFQ → **Run matching** shows the candidates with their score and the reasons for it. Select suppliers → **Distribute**, or set `rfq.auto_distribute`. You can see every quotation and revision. Buyers see quotations only for their own RFQs; suppliers never see each other's.

## Membership plans and entitlements

Admin → Membership plans → edit. You can change the fee, duration, active flag, approval status and every feature toggle or limit. Changes apply **immediately** to all companies on that plan (TEST 7). Every change is audited.

## Platform settings

Every business rule lives here, grouped by area. Settings marked **Pending client approval** are safe defaults. Change the value and set it to *Approved* once the client decides (see `../06_CLIENT_DECISIONS_REGISTER.md`).

## Users and staff

- **Staff & roles** (super admin): create staff accounts and assign roles.
- **Users → user**: activate or deactivate an account, or use **Issue temporary password**. The temporary password is shown once. Verify the person's identity before using it, and use it only while email-based reset is unavailable.

## Reports

Admin → Reports holds 15 reports, each with a date range. **Export CSV** and **Export XLSX** need `reports.export`. Records flagged as samples appear in counts and are labelled as sample.

## Email outbox and audit

- The **Email outbox** shows every notification email and its real status: *queued*, *sent*, *failed* or *disabled*. If delivery is disabled, nothing was sent.
- **Audit logs** can be filtered by event, user or severity. Security events include cross-company access attempts and permission denials.
