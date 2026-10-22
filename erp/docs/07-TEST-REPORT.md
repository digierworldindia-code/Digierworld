# DGR Office — Test report

**Release 1.3.1** (2026-10-23, branch `ccr-5922b9e2-4of5k8`), `vendor/bin/phpunit`, PHPUnit 10.5.66:

| Platform | Result |
| --- | --- |
| PHP 8.3.6 + MySQL 8.0.46 | **137 tests, 1,775 assertions — all passed** (0 failures, 0 errors, 0 skipped) |
| PHP 8.3.6 + MariaDB 11.8.9 | **137 tests, 1,775 assertions — all passed** |
| PHP 8.2.10 + MySQL 8.0.46 | **137 tests, 1,739 assertions — all passed** |

(The PHP 8.2 container has no `pdfinfo`/`pdfimages`, so the PDF tests skip
their page-size and embedded-image checks there; everything else is
identical.) Release 1.2.0 had 107 tests; 1.3.0 added 29 and 1.3.1 adds one
(`RbacAdminTest::testWeakPasswordIsRefusedWithAReadableReason`) plus a new
check in `ThemeComplianceTest` (gold accent never used as a text colour).
Which server each run used was confirmed from the database side (the
MariaDB server's insert counter and test tables changed during its run; the
PHP 8.2 run wrote to its own test database).

Tests run against a real MySQL or MariaDB test database (`database.tests.*`), go
through the real routes, filters, controllers, services and views (CI4
`FeatureTestTrait`), and inspect the resulting database rows, PDFs and
XLSX files. External services are replaced by fakes only at the network
edge: `FakeSheetsClient` (Google Sheets) and `FakeAiProvider` (Anthropic).

---

## 0a. Release 1.3.1 — full check of the running application

Run on a production-mode copy (`CI_ENVIRONMENT = production`, PHP 8.3,
MySQL 8) installed from scratch and loaded with `dgr:demo`, plus the
owner's own records created during the checks.

| Check | How | Result |
| --- | --- | --- |
| Every linked page, every role | Crawler signed in as the owner and each of the 9 demo roles (accountant, billing, CRM manager, 2 telecallers, HR, recruiter, payroll, employee) and followed every link on every page: 1,551 page loads | 0 server errors, 0 PHP error text, 0 forbidden links shown to a role; application log empty |
| Pages no link points to | Every GET route from `php spark routes` not reached by the crawler (lookups, JSON feeds, previews, `me/*`, health) requested directly | all answer correctly (JSON where expected, the themed 404 page for missing records, 401 for the AI API without a token) |
| Every screen in a real browser | Chromium: one page per route pattern (179 for the owner, plus the employee, telecaller and HR screens) × Dark and Light × 1366 px and 390 px — 812 page loads | 0 JavaScript errors, 0 console errors, 0 failed requests; after the fixes 0 pages wider than the screen and 0 text below 3:1 contrast |
| Forms in a real browser | New client → tax invoice with customer search, GST and line entry → finalize → PDF → record payment from the invoice → receipt PDF → invoice shows Paid; edit a bank account and reload; new lead → log a call; new expense (duplicate warning shown for a repeated one); employee requests leave | all saved and shown correctly, 0 JavaScript errors |
| Release ZIP on Apache, Hostinger-style | `dgr-office-1.3.1.zip` extracted into a sub-folder of `public_html` (root `.htaccess` → `public/`), production mode, Apache 2.4 + PHP 8.3, MariaDB 11.8: fresh install (`key:generate`, `dgr:install`, `dgr:create-owner`, `dgr:diagnostics` with no failed check); `.env`, `composer.*`, `spark`, `vendor/`, docs, `app/`, `writable/`, `tests/` all refused (403/404); crawler 339 pages and Chromium 472 page loads (both themes, 1366/390 px) | all pages answer, 0 JavaScript errors, Apache error log and application log empty |
| Upgrade with the ZIP | Copy of a 1.0.0-schema database with data, 1.3.1 files, `dgr:install` | backup written and read back first (122 tables), 3 migrations applied, only `migrations` (+3) and `app_settings` (+2) changed, `CHECK TABLE` OK for all 122 tables, second run finds nothing pending; crawler 683 pages OK (the only 404s are 12 uploaded files that were not copied with the database, reported in the log as missing on disk — the intended behaviour) |
| Clean SQL | `dgr-office-1.3.1-clean.sql` made from a fresh install of the ZIP; imported into empty MySQL 8.0 and MariaDB 11.8 databases | 122 tables; `dgr:install` finds nothing pending; owner created; identical to the 1.3.0 file apart from creation timestamps |
| Command-line tools | `dgr:install`, `dgr:diagnostics`, `dgr:backup` (verified 122 tables), `dgr:cron`, `dgr:demo` load → `--purge` → load → `--purge` on a fresh database | all succeed; purge removes every demo record and login and keeps the owner and audit log; purge refuses while non-demo records exist |

