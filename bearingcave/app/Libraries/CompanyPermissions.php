<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Permissions that a company owner/admin can grant to employees.
 * Owners and company admins implicitly hold every permission.
 */
final class CompanyPermissions
{
    public const ALL = [
        'company.profile'      => 'Edit company profile',
        'company.members'      => 'Manage team members',
        'company.verification' => 'Manage verification documents',
        'company.billing'      => 'View membership, invoices and payments',
        'inventory.manage'     => 'Manage inventory and imports (suppliers)',
        'rfq.manage'           => 'Create RFQs / submit quotations',
        'orders.manage'        => 'Place, confirm and manage orders',
        'services.manage'      => 'Request inspection and logistics services',
        'disputes.manage'      => 'Raise and respond to disputes',
    ];

    public static function keys(): array
    {
        return array_keys(self::ALL);
    }
}
