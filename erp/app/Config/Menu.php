<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Left sidebar. An item is shown when the user holds ANY of its perms.
 * Hiding is cosmetic: every route enforces the same keys server-side.
 */
class Menu extends BaseConfig
{
    /** @var list<array{label: string, icon: string, items: list<array{label: string, url: string, icon?: string, perms: list<string>}>}> */
    public array $sections = [
        ['label' => 'Overview', 'icon' => 'speedometer2', 'items' => [
            ['label' => 'Dashboard', 'url' => '/', 'icon' => 'speedometer2', 'perms' => ['dashboard.view']],
        ]],
        ['label' => 'Billing', 'icon' => 'receipt', 'items' => [
            ['label' => 'Create Document', 'url' => 'billing/documents/new', 'icon' => 'plus-square', 'perms' => ['billing.invoice.create_draft', 'billing.cash_memo.create_draft', 'billing.estimate.create_draft', 'billing.note.create_draft']],
            ['label' => 'Invoices', 'url' => 'billing/documents?type=tax_invoice', 'icon' => 'file-earmark-text', 'perms' => ['billing.invoice.view_own', 'billing.invoice.view_all']],
            ['label' => 'Cash Memos', 'url' => 'billing/documents?type=cash_memo', 'icon' => 'cash-coin', 'perms' => ['billing.cash_memo.view_own', 'billing.cash_memo.view_all']],
            ['label' => 'Receipt Vouchers', 'url' => 'finance/receipts', 'icon' => 'receipt-cutoff', 'perms' => ['finance.receipt.view']],
            ['label' => 'Credit / Debit Notes', 'url' => 'billing/documents?type=credit_note', 'icon' => 'arrow-counterclockwise', 'perms' => ['billing.note.view_all']],
            ['label' => 'Estimates / Proforma', 'url' => 'billing/documents?type=estimate', 'icon' => 'clipboard-check', 'perms' => ['billing.estimate.view_own', 'billing.estimate.view_all']],
            ['label' => 'Recurring Retainers', 'url' => 'billing/recurring', 'icon' => 'arrow-repeat', 'perms' => ['billing.recurring.manage']],
        ]],
        ['label' => 'Finance', 'icon' => 'bank', 'items' => [
            ['label' => 'Payments & Receipts', 'url' => 'finance/receipts', 'icon' => 'wallet2', 'perms' => ['finance.receipt.view']],
            ['label' => 'Bank / Cash Accounts', 'url' => 'finance/accounts', 'icon' => 'bank', 'perms' => ['finance.bank.view']],
            ['label' => 'Deposits / Transfers', 'url' => 'finance/transfers', 'icon' => 'arrow-left-right', 'perms' => ['finance.transfer.view']],
            ['label' => 'Bank Reconciliation', 'url' => 'finance/reconciliation', 'icon' => 'check2-square', 'perms' => ['finance.bank.reconcile', 'finance.bank.import']],
            ['label' => 'Expenses', 'url' => 'finance/expenses', 'icon' => 'bag', 'perms' => ['finance.expense.view_own', 'finance.expense.view_all']],
            ['label' => 'Journal / Ledger', 'url' => 'finance/ledger', 'icon' => 'journal-text', 'perms' => ['finance.ledger.view']],
            ['label' => 'GST', 'url' => 'reports/gst', 'icon' => 'percent', 'perms' => ['reports.gst.view']],
            ['label' => 'Reports', 'url' => 'reports', 'icon' => 'bar-chart-line', 'perms' => ['reports.billing.view', 'reports.finance.view', 'reports.gst.view', 'reports.payroll.view', 'reports.audit.view', 'reports.history.view']],
        ]],
        ['label' => 'Clients & Vendors', 'icon' => 'people', 'items' => [
            ['label' => 'Clients & Vendors', 'url' => 'contacts', 'icon' => 'person-vcard', 'perms' => ['contacts.view_all']],
            ['label' => 'Services Catalogue', 'url' => 'services', 'icon' => 'grid', 'perms' => ['services.view']],
        ]],
        ['label' => 'CRM', 'icon' => 'telephone', 'items' => [
            ['label' => 'My Day', 'url' => 'crm/today', 'icon' => 'sun', 'perms' => ['crm.lead.view_assigned', 'crm.lead.view_team', 'crm.lead.view_all']],
            ['label' => 'Leads', 'url' => 'crm/leads', 'icon' => 'person-lines-fill', 'perms' => ['crm.lead.view_assigned', 'crm.lead.view_team', 'crm.lead.view_all']],
            ['label' => 'Pipeline', 'url' => 'crm/pipeline', 'icon' => 'kanban', 'perms' => ['crm.lead.view_assigned', 'crm.lead.view_team', 'crm.lead.view_all']],
            ['label' => 'Calls', 'url' => 'crm/calls', 'icon' => 'telephone-outbound', 'perms' => ['crm.lead.view_assigned', 'crm.lead.view_team', 'crm.lead.view_all']],
            ['label' => 'Follow-ups', 'url' => 'crm/followups', 'icon' => 'alarm', 'perms' => ['crm.followup.manage']],
            ['label' => 'Visits', 'url' => 'crm/visits', 'icon' => 'geo-alt', 'perms' => ['crm.visit.view']],
            ['label' => 'Meetings', 'url' => 'crm/meetings', 'icon' => 'calendar-event', 'perms' => ['crm.meeting.manage']],
            ['label' => 'Tasks', 'url' => 'crm/tasks', 'icon' => 'list-check', 'perms' => ['crm.task.view_own', 'crm.task.view_all']],
            ['label' => 'Performance', 'url' => 'crm/performance', 'icon' => 'graph-up-arrow', 'perms' => ['crm.reports.view_own', 'crm.reports.view_team']],
            ['label' => 'Google Sheets Sync', 'url' => 'crm/sync', 'icon' => 'cloud-arrow-up', 'perms' => ['crm.sheets.view', 'crm.sheets.manage']],
        ]],
        ['label' => 'HR', 'icon' => 'person-badge', 'items' => [
            ['label' => 'Vacancies / JDs', 'url' => 'hr/vacancies', 'icon' => 'briefcase', 'perms' => ['hr.vacancy.view']],
            ['label' => 'Applicants', 'url' => 'hr/candidates', 'icon' => 'person-plus', 'perms' => ['hr.candidate.view', 'hr.candidate.view_all']],
            ['label' => 'Shortlisting Board', 'url' => 'hr/candidates/board', 'icon' => 'kanban', 'perms' => ['hr.candidate.view', 'hr.candidate.view_all']],
            ['label' => 'Interviews', 'url' => 'hr/interviews', 'icon' => 'chat-square-text', 'perms' => ['hr.interview.manage']],
            ['label' => 'Employees', 'url' => 'hr/employees', 'icon' => 'people', 'perms' => ['hr.employee.view_basic', 'hr.employee.view_all']],
            ['label' => 'Attendance', 'url' => 'hr/attendance', 'icon' => 'calendar-check', 'perms' => ['hr.attendance.view_all', 'hr.attendance.mark']],
            ['label' => 'Leaves', 'url' => 'hr/leaves', 'icon' => 'calendar-x', 'perms' => ['hr.leave.view_all', 'hr.leave.approve']],
            ['label' => 'Holidays', 'url' => 'hr/holidays', 'icon' => 'calendar-heart', 'perms' => ['hr.holiday.manage']],
            ['label' => 'Incentives', 'url' => 'hr/incentives', 'icon' => 'trophy', 'perms' => ['hr.incentive.view_all', 'hr.incentive.policy_manage']],
            ['label' => 'Payroll', 'url' => 'hr/payroll', 'icon' => 'cash-stack', 'perms' => ['hr.payroll.view']],
        ]],
        ['label' => 'My Workspace', 'icon' => 'person-circle', 'items' => [
            ['label' => 'My Workspace', 'url' => 'me', 'icon' => 'person-circle', 'perms' => ['self.profile.view', 'self.attendance.view', 'self.leave.request', 'self.payslip.view']],
            ['label' => 'Calendar & Reminders', 'url' => 'calendar', 'icon' => 'calendar3', 'perms' => ['calendar.view']],
        ]],
        ['label' => 'Administration', 'icon' => 'shield-lock', 'items' => [
            ['label' => 'Users', 'url' => 'admin/users', 'icon' => 'person-gear', 'perms' => ['security.users.view', 'security.users.manage']],
            ['label' => 'Roles & Permissions', 'url' => 'admin/roles', 'icon' => 'shield-check', 'perms' => ['security.roles.manage']],
            ['label' => 'Activity / Audit Logs', 'url' => 'admin/audit', 'icon' => 'clock-history', 'perms' => ['security.audit.view']],
            ['label' => 'AI Permissions', 'url' => 'admin/ai', 'icon' => 'robot', 'perms' => ['ai.permissions.manage', 'ai.usage.view']],
            ['label' => 'Diagnostics', 'url' => 'admin/diagnostics', 'icon' => 'activity', 'perms' => ['security.diagnostics.view']],
        ]],
        ['label' => 'Settings', 'icon' => 'gear', 'items' => [
            ['label' => 'Billing Setup Wizard', 'url' => 'settings/setup', 'icon' => 'magic', 'perms' => ['settings.company.edit']],
            ['label' => 'Company Profile', 'url' => 'settings/company', 'icon' => 'building', 'perms' => ['settings.company.view', 'settings.company.edit']],
            ['label' => 'Branding Assets', 'url' => 'settings/branding', 'icon' => 'image', 'perms' => ['settings.branding.edit']],
            ['label' => 'Bank & Payment Profiles', 'url' => 'settings/banks', 'icon' => 'credit-card-2-front', 'perms' => ['settings.bank.view_masked', 'settings.bank.edit']],
            ['label' => 'Document Series', 'url' => 'settings/sequences', 'icon' => '123', 'perms' => ['settings.sequences.manage']],
            ['label' => 'Tax Rates & Treatments', 'url' => 'settings/tax', 'icon' => 'percent', 'perms' => ['settings.tax.edit']],
            ['label' => 'Invoice Templates', 'url' => 'settings/templates', 'icon' => 'layout-text-window', 'perms' => ['settings.templates.edit']],
            ['label' => 'Signature Policy', 'url' => 'settings/signature-policy', 'icon' => 'pen', 'perms' => ['settings.signature_policy.manage']],
            ['label' => 'Integrations', 'url' => 'settings/integrations', 'icon' => 'plug', 'perms' => ['settings.integrations.manage']],
            ['label' => 'Master Lists', 'url' => 'settings/masters', 'icon' => 'list-ul', 'perms' => ['settings.app.edit', 'crm.settings.manage', 'finance.expense.manage_categories', 'hr.leave.manage_types']],
        ]],
    ];
}
