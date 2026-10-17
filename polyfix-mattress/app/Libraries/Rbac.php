<?php

namespace App\Libraries;

/**
 * Role-based access control: who may do what.
 *
 * Generated from the previous platform's catalogue, so every role keeps exactly
 * the permissions it had. The database's permissions and role_permissions
 * tables hold the same catalogue (imported); this class is what the filters
 * and the navigation check against.
 *
 * Two rules the rest of the application depends on:
 *   1. A permission grants an ACTION, never a SCOPE. A dealer holding sale:read
 *      reads their own sales only. Dealer isolation is applied separately and
 *      unconditionally (see DealerScope).
 *   2. Anything not listed is denied. There is no wildcard at request time;
 *      SUPER_ADMIN simply holds every permission explicitly.
 */
final class Rbac
{
    public const ROLE_KEYS = ['SUPER_ADMIN', 'ADMIN', 'WAREHOUSE', 'WARRANTY_MANAGER', 'SALES_MANAGER', 'FIELD_AGENT', 'DEALER', 'REPORTING'];

    /**
     * Roles whose users are POLYFIZZ staff rather than a dealership.
     *
     * A field agent is staff — they are employed by the company and visit
     * dealers — but they do not work in the admin console. isStaff() answers
     * "not a dealer"; CONSOLE_ROLES below answers "may open /admin".
     */
    public const STAFF_ROLES = ['SUPER_ADMIN', 'ADMIN', 'WAREHOUSE', 'WARRANTY_MANAGER', 'SALES_MANAGER', 'FIELD_AGENT', 'REPORTING'];

    /**
     * Roles that land in the admin console at sign-in.
     *
     * A warehouse operator and a field agent each have their own panel, built
     * for the job and the device they do it on. Sending them to /admin would
     * put them in front of a screen they do not need and cannot mostly use.
     */
    public const CONSOLE_ROLES = ['SUPER_ADMIN', 'ADMIN', 'WARRANTY_MANAGER', 'SALES_MANAGER', 'REPORTING'];

    /** Roles whose home is the warehouse panel. */
    public const WAREHOUSE_ROLES = ['WAREHOUSE'];

    /** Roles whose home is the field-agent panel. */
    public const AGENT_ROLES = ['FIELD_AGENT'];

