# Test report

Every result below comes from runs actually executed in the build environment on 2026-10-09/10:
- Ubuntu container, PHP 8.3.6, MySQL 8.0, Chromium (Playwright 1.56).
- Commands are listed so the results can be reproduced.

## Round 2: workbook gap closure (2026-10-13)

All runs below were executed after the last code change of this round.

| Suite | Result |
|---|---|
| PHPUnit (`vendor/bin/phpunit`) | **31 tests, 382 assertions, 0 failures, 0 errors** (22 earlier tests unchanged except T02 and T07, which were updated to the workbook's enquiry rule; 9 new) |
| Browser E2E (`node tests/e2e/responsive.mjs`) | **133 of 133 checks passed**: 42 screens × 3 widths (375, 768, 1366 px), 4 mobile menus, real CSV import, **real logo upload**, **bulk clearance markdown and restore through the UI** |
| Authenticated crawl (`python3 tests/e2e/crawl.py`) | **460 of 460 pages OK** for guest plus 14 demo accounts (8 staff roles) |
| Migration reversibility (dev database) | 000010/000011 rolled back (columns removed, entitlement restored) and re-applied; sample data unchanged (4 companies, products kept) |

| Workbook requirement | Test | Expected | Actual | Result | Fix during testing |
|---|---|---|---|---|---|
| Supplier list, 86 columns | `SupplierMasterSheetImportTest` | All 86 headings auto-map; all 86 values kept; native fields filled; bank account encrypted, masked, never plain in import rows | As expected | Pass | `fieldExists()` cache made the alignment migration skip deactivation; switched to a live schema check |
| Direct enquiry: No for unverified | T02, T07 | Free-plan enquiry routed to BearingCave; supplier sees it only after it is passed on; enabling the entitlement makes enquiries direct | As expected | Pass | — |
| Liquidation and bulk tools | `InventoryToolsTest`, E2E | Markdown of 20 % then 50 % re-bases on the original price; tiers marked down; end restores prices exactly; another company's listing in the selection is refused and logged; per-item failure reporting | As expected | Pass | My test's expected audit count (4) was wrong; the code logs 3 events, which is correct |
| Real-time stock | `InventoryToolsTest` | JSON stock respects visibility (404 for restricted listings) | As expected | Pass | — |
| Inspection before purchase confirmation | `CheckoutServiceOptionsTest` | Request per order, 10 % of 1,000 = 100, order still awaiting supplier confirmation | As expected | Pass | — |
| Consolidation, warranty text | `CheckoutServiceOptionsTest` | Non-verified flagged "additional charges", verified flagged as privilege, suppliers refused; tier-specific warranty text | As expected | Pass | — |
| Supplier compliance and client scores | `WorkbookScoringDisputesBrandingTest` | Compliance 50 (1 of 2 valid certificates); services bonus 50; overall per weights | As expected | Pass | — |
| Dispute intervention | same | Not eligible without inspection; eligible after an accepted inspection; shown to staff | As expected | Pass | — |
| RFQ trends, branding | same | Aggregated trend; part requested by a single buyer hidden; logo refused on the free plan | As expected | Pass | SQL alias `lines` is reserved in MySQL 8; renamed |
| Site audit outputs, document types | `SiteAuditOutputsTest` | Score derived (80) and typed value ignored; CAR and recommendation stored; MSME, IEC, NDA and test report types offered | As expected | Pass | — |

## Round 1 summary (2026-10-09/10)

| Suite | Command | Result |
|---|---|---|
| PHPUnit (unit and feature, real MySQL `bearingcave_test`) | `vendor/bin/phpunit` | **22 tests, 286 assertions, 0 failures, 0 errors.** Before the last three tests were added, the 19-test suite passed 3 consecutive runs. T11 passed 5 of 5 isolated runs after its fix. |
| Responsive and browser E2E (TEST 12) | `node tests/e2e/responsive.mjs` | **110 of 110 checks passed**: 35 screens × 3 widths, 4 mobile-menu checks, and 1 real multipart CSV import through the UI |
| Authenticated link crawl | `python3 tests/e2e/crawl.py` | **451 of 451 pages OK** (no HTTP ≥ 400, no PHP error output) for guest, 2 buyers, 2 supplier owners, 1 supplier employee and 8 staff roles |
| Migrations from scratch | run by every PHPUnit run (`migrateOnce` on an empty database plus `DatabaseSeeder`) | Pass |

## Scenario results (prompt §33)

| # | Scenario | Preconditions | Expected | Actual | Result | Fix applied during testing |
|---|---|---|---|---|---|---|
| 1 | Supplier registers → documents → admin review → approval → subscription → badge (`T01`) | Clean DB, reference seed | Submission blocked until the required items exist; an officer without `verification.approve` cannot give final approval; verified status alone shows **no** badge; after finance records payment the subscription is active and the badge shows on the public product page | As expected (34 assertions) | **Pass** | Test harness: `postAs()` casts values to strings, as browsers do |
| 2 | Unverified supplier lists bulk stock → approved → buyer enquires → transaction starts (`T02`) | Free supplier, buyer | Per-piece pricing refused on the free plan; lot listing allowed; reviewer approves; enquiry stored; order → supplier confirms → proforma → awaiting payment | As expected | **Pass** | Real bug: `ProductService::save` failed when optional keys (`brand_id`, …) were absent from the POST. It now applies defaults |
| 3 | Verified supplier CSV import → validation → import → visible only in permitted countries (`T03` and E2E) | Verified supplier with one existing SKU | 2 valid rows, 1 duplicate and 1 error row reported; only valid rows imported; country-restricted product visible to an AE buyer but not to an IN buyer or a guest, and absent from the guest API and `sitemap.xml`; unrestricted product public | As expected. In E2E, a real multipart upload of 1 valid and 1 broken row imported only the valid row | **Pass** | `ImportService`/`DocumentService` now accept framework `File` objects as well as uploads |
| 4 | Buyer searches → RFQ → matched verified supplier → quotation → buyer responds (`T04`) | Verified supplier listing 30205, a free supplier, a buyer | Matching returns only the eligible verified supplier, with reasons; the RFQ is invisible to suppliers until distributed; a revision supersedes the first bid; the buyer sees a masked supplier; acceptance creates the order | As expected | **Pass** | Test data: integer POST values cast to strings |
| 5 | Inspection: fee → assignment → report (`T05`, `PC`) | Order with invoice value | Fee = 10 % of invoice value; minimum fee applied only when configured; no report before quote, acceptance and assignment; report stored privately; status completed | As expected | **Pass** | — |
| 6 | Confidential shipping hides supplier; logistics managed (`T06`) | Confidential listing, order | Buyer pages and API show neither the supplier name nor the pickup address; supplier and logistics staff see them; quote → accept → book → deliver; order moves on | As expected | **Pass** | — |
| 7 | Admin changes entitlements → access changes without code (`T07`) | Verified and free suppliers, buyer | Turning off `bulk_import` blocks imports immediately and turning it on restores them; enabling `per_piece_pricing` on the free plan applies to all free suppliers; `direct_enquiries` off refuses enquiries; `buyer_badge` and `buyer_analytics` off hide the badge and analytics; changes audited | As expected | **Pass** | Real gap: `direct_enquiries`, `buyer_badge` and `buyer_analytics` were configurable but had no effect. They are now enforced, and the second test method covers them |
| 8 | Unauthorized company data → denied and logged (`T08`) | Two companies, staff without permissions | 404 on another company's order (buyer and supplier side), product edit, product update (no write happens), invoice and private document; at least 5 `access.*` security audit events written; buyer kept out of the supplier area; staff blocked from modules they lack permission for | As expected | **Pass** | — |
| 9 | Ordering more than available (`T09`) | Product with 10 units | Checkout of 11 refused, nothing persisted (transaction rolled back); exactly 10 succeeds; then any further reservation fails; the CHECK constraint rejects a direct SQL over-reservation or negative stock | As expected | **Pass** | — |
| 10 | Subscription expiry → renewal behaviour (`T10`) | Verified, paid supplier | 7-day reminder sent; on the end date premium features stop **before** the nightly job runs; company stays compliance-verified; renewal invoice → payment → features back | As expected | **Pass** | — |
| 11 | Simultaneous reservations of the same stock (`T11`) | 10 units; 6 forked processes, each reserving 4 at the same instant | Exactly 2 succeed and 4 get `InsufficientStockException`; reserved = 8, on hand = 10. Also: two connections under a row lock, where the second re-evaluates and affects 0 rows | As expected (5 of 5 isolated runs; every full-suite run since the fix) | **Pass** | The test was flaky at first because forked children reused the parent's cached models and DB socket. Each child now resets `DbConfig::$instances`, `Factories` and `Services`. The application code was not changed |
| 12 | Important pages on desktop, tablet and mobile (E2E) | Dev server and demo data | No HTTP errors, no horizontal page overflow, no JS errors at 375, 768 and 1366 px; mobile menus open | 110 of 110 | **Pass** | Real bug: 12 px horizontal overflow on phones (home and contact pages) from Bootstrap `g-5` gutters. Gutters are now capped below 576 px |

## Additional automated checks

| Test | What it proves |
|---|---|
| `PolicyCalculationsTest` | Risk level follows the configured weights and thresholds. Inspection fee is 10 % with an optional minimum. **No logistics charge is calculated while the base is `manual_quote`.** |
| `SearchMatchLabelTest` | A cross-reference hit is labelled "interchangeability not verified" in HTML and in the API. An exact part-number hit is not. |
| `AdminPasswordRecoveryTest` | Only `users.manage` staff can issue a temporary password. The old password stops working and the action is audited. |
| `InventoryAgeTest`, `HelpersTest` | Age bucket boundaries (inclusive and exclusive edges); part-number normalisation; UUID and slug helpers. |

## Issues found by testing and fixed

1. A missing optional POST key crashed product saving (T02).
2. Configurable entitlements had no effect: `direct_enquiries`, `buyer_badge` and `buyer_analytics` (found while documenting T07).
3. Mobile horizontal overflow (T12).
4. There was no account recovery path while email is disabled. Added Admin → Users → **Issue temporary password**.
5. Earlier, during the crawl: the `number_sequences.last_value` column name was a MySQL 8 reserved word, and the error was hidden inside a transaction. Renamed to `counter` and set `transException=true`, so SQL errors can no longer be swallowed.
6. Earlier, during the crawl: admin user and staff lists failed under `ONLY_FULL_GROUP_BY`. Fixed with `ANY_VALUE`.

## Not covered by automated tests (honest gaps)

- Real email delivery, payment provider, escrow, sanctions provider and SMS. None are configured (see `07_PENDING_INTEGRATIONS.md`). The tests assert that nothing is *claimed* as sent or paid.
- Dispute workflow and supplier-master import: exercised only by the crawl (pages render), with no end-to-end assertion test.
- Cross-browser: only Chromium was run. Firefox and Safari were not tested.
- Accessibility: semantic markup and labels are used throughout, but no automated a11y audit (axe or similar) was run.
- Load and performance testing: not performed.
- Security: CSRF, throttling, access control and isolation are covered functionally. No external penetration test was done.
