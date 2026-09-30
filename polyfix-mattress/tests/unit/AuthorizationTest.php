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

    /**
     * These five used to be granted only to a session that had satisfied a
     * second factor. They are now granted by role, like everything else, so
     * an administrator who cannot get a code accepted does not quietly lose
     * the ability to assign roles or change settings.
     */
    public function testTheMostSensitiveAbilitiesAreGrantedByRoleAlone(): void
    {
        $this->actingAs($this->makeUser(['SUPER_ADMIN']));
        $context = service('requestContext');

        foreach (['system:backup', 'system:export', 'system:sql:read', 'system:settings:write', 'user:role:assign'] as $permission) {
            $this->assertTrue($context->can($permission), $permission . ' must be granted by the role alone');
        }
    }

    public function testALesserRoleStillCannotReachThem(): void
    {
        $this->actingAs($this->makeUser(['REPORTING']));
        $context = service('requestContext');

        foreach (['system:settings:write', 'user:role:assign'] as $permission) {
            $this->assertFalse($context->can($permission), $permission . ' must still be refused without the role');
        }
    }

    /** The gate itself is gone, so nothing can reintroduce it by accident. */
    public function testThereIsNoSecondFactorGateLeftInAuthorisation(): void
    {
        $this->assertFalse(defined(Rbac::class . '::MFA_GATED'), 'the gated-permission list is removed');
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