**Bugs found and fixed** (details in CHANGELOG 1.3.1): `dgr:demo` refused
its own documented example password; the password rule message was
ungrammatical; grand totals (and permission ticks) were 2.1:1 on white in
the Light theme; highlighted table rows were bright yellow/pink in the Dark
theme with unreadable gold text (1.9:1); the vacancy form was 6 px wider
than a phone screen; the invoice screen's Total column was off-screen at
1366 px.

## 0. Release 1.3.0 — regression tests 1–18 (requested list)

"Test" = automated PHPUnit test (runs on every platform above). "Browser"
= Chromium (Playwright) against a running copy of the app. "Load copy" =
the app installed on a separate database loaded with 6,000 invoices,
30,000 journal lines, 12,000 leads (8,000 calls), 28,000 audit entries and
120 employees through the app's own services.

| # | Workflow | Evidence (executed) | Result |
| --- | --- | --- | --- |
| 1 | Login → role dashboard → refresh → logout | `LoginLandingTest` (8 roles land on their page and can open it; page asked for before sign-in wins; forbidden landing never used; missing session cookie explained). Browser, load copy: Owner, Accountant → Dashboard; Billing operator → Billing dashboard; Telecaller → CRM My Day; HR manager → HR dashboard; Employee → My Workspace; each stays after reload, signs out to the sign-in page, and the page is refused afterwards; 0 JS errors | ✔ |
| 2 | Create customer → GST invoice → finalize → download PDF | `RegressionWorkflowTest::testCustomerToFinalizedInvoicePdfThroughTheForms` (all through the forms; PDF text checked); `BillingUiPdfTest` | ✔ |
| 3 | CGST + SGST invoice, tax breakdown | `BillingUiPdfTest::testIntraStateInvoiceThroughScreensAndPdfShowsOnlyCgstSgst` (900 + 900 on ₹10,000; no IGST printed) | ✔ |
| 4 | IGST invoice, tax breakdown | `BillingUiPdfTest::testInterStateInvoicePdfShowsOnlyIgst` (1,800; no CGST/SGST printed) | ✔ |
| 5 | Cash memo independent numbering | `BillingAccountingTest::testGstSequence121Then122AndCashMemoSeriesIsIndependent`; concurrency run below (tax invoice and cash-memo series each gap-free) | ✔ |
| 6 | Add bank → edit → save → refresh → persistence | `BankSetupTest` (6 tests: prefill, reload, keep-on-blank number/CIF, default, deactivate/reactivate, validation, opening balance, permission). Old code fails 3 of them (database error on long values, default kept when deactivated, opening balance re-dated) | ✔ |
| 7 | Invoice has correct bank details and signature | `BankSetupTest::testInvoicesPrintCurrentDetailsAndIssuedOnesKeepTheirs`; `DocumentPdfAuditTest` (logo + signature + stamp embedded on all signed types, bank account/IFSC printed, CIF never) | ✔ |
| 8 | Light → Dark → System on all screens | Browser, load copy: top-bar switch gives `#F8F9FA` / `#0B0B0D` backgrounds, System follows the device, choice kept after reload without browser storage (saved to the profile); 20 screens × 2 themes × 3 widths with no console/CSP/HTTP errors; print is white in Dark. `ThemePreferenceTest`, `ThemeComplianceTest` (both palettes, WCAG AA) | ✔ |
| 9 | CRM: add lead → follow-up → save | `RegressionWorkflowTest::testLeadAndFollowUpThroughTheForms`; `CrmAccessReminderTest`, `CrmLeadSyncTest` | ✔ |
| 10 | Employee → attendance → leave → payroll | `HrPayrollTest` (5 tests); `HrBulkScreensTest` (leave balances / register for everyone equal the per-employee values) | ✔ |
| 11 | Role restrictions and unauthorised URLs | `RbacAdminTest`, `CrmAccessReminderTest::testTelecallerSeesOnlyOwnLeads…` (list of forbidden URLs), `BillingUiPdfTest::testBillingOperatorSeesOnlyOwnDocuments…`, `LoginLandingTest` (area dashboards 403/404), `BankSetupTest::testOnlyAuthorisedUsersCanChangeBanks` | ✔ |
| 12 | Excel report → download → verify contents | `ReportsGstTest::testRegistersGstWorkbooksAndLedgerAgree` (reads the generated workbooks); `XlsxWriterTest` (layout, types, formats, totals, filter, import round-trip, 240,000 cells in flat memory). Load copy: CRM, invoice, receipt and contact exports opened with openpyxl and LibreOffice | ✔ |
| 13 | Mobile and desktop layout | Browser: 20 screens at 390, 768 and 1366 px in both themes: no page scrolls sideways (wide tables scroll inside their card); desktop menu hide/show kept after reload | ✔ |
| 14 | Concurrent invoice creation | Load copy over HTTP, 4 separate signed-in sessions in parallel, twice: 32/32 drafts and 32/32 finalizations, no duplicate or missing tax-invoice / cash-memo numbers, 0 deadlocks, audit chain intact. (Before the fix: 5 of 32 drafts failed with a deadlock.) Also `BillingAccountingTest::testConcurrentFinalizationNeverDuplicatesNumbers` (6 parallel PHP processes) | ✔ |
| 15 | Cash deposits do not double-count revenue | `BillingAccountingTest::testCashReceiptAgainstInvoiceDoesNotCreateSecondSaleAndDepositIsNotIncome`; `FinanceLedgerUiTest::testCashDepositMovesMoneyWithoutIncome…`; `ReportsGstTest::testProfitAndLossAndCashFlowExcludeTransfers` | ✔ |
| 16 | Slow / failing external APIs, long operations | `CrmLeadSyncTest` (Google outage: CRM data saved, job failed with back-off, retried; "Sync now" with no time left does nothing and reports what is queued; ID column read once per run); `RecruitmentAiTest::testAiIsOffByDefaultAndFailureIsHonestlyUnavailable`. Load copy: while a 1.6 s export ran, another page of the same user answered in 0.01 s (0.62 s+ when blocked) | ✔ |
| 17 | PHP 8.2 compatibility | Full suite on PHP 8.2.10 (table above); `composer check-platform-reqs` on 8.2: all OK (zipstream-php 3.1.2) | ✔ |
| 18 | MySQL migrations and integrity | Upgrade of a copy of the supplied production dump (1.0.0 schema) with `dgr:install`: verified backup first, 3 migrations applied, row counts unchanged except `migrations` (+3) and `app_settings` (+2); the backup restored to exactly the pre-upgrade database; `CHECK TABLE` OK for 122 tables; 196 foreign keys, 0 orphan rows (also 0 on the load copy, plus 0 unbalanced journal entries). Clean 1.3.0 SQL imports into MySQL 8.0 and MariaDB 11.8. `BackupVerifyTest`, `AuditChainTest` | ✔ |

