# Workbook requirements mapping

**Source:** the client's BearingCave workbook, supplied on 2026-10-13 as an 11-page PDF export (`BearingCave.pdf`). Every populated row of the nine tabs is listed below, with the sheet's own wording and spelling.

- The *Suppiler list* tab printed as a single row of 86 very narrow columns. Its headings were recovered from the PDF text layer and are listed in §7.
- Before this file, requirements came only from the written brief (see `01_REQUIREMENTS_TRACEABILITY.md`). Where the workbook and the brief disagree, the workbook wins and the conflict is recorded in §10.

**Status key:**
- **COMPLETE**: implemented and covered by a passing automated test or browser check.
- **PARTIAL**: code exists but something is missing.
- **PENDING**: not built.
- **FIX REQUIRED**: existing implementation is wrong.
- **BLOCKED**: needs credentials, an external provider or a client decision.

**Test key:**
- `T01`–`T11`, `SMI` (SupplierMasterSheetImport), `INV` (InventoryTools), `CHK` (CheckoutServiceOptions), `WSD` (WorkbookScoringDisputesBranding), `SAO` (SiteAuditOutputs), `SML` (SearchMatchLabel), `PC` (PolicyCalculations): PHPUnit tests in `tests/feature/`.
- `E2E`: `tests/e2e/responsive.mjs`.
- `CRAWL`: `tests/e2e/crawl.py`.

## 1. Domain

| Ref | Workbook | Implementation | Status | Test |
|---|---|---|---|---|
| 1.1 | www.bearingcave.com | `app.baseURL` in `.env`; canonical URLs, sitemap and robots are built from it (`components/head.php`, `Web\Seo`) | COMPLETE (set at deployment) | CRAWL |

## 2. Problems

| Ref | Problem | How the platform addresses it | Status |
|---|---|---|---|
| P-M1 | Manufacturers are stuck with dead inventory worth crores | Free supplier plan, lot listings, bulk upload, inventory ageing, clearance markdowns | COMPLETE |
| P-M2 | Cash blockage, warehouse cost, depreciation | Ageing buckets (less than 6, 6–12, more than 12 months) on listings, dashboard and the aged-stock report; liquidation tools (§8 4.4) | COMPLETE |
| P-M3 | Willing to liquidate at low margins or losses | Lot and clearance pricing; RFQ quotations | COMPLETE |
| P-M4 | Liquidation channels unorganised and non-transparent | Structured catalog, verification, RFQ, orders and audit trail | COMPLETE |
| P-M5 | Brand-sensitive manufacturers do not want discounted inventory publicly visible | Country allow/deny lists, verified-buyer-only listings, hidden supplier identity, confidential shipping, `noindex` | COMPLETE |
| P-C1 | Requirement of genuine branded parts | Brand, part and OEM numbers; supplier verification; inspection (batch authenticity) | COMPLETE |
| P-C2 | Need for lower-than-OEM pricing | Surplus and clearance pricing, RFQ comparison | COMPLETE |
| P-C3 | Immediate stock availability | Live stock (lots and reservations), real-time refresh on product pages | COMPLETE |
| P-C4 | Sourcing obsolete or slow-moving parts globally | Part, OEM and cross-reference search; RFQ matching; global listings | COMPLETE |
| P-C5 | Lack of trusted global suppliers | 12-module verification, badges, performance scores, AVL | COMPLETE |

## 3. Platform Features

