# DGR Office — Business ERP · Product Blueprint (Phase 0)

Internal ERP for **DIGIE R WORLD (DGR World)** — www.digierworld.com.
Stack: PHP 8.3 · CodeIgniter 4.7 · CodeIgniter Shield · MySQL 8 (InnoDB, utf8mb4) · Bootstrap 5.3 (local copy) · vanilla JS · Chart.js · PhpSpreadsheet · Dompdf · google/apiclient (Sheets only) · smalot/pdfparser · brick/math (exact decimals).

> Companion documents
> - `02-PERMISSIONS.md` — permission registry + role matrix, **generated from code** (`php spark dgr:docs`).
> - `03-SCHEMA.md` — table-by-table schema + Mermaid ERD, **generated from the migrated database** (`php spark dgr:docs`).
> - `04-DEPLOYMENT.md`, `05-GOOGLE-SHEETS.md`, `06-AI-BOUNDARY.md`, `07-TEST-REPORT.md`, `08-SECURITY-CHECKLIST.md`.

---

## 1. Business requirements summary & assumptions

| # | Requirement | Design decision |
|---|---|---|
| 1 | Replace billing books, cash receipts, Excel sheets, telecaller sheets, recruitment and salary sheets | One CI4 app, one MySQL database, shared `contacts`, `users`, `employees`, `audit_logs`, `notifications`. |
| 2 | Legally correct Indian GST billing | Document **series** (numbering) and **tax treatment** are separate fields. Cash memo = separate series, same tax engine. |
| 3 | No double counting | Every money movement is a balanced journal entry. Sales recognised once (at finalisation); receipts only move AR→Cash/Bank; transfers move Cash→Bank. Dashboard KPIs read distinct measures. |
| 4 | Non-technical users | Single-screen document form, auto-fill, radio-card GST selector, inline help, India formats (DD-MM-YYYY, ₹ 1,23,456.00). |
| 5 | Deploy on cPanel/VPS | No daemons. One cron line (`php spark dgr:cron`) drives the Sheets queue, reminders, recurring drafts. vendor/ shipped. |
| 6 | Strict RBAC + AI isolation | DB-backed roles/permissions (owner edits without code), deny-by-default filter on every route + record-scoped queries; AI is a capability-scoped service identity with no DB/ORM access. |

### Assumptions (configurable, flagged for accountant review)
- Supplier is a **regular GST-registered** business. GSTIN, legal name, address, state code, PAN, bank accounts are **entered by the owner in the Billing Setup Wizard** — nothing is invented. Until entered, finalisation of tax documents is blocked with a clear message.
- Default SAC / GST rates for agency services are **editable seed suggestions marked "accountant review required"** — the app never claims a universal rate.
- E-invoicing (IRN/QR) is **not live**. Fields exist and finalisation warns if the company marks itself e-invoice-applicable; no fake IRN is generated.
- GST portal filing is **not integrated**. The app produces GSTR-1-style / GSTR-3B-style preparation workbooks only.
- Statutory payroll deductions (PF/ESI/PT/TDS) ship **disabled** with empty rates; HR/accountant enables and configures.
- Personal savings accounts used for business collections are allowed only as a classified "owner clearing" account, flagged for reconciliation.

### Company data required before issuing REAL tax documents (blocking only for finalisation)
Legal name · GSTIN · PAN · registered address · state & state code · invoice series prefix and last-used number from the old book · at least one bank/UPI profile (if printed) · authorised signatory · invoice terms · e-invoice applicability decision · SAC + rate per service confirmed by accountant.

---

## 2. Sitemap / left sidebar (exact)

