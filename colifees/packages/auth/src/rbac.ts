/**
 * Role-based access control catalogue.
 *
 * This file is the single source of truth for "who may do what". The database
 * `permissions` / `role_permissions` tables are seeded from it, the API's
 * `requirePermission` guard checks against it, and the console renders its
 * navigation from the permissions the signed-in user actually holds.
 *
 * Two rules that the rest of the codebase depends on:
 *   1. A permission grants an *action*, never a *scope*. Holding `sale:read`
 *      does not mean "read every sale" — dealer scoping is applied separately
 *      and unconditionally (see `resolveDealerScope` and the database's Row
 *      Level Security policies).
 *   2. Anything not listed is denied. There is no wildcard at request time;
 *      SUPER_ADMIN is expanded to the explicit permission list at seed time.
 */

export const ROLE_KEYS = [
  'SUPER_ADMIN',
  'ADMIN',
  'WAREHOUSE',
  'WARRANTY_MANAGER',
  'SALES_MANAGER',
  'DEALER',
  'REPORTING',
] as const;

export type RoleKey = (typeof ROLE_KEYS)[number];

export interface PermissionDefinition {
  key: string;
  group: string;
  description: string;
}

export const PERMISSIONS = [
  { key: 'dashboard:view', group: 'general', description: 'Open the console and see role-appropriate dashboard' },

  { key: 'product:read', group: 'catalogue', description: 'View products and variants' },
  { key: 'product:write', group: 'catalogue', description: 'Create and edit products and variants' },
  { key: 'product:publish', group: 'catalogue', description: 'Publish or unpublish a product on the public website' },
  { key: 'product:delete', group: 'catalogue', description: 'Soft-delete a product (requires reason)' },

  { key: 'batch:read', group: 'manufacturing', description: 'View manufacturing batches' },
  { key: 'batch:write', group: 'manufacturing', description: 'Create and edit manufacturing batches' },
  { key: 'mattress:read', group: 'manufacturing', description: 'View mattress records and lifecycle history' },
  { key: 'mattress:create', group: 'manufacturing', description: 'Generate mattress serials and QR codes' },
  { key: 'mattress:delete', group: 'manufacturing', description: 'Soft-delete a mattress record (requires reason)' },

  { key: 'dispatch:read', group: 'logistics', description: 'View dispatches' },
  { key: 'dispatch:create', group: 'logistics', description: 'Create a dispatch to a dealer' },
  { key: 'dispatch:update', group: 'logistics', description: 'Edit or send a dispatch' },
  { key: 'dispatch:cancel', group: 'logistics', description: 'Cancel a dispatch (requires reason)' },
  { key: 'receipt:create', group: 'logistics', description: 'Confirm receipt of a dispatch at a dealer' },
  { key: 'receipt:read', group: 'logistics', description: 'View dealer receipts' },

  { key: 'dealer:read', group: 'network', description: 'View dealers' },
  { key: 'dealer:write', group: 'network', description: 'Create and edit dealers' },
  { key: 'dealer:suspend', group: 'network', description: 'Suspend or terminate a dealer' },
  { key: 'dealer_application:read', group: 'network', description: 'View dealership applications' },
  { key: 'dealer_application:review', group: 'network', description: 'Approve, reject or request information on applications' },

  { key: 'customer:read', group: 'sales', description: 'View customer records' },
  { key: 'customer:write', group: 'sales', description: 'Create and edit customer records' },
  { key: 'sale:read', group: 'sales', description: 'View sales' },
  { key: 'sale:create', group: 'sales', description: 'Record a sale and activate warranty' },
  { key: 'sale:void', group: 'sales', description: 'Void a sale (requires reason)' },

  { key: 'warranty:read', group: 'warranty', description: 'View warranty records' },
  { key: 'warranty:void', group: 'warranty', description: 'Void a warranty (requires reason)' },
  { key: 'claim:read', group: 'warranty', description: 'View warranty claims' },
  { key: 'claim:create', group: 'warranty', description: 'Raise a warranty claim' },
  { key: 'claim:review', group: 'warranty', description: 'Move a claim through review and request information' },
  { key: 'claim:decide', group: 'warranty', description: 'Approve or reject a warranty claim' },
  { key: 'claim:replace', group: 'warranty', description: 'Issue a replacement mattress against an approved claim' },
  { key: 'claim:media:view', group: 'warranty', description: 'View claim photographs and invoices' },
  { key: 'risk:view', group: 'warranty', description: 'See fraud risk indicators on claims and dealers' },

  { key: 'lead:read', group: 'marketing', description: 'View website contact leads' },
  { key: 'lead:update', group: 'marketing', description: 'Update lead status and notes' },
  { key: 'cms:read', group: 'marketing', description: 'View website content' },
  { key: 'cms:write', group: 'marketing', description: 'Edit website pages, FAQs and product content' },
  { key: 'cms:publish', group: 'marketing', description: 'Publish website content changes' },
  { key: 'seo:read', group: 'marketing', description: 'View SEO settings' },
  { key: 'seo:write', group: 'marketing', description: 'Edit SEO titles, descriptions, robots and sitemap settings' },

  { key: 'report:view', group: 'analytics', description: 'View reports and analytics' },
  { key: 'report:export', group: 'analytics', description: 'Export report data' },

  { key: 'user:read', group: 'administration', description: 'View platform users' },
  { key: 'user:write', group: 'administration', description: 'Create and edit platform users' },
  { key: 'user:role:assign', group: 'administration', description: 'Grant or revoke roles' },
  { key: 'user:reset_password', group: 'administration', description: 'Force a password reset for another user' },
  { key: 'audit:read', group: 'administration', description: 'Read the audit trail' },

  { key: 'system:settings:read', group: 'system', description: 'View system settings' },
  { key: 'system:settings:write', group: 'system', description: 'Change system settings' },
  { key: 'system:health', group: 'system', description: 'View system and database health' },
  { key: 'system:backup', group: 'system', description: 'Trigger a database backup' },
  { key: 'system:export', group: 'system', description: 'Export the database' },
  { key: 'system:sql:read', group: 'system', description: 'Run read-only SQL from the technical console (MFA required)' },
] as const satisfies readonly PermissionDefinition[];