| Ref | Workbook | Implementation (key files) | Status | Test |
|---|---|---|---|---|
| 1.1 | Structured supplier verification framework before onboarding/upgradation | `VerificationService`, `Account\Verification`, `Admin\Verifications` | COMPLETE | T01 |
| 1.2 | Verified Supplier Program: annual verification fee of ₹50,000 | `membership_plans` `supplier_verified`, `MembershipService`, `BillingService` | COMPLETE (fee and tax pending client approval, D-04/D-05) | T01, T10 |
| 1.3 | Supplier verification badge: Non Verified Supplier / Verified Supplier | "Verified Supplier" or "Unverified supplier" badges on product, cart and compare pages and supplier profiles | COMPLETE | T01, E2E |
| 1.4 | Supplier performance scoring based on transaction history, reliability and compliance | `PerformanceService::computeSupplier`: on-time delivery, rejection, cost, response, service level **plus compliance score (new)** | COMPLETE | WSD |
| C.1 | Client verification badge: Non Verified Client / Verified Client | `verified_badge()` (buyer, gated by `buyer_badge`), buyer dashboard status | COMPLETE | T07 |
| C.2 | Verification required for access to sensitive inventory and to prevent fake RFQs or misuse of client profiles | Restricted listings need verified status plus an explicit grant; unverified buyers are limited to 5 open RFQs; RFQs are reviewed by admin before distribution | COMPLETE | T06, T04 |
| C.3.1 | Client scoring: engagement history and transaction behaviour | `PerformanceService::computeBuyer` (engagement, transaction) | COMPLETE | WSD |
| C.3.2 | Bonus score for using BearingCave logistics and inspection services | `services_bonus_score` (new); weights in `buyer.score_weights` | COMPLETE (weights pending approval) | WSD |
| C.3.3 | Bonus score for posting genuine purchase requirements | `rfq_genuineness_score` counted as a bonus in `overall_score` (new) | COMPLETE | WSD |
| 2.1 | Selective country visibility protecting existing clientele and distribution networks | `product_country_rules`, `company_country_rules`, `VisibilityService` (Verified plan) | COMPLETE | T03 |
| 2.2 | Optional BearingCave-managed shipping at 10 % additional cost, enabling supplier anonymity | Managed and confidential logistics modes; 10 % in `logistics.platform_fee_pct`; **calculation base not stated** | BLOCKED: base needs client decision (D-06); quoted manually until then | T06, PC |
| 2.3 | Suppliers define available stock volumes at listing | Stock lots on create; CSV import quantity | COMPLETE | T02, T03 |
| 2.4 | Bids placed by clients remain confidential and not publicly visible | Quotations visible only to the RFQ owner and staff; RFQs never public | COMPLETE | T04 |
| 2.5 | Suppliers retain control over branding and presentation | Listing content and images; optional logo, public profile and contact for verified suppliers (logo **new**) | COMPLETE | WSD, E2E |
| 3.1 | Free RFQ posting for clients, routed directly to BearingCave | RFQs start as submitted, then admin review | COMPLETE | T04 |
| 3.2 | RFQs distributed only to verified suppliers | Eligibility = verified + `premium_rfq_leads` | COMPLETE | T04 |
| 3.3 | Client search behaviour analysed to generate potential leads | `search_logs` turned into demand insights (matching searches, unmet demand) | COMPLETE | CRAWL |
| 3.4 | Leads shared exclusively with verified suppliers | Demand insights gated by `demand_insights` (Verified plan) | COMPLETE | WSD |
| 4.1 | Quality and warranty claims are the responsibility of supplier and client; BearingCave is a documentation intermediary and provides test reports, inspection certificates and documents | Product page "Warranty & claims"; dispute page lists the order's inspection reports and the supplier's product documents (**new**) | COMPLETE (legal text pending, D-10) | CHK, WSD |
| 4.2 | BearingCave intervention applies when inspection services are opted for at additional cost | `disputes.bc_intervention_eligible` set from an accepted or completed inspection (**new**) | COMPLETE | WSD |
| 4.3 | Escrow-based transaction support for secure payments | `PaymentGatewayInterface`; no licensed partner yet | BLOCKED: escrow partner (D-13) | — |
| 4.4 | Platform-related technical or operational disputes resolved by BearingCave | Dispute categories `platform_service` and `inspection_service`, handled by support | COMPLETE | CRAWL |
| 5.1 | Paid inspection: in-house team or outsourced third-party (e.g. SGS); physical, quantity, packaging, batch authenticity | `InspectionService` (types and scopes) | COMPLETE | T05 |
| 5.2 | Cost: pricing depends on shipment volume and location (third-party) | Third-party requests are quoted manually from volume and location fields | COMPLETE | T05 |
| 6.1 | Consolidation of consignments from multiple suppliers on behalf of clients (additional charges) | Logistics mode `consolidation` for buyers (**fixed**: non-verified buyers now allowed at additional charges) | COMPLETE | CHK |
| 6.2 | Logistics for suppliers requiring confidentiality or discreet shipment management (additional cost) | Confidential mode hides the supplier from the buyer | COMPLETE | T06 |

## 4. Client Features (Not Verified Client / Verified Client)

