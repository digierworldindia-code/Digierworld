<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Shield\Config\AuthGroups as ShieldAuthGroups;

/**
 * BearingCave platform roles.
 *
 * Two layers of authorization are used:
 *  1. Shield groups/permissions below for *platform* roles (staff) and the two
 *     marketplace account types (buyer, supplier).
 *  2. Company-level state (verification status + active membership plan) and
 *     company-member permissions decide what a marketplace user may do. Those
 *     are resolved by App\Services\EntitlementService and
 *     App\Services\CompanyContext, never hard-coded in controllers.
 *
 * "Unverified/Verified Buyer", "Unverified/Verified Supplier" and
 * "Company Employee" are therefore derived roles; see docs/05-permissions-matrix.md.
 */
class AuthGroups extends ShieldAuthGroups
{
    public string $defaultGroup = 'buyer';

    public array $groups = [
        'superadmin' => ['title' => 'Super Admin', 'description' => 'Complete control of the BearingCave platform.'],
        'verification_officer' => ['title' => 'Verification Officer', 'description' => 'Reviews KYC/KYB, compliance, risk and sanctions screening.'],
        'procurement_manager' => ['title' => 'Procurement Manager', 'description' => 'Manages catalog review, RFQs, supplier matching and orders.'],
        'inspection_manager' => ['title' => 'Inspection Manager', 'description' => 'Quotes, assigns and completes inspection requests.'],
        'logistics_manager' => ['title' => 'Logistics Manager', 'description' => 'Quotes and manages shipments.'],
        'finance_manager' => ['title' => 'Finance Manager', 'description' => 'Invoices, payments, memberships and subscriptions.'],
        'account_manager' => ['title' => 'Account Manager', 'description' => 'Dedicated contact for assigned verified suppliers.'],
        'support_executive' => ['title' => 'Customer Support Executive', 'description' => 'Handles disputes, enquiries and contact leads.'],
        'buyer' => ['title' => 'Buyer', 'description' => 'Marketplace buyer account (verification state lives on the company).'],
        'supplier' => ['title' => 'Supplier', 'description' => 'Marketplace supplier account (verification + membership live on the company).'],
    ];

    /**
     * Staff groups (used to route users to the admin panel).
     *
     * @var list<string>
     */
    public array $staffGroups = [
        'superadmin', 'verification_officer', 'procurement_manager', 'inspection_manager',
        'logistics_manager', 'finance_manager', 'account_manager', 'support_executive',
    ];

    public array $permissions = [
        'admin.access'        => 'Access the admin panel',
        'users.view'          => 'View user accounts',
        'users.manage'        => 'Activate/deactivate users and reset access',
        'staff.manage'        => 'Create staff users and assign roles',
        'companies.view'      => 'View buyer and supplier companies',
        'companies.manage'    => 'Suspend/reactivate companies, assign account managers',
        'verification.view'   => 'View verification applications',
        'verification.review' => 'Review verification stages (KYC, compliance, risk, quality, audit, sanctions)',
        'verification.approve' => 'Give final management approval / rejection',
        'kyc.sensitive'       => 'View decrypted bank and identity numbers',
        'avl.manage'          => 'Manage the Approved Vendor List',
        'catalog.manage'      => 'Manage categories and brands',
        'products.review'     => 'Approve/reject product listings',
        'inventory.manage'    => 'View and adjust any supplier inventory',
        'imports.manage'      => 'Import inventory or supplier master data on behalf of suppliers',
        'rfq.view'            => 'View RFQs and quotations',
        'rfq.manage'          => 'Review, match and distribute RFQs',
        'orders.view'         => 'View orders',
        'orders.manage'       => 'Update order status',
        'inspection.manage'   => 'Manage inspection requests and reports',
        'logistics.manage'    => 'Manage logistics requests and shipments',
        'payments.view'       => 'View invoices and payments',
        'payments.record'     => 'Record manual payments and manage gateway settings',
        'memberships.manage'  => 'Manage membership plans and entitlements',
        'subscriptions.manage' => 'Manage supplier subscriptions',
        'performance.manage'  => 'Recalculate supplier performance and buyer scores',
        'disputes.view'       => 'View disputes',
        'disputes.manage'     => 'Assign and resolve disputes',
        'reports.view'        => 'View reports and analytics',
        'reports.export'      => 'Export reports to CSV/Excel',
        'cms.manage'          => 'Manage CMS pages',
        'seo.manage'          => 'Manage SEO settings',
        'notifications.manage' => 'View email outbox and notification settings',
        'settings.manage'     => 'Manage platform settings and business rules',
        'audit.view'          => 'View audit logs',
        'leads.view'          => 'View contact form leads',
    ];

    public array $matrix = [
        'superadmin' => [
            'admin.*', 'users.*', 'staff.*', 'companies.*', 'verification.*', 'kyc.*', 'avl.*', 'catalog.*',
            'products.*', 'inventory.*', 'imports.*', 'rfq.*', 'orders.*', 'inspection.*', 'logistics.*',
            'payments.*', 'memberships.*', 'subscriptions.*', 'performance.*', 'disputes.*', 'reports.*',
            'cms.*', 'seo.*', 'notifications.*', 'settings.*', 'audit.*', 'leads.*',
        ],
        'verification_officer' => [
            'admin.access', 'companies.view', 'verification.view', 'verification.review', 'kyc.sensitive', 'avl.manage', 'reports.view',
        ],
        'procurement_manager' => [
            'admin.access', 'companies.view', 'catalog.manage', 'products.review', 'inventory.manage', 'imports.manage',
            'rfq.view', 'rfq.manage', 'orders.view', 'orders.manage', 'avl.manage', 'reports.view', 'performance.manage',
        ],
        'inspection_manager' => ['admin.access', 'companies.view', 'orders.view', 'inspection.manage'],
        'logistics_manager'  => ['admin.access', 'companies.view', 'orders.view', 'logistics.manage'],
        'finance_manager'    => [
            'admin.access', 'companies.view', 'orders.view', 'payments.view', 'payments.record', 'memberships.manage',
            'subscriptions.manage', 'reports.view', 'reports.export',
        ],
        'account_manager' => ['admin.access', 'companies.view', 'verification.view', 'rfq.view', 'orders.view', 'performance.manage', 'reports.view'],
        'support_executive' => ['admin.access', 'companies.view', 'orders.view', 'disputes.view', 'disputes.manage', 'leads.view'],
        'buyer'    => [],
        'supplier' => [],
    ];
}
