<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Where a user lands after signing in. Each role stores its landing page in
 * roles.landing_url (Administration › Roles); the choices are the area
 * dashboards below plus every page in the menu, each with the permissions
 * needed to open it. App\Libraries\Landing never sends a user to a page
 * their roles cannot open.
 */
class Landing extends BaseConfig
{
    /**
     * Area dashboards (path => label + any-of permissions). '' is the owner
     * dashboard at "/", which shows every widget the user's permissions allow.
     *
     * @var array<string, array{label: string, perms: list<string>}>
     */
    public array $areas = [
        ''                  => ['label' => 'Owner dashboard (all permitted widgets)', 'perms' => ['dashboard.view']],
        'dashboard/billing' => ['label' => 'Billing dashboard', 'perms' => [
            'billing.invoice.view_own', 'billing.invoice.view_all', 'billing.cash_memo.view_own', 'billing.cash_memo.view_all',
            'billing.estimate.view_own', 'billing.estimate.view_all', 'billing.note.view_all',
        ]],
        'crm/today'         => ['label' => 'CRM dashboard — My Day (telecallers)', 'perms' => ['crm.lead.view_assigned', 'crm.lead.view_team', 'crm.lead.view_all']],
        'dashboard/hr'      => ['label' => 'HR dashboard', 'perms' => [
            'dashboard.hr_kpis', 'dashboard.payroll_kpis', 'hr.employee.view_basic', 'hr.employee.view_all',
            'hr.attendance.view_all', 'hr.leave.view_all', 'hr.leave.approve', 'hr.candidate.view', 'hr.candidate.view_all', 'hr.payroll.view',
        ]],
    ];

    /**
     * Landing page of each starter role (set once by migration when the role
     * has none; the owner can change it). Roles not listed use the owner
     * dashboard if they may open it, otherwise their first menu page.
     *
     * @var array<string, string>
     */
    public array $starterDefaults = [
        'super_admin'      => '',
        'accountant'       => '',
        'auditor'          => '',
        'crm_manager'      => '',
        'billing_operator' => 'dashboard/billing',
        'telecaller'       => 'crm/today',
        'sales_executive'  => 'crm/today',
        'hr_manager'       => 'dashboard/hr',
        'hr_recruiter'     => 'dashboard/hr',
        'payroll_manager'  => 'dashboard/hr',
        'employee'         => 'me',
    ];
}