export type PermissionKey = (typeof PERMISSIONS)[number]['key'];

const ALL_PERMISSIONS = PERMISSIONS.map((p) => p.key) as PermissionKey[];

/**
 * Permissions that are never granted by a role assignment alone. They
 * additionally require the holder to have MFA enabled and satisfied on the
 * current session.
 */
export const MFA_GATED_PERMISSIONS: PermissionKey[] = [
  'system:backup',
  'system:export',
  'system:sql:read',
  'system:settings:write',
  'user:role:assign',
];

export const ROLE_PERMISSIONS: Record<RoleKey, PermissionKey[]> = {
  SUPER_ADMIN: ALL_PERMISSIONS,

  ADMIN: [
    'dashboard:view',
    'product:read', 'product:write', 'product:publish', 'product:delete',
    'batch:read', 'batch:write',
    'mattress:read', 'mattress:create',
    'dispatch:read', 'dispatch:create', 'dispatch:update', 'dispatch:cancel',
    'receipt:read',
    'dealer:read', 'dealer:write', 'dealer:suspend',
    'dealer_application:read', 'dealer_application:review',
    'customer:read',
    'sale:read', 'sale:void',
    'warranty:read', 'warranty:void',
    'claim:read', 'claim:review', 'claim:decide', 'claim:replace', 'claim:media:view',
    'risk:view',
    'lead:read', 'lead:update',
    'cms:read', 'cms:write', 'cms:publish',
    'seo:read', 'seo:write',
    'report:view', 'report:export',
    'user:read', 'user:write', 'user:reset_password',
    'audit:read',
    'system:settings:read', 'system:health',
  ],

  WAREHOUSE: [
    'dashboard:view',
    'product:read',
    'batch:read', 'batch:write',
    'mattress:read', 'mattress:create',
    'dispatch:read', 'dispatch:create', 'dispatch:update',
    'receipt:read',
    'dealer:read',
    'report:view',
  ],

  WARRANTY_MANAGER: [
    'dashboard:view',
    'product:read',
    'mattress:read',
    'dealer:read',
    'customer:read',
    'sale:read',
    'warranty:read', 'warranty:void',
    'claim:read', 'claim:review', 'claim:decide', 'claim:replace', 'claim:media:view',
    'risk:view',
    'dispatch:read', 'dispatch:create', 'dispatch:update',
    'report:view',
  ],

  SALES_MANAGER: [
    'dashboard:view',
    'product:read',
    'mattress:read',
    'dealer:read', 'dealer:write',
    'dealer_application:read',
    'customer:read',
    'sale:read',
    'warranty:read',
    'claim:read',
    'lead:read', 'lead:update',
    'report:view', 'report:export',
  ],

  // A dealer holds ordinary action permissions; the *scope* of every one of
  // them is narrowed to their own dealer by the request pipeline and by
  // PostgreSQL Row Level Security.
  DEALER: [
    'dashboard:view',
    'product:read',
    'mattress:read',
    'receipt:create', 'receipt:read',
    'dispatch:read',
    'customer:read', 'customer:write',
    'sale:read', 'sale:create',
    'warranty:read',
    'claim:read', 'claim:create', 'claim:media:view',
  ],

  REPORTING: [
    'dashboard:view',
    'product:read',
    'mattress:read',
    'dealer:read',
    'sale:read',
    'warranty:read',
    'claim:read',
    'report:view', 'report:export',
  ],
};

