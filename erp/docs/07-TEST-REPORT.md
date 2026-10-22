# DGR Office — Test report

**Run:** `vendor/bin/phpunit` · PHP 8.3.6 · MySQL 8.0.46 · PHPUnit 10.5.66
**Result:** **104 tests, 1093 assertions — all passed** (0 failures, 0
errors, 0 skipped; about 2½ min) on 2026-10-22 (release 1.1.1), branch
`ccr-5922b9e2-4of5k8`.

Tests run against a real MySQL test database (`database.tests.*`), go
through the real routes, filters, controllers, services and views (CI4
`FeatureTestTrait`), and inspect the resulting database rows, PDFs and
XLSX files. External services are replaced by fakes only at the network
edge: `FakeSheetsClient` (Google Sheets) and `FakeAiProvider` (Anthropic).

---

## 1. Specification §18 — accounting and billing (must pass)

| Case | Test(s) | Result |
| --- | --- | --- |
| GST invoice 121 → next 122; cash memo CM-0001 does not affect the GST sequence | `BillingAccountingTest::testGstSequence121Then122AndCashMemoSeriesIsIndependent`; `AcceptanceEvidenceTest::testOneTimeSetup…` (121 → CM-0001, CM-0002 → 122 through the screens) | ✔ |
| ₹10,000 cash memo, same state, 18 % → CGST 9 % + SGST 9 %, reported as taxable supply | `BillingAccountingTest::testCashMemoIsTaxedAndReportedLikeAnyTaxableSupply`; `BillingUiPdfTest::testCashMemoPdfIsTitledAndTaxedAsTaxInvoice`; `GstCalculatorTest::testCashMemoUsesTheSameTaxRulesAsInvoice` | ✔ |
| ₹5,000 cash against an invoice: outstanding falls, collection rises, sales do not | `BillingAccountingTest::testCashReceiptAgainstInvoiceDoesNotCreateSecondSaleAndDepositIsNotIncome` | ✔ |
| Deposit that cash into a bank/savings account: cash falls, bank rises, no revenue/GST | same test; `FinanceLedgerUiTest::testCashDepositMovesMoneyWithoutIncomeAndStatementsRunningBalance` | ✔ |
| Partial payment then UPI for the rest: fully settled, history correct | `BillingAccountingTest::testPartialThenUpiSettlesInvoiceFully`; `ReceiptsUiTest::testPaymentWithOverpaymentAppliedThenAdvanceUsedAndRefunded` | ✔ |
| Parallel drafts never consume numbers; simultaneous finalization never duplicates | `BillingAccountingTest::testConcurrentFinalizationNeverDuplicatesNumbers` (6 real parallel PHP processes) | ✔ |
| Changing state/tax category recomputes; user confirms uncertain rules | `BillingAccountingTest::testChangingCustomerRecomputesSuggestedTaxType`, `::testReviewRequiresAuthorisedReason`, `::testImpossibleTaxTypeAndMissingPlaceOfSupplyBlockFinalization`; `PlaceOfSupplyAdvisorTest` (11 tests) | ✔ |
| Credit note / refund update balances without deleting final history | `BillingAccountingTest::testCreditNoteReducesBalanceWithoutTouchingTheInvoice`, `::testFinalizedDocumentsCannotBeEditedOrDeleted`; `ReceiptsUiTest::testVoidReopensInvoice…`, `::testBouncedChequeVoidsReceipt` | ✔ |
| Cash memo, tax invoice and month reports reconcile without double counting | `BillingAccountingTest::testMonthlyRegistersReconcileWithoutDoubleCounting`; `ReportsGstTest::testRegistersGstWorkbooksAndLedgerAgree` | ✔ |
| Invoiced vs collected vs tax liability, accrual and cash views | `ReportsGstTest::testProfitAndLossAndCashFlowExcludeTransfers`, `::testRegistersGstWorkbooksAndLedgerAgree`; `GstSetOffTest` (3 tests) | ✔ |

Also covered: overpayment → advance and later application
(`testOverpaymentBecomesAdvanceAndIsAppliedLater`), duplicate receipt
detection, UTGST for union territories, inclusive pricing, round-off,
non-taxable treatments, LUT exports, composition suppliers, expenses with
blocked ITC and missing GSTIN (`ExpensesUiTest`), bank-statement import,
dedupe and matching (`ReconciliationTest`, `BankStatementParserTest`),
recurring retainers generating drafts once per period only
(`RecurringIntegrationsTest`).

## 2. Specification §19 — CRM and HR (must pass)