```
DGR Office
├─ Overview                      /                         dashboard.view (+ widget-level perms)
├─ Billing
│  ├─ Create Document            /billing/documents/new    any billing.*.create_draft
│  ├─ Invoices                   /billing/documents?type=tax_invoice
│  ├─ Cash Memos                 /billing/documents?type=cash_memo
│  ├─ Receipt Vouchers           /finance/receipts
│  ├─ Credit / Debit Notes       /billing/documents?type=credit_note
│  ├─ Estimates / Proforma       /billing/documents?type=estimate
│  └─ Recurring Retainers        /billing/recurring
├─ Finance
│  ├─ Payments & Receipts        /finance/receipts
│  ├─ Bank / Cash Accounts       /finance/accounts
│  ├─ Deposits / Transfers       /finance/transfers
│  ├─ Bank Reconciliation        /finance/reconciliation
│  ├─ Expenses                   /finance/expenses
│  ├─ Journal / Ledger           /finance/ledger
│  ├─ GST                        /reports/gst
│  └─ Reports                    /reports
├─ Clients & Vendors             /contacts
│  └─ Services Catalogue         /services
├─ CRM
│  ├─ My Day (Telecaller)        /crm/today
│  ├─ Leads                      /crm/leads
│  ├─ Pipeline (board)           /crm/pipeline
│  ├─ Calls                      /crm/calls
│  ├─ Follow-ups                 /crm/followups
│  ├─ Visits                     /crm/visits
│  ├─ Meetings                   /crm/meetings
│  ├─ Tasks                      /crm/tasks
│  ├─ Performance                /crm/performance
│  └─ Google Sheets Sync         /crm/sync
├─ HR
│  ├─ Vacancies / JDs            /hr/vacancies
│  ├─ Applicants                 /hr/candidates
│  ├─ Shortlisting Board         /hr/candidates/board
│  ├─ Interviews                 /hr/interviews
│  ├─ Employees                  /hr/employees
│  ├─ Attendance                 /hr/attendance
│  ├─ Leaves                     /hr/leaves
│  ├─ Holidays                   /hr/holidays
│  ├─ Incentives                 /hr/incentives
│  └─ Payroll                    /hr/payroll
├─ My Workspace (employee self-service)  /me
├─ Calendar & Reminders          /calendar
├─ Users & Roles                 /admin/users, /admin/roles
├─ Activity / Audit Logs         /admin/audit
└─ Settings / Integrations
   ├─ Billing Setup Wizard       /settings/setup
   ├─ Company Profile            /settings/company
   ├─ Branding Assets            /settings/branding
   ├─ Bank & Payment Profiles    /settings/banks
   ├─ Document Series            /settings/sequences
   ├─ Tax Rates & Treatments     /settings/tax
   ├─ Invoice Templates          /settings/templates
   ├─ Signature Policy           /settings/signature-policy
   ├─ Integrations (Sheets/AI/SMTP)   /settings/integrations
   ├─ AI Permissions             /admin/ai
   └─ Diagnostics                /admin/diagnostics
```
Menu items render only when the user holds at least one permission for the target; **routes enforce the same permission server-side** (hiding a link is never the control).

Top bar: global search (authorisation-filtered), quick-create (+), notification bell, profile menu. Mobile: offcanvas sidebar, single-column forms, sticky action bar.

---

## 3. Key screen-by-screen workflows

### 3.1 GST Tax Invoice
1. **Create Document** → choose type card: *Tax Invoice · Cash Memo · Bill of Supply · Estimate/Proforma · Credit Note · Debit Note*.
2. **Customer**: autocomplete (name/phone/GSTIN). Selecting fills legal/display name, GSTIN, billing address, state, payment terms → due date. Billing operators only receive the *billing-allowed* contact fields from the API.
3. **GST Type & Place of Supply** (dedicated section, see wireframe §4).
4. **Line items**: service catalogue picker or custom row; description, SAC, qty, unit, rate, discount, **tax treatment**, **GST rate**; keyboard: `Enter` = next field, `Ctrl+Enter` = new row, `Alt+↑/↓` = move row.
5. Live totals: subtotal · discount · taxable · CGST/SGST(or UTGST) or IGST · round-off · grand total · amount in words.
6. **Save Draft** (internal `DRAFT-xxxx` id, no statutory number consumed) → **Preview** (watermarked DRAFT PDF) → **Finalize** (requires `billing.invoice.finalize`; server re-validates tax, blocks if place-of-supply missing/uncertain and unresolved; allocates number under row lock; writes immutable identity/tax/template snapshot; posts journal).
7. After finalize: Print / Download PDF / Record Payment / Issue Credit Note / Duplicate as new draft.

