# DGR Office — Test report

**Run:** `vendor/bin/phpunit` · PHP 8.3.6 · MySQL 8.0.46 · PHPUnit 10.5.66
**Result:** **96 tests, 963 assertions — all passed** (0 failures, 0
errors, 0 skipped; 2 min 41 s) on 2026-10-18, branch
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
| Every menu page of every starter role renders without errors / broken links | Logged-in crawl of all sidebar and in-page links as owner and as each demo role, plus headless-Chromium screenshots of dashboards | ✔ owner sees all widgets; telecaller sees only CRM widgets; no 500s or dead links found |
| Demo data load and purge | `dgr:demo` then `dgr:demo --purge` on a scratch database | ✔ trial balance Dr = Cr ₹10,13,966.68 after load; purge leaves configuration, owner and audit log |
| Backup and restore | `dgr:backup --files`, restore into an empty database | ✔ 122 tables, identical timestamps and audit hash chain |
| Diagnostics | `php spark dgr:diagnostics` | ✔ no failures (warnings only for unconfigured optional integrations) |
| Release archive | built by `scripts/build-release.sh`, unzipped into an empty folder, fresh `.env` + empty database → `dgr:install`, `dgr:create-owner`, `dgr:diagnostics`, sign-in | see the release notes in the final delivery message for the run of this build |

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
