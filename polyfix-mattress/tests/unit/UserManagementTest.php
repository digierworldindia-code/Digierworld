<?php

namespace Tests\Unit;

use App\Exceptions\AppException;
use App\Services\UserService;
use Tests\Support\PolyfixTestCase;

/**
 * Creating and changing accounts — where privilege escalation would start if
 * the rules were not enforced in one place.
 *
 * @internal
 */
final class UserManagementTest extends PolyfixTestCase
{
    private UserService $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserService($this->db);
    }

    public function testAnAccountIsCreatedWithAOneTimePasswordItMustChange(): void
    {
        $this->actingAs($this->makeUser(['SUPER_ADMIN']));

        $created = $this->users->create([
            'email' => 'new.person@polyfixmattress.test', 'full_name' => 'New Person', 'roles' => ['WAREHOUSE'],
        ], ['SUPER_ADMIN']);

        $row = $this->db->table('users')->where('id', $created['id'])->get()->getRowArray();
        $this->assertSame('new.person@polyfixmattress.test', $row['email']);
        $this->assertSame(1, (int) $row['must_change_password']);
        $this->assertStringStartsWith('$argon2id$', $row['password_hash']);
        $this->assertGreaterThanOrEqual(12, strlen($created['temporary_password']));
        $this->assertSame(['WAREHOUSE'], $this->users->rolesOf($created['id']));
    }

    public function testTwoAccountsNeverGetTheSameTemporaryPassword(): void
    {
        $this->actingAs($this->makeUser(['SUPER_ADMIN']));

        $first  = $this->users->create(['email' => 'one@polyfixmattress.test', 'full_name' => 'One', 'roles' => ['REPORTING']], ['SUPER_ADMIN']);
        $second = $this->users->create(['email' => 'two@polyfixmattress.test', 'full_name' => 'Two', 'roles' => ['REPORTING']], ['SUPER_ADMIN']);

        $this->assertNotSame($first['temporary_password'], $second['temporary_password'], 'there is no universal password');
    }

    public function testNobodyCanCreateAnAccountSeniorToTheirOwn(): void
    {
        $this->actingAs($this->makeUser(['WAREHOUSE']));

        $this->expectExceptionMessage('senior to your own');
        $this->users->create(['email' => 'escalate@polyfixmattress.test', 'full_name' => 'Escalation', 'roles' => ['SUPER_ADMIN']], ['WAREHOUSE']);
    }

    public function testADealerLoginMustBelongToADealershipAndHoldNoStaffRole(): void
    {
        $this->actingAs($this->makeUser(['ADMIN']));
        $dealerId = $this->makeDealer();

        try {
            $this->users->create(['email' => 'lonely@polyfixmattress.test', 'full_name' => 'No Dealership', 'roles' => ['DEALER']], ['ADMIN']);
            $this->fail('a dealer login with no dealership should be refused');
        } catch (AppException $e) {
            $this->assertStringContainsString('linked to a dealer', $e->getMessage());
        }

        try {
            $this->users->create([
                'email' => 'both@polyfixmattress.test', 'full_name' => 'Both Hats', 'roles' => ['DEALER', 'ADMIN'], 'dealer_id' => $dealerId,
            ], ['ADMIN']);
            $this->fail('a dealer login must not also hold a staff role');
        } catch (AppException $e) {
            $this->assertStringContainsString('separate account', $e->getMessage());
        }

        $created = $this->users->create([
            'email' => 'dealer.login@polyfixmattress.test', 'full_name' => 'Dealer Login', 'roles' => ['DEALER'], 'dealer_id' => $dealerId,
        ], ['ADMIN']);
        $this->assertSame(1, $this->db->table('dealer_users')->where(['user_id' => $created['id'], 'dealer_id' => $dealerId])->countAllResults());
    }

    public function testAnEmailAddressIsUsedOnce(): void
    {
        $this->actingAs($this->makeUser(['ADMIN']));
        $this->users->create(['email' => 'taken@polyfixmattress.test', 'full_name' => 'First', 'roles' => ['REPORTING']], ['ADMIN']);

        $this->expectExceptionMessage('already exists');
        $this->users->create(['email' => 'TAKEN@polyfixmattress.test', 'full_name' => 'Second', 'roles' => ['REPORTING']], ['ADMIN']);
    }

    public function testNobodyCanChangeTheirOwnRoles(): void
    {
        $adminId = $this->makeUser(['ADMIN']);
        $this->actingAs($adminId);

        $this->expectExceptionMessage('your own roles');
        $this->users->assignRoles($adminId, ['SUPER_ADMIN'], 'Promoting myself', ['ADMIN']);
    }

    public function testChangingRolesEndsTheAccountsSessions(): void
    {
        $this->actingAs($this->makeUser(['SUPER_ADMIN']));
        $targetId = $this->makeUser(['REPORTING']);
        $this->insert('sessions', [
            'id' => uuid4(), 'user_id' => $targetId, 'token_hash' => hash('sha256', 'x'), 'ip' => '127.0.0.1',
            'created_at' => utc_now(), 'last_seen_at' => utc_now(), 'absolute_expiry' => gmdate('Y-m-d H:i:s', time() + 3600) . '.000000',
        ]);

        $this->users->assignRoles($targetId, ['WAREHOUSE'], 'Moved to the warehouse team', ['SUPER_ADMIN']);

        $this->assertSame(['WAREHOUSE'], $this->users->rolesOf($targetId));
        $this->assertSame(0, $this->db->table('sessions')->where(['user_id' => $targetId, 'revoked_at' => null])->countAllResults(),
            'the change takes effect immediately, not at the next sign-in');
    }

    public function testAJuniorAccountCannotResetASeniorOnesPassword(): void
    {
        $this->actingAs($this->makeUser(['SALES_MANAGER']));
        $seniorId = $this->makeUser(['SUPER_ADMIN']);

        $this->expectExceptionMessage('more senior than your own');
        $this->users->forcePasswordReset($seniorId, 'Trying it on', ['SALES_MANAGER']);
    }

    public function testAnAdministrativeResetIssuesAOneTimePasswordAndEndsSessions(): void
    {
        $this->actingAs($this->makeUser(['SUPER_ADMIN']));
        $targetId = $this->makeUser(['WAREHOUSE']);
        $before   = $this->db->table('users')->select('password_hash')->where('id', $targetId)->get()->getRow()->password_hash;

        $password = $this->users->forcePasswordReset($targetId, 'Lost the password', ['SUPER_ADMIN']);

        $row = $this->db->table('users')->where('id', $targetId)->get()->getRowArray();
        $this->assertNotSame($before, $row['password_hash']);
        $this->assertSame(1, (int) $row['must_change_password']);
        $this->assertTrue(password_verify($password, $row['password_hash']));
        $this->assertSame(0, (int) $row['failed_login_count']);
    }

    public function testClearingTwoFactorLetsSomeoneEnrolANewPhone(): void
    {
        $this->actingAs($this->makeUser(['SUPER_ADMIN']));
        $targetId = $this->makeUser(['ADMIN'], null, ['mfa_enabled' => 1, 'mfa_secret_encrypted' => 'v1.x', 'mfa_enrolled_at' => utc_now()]);

        $this->users->resetMfa($targetId, 'Phone replaced', ['SUPER_ADMIN']);

        $row = $this->db->table('users')->where('id', $targetId)->get()->getRowArray();
        $this->assertSame(0, (int) $row['mfa_enabled']);
        $this->assertNull($row['mfa_secret_encrypted']);
        $this->assertNull($row['mfa_recovery_codes']);
    }

    public function testSuspendingAnAccountEndsItsSessions(): void
    {
        $this->actingAs($this->makeUser(['SUPER_ADMIN']));
        $targetId = $this->makeUser(['WAREHOUSE']);
        $this->insert('sessions', [
            'id' => uuid4(), 'user_id' => $targetId, 'token_hash' => hash('sha256', 'y'), 'ip' => '127.0.0.1',
            'created_at' => utc_now(), 'last_seen_at' => utc_now(), 'absolute_expiry' => gmdate('Y-m-d H:i:s', time() + 3600) . '.000000',
        ]);

        $this->users->update($targetId, ['full_name' => 'Test Person', 'status' => 'SUSPENDED'], ['SUPER_ADMIN']);

        $this->assertSame('SUSPENDED', $this->db->table('users')->select('status')->where('id', $targetId)->get()->getRow()->status);
        $this->assertSame(0, $this->db->table('sessions')->where(['user_id' => $targetId, 'revoked_at' => null])->countAllResults());
    }

    public function testEveryAccountChangeIsRecorded(): void
    {
        $this->actingAs($this->makeUser(['SUPER_ADMIN']));
        $created = $this->users->create(['email' => 'audited@polyfixmattress.test', 'full_name' => 'Audited', 'roles' => ['REPORTING']], ['SUPER_ADMIN']);
        $this->users->assignRoles($created['id'], ['WAREHOUSE'], 'Team change', ['SUPER_ADMIN']);
        $this->users->forcePasswordReset($created['id'], 'Forgotten password', ['SUPER_ADMIN']);

        $actions = array_column($this->db->table('audit_logs')->select('action')->where('entity_id', $created['id'])->get()->getResultArray(), 'action');
        $this->assertContains('USER_CREATED', $actions);
        $this->assertContains('USER_ROLES_CHANGED', $actions);
        $this->assertContains('USER_PASSWORD_RESET_BY_ADMIN', $actions);

        // The password itself is never in the trail.
        $entries = $this->db->table('audit_logs')->where('entity_id', $created['id'])->get()->getResultArray();
        foreach ($entries as $entry) {
            $this->assertStringNotContainsString($created['temporary_password'], (string) $entry['new_value']);
        }
    }
}