| # | Feature | Not verified | Verified | Implementation | Status | Test |
|---|---|---|---|---|---|---|
| 1 | Price | FREE | FREE | Both buyer plans are free | COMPLETE | — |
| 3 | In-house inspection (physical, quantity, packaging, batch authenticity) | 10 % of total invoice value | 10 % | `inspection.in_house_rate_pct` = 10; also offered at checkout (**new**) | COMPLETE | T05, CHK, PC |
| 4 | Outsourced third-party inspection (SGS) | By volume and location | Same | Manual quote | COMPLETE | T05 |
| 5 | Engagement history | No | YES | Score computed for every buyer, **shown only to verified buyers** (dashboard) | COMPLETE | WSD |
| 6 | Transaction behaviour | No | YES | As row 5 | COMPLETE | WSD |
| 7 | BearingCave logistics | No | YES | Read as a scoring factor (bonus), shown to verified buyers; logistics itself stays available to all (see §10 C-3) | COMPLETE (interpretation recorded) | WSD |
| 8 | Posting genuine purchase requirements | NO | YES | RFQ genuineness score and bonus | COMPLETE | WSD |
| 9 | Client search behaviour | NO | YES | Search analytics card on the buyer dashboard (`buyer_analytics`) | COMPLETE | T07 |
| 10 | Escrow-based transaction support | YES | YES | Abstraction only | BLOCKED (D-13) | — |
| 11 | Platform-related technical or operational disputes | Bearing Cave | Bearing Cave | Disputes | COMPLETE | CRAWL |
| 12 | Consolidation from multiple suppliers | NO, available at additional charges | YES | Allowed for all buyers; non-verified flagged "additional charges apply" (**fixed**) | COMPLETE | CHK |
| — | Multi-supplier comparison across price, availability and terms | — | — | `Web\Compare` (advanced columns for verified buyers) | COMPLETE | E2E |

## 5. Supplier Features (Not Verified Supplier / Verified Supplier)

| # | Feature | Not verified | Verified | Implementation | Status | Test |
|---|---|---|---|---|---|---|
| 1 | Supplier verification system | FREE | 50,000/- | Plans `supplier_free` / `supplier_verified` | COMPLETE | T01 |
| 2 | Branding & marking | NO | YES (optional) | Logo upload (**new**), public profile | COMPLETE | WSD, E2E |
| 3 | Contact details | NO | YES (optional) | `public_contact` + supplier opt-in | COMPLETE | T01 |
| 4 | Company profile | NO | YES | `public_profile` | COMPLETE | T01 |
| 5 | Shipping | BearingCave-managed at 10 % additional | Direct or BearingCave-managed | `direct_logistics` entitlement; 10 % base blocked (D-06) | COMPLETE / BLOCKED (base) | T06 |
| 6 | Price with stock qty | Yes | Yes | Lot price plus stock | COMPLETE | T02 |
| 7 | Price per pcs | NO | Yes | `per_piece_pricing` | COMPLETE | T02, T07 |
| 8 | Supplier bidding participation | No | Yes | `supplier_bidding` | COMPLETE | T04 |
| 9 | Selective country visibility | Global | Selected visibility | `country_visibility_control` | COMPLETE | T03 |
| 10 | Premium RFQ leads access | No | Yes | `premium_rfq_leads` | COMPLETE | T04 |
| 11 | Warranty-related claims | No warranty; BC intervention when inspection opted | Supplier/client responsibility; BC documentation intermediary; intervention when inspection opted | Product page text per tier; dispute eligibility (**new**) | COMPLETE (legal text pending) | CHK, WSD |
| 12 | Escrow-based transaction support | Yes | Yes | — | BLOCKED (D-13) | — |
| 13 | Platform-related disputes | Bearing Cave | Bearing Cave | Disputes | COMPLETE | CRAWL |
| — | Verification badge | — | ✔ | `verified_badge` entitlement | COMPLETE | T01 |
| — | Unlimited product listing visibility on the marketplace | (blank) | (blank) | `max_active_products` unlimited on both plans; no value given in the sheet | COMPLETE (no limit set) | — |
| — | Sell inventory | In bulk | In bulk or smaller quantities below the declared stock volume | Free plan: lot sale only; Verified: piece/lot/both, MOQ | COMPLETE | T02 |
| — | Ranking priority | LOW | HIGH | `priority_ranking` boost in `RankingService` | COMPLETE | CRAWL |
| — | Supplier performance scoring | Yes | Yes | `PerformanceService` for all suppliers | COMPLETE | WSD |
| — | Bulk inventory upload tools (Excel/CSV) | No | Yes | `bulk_import` | COMPLETE | T03, T07, E2E |
| — | Advanced supplier analytics and visibility controls | No | Yes | `advanced_analytics`, `country_visibility_control` | COMPLETE | T07 |
| — | Dedicated BearingCave account manager | No | Yes | `dedicated_account_manager` | COMPLETE | CRAWL |
| — | Priority ranking and enhanced visibility in search and category listings | No | Yes | Ranking used by search and category pages | COMPLETE | CRAWL |
| — | Future production and demand planning insights based on platform RFQ trends | No | Yes | RFQ trend panel (12 months, top requested parts with at least 2 distinct buyers) (**new**) | COMPLETE | WSD |
| — | Direct enquiry submission from product pages to suppliers | **No** | Yes | `direct_enquiries` now **off** for the free plan (**fixed**); those enquiries are routed to BearingCave (Admin → Product enquiries) | COMPLETE | T02, T07 |

