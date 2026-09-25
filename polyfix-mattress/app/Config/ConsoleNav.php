<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Admin console navigation. An entry appears only when the signed-in user
 * holds its permission — but hiding a link is a courtesy, not the control:
 * every route checks the same permission in its filter (Config\Routes).
 */
class ConsoleNav extends BaseConfig
{
    /** @var array<string, list<array{0:string, 1:string, 2:string, 3:string}>> group => [label, path, permission, icon] */
    public array $groups = [
        'Overview' => [
            ['Dashboard', 'admin', 'dashboard:view', 'bi-speedometer2'],
            ['Notifications', 'admin/notifications', 'dashboard:view', 'bi-bell'],
        ],
        'Operations' => [
            ['Products', 'admin/products', 'product:read', 'bi-grid'],
            ['Mattresses', 'admin/mattresses', 'mattress:read', 'bi-upc-scan'],
            ['Batches', 'admin/batches', 'batch:read', 'bi-boxes'],
            ['Dispatches', 'admin/dispatches', 'dispatch:read', 'bi-truck'],
        ],
        'Warranty' => [
            ['Claims', 'admin/claims', 'claim:read', 'bi-clipboard2-pulse'],
            ['Warranties', 'admin/warranties', 'warranty:read', 'bi-shield-check'],
            ['Replacements', 'admin/replacements', 'claim:read', 'bi-arrow-repeat'],
        ],
        'Network' => [
            ['Dealers', 'admin/dealers', 'dealer:read', 'bi-shop'],
            ['Dealer inventory', 'admin/mattresses?status=DEALER_RECEIVED', 'mattress:read', 'bi-box-seam'],
            ['Applications', 'admin/applications', 'dealer_application:read', 'bi-person-plus'],
            ['Sales', 'admin/sales', 'sale:read', 'bi-receipt'],
            ['Customers', 'admin/customers', 'customer:read', 'bi-people'],
            ['Leads', 'admin/leads', 'lead:read', 'bi-envelope'],
        ],
        'Insight' => [
            ['Reports', 'admin/reports', 'report:view', 'bi-bar-chart'],
        ],
        'Website' => [
            ['Content', 'admin/content', 'cms:read', 'bi-layout-text-window'],
            ['SEO', 'admin/seo', 'seo:read', 'bi-search'],
        ],
        'Administration' => [
            ['Users', 'admin/users', 'user:read', 'bi-person-badge'],
            ['Roles & permissions', 'admin/roles', 'user:read', 'bi-key'],
            ['Audit log', 'admin/audit', 'audit:read', 'bi-journal-check'],
            ['System', 'admin/system', 'system:health', 'bi-heart-pulse'],
            ['Settings', 'admin/settings', 'system:settings:read', 'bi-gear'],
        ],
    ];
}
