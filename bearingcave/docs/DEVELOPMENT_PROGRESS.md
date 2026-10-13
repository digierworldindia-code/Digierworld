# Development progress

Last updated 2026-10-13, after auditing the existing application against the client's workbook (`BearingCave.pdf`, 9 tabs). The detailed row-by-row mapping is in `EXCEL_REQUIREMENTS_MAPPING.md`.

## Where development stood, and where it continued

| Milestone | Evidence | State |
|---|---|---|
| Phases 1–9 of the original brief (foundation → testing) | commits `e710bf6`, `ee03407`, `29ff99a`, `ce0acf6`, `eec97ed`, `9edae13`; 22 passing PHPUnit tests; E2E 110/110; crawl 451/451 | Complete and verified before this round; **not redone** |
| Workbook audit (this round) | each workbook row checked against code, then run or tested | 13 gaps found (below); everything else confirmed working |
| Workbook gap closure (this round) | commit `21f92f0` plus documentation commit; migrations 000010–000013 | Done; see the test report |

Method: no module was rebuilt. Gaps were closed by extending the existing services, controllers and views. The database was backed up first, changed only through additive and reversible migrations, and its sample records were preserved (4 companies and their products before and after).

## Audit result: gaps found and how they were closed

| # | Feature (workbook ref) | Existing files | Status at audit | Working? | Work done (now) | Status now |
|---|---|---|---|---|---|---|
| 1 | Suppiler list, 86 headings (tab 7) | `SupplierMasterFieldSeeder`, `SupplierMasterService`, `Admin\SupplierMaster` | FIX REQUIRED | No: fields were my 86 *proposals* | Real headings active, proposals retired (not deleted), bank account encrypted and masked, retired-heading reactivation | COMPLETE |
| 2 | Direct enquiry: Not Verified Supplier = No (tab 5, tab 8 2.2) | `Web\Product::enquire`, `MembershipSeeder` | FIX REQUIRED | No: free plan had direct enquiries | Free plan off (migration); platform routing; Admin → Product enquiries (answer, pass on, close); `enquiries.manage` permission | COMPLETE |
| 3 | Inventory liquidation tools (tab 8 4.4) | — | PENDING | No | Bulk clearance markdown, reversible; tiers honoured; clearance filter and badges; API `clearance_pct` | COMPLETE |
| 4 | Structured tools for large inventories (tab 8 6.2) | `Supplier\Products::index` (filters only) | PARTIAL | Partially | Bulk submit, unpublish, archive and clearance with per-item failure reporting | COMPLETE |
| 5 | Real-time stock availability (tab 8 4.1) | product page (static on load) | PARTIAL | Partially | `/product/{uuid}/stock` plus 30 s refresh | COMPLETE |
| 6 | Inspection prior to purchase confirmation (tab 8 5.2) | product page link only | PARTIAL | Partially | Checkout option creates one inspection request per order | COMPLETE |
| 7 | Consolidation for non-verified clients at additional charges (tab 4 #12, tab 3 6.1) | `LogisticsService` blocked them | FIX REQUIRED | No | Allowed and flagged as chargeable; verified buyers flagged as privilege | COMPLETE |
| 8 | Supplier scoring includes compliance (tab 3 1.4) | `PerformanceService` | PARTIAL | Partially | `compliance_score` component | COMPLETE |
| 9 | Client bonus for BearingCave logistics and inspection, plus overall (tab 3 C.3) | `PerformanceService::computeBuyer` | PARTIAL | Partially | `services_bonus_score`, `overall_score`, configurable weights (pending approval) | COMPLETE |
| 10 | Demand planning from RFQ trends (tab 5) | `Supplier\Analytics` (search only) | PARTIAL | Partially | 12-month RFQ trend and top-requested parts (k ≥ 2 buyers) | COMPLETE |
| 11 | BearingCave intervention when inspection opted; documents provided (tab 3 4.1/4.2) | `DisputeService` | PARTIAL | Partially | Eligibility flag from paid inspection; inspection reports and supplier documents in the dispute | COMPLETE |
| 12 | Branding & Marking, verified optional (tab 5 #2) | `companies.logo_path` unused | PENDING | No | Logo upload and removal (Verified plan); shown on profile and listings | COMPLETE |
| 13 | Site audit outputs: score, corrective actions, recommendation (tab 6 #7); document types MSME, IEC, NDA, contracts, test reports (tab 6 #1, #8) | `site_audits`, verification documents | PARTIAL | Partially | Migration 000013, derived audit score, CAR and recommendation; shared document-type list | COMPLETE |
| — | Warranty & claims text per supplier tier (tab 5 #11) | generic checkout text | PARTIAL | Partially | Product-page text per tier (legal approval pending) | COMPLETE |

## Status of every workbook area

| Workbook area | Rows | COMPLETE | BLOCKED | Notes |
|---|---|---|---|---|
| Domain | 1 | 1 | — | set `app.baseURL` at deployment |
| Problems | 10 | 10 | — | |
| Platform Features | 26 | 24 | 2 | escrow partner (4.3); logistics 10 % base (2.2) |
| Client Features | 12 | 11 | 1 | escrow (#10) |
| Supplier Features | 23 | 21 | 1 + 1 partly | escrow (#12); #5 shipping 10 % base |
| Supplier Verification (12 modules) | 12 | 11 | 1 | sanctions provider; manual screening works |
| Suppiler list | 86 columns | 86 | — | |
| Product Page Features | 17 | 17 | — | |
| Comparative websites | 6 | documented | — | no software requirement |

No workbook requirement is **PENDING** or **FIX REQUIRED** any more. **BLOCKED** items need an external provider or a client decision. They are listed with their safe defaults in `06_CLIENT_DECISIONS_REGISTER.md` (D-04 … D-32) and `07_PENDING_INTEGRATIONS.md`.

## Files in this round

**Created (11 code and test files, plus the new docs below):**
- `app/Controllers/Admin/Enquiries.php`, `app/Views/admin/enquiries.php`
- migrations `2026-10-13-000010` to `000013`
- tests: `SupplierMasterSheetImportTest`, `InventoryToolsTest`, `CheckoutServiceOptionsTest`, `WorkbookScoringDisputesBrandingTest`, `SiteAuditOutputsTest`
- docs: `EXCEL_REQUIREMENTS_MAPPING.md`, `DEVELOPMENT_PROGRESS.md`, `FEATURE_MATRIX.md`, `DATABASE_GUIDE.md` (and `TEST_REPORT.md`, renamed from `TEST_REPORT.md`)

**Modified (59), in place, keeping existing behaviour:**

| Area | Files |
|---|---|
| Config | Routes, AuthGroups |
| Controllers (16) | Account\{Disputes, Logistics, Profile, Verification}, Admin\{Disputes, Performance, SupplierMaster, Verifications}, Api\V1\Products, Buyer\Cart, Supplier\{Analytics, Enquiries, Products}, Web\{Catalog, Product} |
| Seeders (3) | Membership, Settings, SupplierMasterField |
| Models (8) | BuyerProfile, Dispute, InspectionRequest, ProductEnquiry, Product, SiteAudit, SupplierMasterField, SupplierPerformance |
| Services (8) | Dispute, Document, Logistics, Performance, Product, Report, Search, SupplierMaster |
| Views (17) | account/{dispute, profile, verification}, admin/{company, dispute, performance, supplier_master, verification}, buyer/{checkout, dashboard, enquiries}, components/product_card, supplier/{analytics, products}, web/{catalog, product, supplier} |
| Other | `public/assets/js/app.js`, `nav_helper`, `.gitignore`, tests T02/T07, `tests/e2e/responsive.mjs` |

**Preserved unchanged:** the rest of the 473 tracked files, including:
- all base migrations 000001–000009, layouts, header, footer, homepage, design tokens and colour theme
- buyer, supplier and admin dashboards' structure and navigation (one admin menu item added: *Product enquiries*)
- authentication, RBAC, the order, RFQ, inspection and logistics workflows, the API contract (one field and one filter added), and demo data

## Remaining work (exact)

1. **Client decisions:** D-04 (tax), D-05 (fee approval), D-06 (logistics 10 % base), D-07 (consolidation charge), D-10/D-15/D-26 (legal texts), D-13 (escrow partner), D-16 (sanctions provider), D-24 and D-30 to D-32 (workbook interpretations).
2. **Integrations:** SMTP (then MFA and password reset by email), escrow/payment gateway adapter, sanctions screening adapter, optional SMS/WhatsApp.
3. **Recommended engineering before launch:** cross-browser and accessibility audit, load test, penetration test, production hosting and backups (see `CHECKLIST.md`).