    /** @var array<string, array{group:string, description:string}> */
    public const PERMISSIONS = [
        'dashboard:view' => ['group' => 'general', 'description' => 'Open the console and see role-appropriate dashboard'],
        'product:read' => ['group' => 'catalogue', 'description' => 'View products and variants'],
        'product:write' => ['group' => 'catalogue', 'description' => 'Create and edit products and variants'],
        'product:publish' => ['group' => 'catalogue', 'description' => 'Publish or unpublish a product on the public website'],
        'product:delete' => ['group' => 'catalogue', 'description' => 'Soft-delete a product (requires reason)'],
        'batch:read' => ['group' => 'manufacturing', 'description' => 'View manufacturing batches'],
        'batch:write' => ['group' => 'manufacturing', 'description' => 'Create and edit manufacturing batches'],
        'mattress:read' => ['group' => 'manufacturing', 'description' => 'View mattress records and lifecycle history'],
        'mattress:create' => ['group' => 'manufacturing', 'description' => 'Generate mattress serials and QR codes'],
        'mattress:delete' => ['group' => 'manufacturing', 'description' => 'Soft-delete a mattress record (requires reason)'],
        'dispatch:read' => ['group' => 'logistics', 'description' => 'View dispatches'],
        'dispatch:create' => ['group' => 'logistics', 'description' => 'Create a dispatch to a dealer'],
        'dispatch:update' => ['group' => 'logistics', 'description' => 'Edit or send a dispatch'],
        'dispatch:cancel' => ['group' => 'logistics', 'description' => 'Cancel a dispatch (requires reason)'],
        'receipt:create' => ['group' => 'logistics', 'description' => 'Confirm receipt of a dispatch at a dealer'],
        'receipt:read' => ['group' => 'logistics', 'description' => 'View dealer receipts'],
        'dealer:read' => ['group' => 'network', 'description' => 'View dealers'],
        'dealer:write' => ['group' => 'network', 'description' => 'Create and edit dealers'],
        'dealer:suspend' => ['group' => 'network', 'description' => 'Suspend or terminate a dealer'],
        'dealer_application:read' => ['group' => 'network', 'description' => 'View dealership applications'],
        'dealer_application:review' => ['group' => 'network', 'description' => 'Approve, reject or request information on applications'],
        'customer:read' => ['group' => 'sales', 'description' => 'View customer records'],
        'customer:write' => ['group' => 'sales', 'description' => 'Create and edit customer records'],
        'sale:read' => ['group' => 'sales', 'description' => 'View sales'],
        'sale:create' => ['group' => 'sales', 'description' => 'Record a sale and activate warranty'],
        'sale:void' => ['group' => 'sales', 'description' => 'Void a sale (requires reason)'],
        'warranty:read' => ['group' => 'warranty', 'description' => 'View warranty records'],
        'warranty:void' => ['group' => 'warranty', 'description' => 'Void a warranty (requires reason)'],
        'claim:read' => ['group' => 'warranty', 'description' => 'View warranty claims'],
        'claim:create' => ['group' => 'warranty', 'description' => 'Raise a warranty claim'],
        'claim:review' => ['group' => 'warranty', 'description' => 'Move a claim through review and request information'],
        'claim:decide' => ['group' => 'warranty', 'description' => 'Approve or reject a warranty claim'],
        'claim:replace' => ['group' => 'warranty', 'description' => 'Issue a replacement mattress against an approved claim'],
        'claim:media:view' => ['group' => 'warranty', 'description' => 'View claim photographs and invoices'],
        'risk:view' => ['group' => 'warranty', 'description' => 'See fraud risk indicators on claims and dealers'],
        'lead:read' => ['group' => 'marketing', 'description' => 'View website contact leads'],
        'lead:update' => ['group' => 'marketing', 'description' => 'Update lead status and notes'],
        'cms:read' => ['group' => 'marketing', 'description' => 'View website content'],
        'cms:write' => ['group' => 'marketing', 'description' => 'Edit website pages, FAQs and product content'],
        'cms:publish' => ['group' => 'marketing', 'description' => 'Publish website content changes'],
        'seo:read' => ['group' => 'marketing', 'description' => 'View SEO settings'],
        'seo:write' => ['group' => 'marketing', 'description' => 'Edit SEO titles, descriptions, robots and sitemap settings'],
        'report:view' => ['group' => 'analytics', 'description' => 'View reports and analytics'],
        'report:export' => ['group' => 'analytics', 'description' => 'Export report data'],
        'user:read' => ['group' => 'administration', 'description' => 'View platform users'],
        'user:write' => ['group' => 'administration', 'description' => 'Create and edit platform users'],
        'user:role:assign' => ['group' => 'administration', 'description' => 'Grant or revoke roles'],
        'user:reset_password' => ['group' => 'administration', 'description' => 'Force a password reset for another user'],
        'audit:read' => ['group' => 'administration', 'description' => 'Read the audit trail'],
        'survey:read' => ['group' => 'survey', 'description' => 'View dealer stock surveys and their reports'],
        'survey:conduct' => ['group' => 'survey', 'description' => 'Start a survey at an assigned dealer and record findings'],
        'survey:assign' => ['group' => 'survey', 'description' => 'Assign dealers to field agents and set the survey schedule'],
        'discrepancy:read' => ['group' => 'survey', 'description' => 'View stock discrepancies raised by a survey'],
        'discrepancy:resolve' => ['group' => 'survey', 'description' => 'Resolve or dismiss a stock discrepancy'],
        'warranty_review:recommend' => ['group' => 'survey', 'description' => 'Recommend that a warranty be reviewed for rejection'],
        'warranty_review:read' => ['group' => 'survey', 'description' => 'View warranty review cases'],
        'warranty_review:decide' => ['group' => 'survey', 'description' => 'Uphold or dismiss a warranty rejection recommendation'],
        'warehouse:scan' => ['group' => 'manufacturing', 'description' => 'Scan units into production and onto a dispatch'],
        'system:settings:read' => ['group' => 'system', 'description' => 'View system settings'],
        'system:settings:write' => ['group' => 'system', 'description' => 'Change system settings'],
        'system:health' => ['group' => 'system', 'description' => 'View system and database health'],
        'system:backup' => ['group' => 'system', 'description' => 'Trigger a database backup'],
        'system:export' => ['group' => 'system', 'description' => 'Export the database'],
        'system:sql:read' => ['group' => 'system', 'description' => 'Run read-only SQL from the technical console'],
    ];