| Case | Test(s) | Result |
| --- | --- | --- |
| Lead with three calls shows three call events, latest status, next reminder, owner | `CrmLeadSyncTest::testTelecallerLeadWithThreeCallsShows…` | ✔ |
| Overdue follow-up on telecaller's and manager's dashboard; owner visit has its own timeline | `CrmAccessReminderTest::testOverdueFollowupVisibleToTelecallerAndManagerWithReminders`, `::testOwnerVisitHasItsOwnTimelineAndNextActionFollowup` | ✔ |
| Sheets: create appends one row, edit updates the same row, outage loses nothing, retry succeeds | `CrmLeadSyncTest::testSheetsAppendOnceUpdateSameRowSurviveOutageAndRetry` (fake Sheets client) | ✔ |
| Telecaller cannot see bank, GST reports or salaries even by direct URL | `CrmAccessReminderTest::testTelecallerSeesOnlyOwnLeadsAndNoFinanceGstPayrollOrOthersData` (finance, GST, payroll, HR, admin and AI URLs → 403; another telecaller's lead id → 404, no call logged) | ✔ |
| JD created, CV uploaded, AI review with job-related score + evidence or honest “unavailable”, interview recorded | `RecruitmentAiTest::testVacancyNeedsJdAndCvIsParsedServerSide`, `::testAiIsOffByDefaultAndFailureIsHonestlyUnavailable`, `::testAiReviewGetsOnlySanitisedCvJdAndRubric…` | ✔ |
| Joined candidate → employee linked to the candidate timeline | `RecruitmentAiTest::testRecruiterCannotSeeSalariesAndJoinedCandidateBecomesLinkedEmployee` | ✔ |
| Paid/unpaid leave → proration; incentives included exactly once | `HrPayrollTest::testPaidAndUnpaidLeaveProrationAndIncentivesIncludedExactlyOnce` | ✔ |
| Closed payroll rejects unauthorized changes; authorized correction audited | `HrPayrollTest::testAccrualAndPaymentAreNotDoubleCountedAndLockedPeriodRejectsChanges` | ✔ |

## 3. Specification §25 and §27 — access control and final acceptance

| Case | Test(s) | Result |
| --- | --- | --- |
| Owner creates a role and a user without code; UI, direct URLs and dashboard JSON enforce it; deny override wins | `RbacAdminTest::testOwnerCreatesRoleAndUserAndEveryLayerEnforcesIt` | ✔ |
| Dangerous grant needs password re-entry; expired role grants nothing | `RbacAdminTest::testDangerousGrantNeedsPasswordAndExpiredRoleGrantsNothing` | ✔ |
| Last Super Admin cannot be removed or deactivated | `RbacAdminTest::testLastSuperAdminCannotBeRemovedOrDeactivated` | ✔ |
| One-time identity, GSTIN, two banks + default, logo, signature, stamp; invoices use them; CIF absent from every PDF/XLSX/AI payload | `AcceptanceEvidenceTest::testOneTimeSetupBrandedInvoicesSequencesCashDepositAndNoCifAnywhere` | ✔ |
| Billing operator: own drafts with allowed customer fields; no bank setup, GST/revenue reports, signature or finalize | `BillingUiPdfTest::testBillingOperatorSeesOnlyOwnDocumentsAndCannotFinalizeOrDownload`; `AcceptanceEvidenceTest` (403 list) | ✔ |
| 121 → CM-0001, CM-0002 → 122; concurrency safe | see §1 | ✔ |
| Intra-state ₹10,000 + 18 % PDF shows CGST 9 % ₹900, SGST 9 % ₹900, total ₹11,800, no IGST | `BillingUiPdfTest::testIntraStateInvoiceThroughScreensAndPdfShowsOnlyCgstSgst`; evidence `01-…pdf` | ✔ |
| Inter-state ₹10,000 + 18 % PDF shows IGST 18 % ₹1,800, total ₹11,800, no CGST/SGST | `BillingUiPdfTest::testInterStateInvoicePdfShowsOnlyIgst`; evidence `02-…pdf` | ✔ |
| Customer vs place of supply recalculates; missing PoS blocks; mixed rates summarise | `BillingUiPdfTest::testImpossibleGstTypeDraftIsBlocked…`, `::testB2bLineWithoutSacBlocks…`; `GstCalculatorTest::testMultipleRatesSummariseByTypeAndRate` | ✔ |
| Cash paid then deposited: one receipt, one transfer, no extra income; registers reconcile | `AcceptanceEvidenceTest` + §1 | ✔ |
| Telecaller sees only assigned leads; finance/payroll/other leads → 403 without leakage | `CrmAccessReminderTest::testTelecallerSeesOnlyOwnLeads…` | ✔ |
| AI CV review gets only CV/JD/criteria; prompt-injection and token access to invoice/CRM endpoints blocked | `RecruitmentAiTest::testAiReviewGetsOnlySanitisedCvJdAndRubricAndInjectionIsNeutralised`, `::testAiTokenIsConfinedToItsCapabilityAndRecords`, `::testReplyMentioningProtectedTraitIsDiscarded`, `::testNoConsentMeansNoAi`; `UntrustedTextTest` | ✔ |
| Payroll AI off by default; when on, reads only allowed summary fields for selected period/employees; mutation blocked | `HrPayrollTest::testPayrollAiIsOffByDefaultAndReadOnlyForAllowlistedEmployeesWhenEnabled` | ✔ |
| HR shortlists after human review; recruiter cannot read salaries via candidate history | `RecruitmentAiTest::testRecruiterCannotSeeSalaries…` | ✔ |
| Sheets job saved to DB first, retried, idempotent; DND/private data masked in the shared sheet | `CrmLeadSyncTest::testSheetsAppendOnce…`, `::testDndLeadsAreMaskedInSheetsAndCallsAreBlocked` | ✔ |
| A4 PDF with original logo, permitted signature/stamp, PoS, tax split, bank details, number; history unchanged after settings edit | `AcceptanceEvidenceTest::testOneTimeSetup…` (logo bytes = uploaded file; snapshot unchanged after company rename) | ✔ |
| Build with included vendor on the target PHP; migrations + seeders on a clean database | Release-archive verification (§5) | ✔ |
| Employee sees only own payslip; HR/CRM exports | `HrPayrollTest::testEmployeeSeesOnlyOwnPayslipAndNoPayrollScreens`; `AcceptanceEvidenceTest::testHrAndCrmOutputsPayslipSalaryRegisterApplicantsAndLeads` | ✔ |
| Sample import files import cleanly; re-import detected as duplicates | `SampleImportFilesTest` (3 tests) | ✔ |
| Calendar shows only the caller's own tasks/follow-ups plus holidays; needs `calendar.view` | `CalendarTest` (2 tests) | ✔ |

## 3a. UI theme and frontend asset policy (release 1.1.0)

| Case | Test(s) | Result |
| --- | --- | --- |
| Every screen uses only the black · white · gold design system: no light-only Bootstrap classes, solid badges, hex colours, `<style>` or `style=""` in any view | `ThemeComplianceTest::testScreensUseOnlyTheDesignSystem` (all screen views; PDF templates excluded, they print on white paper) | ✔ |
| Every page shell is dark; no CDN, external script/stylesheet, `@import` or jQuery; no colour values in JavaScript | `ThemeComplianceTest::testEveryPageShellIsDarkAndLoadsOnlyLocalAssets` | ✔ |
| Approved palette unchanged (#0B0B0D, #111114, #1A1A1E, #FFFFFF, #A1A1AA, #D4AF37); text and status colours reach WCAG AA 4.5:1 on every surface | `ThemeComplianceTest::testApprovedPaletteIsDefinedOnceAndReadable` | ✔ |
| Bootstrap 5.3.8, Bootstrap Icons 1.13.2, Chart.js 4.5.1 are the official registry files, pinned, hash-matched, nothing extra shipped | `VendorAssetsTest` (offline); `scripts/verify-vendor-assets.sh` (online, below) | ✔ |
| A stored file missing on disk gives 404, not a server error; permission is still checked first | `HrPayrollTest::testEmployeeSeesOnlyOwnPayslipAndNoPayrollScreens` | ✔ |
| (1.1.1) Sign-in page shows the logo while signed out; signed-out images and script calls get 401 and never become the post-sign-in page; a signed-out page visit returns to that page after sign-in | `SignInFlowTest::testSignInPageLogoIsPublicAndOnlyPageVisitsBecomeTheReturnTarget` (fails on 1.1.0: the logo request is redirected to the sign-in page) | ✔ |
| (1.1.1) Logo/signature files lost from `writable/uploads`: no broken logo, `brand/logo` 404, Dashboard/Branding/Setup Wizard name the missing file, an invoice issued with them still downloads as a PDF, signing is no longer offered, new invoices do not reference the lost files, Diagnostics fails *Active logo/signature/stamp files* and warns on other missing uploads | `MissingUploadsTest::testLostLogoAndSignatureFilesAreReportedAndNothingBreaks` (fails on 1.1.0: the invoice PDF errors with "File not found.") | ✔ |

## 4. Evidence files (generated by the tests)

`DGR_EVIDENCE_DIR=docs/evidence vendor/bin/phpunit tests/feature/AcceptanceEvidenceTest.php tests/feature/RecruitmentAiTest.php`
writes these into [docs/evidence/](evidence/):

| File | What it shows |
| --- | --- |
| `01-tax-invoice-121-intra-state-cgst-sgst.pdf` | Official logo, signature/stamp, CGST 9 % ₹900 + SGST 9 % ₹900 = ₹11,800 |
| `02-tax-invoice-122-inter-state-igst.pdf` | IGST 18 % ₹1,800 = ₹11,800; number 122 after two cash memos |
| `03-cash-memo-cm-0001.pdf` | Separate series, same tax rules |
| `04-receipt-voucher.pdf` | Cash receipt against invoice 121 |
| `05-sales-register.xlsx` … `11-receipts.xlsx` | Sales and cash-memo registers, GSTR-1 / GSTR-3B preparation, collections, bank statement, receipts |
| `12-payslip.pdf`, `13-salary-register.xlsx` | Payslip (bank masked ••••9900), salary register |
| `14-applicants.xlsx`, `15-crm-workbook.xlsx` | Applicant export without contact details or salary; CRM workbook |
| `16-ai-cv-review-exact-request.txt` | The exact system prompt + user content sent for a CV review (no name, e-mail, phone, CIF or salary; injection removed) |
| `17-ai-cv-review-stored-result.json` | The validated, stored review with server-computed fit score |

The tests also assert that the company CIF string does not occur in any
of these files.

## 5. Manual and operational checks

| Check | How | Result |
| --- | --- | --- |
| Release archive installs on a clean machine | `scripts/build-release.sh 1.0.0` → unzip into an empty folder → fresh `.env`, `key:generate`, empty MySQL database → `dgr:install` (9 migrations + seeders, no manual SQL) → `dgr:create-owner` → `dgr:diagnostics` in **production** mode | ✔ no blocking problems; only expected warnings (cron not yet run, optional integrations, HTTPS, no backup yet, billing setup pending) |
| Release **1.1.0** archive installs on a clean machine | `scripts/build-release.sh 1.1.0` → unzip into an empty folder → fresh `.env` (production), `key:generate`, empty MySQL database → `dgr:install` → `dgr:create-owner` → `dgr:diagnostics` → browser crawl as the new owner | ✔ no blocking problems, same expected warnings; the build's own checks passed (every referenced asset present, vendor files match `MANIFEST.json`); 145 pages crawled with **no CSP violations, console errors or failed asset loads**; CSP header as documented |
| Every link of every role works on that install | A crawler logged in as the owner and as all nine demo roles and followed every same-site link (GET), reporting any 403/404/500 or PHP error text | ✔ **1,631 pages**: every one returned 200 or a redirect (14 redirects in total, e.g. after sign-in), none returned 403, 404 or 500 and none showed a PHP error; no link shown to a user leads to a page they may not open |
| Pages render with their assets | Headless Chromium on the fresh install (dashboard, billing setup, new invoice, calendar at desktop and 390 px phone width) | ✔ no console or asset errors |
| Demo data load and purge | `dgr:demo` then `dgr:demo --purge` on a scratch database | ✔ trial balance Dr = Cr ₹10,13,966.68 after load; purge leaves configuration, owner and audit log |
| Demo SQL dump imports elsewhere | `dgr-office-1.0.0-demo.sql.gz` imported into an empty database used by a *different* install (different encryption key) → `dgr:install` reports nothing pending → crawl as the demo accountant | ✔ 289 pages all 200; demo owner is forced to set a new password at first sign-in; diagnostics flags the demo accounts |
| Backup and restore | `dgr:backup --files`, restore into an empty database | ✔ 122 tables, identical timestamps and audit hash chain |
| Login throttling | Ten parallel sign-ins from one address | ✔ requests beyond Shield's limit (10 per minute) were refused |
| Black theme renders without CSP or script errors (1.1.0) | Headless Chromium on the demo install. Signed in as the owner, then as the employee and a telecaller. Followed every same-site link breadth-first (at most two records per path; downloads skipped). Recorded CSP violations, console errors and failed or 4xx/5xx asset loads | ✔ owner 198 pages, employee 14, telecaller 33: **no CSP violations, console errors or failed asset loads**. The one problem found is fixed below |
| Screens look right on desktop and phone (1.1.0) | Screenshots at 1366 px and 390 px of the dashboard (charts), billing list, invoice editor, CRM My Day, pipeline and leads, HR payroll, company settings, reports, calendar, role editor, sign-in and 404 pages | ✔ consistent dark theme. Two issues found and fixed: panel headers inside table cards had no padding, and two neighbouring gold chart series were too alike |
| Frontend libraries are official, unmodified and free of known vulnerabilities | `scripts/verify-vendor-assets.sh`: downloads each tarball from registry.npmjs.org and checks its sha512 against the registry. Requires every shipped file to be byte-identical to the tarball copy, then runs `npm audit` | ✔ 6 files identical; **0 vulnerabilities** (2026-10-20). A deliberately altered file is rejected (exit 1) |

### Defects found by this QA pass and fixed before release

| Defect | Fix |
| --- | --- |
| Bootstrap, Bootstrap Icons and Chart.js were missing from git (the `vendor/` ignore rule also matched `public/assets/vendor/`), so a clone or release had no CSS/JS | Rule narrowed to `/vendor/`, assets committed; the build script now refuses to package if any asset referenced by a view is missing |
| “Calendar & Reminders” menu entry pointed to a page that did not exist | Calendar page built (own items only) with tests |
| Quick-add “Task” pointed to a non-existent URL | Points to the task form on the Tasks page |
| Ledger rows linked payroll sources for users without payroll access; lead page linked the client record for users without contact access | Links are rendered only when the viewer holds the target's permission |
| A user who may create but not browse contacts was redirected to a 403 after saving | Stays on the form with a success message |
| `writable/cron.lock` was tracked in git | Untracked and ignored |
| `git archive` from the `erp/` sub-folder produced an empty archive | Build script archives from the repository top level and checks the result |
| (1.1.0 QA) Opening a stored file whose contents are not on disk gave a 500 error page. This happens for the demo CVs and payslips after importing the demo SQL into another install, or after a database restore without the uploads backup | Returns 404 after the usual permission check and logs a warning naming the missing file. Regression test added; the demo-SQL limitation is documented in 04-DEPLOYMENT §11 |
| (1.1.0 QA) The release script's "every referenced asset exists" check did not see files loaded through `asset('…')` | The check now covers `asset()` calls, and the build refuses to package vendor files that differ from `MANIFEST.json` |
| (1.1.1, reported from a live install) After signing in, users landed on `…/brand/logo` instead of the dashboard whenever a logo was uploaded. The sign-in page loads the logo, the logo URL required a session, so the image request was redirected and Shield remembered it as the page to return to. On the sign-in page itself the logo showed broken | `brand/logo` is public (it is the official logo shown on the sign-in page); a new filter answers signed-out non-page requests with 401 so only page visits are remembered. Verified in a browser: logo shown on the sign-in page, sign-in lands on the dashboard, and a signed-out visit to a page returns to it |
| (1.1.1, reported from a live install) The logo URL returned an empty 404 and showed as a broken image, because the database listed a logo whose file was not in `writable/uploads`. Every invoice, receipt and payslip PDF that embeds the logo then failed | When the file is missing: the company name is shown instead, PDFs are produced without the missing image, the snapshot and signing ignore files that are gone, the Dashboard, Branding Assets page and Setup Wizard say what is missing, and Diagnostics reports it. The deployment guide §10 now gives a File-Manager-safe upgrade and the recovery steps |

## 6. Known limitations (honest status)

* **Google Sheets** and **Anthropic** were tested against fakes only — no
  real credentials were available in the build environment. Both fail
  safely (MySQL is always written first; AI shows “Analysis unavailable”).
  Verify each once with your own credentials.
* **GST portal filing, e-invoicing (IRN/QR) and e-way bills are not
  integrated.** GSTR-1/3B outputs are preparation workbooks.
* **Statutory payroll (PF/ESI/PT/TDS/LWF) is disabled** until rates are
  entered and marked reviewed; no statutory rates ship with the app.
* Default SAC codes / GST rates are suggestions for accountant review.
* **MariaDB** (e.g. XAMPP's bundled database) is untested; use MySQL 8.
* Only the Anthropic provider is implemented for AI review.
* Browser automation covered rendering and navigation; it is not a full
  cross-browser or accessibility audit.
* The MySQL test database keeps data between runs; tests are written to
  be independent of leftovers (unique periods, deltas, resets).