### 3.2 Cash Memo
Same form, type **Cash Memo**, series `CM-26-27-0001`. Tax engine identical: a taxable ₹10,000 intra-state 18% cash memo carries CGST 9% ₹900 + SGST 9% ₹900 and appears in the outward-supply GST register. Optional "Paid at counter" creates the matching **receipt** (Dr Cash, Cr AR) — one sale, one receipt.

### 3.3 Cash → Bank deposit
Finance › Deposits/Transfers › New: From `Cash in Hand` → To `Current A/c (HDFC…1234)` · amount · date · reference/slip no. Posts **Dr Bank / Cr Cash** only. Report label: "Transfer — not income". Bank-statement import later matches the deposit line to this transfer (not to a new receipt).

### 3.4 Telecaller daily calls (My Day)
Tabs in the configurable order: **Due follow-ups → Overdue → Existing leads → New leads → Meetings/Visits → Completed today**, with daily target vs actual. Each row: call button (`tel:`), WhatsApp link, *Log call* quick modal (direction, outcome, duration, notes, next action + date/time, reminder, owner). Every log = new timeline event; previous history is never overwritten.

### 3.5 Google Sheets sync
Save lead → MySQL transaction commits → `google_sync_jobs` row enqueued (coalesced per entity/worksheet, idempotency key) → cron `dgr:cron` processes: lookup row by stable ID in column A → update that row, else append → store remote row ref. Failure → exponential backoff (1, 2, 4 … 60 min, max 8 tries) → dashboard shows Failed rows / Retry now. Sheets offline never blocks the CRM save.

### 3.6 Candidate AI screening
Vacancy (JD v1, v2…) → upload CV (PDF/DOCX ≤ 5 MB, private storage) → parser extracts text (status shown) → HR clicks **Request AI review** (needs `hr.candidate.score` + capability `ai.recruitment.evaluate_cv` enabled + candidate consent + "share with provider" allowed) → server builds a minimal payload (sanitised CV text, active JD version, rubric) → structured JSON validated → saved with provider/model/version → proposed timeline event "JD Match Suggested" → **HR accepts/overrides with reason**. No provider? A clearly-labelled **rules-based keyword match** is offered. Provider/parse failure → "Analysis unavailable" (no fabricated score).

### 3.7 Payroll
Payroll › New run (month) → system pulls attendance/leave/holiday calendar, salary structure in force, **approved & unpaid** incentive entries, advances → per-employee lines (payable days, proration, components, deductions) → review & adjust (reason) → **Finalize** (locks period; posts Dr Salary/Incentive Expense, Cr Salary Payable) → **Record payment** (Dr Salary Payable, Cr Bank) → payslips PDF, salary register XLSX. Post-lock corrections only through `hr.payroll.adjust` with reason, audit logged.

---

## 4. Wireframes

### 4.1 Dedicated GST Type & Place of Supply section (between Customer and Line items)
```
┌─ 2. GST Type & Place of Supply ───────────────────────────────────────────┐
│ Supplier: DIGIE R WORLD · GSTIN 08XXXXXXXXXXXXX · State: Rajasthan (08)    │
│ Recipient: Acme Pvt Ltd · GSTIN 07AAAAA0000A1Z5 (Registered) · Delhi (07)  │
│ Place of Supply  [ 07 — Delhi                ▼ ]  ☐ Recipient is SEZ unit │
│ ⓘ Suggested: 07 Delhi — recipient registered; default rule = recipient    │
│   location (Sec 12(2) IGST Act). Special-rule services require review.    │
│                                                                           │
│  ( ) CGST + SGST           (●) IGST                 ( ) CGST + UTGST       │
│  Intra-state supply        Inter-state supply       Intra-UT supply        │
│  9% + 9% on 18% rate       18% on 18% rate          disabled: POS not a UT │
│                                                                           │
│ ⚠ Conflict: you chose CGST+SGST but POS (07) ≠ supplier state (08).       │
│   This combination is not allowed. [Use suggested IGST]                   │
│ Tax review: ○ Auto-verified   ● Needs review → reason [__________] (perm)  │
└───────────────────────────────────────────────────────────────────────────┘
┌─ 3. Line items ──────────────────────────────────────────────────────────────────────┐
│ # Service ▼   Description        SAC     Qty Unit Rate    Disc Treatment▼  GST%▼ Line │
│ 1 SEO Monthly Keyword+On-page    998365  1   Month 10,000 0    Regular     18   11,800│
└──────────────────────────────────────────────────────────────────────────────────────┘
Tax summary:  IGST @18% on ₹10,000.00 = ₹1,800.00 · Grand total ₹11,800.00
```
Rules: tax **type** (document), tax **treatment** (line), **rate** (line) are three separate fields. Impossible combinations are rejected server-side, not just in JS.