## 6. Supplier Verification System (12 modules)

| # | Module | Workbook items | Implementation | Status | Test |
|---|---|---|---|---|---|
| 1 | Supplier Registration & Profile Management | Company name and legal entity; GST, PAN, CIN, VAT, Tax ID validation; business address; contact person; bank account; categories. Documents: Certificate of Incorporation, GST Registration, PAN Card, MSME Certificate, IEC | `companies`, `kyc_records`, `supplier_profiles`; document types now include **MSME and IEC** (**new**, `DocumentService::COMPANY_DOC_TYPES`) | COMPLETE | T01, SAO |
| 2 | KYC Verification | PAN, GST, director/owner, UBO, bank account validation, address | `kyc_records` verification flags; directors and UBO | COMPLETE | T01 |
| 3 | Compliance & Regulatory | ISO, IATF 16949, AS9100, RoHS & REACH, ESG, insurance, labour law | `compliance_certifications` (types incl. labour/insurance), expiry job | COMPLETE | T01 |
| 4 | Financial Verification | Credit rating, turnover, profitability, balance sheet, banking references, legal cases, bankruptcy; outputs low/medium/high risk | `financial_assessments` plus risk level | COMPLETE | T01 |
| 5 | Risk Assessment | Financial (poor liquidity, low net worth), operational (capacity shortages), compliance (missing certifications), geographic (political instability), supply (single-source dependency), ESG (environmental or labour issues) | `risk_assessments` 1–5 per factor with evidence; indicators shown on the form (**new**); weights pending (D-20) | COMPLETE | PC, T01 |
| 6 | Quality Assessment | Certifications, inspection systems, PPAP, incoming/outgoing QC, CAPA, rejection history, IATF 16949, APQP, FMEA | `quality_assessments` | COMPLETE | T01 |
| 7 | Site Audit & Factory Assessment | Manufacturing capability, capacity, machinery, warehouse, calibration, employee competency, quality systems. Outputs: audit score, corrective action requests, approval recommendation | `site_audits` ratings plus **audit_score (derived), corrective_actions, recommendation** (**new**, migration 000013) | COMPLETE | SAO |
| 8 | Document Management | ISO certificates, insurance policies, NDAs, contracts, quality certificates, test reports; expiry alerts, renewal reminders, version control | `documents` (version, expiry, reminders via `bearingcave:daily`); types **NDA, contract, test report, ISO and quality certificate** added (**new**) | COMPLETE | T01, SAO |
| 9 | Approval Workflow | Registration → KYC → Compliance → Financial → Quality → Management Approval → AVL | Configurable stages (`verification.supplier_stages`, a superset in the same order) | COMPLETE | T01 |
| 10 | Sanctions & Restricted Party Screening | UN, OFAC, PEP, AML databases | `sanctions_screenings` (manual records) | BLOCKED: screening provider (D-16); manual recording works | T01 |
| 11 | Supplier Performance Monitoring | On-time delivery %, rejection %, cost competitiveness, response time, service level | `supplier_performance` | COMPLETE | WSD |
| 12 | AVL Management | Category-wise approval, brands, product-specific, status tracking, suspension/reactivation | `approved_vendors`, company suspend/reactivate | COMPLETE | T04 |

