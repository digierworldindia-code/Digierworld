<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Permission registry — the single source of truth for every permission key.
 *
 * Keys are seeded into the `permissions` table by PermissionSeeder and are
 * referenced by route filters (`perm:<key>`), controllers and services.
 * Roles are stored in the database and editable by the owner without code.
 *
 * Flags:
 *  - danger: granting it requires password re-entry and shows a warning.
 *  - scope:  'own' | 'assigned' | 'team' | 'all' for record-scope permissions.
 */
class Permissions extends BaseConfig
{
    /**
     * @var array<string, array{label: string, perms: array<string, array{0: string, 1?: array<string, mixed>}>}>
     */
    public array $modules = [
        'dashboard' => ['label' => 'Dashboard', 'perms' => [
            'dashboard.view'         => ['Open the overview dashboard (widgets are individually gated)'],
            'dashboard.finance_kpis' => ['See company-wide revenue, collection, GST, cash and bank KPIs', ['danger' => true]],
            'dashboard.crm_kpis'     => ['See team-wide CRM KPIs'],
            'dashboard.hr_kpis'      => ['See recruitment and attendance KPIs'],
            'dashboard.payroll_kpis' => ['See payroll totals on dashboard', ['danger' => true]],
        ]],
        'contacts' => ['label' => 'Clients & Vendors', 'perms' => [
            'contacts.view_billing' => ['Select customers for billing (billing fields only: name, GSTIN, address, state, terms)'],
            'contacts.view_all'     => ['View full client/vendor profiles'],
            'contacts.create'       => ['Create clients/vendors'],
            'contacts.edit'         => ['Edit clients/vendors'],
            'contacts.merge'        => ['Merge duplicate contacts'],
            'contacts.import'       => ['Import contacts from XLSX/CSV'],
            'contacts.export'       => ['Export contact lists', ['danger' => true]],
            'contacts.delete'       => ['Archive contacts'],
            'contacts.finance_view' => ['See a contact\'s invoices, receipts and outstanding balance'],
        ]],
        'services' => ['label' => 'Service Catalogue', 'perms' => [
            'services.view'   => ['View service catalogue'],
            'services.manage' => ['Create/edit services, default SAC and rates'],
        ]],
        'billing' => ['label' => 'Billing', 'perms' => [
            'billing.invoice.view_own'    => ['View tax invoices / bills of supply I created', ['scope' => 'own']],
            'billing.invoice.view_all'    => ['View all tax invoices / bills of supply', ['scope' => 'all']],
            'billing.invoice.create_draft'=> ['Create tax invoice drafts'],
            'billing.invoice.edit_draft'  => ['Edit tax invoice drafts'],
            'billing.invoice.finalize'    => ['Finalize tax invoices (allocates statutory number)', ['danger' => true]],
            'billing.invoice.print'       => ['Print tax invoices'],
            'billing.invoice.download'    => ['Download tax invoice PDFs'],
            'billing.invoice.export'      => ['Export tax invoice lists'],
            'billing.invoice.cancel'      => ['Cancel finalized invoices (via credit note / void)', ['danger' => true]],
            'billing.cash_memo.view_own'    => ['View cash memos I created', ['scope' => 'own']],
            'billing.cash_memo.view_all'    => ['View all cash memos', ['scope' => 'all']],
            'billing.cash_memo.create_draft'=> ['Create cash memo drafts'],
            'billing.cash_memo.edit_draft'  => ['Edit cash memo drafts'],
            'billing.cash_memo.finalize'    => ['Finalize cash memos', ['danger' => true]],
            'billing.cash_memo.print'       => ['Print cash memos'],
            'billing.cash_memo.download'    => ['Download cash memo PDFs'],
            'billing.cash_memo.export'      => ['Export cash memo lists'],
            'billing.cash_memo.cancel'      => ['Cancel finalized cash memos', ['danger' => true]],
            'billing.estimate.view_own'     => ['View estimates/quotations/proforma I created', ['scope' => 'own']],
            'billing.estimate.view_all'     => ['View all estimates', ['scope' => 'all']],
            'billing.estimate.create_draft' => ['Create estimates/quotations/proforma'],
            'billing.estimate.edit_draft'   => ['Edit estimates'],
            'billing.estimate.finalize'     => ['Issue estimates (non-posting)'],
            'billing.estimate.print'        => ['Print estimates'],
            'billing.estimate.download'     => ['Download estimate PDFs'],
            'billing.estimate.convert'      => ['Convert estimate to invoice draft'],
            'billing.note.view_all'         => ['View credit/debit notes'],
            'billing.note.create_draft'     => ['Create credit/debit note drafts'],
            'billing.note.edit_draft'       => ['Edit credit/debit note drafts'],
            'billing.note.cancel'           => ['Cancel finalized credit/debit notes (reversal)', ['danger' => true]],
            'billing.issue_credit_note'     => ['Finalize credit/debit notes', ['danger' => true]],
            'billing.note.print'            => ['Print credit/debit notes'],
            'billing.note.download'         => ['Download credit/debit note PDFs'],
            'billing.apply_signature'       => ['Apply authorised signature/stamp on documents', ['danger' => true]],
            'billing.change_bank_template'  => ['Change bank profile / template printed on a document'],
            'billing.tax.override'          => ['Resolve uncertain place-of-supply / tax review with reason', ['danger' => true]],
            'billing.export_all'            => ['Bulk export all billing documents', ['danger' => true]],
            'billing.recurring.manage'      => ['Manage recurring retainers and generate drafts'],
            'billing.share_link'            => ['Create expiring signed share links'],
        ]],
        'finance' => ['label' => 'Finance', 'perms' => [
            'finance.receipt.view'     => ['View payment receipts'],
            'finance.receipt.create'   => ['Record customer receipts/advances'],
            'finance.receipt.allocate' => ['Allocate receipts/advances to invoices'],
            'finance.receipt.void'     => ['Void receipts (reversal entry)', ['danger' => true]],
            'finance.receipt.refund'   => ['Record refunds'],
            'finance.ledger.view'      => ['View journal and ledger'],
            'finance.ledger.journal'   => ['Post manual journal entries', ['danger' => true]],
            'finance.bank.view'        => ['View bank/cash accounts, balances and transactions', ['danger' => true]],
            'finance.bank.import'      => ['Import bank statements'],
            'finance.bank.reconcile'   => ['Confirm reconciliation matches'],
            'finance.transfer.view'    => ['View deposits/transfers'],
            'finance.transfer.create'  => ['Record deposits/transfers'],
            'finance.transfer.void'    => ['Void transfers', ['danger' => true]],
            'finance.expense.view_own' => ['View expenses I recorded', ['scope' => 'own']],
            'finance.expense.view_all' => ['View all expenses', ['scope' => 'all']],
            'finance.expense.create'   => ['Record expenses'],
            'finance.expense.edit'     => ['Edit unapproved expenses'],
            'finance.expense.approve'  => ['Approve/reject expenses'],
            'finance.expense.pay'      => ['Record expense/vendor payments'],
            'finance.expense.export'   => ['Export expense registers'],
            'finance.expense.manage_categories' => ['Manage expense categories, ITC rules and recurring expenses'],
        ]],
        'reports' => ['label' => 'Reports', 'perms' => [
            'reports.billing.view'   => ['Sales registers (invoice, cash memo, combined)', ['danger' => true]],
            'reports.finance.view'   => ['Collections, aging, cash flow, P&L, transfers', ['danger' => true]],
            'reports.gst.view'       => ['GST output/ITC/payable worksheets, GSTR prep', ['danger' => true]],
            'reports.export'         => ['Export reports to XLSX/CSV/PDF', ['danger' => true]],
            'reports.payroll.view'   => ['Salary, incentive expense reports', ['danger' => true]],
            'reports.audit.view'     => ['Audit exception and correction report'],
            'reports.history.view'   => ['Report download history'],
        ]],
        'crm' => ['label' => 'CRM', 'perms' => [
            'crm.lead.view_assigned' => ['View leads assigned to me', ['scope' => 'assigned']],
            'crm.lead.view_team'     => ['View leads of my team (reporting to me)', ['scope' => 'team']],
            'crm.lead.view_all'      => ['View all leads', ['scope' => 'all']],
            'crm.lead.create'        => ['Create leads'],
            'crm.lead.edit'          => ['Edit leads within my scope'],
            'crm.lead.assign'        => ['Assign/reassign leads'],
            'crm.lead.delete'        => ['Archive leads'],
            'crm.lead.import'        => ['Bulk import leads'],
            'crm.lead.convert'       => ['Convert won lead to client'],
            'crm.call.create'        => ['Log calls'],
            'crm.followup.manage'    => ['Create/complete follow-ups'],
            'crm.visit.view'         => ['View visits in my scope'],
            'crm.visit.assign'       => ['Schedule/assign visits'],
            'crm.visit.update_outcome' => ['Record visit outcome/feedback'],
            'crm.meeting.manage'     => ['Schedule/update meetings'],
            'crm.task.view_own'      => ['View my tasks', ['scope' => 'own']],
            'crm.task.view_all'      => ['View all tasks', ['scope' => 'all']],
            'crm.task.create'        => ['Create tasks'],
            'crm.task.assign'        => ['Assign tasks to others (incl. owner)'],
            'crm.export'             => ['Export CRM data', ['danger' => true]],
            'crm.reports.view_own'   => ['View my CRM performance'],
            'crm.reports.view_team'  => ['View team CRM performance'],
            'crm.settings.manage'    => ['Manage stages, sources, lost reasons, targets'],
            'crm.sheets.view'        => ['View Google Sheets sync status'],
            'crm.sheets.manage'      => ['Configure Google Sheets sync, retry jobs', ['danger' => true]],
        ]],
        'hr' => ['label' => 'HR & Payroll', 'perms' => [
            'hr.vacancy.view'        => ['View vacancies/JDs'],
            'hr.vacancy.manage'      => ['Create/edit vacancies and JD versions'],
            'hr.candidate.view'      => ['View applicants assigned to me (as recruiter/interviewer)', ['scope' => 'assigned']],
            'hr.candidate.view_all'  => ['View all applicants', ['scope' => 'all']],
            'hr.candidate.create'    => ['Add applicants, upload CVs'],
            'hr.candidate.edit'      => ['Edit applicants'],
            'hr.candidate.score'     => ['Request AI/rules CV review, record HR score'],
            'hr.candidate.decide'    => ['Shortlist/hold/reject/offer (human decision)'],
            'hr.candidate.export'    => ['Export applicant data', ['danger' => true]],
            'hr.candidate.convert'   => ['Convert joined candidate to employee'],
            'hr.timeline.create'     => ['Add candidate timeline entries'],
            'hr.interview.manage'    => ['Schedule interviews, record scorecards'],
            'hr.employee.view_basic' => ['Employee directory (name, department, designation, work contact)'],
            'hr.employee.view_all'   => ['Full employee profiles (excl. salary & bank)'],
            'hr.employee.manage'     => ['Create/edit employees'],
            'hr.employee.private_docs' => ['View/upload private employee documents', ['danger' => true]],
            'hr.employee.bank_view'  => ['View employee bank details', ['danger' => true]],
            'hr.salary.view'         => ['View salary structures', ['danger' => true]],
            'hr.salary.manage'       => ['Edit salary structures', ['danger' => true]],
            'hr.salary.export'       => ['Export salary registers', ['danger' => true]],
            'hr.attendance.view_all' => ['View all attendance'],
            'hr.attendance.mark'     => ['Mark daily attendance'],
            'hr.attendance.correct'  => ['Correct attendance with reason'],
            'hr.leave.view_all'      => ['View all leave requests and balances'],
            'hr.leave.approve'       => ['Approve/reject leaves'],
            'hr.leave.manage_types'  => ['Manage leave types and entitlements'],
            'hr.holiday.manage'      => ['Manage holiday calendar and weekly offs'],
            'hr.incentive.view_all'  => ['View all incentive entries'],
            'hr.incentive.policy_manage' => ['Manage incentive policies'],
            'hr.incentive.approve'   => ['Approve/reject/clawback incentive entries', ['danger' => true]],
            'hr.payroll.view'        => ['View payroll runs', ['danger' => true]],
            'hr.payroll.calculate'   => ['Create/calculate draft payroll runs', ['danger' => true]],
            'hr.payroll.finalize'    => ['Finalize and lock payroll', ['danger' => true]],
            'hr.payroll.pay'         => ['Record salary payments', ['danger' => true]],
            'hr.payroll.adjust'      => ['Post-lock payroll corrections with reason', ['danger' => true]],
            'hr.payroll.statutory'   => ['Configure PF/ESI/PT/TDS settings', ['danger' => true]],
            'hr.payslip.view_all'    => ['View/download all payslips', ['danger' => true]],
        ]],
        'self' => ['label' => 'My Workspace', 'perms' => [
            'self.profile.view'    => ['View my employee profile'],
            'self.attendance.view' => ['View my attendance'],
            'self.attendance.checkin' => ['Self check-in/out'],
            'self.leave.request'   => ['Request leave and view my balance'],
            'self.payslip.view'    => ['View/download my payslips'],
            'self.incentive.view'  => ['View my incentive entries'],
        ]],
        'calendar' => ['label' => 'Calendar', 'perms' => [
            'calendar.view' => ['Calendar of my permitted tasks, follow-ups, visits, interviews'],
        ]],
        'settings' => ['label' => 'Settings', 'perms' => [
            'settings.company.view'    => ['View company profile'],
            'settings.company.edit'    => ['Edit legal identity / company profile', ['danger' => true]],
            'settings.branding.edit'   => ['Upload/replace logo, signature, stamp, QR, letterhead', ['danger' => true]],
            'settings.bank.view_masked'=> ['See bank profiles with masked numbers'],
            'settings.bank.view_full'  => ['See full account numbers', ['danger' => true]],
            'settings.bank.view_cif'   => ['See confidential CIF numbers', ['danger' => true]],
            'settings.bank.edit'       => ['Create/edit bank & payment profiles', ['danger' => true]],
            'settings.sequences.manage'=> ['Configure document series', ['danger' => true]],
            'settings.tax.edit'        => ['Tax rates, treatments, SAC templates', ['danger' => true]],
            'settings.templates.edit'  => ['Invoice templates and layout', ['danger' => true]],
            'settings.signature_policy.manage' => ['Configure who may apply signatures', ['danger' => true]],
            'settings.integrations.manage' => ['SMTP, Google Sheets, AI provider credentials', ['danger' => true]],
            'settings.app.edit'        => ['General app settings, FY, preferences'],
        ]],
        'security' => ['label' => 'Users, Roles & Security', 'perms' => [
            'security.users.view'     => ['View users'],
            'security.users.manage'   => ['Create/disable users, reset passwords, assign roles', ['danger' => true]],
            'security.roles.manage'   => ['Create/edit roles and permission matrix', ['danger' => true]],
            'security.sessions.manage'=> ['View/revoke active sessions', ['danger' => true]],
            'security.audit.view'     => ['View audit logs'],
            'security.diagnostics.view' => ['Admin diagnostics'],
            'security.backup.manage'  => ['Create/download backups', ['danger' => true]],
        ]],
        'ai' => ['label' => 'AI Governance', 'perms' => [
            'ai.permissions.manage' => ['Configure AI capabilities, scopes, service tokens', ['danger' => true]],
            'ai.usage.view'         => ['View AI usage, cost and action logs'],
        ]],
    ];