export const ROLE_METADATA: Record<RoleKey, { name: string; rank: number; description: string }> = {
  SUPER_ADMIN: { name: 'Super Admin', rank: 10, description: 'Complete system access including database tools' },
  ADMIN: { name: 'Admin', rank: 20, description: 'Operational management across the business' },
  WARRANTY_MANAGER: { name: 'Warranty Manager', rank: 30, description: 'Warranty claims, verification and replacements' },
  SALES_MANAGER: { name: 'Sales Manager', rank: 30, description: 'Dealer performance, sales analytics and leads' },
  WAREHOUSE: { name: 'Warehouse', rank: 40, description: 'Manufacturing, stock and dispatch operations' },
  DEALER: { name: 'Dealer', rank: 60, description: 'Own inventory, sales and warranty claims only' },
  REPORTING: { name: 'Reporting', rank: 70, description: 'Read-only access for reporting and analysis' },
};

export function permissionsForRoles(roles: readonly string[]): PermissionKey[] {
  const set = new Set<PermissionKey>();
  for (const role of roles) {
    const perms = ROLE_PERMISSIONS[role as RoleKey];
    if (perms) for (const p of perms) set.add(p);
  }
  return [...set];
}

export function hasPermission(held: readonly string[], required: PermissionKey): boolean {
  return held.includes(required);
}

export function hasAllPermissions(held: readonly string[], required: readonly PermissionKey[]): boolean {
  return required.every((r) => held.includes(r));
}

export function hasAnyPermission(held: readonly string[], required: readonly PermissionKey[]): boolean {
  return required.some((r) => held.includes(r));
}

export function isDealerRole(roles: readonly string[]): boolean {
  return roles.includes('DEALER');
}

/** True when this role may only ever act inside one dealer's data. */
export function requiresDealerScope(roles: readonly string[]): boolean {
  return roles.length > 0 && roles.every((r) => r === 'DEALER');
}

export function requiresMfa(roles: readonly string[], configuredRoles: readonly string[]): boolean {
  return roles.some((r) => configuredRoles.includes(r));
}
