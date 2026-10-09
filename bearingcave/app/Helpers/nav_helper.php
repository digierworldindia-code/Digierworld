<?php

declare(strict_types=1);

/**
 * Dashboard navigation per area. Items the user's plan does not include
 * are shown with a lock and lead to the membership page (discoverability
 * without dead links). Admin items are filtered by Shield permissions.
 */
if (! function_exists('dashboard_menu')) {
    function dashboard_menu(string $area): array
    {
        $company = current_company();
        $lock    = static fn (string $feature) => ! entitled($feature, $company);

        if ($area === 'buyer') {
            return [
                'Overview' => [
                    ['Dashboard', 'buyer/dashboard', 'bi-grid-1x2'],
                    ['Marketplace search', 'marketplace', 'bi-search'],
                    ['Saved inventory', 'buyer/saved', 'bi-bookmark'],
                    ['Comparison', 'compare', 'bi-layout-three-columns'],
                ],
                'Procurement' => [
                    ['RFQs & quotations', 'buyer/rfqs', 'bi-file-earmark-text'],
                    ['Enquiries', 'buyer/enquiries', 'bi-chat-left-text'],
                    ['Cart / RFQ basket', 'buyer/cart', 'bi-cart3'],
                    ['Orders', 'buyer/orders', 'bi-box-seam'],
                    ['Invoices & payments', 'buyer/billing', 'bi-receipt'],
                ],
                'Services' => [
                    ['Inspection', 'buyer/inspections', 'bi-clipboard-check'],
                    ['Logistics', 'buyer/logistics', 'bi-truck'],
                    ['Disputes', 'buyer/disputes', 'bi-shield-exclamation'],
                ],
                'Company' => [
                    ['Company profile', 'buyer/company', 'bi-building'],
                    ['Verification', 'buyer/verification', 'bi-patch-check'],
                    ['Documents', 'buyer/documents', 'bi-folder2'],
                    ['Team', 'buyer/team', 'bi-people'],
                    ['Notifications', 'notifications', 'bi-bell'],
                    ['Account settings', 'account', 'bi-gear'],
                ],
            ];
        }

        if ($area === 'supplier') {
            return [
                'Overview' => [
                    ['Dashboard', 'supplier/dashboard', 'bi-grid-1x2'],
                    ['Analytics & performance', 'supplier/analytics', 'bi-graph-up', $lock('advanced_analytics')],
                ],
                'Inventory' => [
                    ['Inventory list', 'supplier/products', 'bi-boxes'],
                    ['Add inventory', 'supplier/products/new', 'bi-plus-square'],
                    ['Bulk upload', 'supplier/imports', 'bi-file-earmark-spreadsheet', $lock('bulk_import')],
                    ['Country visibility', 'supplier/visibility', 'bi-globe2', $lock('country_visibility_control')],
                ],
                'Sales' => [
                    ['Product enquiries', 'supplier/enquiries', 'bi-chat-left-text'],
                    ['RFQ leads & bidding', 'supplier/rfqs', 'bi-megaphone', $lock('premium_rfq_leads')],
                    ['My quotations', 'supplier/quotations', 'bi-file-earmark-text', $lock('supplier_bidding')],
                    ['Orders', 'supplier/orders', 'bi-box-seam'],
                    ['Logistics', 'supplier/logistics', 'bi-truck'],
                    ['Inspection', 'supplier/inspections', 'bi-clipboard-check'],
                    ['Disputes', 'supplier/disputes', 'bi-shield-exclamation'],
                ],
                'Company' => [
                    ['Company profile', 'supplier/company', 'bi-building'],
                    ['Verification', 'supplier/verification', 'bi-patch-check'],
                    ['Membership', 'supplier/membership', 'bi-award'],
                    ['Invoices & payments', 'supplier/billing', 'bi-receipt'],
                    ['Documents', 'supplier/documents', 'bi-folder2'],
                    ['Account manager', 'supplier/account-manager', 'bi-person-badge', $lock('dedicated_account_manager')],
                    ['Team', 'supplier/team', 'bi-people'],
                    ['Notifications', 'notifications', 'bi-bell'],
                    ['Account settings', 'account', 'bi-gear'],
                ],
            ];
        }

        $u   = auth()->user();
        $can = static fn (string $p) => $u !== null && $u->can($p);

        $menu = [
            'Overview' => [
                ['Dashboard', 'admin', 'bi-speedometer2', false, true],
                ['Reports & analytics', 'admin/reports', 'bi-bar-chart', false, $can('reports.view')],
                ['Performance scores', 'admin/performance', 'bi-graph-up-arrow', false, $can('reports.view')],
            ],
            'Accounts' => [
                ['Users', 'admin/users', 'bi-person', false, $can('users.view')],
                ['Buyers', 'admin/companies?type=buyer', 'bi-bag', false, $can('companies.view')],
                ['Suppliers', 'admin/companies?type=supplier', 'bi-building', false, $can('companies.view')],
                ['Staff & roles', 'admin/staff', 'bi-person-gear', false, $can('staff.manage')],
            ],
            'Verification' => [
                ['Verification queue', 'admin/verifications', 'bi-patch-check', false, $can('verification.view')],
                ['Approved Vendor List', 'admin/avl', 'bi-list-check', false, $can('avl.manage')],
                ['Supplier master data', 'admin/supplier-master', 'bi-table', false, $can('imports.manage')],
            ],
            'Catalog' => [
                ['Product review', 'admin/products', 'bi-box', false, $can('products.review')],
                ['Categories', 'admin/categories', 'bi-diagram-3', false, $can('catalog.manage')],
                ['Brands', 'admin/brands', 'bi-tags', false, $can('catalog.manage')],
                ['Inventory movements', 'admin/inventory', 'bi-arrow-left-right', false, $can('inventory.manage')],
                ['Imports', 'admin/imports', 'bi-upload', false, $can('imports.manage')],
            ],
            'Procurement' => [
                ['RFQs & matching', 'admin/rfqs', 'bi-megaphone', false, $can('rfq.view')],
                ['Quotations / bids', 'admin/quotations', 'bi-file-earmark-text', false, $can('rfq.view')],
                ['Orders', 'admin/orders', 'bi-box-seam', false, $can('orders.view')],
                ['Inspection', 'admin/inspections', 'bi-clipboard-check', false, $can('inspection.manage')],
                ['Logistics', 'admin/logistics', 'bi-truck', false, $can('logistics.manage')],
                ['Disputes', 'admin/disputes', 'bi-shield-exclamation', false, $can('disputes.view')],
            ],
            'Finance' => [
                ['Invoices', 'admin/invoices', 'bi-receipt', false, $can('payments.view')],
                ['Payments & escrow', 'admin/payments', 'bi-credit-card', false, $can('payments.view')],
                ['Membership plans', 'admin/memberships', 'bi-award', false, $can('memberships.manage')],
                ['Subscriptions', 'admin/subscriptions', 'bi-calendar-check', false, $can('subscriptions.manage')],
            ],
            'Platform' => [
                ['CMS pages', 'admin/cms', 'bi-file-richtext', false, $can('cms.manage')],
                ['SEO settings', 'admin/seo', 'bi-globe', false, $can('seo.manage')],
                ['Email outbox', 'admin/notifications', 'bi-envelope', false, $can('notifications.manage')],
                ['Platform settings', 'admin/settings', 'bi-sliders', false, $can('settings.manage')],
                ['Contact leads', 'admin/leads', 'bi-inbox', false, $can('leads.view')],
                ['Audit logs', 'admin/audit', 'bi-journal-text', false, $can('audit.view')],
            ],
        ];
        foreach ($menu as $section => $items) {
            $menu[$section] = array_values(array_filter($items, static fn ($i) => $i[4]));
            if ($menu[$section] === []) {
                unset($menu[$section]);
            }
        }

        return $menu;
    }
}

if (! function_exists('nav_active')) {
    function nav_active(string $path): bool
    {
        $current = trim(uri_string(), '/');
        $path    = trim(strtok($path, '?'), '/');
        if ($path === 'admin' || $path === '') {
            return $current === $path;
        }

        return $current === $path || str_starts_with($current . '/', $path . '/');
    }
}