    /**
     * AI service-account capabilities. These are NOT user permissions; they are
     * granted to AI service accounts and are all disabled by default.
     *
     * @var array<string, array{label: string, fields: list<string>}>
     */
    public array $aiCapabilities = [
        'ai.recruitment.evaluate_cv' => [
            'label'  => 'Evaluate one selected CV against one active JD version',
            'fields' => ['candidate.cv_text_sanitised', 'job.title', 'jd_version.content', 'jd_version.must_have', 'jd_version.nice_to_have', 'rubric'],
        ],
        'ai.recruitment.create_timeline_entry' => [
            'label'  => 'Propose one candidate timeline event (recorded by server under HR actor)',
            'fields' => ['candidate.id', 'recruitment_event.proposed'],
        ],
        'ai.recruitment.suggest_interview_questions' => [
            'label'  => 'Suggest interview questions from JD + CV evidence',
            'fields' => ['candidate.cv_text_sanitised', 'jd_version.content'],
        ],
        'ai.payroll.read_approved_summary' => [
            'label'  => 'Read approved payroll summary aggregates for allowlisted employees/period (OFF by default)',
            'fields' => ['payroll_item.employee_code', 'payroll_item.payable_days', 'payroll_item.gross_pay', 'payroll_item.total_deductions', 'payroll_item.net_pay', 'payroll_run.period'],
        ],
        'ai.payroll.summarize_period' => [
            'label'  => 'Summarize a finalized payroll period in aggregate (OFF by default)',
            'fields' => ['payroll_run.period', 'payroll_run.totals'],
        ],
    ];