## 7. Suppiler list: 86 master-data columns

All 86 headings are the active field set in `supplier_master_fields` (sheet order and spelling, `is_confirmed = 1`). They are imported by Admin → Supplier master data. **Status: COMPLETE (was FIX REQUIRED). Test: SMI** (a file with all 86 headings auto-maps, imports and preserves all 86 values).

Columns with a native target also fill the normalised tables. Every column is additionally stored in `supplier_master_attributes`.

| # | Heading | Target | # | Heading | Target |
|---|---|---|---|---|---|
| 1 | SKU | attribute | 44 | Compliance Risk | attribute |
| 2 | Company Name | companies.legal_name | 45 | Geographic Risk | attribute |
| 3 | Contact Person | attribute | 46 | Supply Risk | attribute |
| 4 | Designation | attribute | 47 | ESG Risk | attribute |
| 5 | Manufacturing Catagory | supplier_profiles.main_categories | 48 | Quality certifications | attribute |
| 6 | Address 1 | companies.address_line1 | 49 | Inspection systems | attribute |
| 7 | State | companies.state | 50 | PPAP capability | attribute |
| 8 | Country | companies.country_code (name or ISO-2) | 51 | Incoming and outgoing QC procedures | attribute |
| 9 | Land Line No. | companies.phone | 52 | CAPA process | attribute |
| 10 | Mobile no. | attribute | 53 | Rejection history | attribute |
| 11 | Fax No. | attribute | 54 | IATF 16949 | attribute |
| 12 | Email | companies.email | 55 | PPAP | attribute |
| 13 | Website | companies.website | 56 | APQP | attribute |
| 14 | Instagram | attribute | 57 | FMEA capability | attribute |
| 15 | Facebook | attribute | 58 | Manufacturing capability | attribute |
| 16 | Linkedin | attribute | 59 | Production capacity | supplier_profiles.production_capacity |
| 17 | GST Registration | kyc_records.gst_number | 60 | Machinery condition | attribute |
| 18 | PAN Card | kyc_records.pan_number | 61 | Warehouse management | attribute |
| 19 | CIN | kyc_records.cin_number | 62 | Calibration systems | attribute |
| 20 | VAT | kyc_records.vat_number | 63 | Employee competency | attribute |
| 21 | Tax ID validation | kyc_records.tax_id | 64 | Quality systems | attribute |
| 22 | Bank account | kyc_records.bank_account_number_enc (**encrypted**; attribute stores a masked value) | 65 | Outputs | attribute |
| 23 | Certificate of Incorporation | attribute | 66 | ISO certificates | attribute |
| 24 | MSME Certificate | attribute | 67 | Insurance policies | attribute |
| 25 | Import Export Code (IEC) | attribute | 68 | NDAs | attribute |
| 26 | Director/Owner verification | attribute | 69 | Contracts | attribute |
| 27 | ISO certifications | attribute | 70 | Quality certificates | attribute |
| 28 | IATF 16949 (Automotive) | attribute | 71 | Test reports | attribute |
| 29 | AS9100 (Aerospace) | attribute | 72 | Features | attribute |
| 30 | RoHS & REACH compliance | attribute | 73 | UN sanctions lists | attribute |
| 31 | ESG compliance | attribute | 74 | OFAC sanctions | attribute |
| 32 | Insurance certificates | attribute | 75 | Politically Exposed Persons (PEP) | attribute |
| 33 | Labor law compliance | attribute | 76 | Anti-money laundering databases | attribute |
| 34 | Credit rating | attribute | 77 | On-time delivery % | attribute |
| 35 | Annual turnover | attribute | 78 | Quality rejection % | attribute |
| 36 | Profitability | attribute | 79 | Cost competitiveness | attribute |
| 37 | Balance sheet review | attribute | 80 | Response time | attribute |
| 38 | Banking references | attribute | 81 | Service level | attribute |
| 39 | Outstanding legal cases | attribute | 82 | Category-wise supplier approval | attribute |
| 40 | Bankruptcy checks | attribute | 83 | Approved brands | attribute |
| 41 | Risk outputs | attribute | 84 | Product-specific approvals | attribute |
| 42 | Financial Risk | attribute | 85 | Supplier status tracking | attribute |
| 43 | Operational Risk | attribute | 86 | Suspension and reactivation | attribute |