    /** @var array<string, list<string>> */
    public const ROLE_PERMISSIONS = [
        'SUPER_ADMIN' => [
            'dashboard:view', 'product:read', 'product:write', 'product:publish',
            'product:delete', 'batch:read', 'batch:write', 'mattress:read',
            'mattress:create', 'mattress:delete', 'dispatch:read', 'dispatch:create',
            'dispatch:update', 'dispatch:cancel', 'receipt:create', 'receipt:read',
            'dealer:read', 'dealer:write', 'dealer:suspend', 'dealer_application:read',
            'dealer_application:review', 'customer:read', 'customer:write', 'sale:read',
            'sale:create', 'sale:void', 'warranty:read', 'warranty:void',
            'claim:read', 'claim:create', 'claim:review', 'claim:decide',
            'claim:replace', 'claim:media:view', 'risk:view', 'lead:read',
            'lead:update', 'cms:read', 'cms:write', 'cms:publish',
            'seo:read', 'seo:write', 'report:view', 'report:export',
            'user:read', 'user:write', 'user:role:assign', 'user:reset_password',
            'audit:read', 'survey:read', 'survey:conduct', 'survey:assign',
            'discrepancy:read', 'discrepancy:resolve', 'warranty_review:recommend', 'warranty_review:read',
            'warranty_review:decide', 'warehouse:scan', 'system:settings:read', 'system:settings:write',
            'system:health', 'system:backup', 'system:export', 'system:sql:read',
        ],
        'ADMIN' => [
            'dashboard:view', 'product:read', 'product:write', 'product:publish',
            'product:delete', 'batch:read', 'batch:write', 'mattress:read',
            'mattress:create', 'dispatch:read', 'dispatch:create', 'dispatch:update',
            'dispatch:cancel', 'receipt:read', 'dealer:read', 'dealer:write',
            'dealer:suspend', 'dealer_application:read', 'dealer_application:review', 'customer:read',
            'sale:read', 'sale:void', 'warranty:read', 'warranty:void',
            'claim:read', 'claim:review', 'claim:decide', 'claim:replace',
            'claim:media:view', 'risk:view', 'lead:read', 'lead:update',
            'cms:read', 'cms:write', 'cms:publish', 'seo:read',
            'seo:write', 'report:view', 'report:export', 'user:read',
            'user:write', 'user:reset_password', 'audit:read', 'survey:read',
            'survey:assign', 'discrepancy:read', 'discrepancy:resolve', 'warranty_review:read',
            'warranty_review:decide', 'warehouse:scan', 'system:settings:read', 'system:health',
        ],
        'WAREHOUSE' => [
            'dashboard:view', 'product:read', 'batch:read', 'batch:write',
            'mattress:read', 'mattress:create', 'dispatch:read', 'dispatch:create',
            'dispatch:update', 'receipt:read', 'dealer:read', 'report:view',
            'warehouse:scan',
        ],
        'WARRANTY_MANAGER' => [
            'dashboard:view', 'product:read', 'mattress:read', 'dealer:read',
            'customer:read', 'sale:read', 'warranty:read', 'warranty:void',
            'claim:read', 'claim:review', 'claim:decide', 'claim:replace',
            'claim:media:view', 'risk:view', 'dispatch:read', 'dispatch:create',
            'dispatch:update', 'report:view', 'survey:read', 'discrepancy:read',
            'discrepancy:resolve', 'warranty_review:read', 'warranty_review:decide',
        ],
        'SALES_MANAGER' => [
            'dashboard:view', 'product:read', 'mattress:read', 'dealer:read',
            'dealer:write', 'dealer_application:read', 'customer:read', 'sale:read',
            'warranty:read', 'claim:read', 'lead:read', 'lead:update',
            'report:view', 'report:export', 'survey:read', 'discrepancy:read',
            'warranty_review:read',
        ],
        'FIELD_AGENT' => [
            'dashboard:view', 'product:read', 'mattress:read', 'dealer:read',
            'customer:read', 'sale:read', 'warranty:read', 'dispatch:read',
            'survey:read', 'survey:conduct', 'discrepancy:read', 'warranty_review:recommend',
            'warranty_review:read',
        ],
        'DEALER' => [
            'dashboard:view', 'product:read', 'mattress:read', 'receipt:create',
            'receipt:read', 'dispatch:read', 'customer:read', 'customer:write',
            'sale:read', 'sale:create', 'warranty:read', 'claim:read',
            'claim:create', 'claim:media:view', 'survey:read', 'warranty_review:read',
        ],
        'REPORTING' => [
            'dashboard:view', 'product:read', 'mattress:read', 'dealer:read',
            'sale:read', 'warranty:read', 'claim:read', 'report:view',
            'report:export', 'survey:read', 'discrepancy:read', 'warranty_review:read',
        ],
    ];