### Performance on the load copy (every GET page timed)

| Page | Before | After |
| --- | --- | --- |
| CRM export (12,000 leads) | 9.1 s, 256 MB | 1.6 s, 38 MB |
| Invoice export (6,000 documents) | 3.7 s | 0.3 s |
| Cash account statement | 3.2 MB page | 100 rows per page |
| Leave balances (120 employees) | 1,816 SQL queries | fixed handful (< 100 ms) |
| Attendance register | 136 queries | fixed handful (< 100 ms) |
| Slowest remaining page | — | GST output report 0.8 s; all other pages < 0.5 s |

### Not executed (honest status)

* No test against the live Hostinger server: the settings a 504 depends on
  there (gateway timeout, PHP workers, database limits) need the hosting
  panel — see docs/04-DEPLOYMENT.md §13.
* Google Sheets and Anthropic were exercised with fakes (outage, retry,
  time budget), not with real credentials.
* Browsers other than Chromium were not run.

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

## 3b. Hostinger deployment fixes (release 1.2.0)

| Case | Test(s) | Result |
| --- | --- | --- |
| Six simultaneous invoice finalisations get six unique consecutive numbers on MariaDB 11.8 (failed with error 1020 before the fix) | `BillingAccountingTest::testConcurrentFinalizationNeverDuplicatesNumbers` run on MariaDB 11.8.9 | ✔ |
| A role without the dashboard lands on its first module; with no modules it gets an explanation; `dashboard/data` still needs the permission | `SignInFlowTest::testHomeOpensTheFirstModuleOfARoleWithoutTheDashboard` | ✔ |
| Every route points at an existing public controller method | `RoutesTest` | ✔ |
| Every permission key the code checks is registered | `PermissionKeysTest` | ✔ |

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

