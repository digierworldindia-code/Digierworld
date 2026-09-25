<?php

namespace Tests\Unit;

use App\Libraries\Rbac;
use Tests\Support\PolyfixTestCase;

/**
 * Who may do what.
 *
 * The permission map in code and the grant rows in the database must agree —
 * a drift between them is how a role quietly gains an ability nobody granted.
 *
 * @internal
 */
final class AuthorizationTest extends PolyfixTestCase
{
    public function testEveryRoleHoldsExactlyThePermissionsTheDatabaseGrants(): void
    {
        foreach (Rbac::ROLE_KEYS as $role) {
            $stored = array_column($this->db->table('role_permissions rp')
                ->select('p.key')->join('roles r', 'r.id = rp.role_id')->join('permissions p', 'p.id = rp.permission_id')
                ->where('r.key', $role)->get()->getResultArray(), 'key');

            sort($stored);
            $expected = Rbac::ROLE_PERMISSIONS[$role];
            sort($expected);

            $this->assertSame($expected, $stored, "the {$role} role's permissions differ between code and database");
        }
    }

    public function testEveryGrantedPermissionExists(): void
    {
        foreach (Rbac::ROLE_PERMISSIONS as $role => $permissions) {
            foreach ($permissions as $permission) {
                $this->assertArrayHasKey($permission, Rbac::PERMISSIONS, "{$role} is granted an unknown permission: {$permission}");
            }
        }
    }

    public function testADealerRoleCannotReachStaffAbilities(): void
    {
        $dealer = Rbac::ROLE_PERMISSIONS['DEALER'];

        foreach ([
            'mattress:create', 'dispatch:create', 'claim:decide', 'claim:review', 'claim:replace', 'warranty:void',
            'dealer:write', 'user:read', 'user:write', 'audit:read', 'report:view', 'system:settings:read', 'risk:view',
        ] as $staffOnly) {
            $this->assertNotContains($staffOnly, $dealer, "a dealer login must not hold {$staffOnly}");
        }
    }

    public function testReportingIsReadOnly(): void
    {
        foreach (Rbac::ROLE_PERMISSIONS['REPORTING'] as $permission) {
            $this->assertMatchesRegularExpression(
                '/:(read|view|export|health)$/',
                $permission,
                "the reporting role must not be able to change anything: {$permission}",
            );
        }
    }

    public function testSensitiveAbilitiesRequireTwoFactor(): void
    {
        foreach (['system:backup', 'system:export', 'system:sql:read', 'system:settings:write', 'user:role:assign'] as $permission) {
            $this->assertContains($permission, Rbac::MFA_GATED, "{$permission} must be behind two-factor authentication");
        }
    }

    public function testASessionWithoutTwoFactorCannotUseAGatedPermission(): void
    {
        $userId  = $this->makeUser(['SUPER_ADMIN'], null, ['mfa_enabled' => 1]);
        $this->actingAs($userId);
        $context = service('requestContext');

        $this->assertTrue($context->can('user:role:assign'), 'a session that passed two-factor may');

        $this->actingAs($userId, ['mfa_satisfied' => false]);
        $this->assertFalse($context->can('user:role:assign'), 'an unsatisfied session may not');
        $this->assertTrue($context->can('dealer:read'), 'ordinary permissions still work');
    }

    public function testStaffAndDealerAccountsAreToldApart(): void
    {
        $dealerId = $this->makeDealer();
        $this->actingAs($this->makeUser(['DEALER'], $dealerId));
        $this->assertTrue(service('requestContext')->isDealer());
        $this->assertFalse(service('requestContext')->isStaff());

        service('requestContext')->signOut();
        $this->actingAs($this->makeUser(['WAREHOUSE']));
        $this->assertTrue(service('requestContext')->isStaff());
        $this->assertFalse(service('requestContext')->isDealer());
    }

    public function testRoleSeniorityMatchesTheStoredRanks(): void
    {
        foreach (Rbac::ROLE_METADATA as $key => $meta) {
            $stored = $this->db->table('roles')->select('rank')->where('key', $key)->get()->getRow();
            $this->assertNotNull($stored, "role {$key} is missing from the database");
            $this->assertSame($meta['rank'], (int) $stored->rank, "the rank of {$key} differs between code and database");
        }
    }
}