Imported master-data values are **source data, not verification results**. A "Credit rating" column, for example, does not mark the financial stage as passed; staff still review evidence in the verification workflow.

## 8. Product Page Features

| Ref | Workbook | Implementation | Status | Test |
|---|---|---|---|---|
| 1.1–1.7 | Search: part number, OEM, cross-reference, category, compatibility (application brand / vehicle fitment), size and specifications, brand | `SearchService` (normalised numbers, fitment make/model/year, dimension and specification filters) | COMPLETE | T04, SML |
| 2.1 | Integrated RFQ for bulk and custom requirements | `RfqService` | COMPLETE | T04 |
| 2.2 | Direct enquiry submission from product pages to suppliers | Direct for verified suppliers; via BearingCave otherwise (**fixed**) | COMPLETE | T02, T07 |
| 2.3 | Multi-supplier comparison across price, availability and terms | Compare page and "other suppliers" table | COMPLETE | E2E |
| 2.4 | Global sourcing network enabling cross-border inventory access | Country filter, international registration, multi-currency listings | COMPLETE | T03 |
| 3.1 | Verified Supplier filtering | `verified=1` filter | COMPLETE | CRAWL |
| 3.2 | Supplier visibility based on platform-defined trust and performance parameters | Ranking = performance × weight + plan boost | COMPLETE | CRAWL |
| 4.1 | Real-time stock availability updates | Product page refreshes stock every 30 s (`/product/{uuid}/stock`) (**new**) | COMPLETE | INV |
| 4.2 | Quantity-based dynamic pricing | `pricing_tiers` | COMPLETE | INV |
| 4.3 | Inventory ageing: 6-month dead stock, 12-month dead stock | Age buckets with documented boundaries | COMPLETE | U (InventoryAge) |
| 4.4 | Inventory liquidation tools for surplus stock clearance and optimisation | Bulk clearance markdown, restore, filter and badges (**new**) | COMPLETE | INV, E2E |
| 5.1 | Add to cart | `CartService` | COMPLETE | T02, T09 |
| 5.2 | Request inspection at additional cost **prior to purchase confirmation** | Checkout option creates an inspection request per order (**new**) | COMPLETE | CHK |
| 5.3 | Request BearingCave logistics at additional cost prior to purchase confirmation | Checkout shipping choice (managed / confidential) | COMPLETE | T06 |
| 5.4 | Controlled visibility listings based on supplier market restrictions | `VisibilityService` | COMPLETE | T03 |
| 6.1 | Bulk inventory upload via Excel/CSV | `ImportService` | COMPLETE | T03, E2E |
| 6.2 | Structured tools for managing and updating large inventory listings | Bulk actions (submit, unpublish, archive, clearance) and filters (**new**) | COMPLETE | INV, E2E |

## 9. Comparative websites (client's own notes)

The workbook supplies the client's assessment. It is reproduced in `02_COMPETITOR_RESEARCH.md` §0. No new software requirement follows from it, but the remarks guide design: "well-structured website with good content and an attractive layout", "very good website", and a mobile app noted for Excess2Sell (not in scope; the site is responsive).

## 10. Conflicts and interpretations (recorded, not guessed)

| ID | Conflict | Resolution in code | Client action |
|---|---|---|---|
| C-1 | Brief TEST 2 ("eligible buyer enquires" with an unverified supplier) vs workbook ("Direct enquiry … Not Verified Supplier: No") | Workbook wins: no *direct* enquiry. The buyer's enquiry goes to BearingCave, which answers or passes it on, so TEST 2 still passes | Confirm the routing (D-24) |
| C-2 | Brief: consolidation for verified buyers only; workbook: non-verified "NO, available at additional charges" | Allowed for all buyers; non-verified requests flagged as chargeable | Confirm pricing (D-07) |
| C-3 | Client Features row 7 "BearingCave logistics: No / YES" sits among scoring rows (5–9) | Treated as a scoring factor; logistics stays available to all buyers (the platform features offer it to clients generally) | Confirm (D-30) |
| C-4 | Workbook gives no weights for client scoring bonuses | Configurable weights 40/40/10/10 and 25 points per service, marked pending | Approve weights (D-31) |
| C-5 | "Unlimited product listing visibility on the marketplace" has blank cells for both plans | No listing limit on either plan | Confirm (D-32) |