### 4.2 Billing profile / signature / stamp authorisation
```
Settings › Branding Assets                       Settings › Signature Policy
┌──────────────────────────────────────┐        ┌───────────────────────────────────────────────┐
│ Official logo  [preview: original]   │        │ Who may apply the authorised signature/stamp? │
│  v3 · 900×192 PNG · sha256 4f…  ✔    │        │ Role / user        Tax Inv  Cash Memo Receipt │
│  [Replace] [History]  (never edited) │        │ Owner (Super)        ✔        ✔        ✔     │
│ Signature  [preview]  v1  ☑ Tax Inv  │        │ Accountant           ✔        ✔        ☐     │
│            ☑ Cash memo ☐ Estimate    │        │ Billing Operator     ☐        ☐        ☐     │
│ Stamp      [preview]  v1  position ▼ │        │ ☑ Require billing.apply_signature permission  │
│ Sign+stamp combined (optional)       │        │ ☑ Log every signature use (doc, user, ver.)   │
│ UPI QR     [preview]  for A/c ▼      │        │ Unsigned docs print "Computer-generated —     │
│ Letterhead (optional)                │        │  signature not applied" footer.               │
└──────────────────────────────────────┘        └───────────────────────────────────────────────┘
Settings › Bank & Payment Profiles
┌───────────────────────────────────────────────────────────────────────────────┐
│ ★ HDFC Current A/c   Beneficiary DIGIE R WORLD  A/c ••••1234  IFSC HDFC0…  UPI │
│   CIF: •••••• (internal, never printed — visible to settings.bank.view_cif)   │
│   Ownership: Company current account   ☑ Default on invoice   ☑ Active        │
│ ○ SBI Savings (Owner personal)  ⚠ Not printable on invoices unless approved   │
└───────────────────────────────────────────────────────────────────────────────┘
```

---

## 5. Accounting rules (double-entry)

| Event | Debit | Credit | Revenue? |
|---|---|---|---|
| Finalise Tax Invoice / Cash Memo / Bill of Supply | Accounts Receivable (grand total) | Sales (taxable value) · Output CGST/SGST/UTGST/IGST · Round-off | **Yes (once)** |
| Customer receipt allocated to invoice | Cash / Bank / UPI clearing | Accounts Receivable | No |
| Receipt not allocated (advance) | Cash / Bank | Customer Advances | No |
| Apply advance to invoice later | Customer Advances | Accounts Receivable | No |
| Cash deposited to bank | Bank | Cash in Hand | No |
| Receipt into owner's personal savings (classified) | Owner Clearing (asset, flagged) | AR / Advances | No |
| Credit note (finalised) | Sales Returns · Output tax | Accounts Receivable | Reduces |
| Refund to customer | Customer Advances / AR | Cash / Bank | No |
| Expense (accrual) | Expense category · Eligible Input GST | Accounts Payable / Cash / Bank | — |
| Ineligible GST on expense | Expense category (tax added to cost) | — | — |
| Payroll finalise | Salary Expense · Incentive Expense | Salary Payable · Statutory payables · Advances recovery | — |
| Salary paid | Salary Payable | Bank / Cash | — |
| Reversal / void | Mirror entry linked via `reversal_of_id` | | — |

