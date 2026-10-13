# Feature matrix

Who can use what, as implemented. ✔ = available · ◐ = limited (see note) · — = not available · ⏸ = BLOCKED (external provider or client decision). Plan columns follow the seeded entitlements; admins can change them without code (Admin → Membership plans).

| Feature | Guest | Buyer | Verified buyer | Supplier (free) | Verified supplier | Staff | Where | Status |
|---|:-:|:-:|:-:|:-:|:-:|:-:|---|---|
| Search by part / OEM / cross ref / brand / category / fitment / dimensions | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | `/marketplace` | COMPLETE |
| See prices | ◐ setting | ✔ | ✔ | ✔ | ✔ | ✔ | product cards/page | COMPLETE |
| Verified-buyer-only (restricted) listings | — | — | ◐ after explicit grant | — | — | ✔ | product page | COMPLETE |
| Real-time stock on product page | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | product page | COMPLETE |
| Clearance deals filter / badges | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | marketplace | COMPLETE |
| Compare (price, stock, terms) | ✔ | ✔ | ✔ advanced columns | ✔ | ✔ | ✔ | `/compare` | COMPLETE |
| Enquiry from product page | — | ✔ | ✔ | — | — | — | product page | COMPLETE |
| … delivered directly to the supplier | | ◐ only for suppliers with `direct_enquiries` (Verified) | same | | receives ✔ | | | COMPLETE |
| … routed via BearingCave (free-plan suppliers) | | ✔ | ✔ | receives when forwarded | | answer/forward | Admin → Product enquiries | COMPLETE |
| RFQ posting | — | ✔ max 5 open | ✔ unlimited | — | — | review/distribute | Buyer → RFQs | COMPLETE |
| Premium RFQ leads and bidding | — | — | — | — | ✔ | — | Supplier → RFQ leads | COMPLETE |
| Cart, checkout, orders | — | ✔ | ✔ | sells | sells | manage | Buyer → Cart | COMPLETE |
| Inspection request at checkout (before confirmation) | — | ✔ | ✔ | — | — | quote/assign/report | checkout | COMPLETE |
| In-house inspection 10 % / third-party quoted | — | ✔ | ✔ | ✔ | ✔ | ✔ | Inspection | COMPLETE |
| BearingCave-managed / confidential logistics | — | ✔ | ✔ | ✔ | ✔ | quote/track | Logistics | COMPLETE (10 % base ⏸ D-06) |
| Supplier-direct logistics | — | — | — | — | ✔ | — | Logistics | COMPLETE |
| Multi-supplier consolidation | — | ✔ additional charges | ✔ privilege | — | — | ✔ | Logistics | COMPLETE |
| Escrow payments | — | ⏸ | ⏸ | ⏸ | ⏸ | ⏸ | — | BLOCKED (D-13) |
| Bank-transfer payments (finance-confirmed) | — | ✔ | ✔ | ✔ membership | ✔ membership | record | Billing | COMPLETE |
| Disputes, with BearingCave intervention when inspection was bought | — | ✔ | ✔ | ✔ | ✔ | assign/resolve | Disputes | COMPLETE |
| Verified badge | — | — | ✔ | — | ✔ | — | everywhere | COMPLETE |
| Client scores (engagement, transactions, services bonus, overall) | — | computed | ✔ shown | — | — | ✔ | Buyer dashboard, Admin → Performance | COMPLETE (weights ⏸ D-31) |
| Client search analytics | — | — | ✔ | — | — | — | Buyer dashboard | COMPLETE |
| List inventory (lot price + stock) | — | — | — | ✔ | ✔ | ✔ on behalf | Supplier → Inventory | COMPLETE |
| Per-piece price, quantity tiers | — | — | — | — | ✔ | — | product form | COMPLETE |
| Country visibility (allow/deny) | — | — | — | — global | ✔ | — | product form, Visibility | COMPLETE |
| Bulk CSV/Excel upload | — | — | — | — | ✔ | ✔ on behalf | Bulk upload | COMPLETE |
| Bulk actions + clearance markdowns | — | — | — | ✔ | ✔ | — | Inventory list | COMPLETE |
| Public profile, contact, logo (branding) | — | — | — | — | ✔ optional | — | Company profile | COMPLETE |
| Priority ranking | — | — | — | low | high | — | search order | COMPLETE |
| Performance score (incl. compliance) | — | — | — | ✔ | ✔ | ✔ | Analytics | COMPLETE |
| Advanced analytics, demand insights (search + RFQ trends) | — | — | — | — | ✔ | ✔ | Analytics | COMPLETE |
| Dedicated account manager | — | — | — | — | ✔ | assign | Account manager | COMPLETE |
| 12-module verification | — | ✔ (buyer stages) | — | ✔ | ✔ | review | Verification | COMPLETE (sanctions provider ⏸ D-16) |
| Supplier master data (86 sheet columns) | — | — | — | — | — | ✔ | Admin → Supplier master data | COMPLETE |
| Reports (15) with CSV/XLSX | — | — | — | — | — | ✔ | Admin → Reports | COMPLETE |
| Settings, plans, CMS, SEO, audit, outbox | — | — | — | — | — | ✔ by permission | Admin | COMPLETE |
| Email delivery, SMS/WhatsApp, MFA | ⏸ | ⏸ | ⏸ | ⏸ | ⏸ | ⏸ | — | BLOCKED (SMTP / providers) |
| REST API (token) | public endpoints | ✔ | ✔ | ✔ | ✔ | — | `/api/v1` | COMPLETE |