    /**
     * Starter roles. Owners can edit/clone/delete these in Users & Roles.
     * '*' = every permission (Super Admin only).
     *
     * @var array<string, array{name: string, description: string, perms: list<string>}>
     */
    public array $starterRoles = [
        'super_admin' => [
            'name' => 'Owner / Super Admin',
            'description' => 'All modules, approvals and permission management. Privileged actions are logged.',
            'perms' => ['*'],
        ],
        'accountant' => [
            'name' => 'Accountant',
            'description' => 'Billing, finance, bank, GST reports. No CRM lead lists or candidate CVs by default.',
            'perms' => [
                'dashboard.view', 'dashboard.finance_kpis',
                'contacts.view_billing', 'contacts.view_all', 'contacts.create', 'contacts.edit', 'contacts.finance_view', 'contacts.export', 'contacts.import',
                'services.view', 'services.manage',
                'billing.invoice.view_all', 'billing.invoice.create_draft', 'billing.invoice.edit_draft', 'billing.invoice.finalize', 'billing.invoice.print', 'billing.invoice.download', 'billing.invoice.export', 'billing.invoice.cancel',
                'billing.cash_memo.view_all', 'billing.cash_memo.create_draft', 'billing.cash_memo.edit_draft', 'billing.cash_memo.finalize', 'billing.cash_memo.print', 'billing.cash_memo.download', 'billing.cash_memo.export', 'billing.cash_memo.cancel',
                'billing.estimate.view_all', 'billing.estimate.create_draft', 'billing.estimate.edit_draft', 'billing.estimate.finalize', 'billing.estimate.print', 'billing.estimate.download', 'billing.estimate.convert',
                'billing.note.view_all', 'billing.note.create_draft', 'billing.note.edit_draft', 'billing.issue_credit_note', 'billing.note.print', 'billing.note.download', 'billing.note.cancel',
                'billing.tax.override', 'billing.recurring.manage', 'billing.change_bank_template',
                'finance.receipt.view', 'finance.receipt.create', 'finance.receipt.allocate', 'finance.receipt.void', 'finance.receipt.refund',
                'finance.ledger.view', 'finance.bank.view', 'finance.bank.import', 'finance.bank.reconcile',
                'finance.transfer.view', 'finance.transfer.create', 'finance.transfer.void',
                'finance.expense.view_all', 'finance.expense.create', 'finance.expense.edit', 'finance.expense.approve', 'finance.expense.pay', 'finance.expense.export', 'finance.expense.manage_categories',
                'reports.billing.view', 'reports.finance.view', 'reports.gst.view', 'reports.export', 'reports.audit.view', 'reports.history.view',
                'settings.company.view', 'settings.bank.view_masked', 'settings.tax.edit',
                'calendar.view',
            ],
        ],
        'billing_operator' => [
            'name' => 'Billing Operator',
            'description' => 'Creates invoice/cash memo drafts for allowed customers. No revenue totals, bank, GST dashboards, CRM, HR. Finalize/print individually assignable.',
            'perms' => [
                'dashboard.view',
                'contacts.view_billing',
                'services.view',
                'billing.invoice.view_own', 'billing.invoice.create_draft', 'billing.invoice.edit_draft',
                'billing.cash_memo.view_own', 'billing.cash_memo.create_draft', 'billing.cash_memo.edit_draft',
                'billing.estimate.view_own', 'billing.estimate.create_draft', 'billing.estimate.edit_draft',
                'calendar.view',
            ],
        ],
        'crm_manager' => [
            'name' => 'CRM Manager',
            'description' => 'All CRM data, assignments, team performance and Sheets sync status. No bank details or salaries.',
            'perms' => [
                'dashboard.view', 'dashboard.crm_kpis',
                'contacts.view_billing', 'contacts.create',
                'services.view',
                'crm.lead.view_assigned', 'crm.lead.view_team', 'crm.lead.view_all', 'crm.lead.create', 'crm.lead.edit', 'crm.lead.assign', 'crm.lead.delete', 'crm.lead.import', 'crm.lead.convert',
                'crm.call.create', 'crm.followup.manage', 'crm.visit.view', 'crm.visit.assign', 'crm.visit.update_outcome', 'crm.meeting.manage',
                'crm.task.view_own', 'crm.task.view_all', 'crm.task.create', 'crm.task.assign',
                'crm.export', 'crm.reports.view_own', 'crm.reports.view_team', 'crm.settings.manage', 'crm.sheets.view',
                'billing.estimate.view_own', 'billing.estimate.create_draft', 'billing.estimate.edit_draft',
                'calendar.view',
            ],
        ],
        'telecaller' => [
            'name' => 'Telecaller',
            'description' => 'Own assigned leads, calls, follow-ups, meetings, visits, tasks and own stats only.',
            'perms' => [
                'dashboard.view',
                'crm.lead.view_assigned', 'crm.lead.create', 'crm.lead.edit',
                'crm.call.create', 'crm.followup.manage', 'crm.visit.view', 'crm.visit.assign', 'crm.meeting.manage',
                'crm.task.view_own', 'crm.task.create', 'crm.task.assign',
                'crm.reports.view_own',
                'self.profile.view', 'self.attendance.view', 'self.leave.request', 'self.payslip.view', 'self.incentive.view',
                'calendar.view',
            ],
        ],
        'sales_executive' => [
            'name' => 'Sales Executive',
            'description' => 'Assigned leads, visits, meetings and quotations.',
            'perms' => [
                'dashboard.view',
                'contacts.view_billing',
                'services.view',
                'crm.lead.view_assigned', 'crm.lead.create', 'crm.lead.edit', 'crm.lead.convert',
                'crm.call.create', 'crm.followup.manage', 'crm.visit.view', 'crm.visit.assign', 'crm.visit.update_outcome', 'crm.meeting.manage',
                'crm.task.view_own', 'crm.task.create', 'crm.task.assign', 'crm.reports.view_own',
                'billing.estimate.view_own', 'billing.estimate.create_draft', 'billing.estimate.edit_draft', 'billing.estimate.finalize', 'billing.estimate.print', 'billing.estimate.download',
                'self.profile.view', 'self.attendance.view', 'self.leave.request', 'self.payslip.view', 'self.incentive.view',
                'calendar.view',
            ],
        ],
        'hr_manager' => [
            'name' => 'HR Manager',
            'description' => 'Recruitment, employees, attendance, leave, incentives and payroll.',
            'perms' => [
                'dashboard.view', 'dashboard.hr_kpis', 'dashboard.payroll_kpis',
                'hr.vacancy.view', 'hr.vacancy.manage',
                'hr.candidate.view', 'hr.candidate.view_all', 'hr.candidate.create', 'hr.candidate.edit', 'hr.candidate.score', 'hr.candidate.decide', 'hr.candidate.export', 'hr.candidate.convert',
                'hr.timeline.create', 'hr.interview.manage',
                'hr.employee.view_basic', 'hr.employee.view_all', 'hr.employee.manage', 'hr.employee.private_docs', 'hr.employee.bank_view',
                'hr.salary.view', 'hr.salary.manage', 'hr.salary.export',
                'hr.attendance.view_all', 'hr.attendance.mark', 'hr.attendance.correct',
                'hr.leave.view_all', 'hr.leave.approve', 'hr.leave.manage_types', 'hr.holiday.manage',
                'hr.incentive.view_all', 'hr.incentive.policy_manage', 'hr.incentive.approve',
                'hr.payroll.view', 'hr.payroll.calculate', 'hr.payroll.finalize', 'hr.payroll.pay', 'hr.payroll.adjust', 'hr.payroll.statutory', 'hr.payslip.view_all',
                'reports.payroll.view', 'reports.export',
                'self.profile.view', 'self.attendance.view', 'self.leave.request', 'self.payslip.view', 'self.incentive.view',
                'calendar.view',
            ],
        ],
        'hr_recruiter' => [
            'name' => 'HR Recruiter',
            'description' => 'JDs, assigned applicants, interviews, candidate timeline and scoped AI CV review. No finance, CRM or salary.',
            'perms' => [
                'dashboard.view', 'dashboard.hr_kpis',
                'hr.vacancy.view', 'hr.vacancy.manage',
                'hr.candidate.view', 'hr.candidate.create', 'hr.candidate.edit', 'hr.candidate.score', 'hr.candidate.decide',
                'hr.timeline.create', 'hr.interview.manage',
                'hr.employee.view_basic',
                'self.profile.view', 'self.attendance.view', 'self.leave.request', 'self.payslip.view',
                'calendar.view',
            ],
        ],
        'payroll_manager' => [
            'name' => 'Payroll Manager',
            'description' => 'Attendance, approved incentives, payroll calculation and payslips. No billing/CRM.',
            'perms' => [
                'dashboard.view', 'dashboard.payroll_kpis',
                'hr.employee.view_basic', 'hr.employee.view_all', 'hr.employee.bank_view',
                'hr.salary.view', 'hr.salary.manage', 'hr.salary.export',
                'hr.attendance.view_all', 'hr.attendance.mark', 'hr.attendance.correct',
                'hr.leave.view_all', 'hr.incentive.view_all',
                'hr.payroll.view', 'hr.payroll.calculate', 'hr.payroll.finalize', 'hr.payroll.pay', 'hr.payroll.adjust', 'hr.payslip.view_all',
                'reports.payroll.view', 'reports.export',
                'self.profile.view', 'self.attendance.view', 'self.leave.request', 'self.payslip.view',
                'calendar.view',
            ],
        ],
        'employee' => [
            'name' => 'Employee',
            'description' => 'Own attendance, leaves, payslips and assigned tasks only.',
            'perms' => [
                'dashboard.view',
                'crm.task.view_own',
                'self.profile.view', 'self.attendance.view', 'self.attendance.checkin', 'self.leave.request', 'self.payslip.view', 'self.incentive.view',
                'calendar.view',
            ],
        ],
        'auditor' => [
            'name' => 'Auditor (Read Only)',
            'description' => 'Time-limited read-only access to selected registers and audit trails. Assign with an expiry date.',
            'perms' => [
                'dashboard.view',
                'billing.invoice.view_all', 'billing.cash_memo.view_all', 'billing.note.view_all', 'billing.invoice.download', 'billing.cash_memo.download',
                'finance.receipt.view', 'finance.ledger.view', 'finance.transfer.view', 'finance.expense.view_all',
                'reports.billing.view', 'reports.finance.view', 'reports.gst.view', 'reports.audit.view',
                'security.audit.view',
            ],
        ],
    ];

    /**
     * Separation-of-duties pairs: holding both triggers an admin warning
     * (not a hard block — small agencies often need one person for both).
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    public array $sodWarnings = [
        ['finance.expense.create', 'finance.expense.approve', 'Same user can record and approve expenses'],
        ['hr.payroll.calculate', 'hr.payroll.finalize', 'Same user can calculate and finalize payroll'],
        ['hr.salary.manage', 'hr.payroll.pay', 'Same user can change salary and pay salary'],
        ['finance.receipt.create', 'finance.bank.reconcile', 'Same user can record receipts and confirm reconciliation'],
        ['security.roles.manage', 'security.audit.view', 'Role manager can also read the audit trail of their own changes'],
    ];

    /** @return array<string, array{module: string, description: string, danger: bool, scope: string|null}> */
    public function flat(): array
    {
        $out = [];
        foreach ($this->modules as $module => $def) {
            foreach ($def['perms'] as $key => $meta) {
                $flags     = $meta[1] ?? [];
                $out[$key] = [
                    'module'      => $module,
                    'description' => $meta[0],
                    'danger'      => (bool) ($flags['danger'] ?? false),
                    'scope'       => $flags['scope'] ?? null,
                ];
            }
        }

        return $out;
    }
}