KPI definitions (tooltips in UI): **Invoiced** = Σ grand total of finalised tax invoices (+ bills of supply) in period; **Cash memo value** = Σ cash memos; **Taxable turnover** = Σ taxable value of all finalised outward supplies minus credit notes; **Collections** = Σ customer receipts (excludes transfers); **Outstanding** = AR balance; **GST liability estimate** = output tax − eligible ITC; **Net cash movement** = Δ(cash + bank) (transfers net to zero).

---

## 6. Isolation matrix (AI · Telecaller · Billing Operator · Payroll)

| Resource / endpoint | Telecaller | Billing Operator | Payroll Manager | AI service token | Enforcement layers |
|---|---|---|---|---|---|
| `/crm/leads/{id}` assigned to self | ✔ | ✘ 403 | ✘ 403 | ✘ 401 | route perm `crm.lead.view_*` + query scope `assigned_to = me` |
| `/crm/leads/{id}` of another telecaller | ✘ 403/404 | ✘ | ✘ | ✘ | scope query returns no row → 404, no data |
| `/billing/documents` own drafts | ✘ | ✔ own only | ✘ | ✘ | `billing.invoice.view_own` → `created_by = me` |
| `/billing/documents/{id}/finalize` | ✘ | only if granted `.finalize` | ✘ | ✘ | route perm + service re-check |
| `/finance/accounts`, `/finance/ledger` | ✘ | ✘ | ✘ | ✘ | `finance.bank.view` / `finance.ledger.view` |
| `/reports/gst`, revenue KPIs | ✘ | ✘ | ✘ | ✘ | `reports.gst.view`, `dashboard.finance_kpis` (widgets + JSON APIs) |
| `/hr/payroll/*`, salaries | ✘ | ✘ | ✔ selected employees | ✘ | `hr.payroll.*` + employee scope |
| `/hr/candidates/{id}` (recruiter) | ✘ | ✘ | ✘ | ✘ | `hr.candidate.view`; salary fields require `hr.salary.view` |
| `/settings/banks` | ✘ | ✘ | ✘ | ✘ | `settings.bank.*`; CIF needs `settings.bank.view_cif` |
| `/api/ai/recruitment/*` | ✘ | ✘ | ✘ | ✔ only with capability + live grant | `aitoken` filter, capability, TTL, grant↔record match |
| `/api/ai/payroll/summary` | ✘ | ✘ | ✘ | ✔ only if enabled (OFF default) | capability + employee/period allowlist, read-only |
| Any other route with Bearer token | — | — | — | ✘ 401 | web routes accept only session auth |
| Global search / count APIs | own leads only | own docs only | own scope | ✘ | each provider re-applies the same scope |

---

## 7. Build order & completion checklist
- [ ] **P0** Blueprint, generated permission & schema docs
- [ ] **P1** CI4 + Shield auth, DB RBAC, audit log, layout, company settings + setup wizard, users & roles, diagnostics
- [ ] **P2** Contacts (GSTIN validation, dedupe, merge, import/export), services, tax rates
- [ ] **P3** GST engine, sequences (row lock), documents, snapshots, PDFs (3 templates), receipts, allocations, journal, transfers, bank import + reconciliation, expenses/ITC, registers & GST workbooks (XLSX/CSV/PDF)
- [ ] **P4** CRM leads/calls/follow-ups/visits/meetings/tasks/reminders, My Day, pipeline, conversion, import, performance, Sheets sync queue
- [ ] **P5** Vacancies/JD versions, candidates, CV parsing, AI gateway + rules fallback, timeline, interviews, convert → employee, attendance, leaves, salary, incentives, payroll, payslips, AI permissions admin
- [ ] **P6** Owner dashboard, automated tests (sections 18/19/25/27), acceptance evidence (real requests, PDFs/XLSX), backup, SQL dump, deployment docs, security checklist, packaging