    /** @var array<string, array{name:string, rank:int, description:string}> */
    public const ROLE_METADATA = [
        'SUPER_ADMIN' => ['name' => 'Super Admin', 'rank' => 10, 'description' => 'Complete system access including database tools'],
        'ADMIN' => ['name' => 'Admin', 'rank' => 20, 'description' => 'Operational management across the business'],
        'WARRANTY_MANAGER' => ['name' => 'Warranty Manager', 'rank' => 30, 'description' => 'Warranty claims, verification and replacements'],
        'SALES_MANAGER' => ['name' => 'Sales Manager', 'rank' => 30, 'description' => 'Dealer performance, sales analytics and leads'],
        'WAREHOUSE' => ['name' => 'Warehouse', 'rank' => 40, 'description' => 'Manufacturing, stock and dispatch operations'],
        'FIELD_AGENT' => ['name' => 'Field Agent', 'rank' => 50, 'description' => 'Visits assigned dealers and verifies physical stock'],
        'DEALER' => ['name' => 'Dealer', 'rank' => 60, 'description' => 'Own inventory, sales and warranty claims only'],
        'REPORTING' => ['name' => 'Reporting', 'rank' => 70, 'description' => 'Read-only access for reporting and analysis'],
    ];

    /**
     * @param list<string> $roles
     *
     * @return list<string>
     */
    public static function permissionsForRoles(array $roles): array
    {
        $set = [];
        foreach ($roles as $role) {
            foreach (self::ROLE_PERMISSIONS[$role] ?? [] as $permission) {
                $set[$permission] = true;
            }
        }

        return array_keys($set);
    }

    /** @param list<string> $roles */
    public static function isStaff(array $roles): bool
    {
        return array_intersect($roles, self::STAFF_ROLES) !== [];
    }

    public static function roleName(string $role): string
    {
        return self::ROLE_METADATA[$role]['name'] ?? $role;
    }

    /**
     * The panel an account belongs in: 'admin', 'dealer', 'warehouse' or
     * 'agent'. Used to land somebody after sign-in, and by the filters that
     * keep each panel to the people it was built for.
     *
     * The console is checked first, so an administrator who also holds the
     * warehouse role lands in the console and walks to the panel from there,
     * rather than the other way round.
     *
     * @param list<string> $roles
     */
    public static function homeArea(array $roles, bool $isDealer): string
    {
        if ($isDealer) {
            return 'dealer';
        }
        if (array_intersect($roles, self::CONSOLE_ROLES) !== []) {
            return 'admin';
        }
        if (array_intersect($roles, self::WAREHOUSE_ROLES) !== []) {
            return 'warehouse';
        }
        if (array_intersect($roles, self::AGENT_ROLES) !== []) {
            return 'agent';
        }

        // A staff account with no panel role still has to go somewhere, and
        // the console refuses what it may not do, one route at a time.
        return 'admin';
    }
}