### Release 1.2.0: checks on a Hostinger-like server

Apache 2.4 with `.htaccess` (`mod_rewrite`), PHP 8.3, **MariaDB 11.8.9**
(Docker image `mariadb:11.8`), `CI_ENVIRONMENT = production`.

| Check | How | Result |
| --- | --- | --- |
| The three reported errors, on the delivered 1.1.0 zip (byte-identical to the one uploaded) with the uploaded clean SQL imported into MariaDB 11.8 | Data created on 1.1.0, then: logo file removed → document PDF; signed out → sign in; `.env` key blanked → save bank account | Reproduced exactly: `BusinessRuleException: File not found` in `FileStorage.php` line 102; sign-in landing on `/brand/logo` (404); `EncryptionException: Encrypter needs a starter key` (500) |
| Clean SQL imports into MariaDB 11.8 | `mysql < dgr-office-1.3.0-clean.sql` (1.3.0) | ✔ 122 tables, no errors |
| Whole project inside `public_html/dgr` (root `.htaccess`) | Fresh install from the working tree; browser sign-in, refresh, sign-out, signed-out visit to a protected page then sign-in | ✔ lands on the dashboard; returns to the requested page; no console errors |
| Only `public/` reachable in that layout | `GET /dgr/.env`, `.env.example`, `app/Config/App.php`, `writable/`, `vendor/autoload.php`, `composer.json`, `spark`, `tests/`, `README.md` | ✔ all 403/404; stylesheets served |
| `public/` copied into `public_html/dgr2`, app outside the web root (`index.php` path edited) | Same browser flow, PDFs, Diagnostics | ✔ |
| Wrong `app.baseURL` (placeholder, or missing `/dgr/`) | Open the sign-in page | ✔ page states the address it was opened at and the exact value to set |
| Encryption key missing, then generated | Save a bank account through the form before and after `php spark key:generate` | ✔ clear message on the form (input kept, account number not echoed back), then saved and stored encrypted |
| PDFs with and without branding; branding file then removed from disk | Invoice/estimate PDFs before upload, after uploading logo + signature through the UI, after deleting the logo file | ✔ 200 PDFs; logo embedded when present; produced without it when missing; `/brand/logo` 404 |
| Every page of every role | Browser crawl in the sub-folder as owner (175 pages) and accountant, billing operator, CRM manager, telecaller, HR manager, recruiter, payroll manager, employee | ✔ no 403/404/500, no CSP violations, no JavaScript errors; no PHP warnings in the Apache log |
| Diagnostics in the browser | Admin › Diagnostics on both layouts | ✔ MariaDB 11.8 supported, strict mode on, site address matches, web-root layout recognised |

**Not tested** (no access from the build environment): Hostinger's own
servers and LiteSpeed (Apache was used; LiteSpeed reads the same
`.htaccess` rules), HTTPS with `app.forceGlobalSecureRequests` (local
tests were over HTTP), real SMTP / Google Sheets / AI provider accounts
(fakes in the tests), and PHP *without* the sodium extension (it is
compiled into the PHP used here; that sodium is not needed is based on
the code and on composer.lock, where no package requires it). Your live
database was not available, so which of its uploads are missing could
not be checked: run Diagnostics after upgrading.

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
* **MariaDB**: 11.8.9 is tested (full suite and a production-mode install).
  Versions 10.6–11.7 are expected to work but were not run; older than
  10.6 is not supported.
* Only the Anthropic provider is implemented for AI review.
* Browser automation covered rendering and navigation; it is not a full
  cross-browser or accessibility audit.
* The MySQL test database keeps data between runs; tests are written to
  be independent of leftovers (unique periods, deltas, resets).
