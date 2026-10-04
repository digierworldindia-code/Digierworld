<?php

namespace Tests\Database;

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\ValidationException;
use App\Libraries\Uuid;
use App\Core\Database;
use Tests\Support\DatabaseTestCase;

/**
 * Login, lockout, password policy, session revocation and replay protection.
 *
 * @internal
 */
final class AuthAndIdempotencyTest extends DatabaseTestCase
{
    public function testLoginFailuresAreGenericAndLockTheAccount(): void
    {
        $this->setSettings(['security.max_failed_logins' => '3', 'security.lockout_minutes' => '15']);
        $user = $this->createUser('OPERATOR', 'E1001', 'op.lock');
        $auth = service('auth');

        $unknown = $auth->attempt('nobody', 'whatever');
        $wrong   = $auth->attempt('op.lock', 'wrong');
        $this->assertFalse($unknown['ok']);
        $this->assertSame($unknown['message'], $wrong['message'], 'no hint whether the user exists');

        $auth->attempt('op.lock', 'wrong');
        $auth->attempt('op.lock', 'wrong'); // third failure locks
        $this->assertNotNull($this->db->table('users')->where('id', $user['id'])->get()->getRow('locked_until'));
        $this->assertFalse($auth->attempt('op.lock', 'Correct-Horse-91')['ok'], 'locked accounts cannot log in, even with the right password');
        $this->assertSame(1, $this->db->table('login_logs')->where('user_id', $user['id'])->where('event', 'ACCOUNT_LOCKED')->countAllResults());

        // After the lockout period the correct password works and resets the counter.
        $this->db->table('users')->where('id', $user['id'])->update(['locked_until' => gmdate('Y-m-d H:i:s', time() - 60)]);
        $this->assertTrue($auth->attempt('op.lock', 'Correct-Horse-91')['ok']);
        $this->assertSame('0', (string) $this->db->table('users')->where('id', $user['id'])->get()->getRow('failed_login_count'));
    }

    public function testDisabledAccountsCannotLogIn(): void
    {
        $user = $this->createUser('OPERATOR', 'E1001', 'op.off');
        $this->db->table('users')->where('id', $user['id'])->update(['status' => 'DISABLED']);
        $this->assertFalse(service('auth')->attempt('op.off', 'Correct-Horse-91')['ok']);
    }

    public function testPasswordsAreHashedAndPolicyIsEnforced(): void
    {
        $user = $this->createUser('OPERATOR', 'E1001', 'op.pw');
        $auth = service('auth');
        $this->assertTrue($auth->attempt('op.pw', 'Correct-Horse-91')['ok']);
        $hash = (string) $this->db->table('users')->where('id', $user['id'])->get()->getRow('password_hash');
        $this->assertStringNotContainsString('Correct-Horse-91', $hash);
        $this->assertTrue(password_verify('Correct-Horse-91', $hash));

        foreach (['short1!', 'password123', 'op.pw-is-me-2026'] as $weak) {
            try {
                $auth->changePassword($user['id'], 'Correct-Horse-91', $weak, $weak);
                $this->fail("Weak password accepted: {$weak}");
            } catch (ValidationException) {
            }
        }
        $auth->changePassword($user['id'], 'Correct-Horse-91', 'Brass-Fitting-Gauge-77', 'Brass-Fitting-Gauge-77');
        $this->assertTrue(password_verify('Brass-Fitting-Gauge-77', (string) $this->db->table('users')->where('id', $user['id'])->get()->getRow('password_hash')));

        // History: the previous password cannot be reused.
        $this->expectException(ValidationException::class);
        $auth->changePassword($user['id'], 'Brass-Fitting-Gauge-77', 'Correct-Horse-91', 'Correct-Horse-91');
    }

    public function testChangingTheSecurityStampEndsOpenSessions(): void
    {
        $user = $this->createUser('OPERATOR', 'E1001', 'op.stamp');
        $this->actingAs($user);
        $this->assertSame($user['id'], service('auth')->user()['id'] ?? null);

        $this->db->table('users')->where('id', $user['id'])->update(['security_stamp' => bin2hex(random_bytes(16))]);
        \App\Config\Services::resetSingle('auth');
        $this->assertNull(service('auth')->user(), 'a role change, reset or disable revokes the session');
    }

    public function testIdempotencyKeyReturnsTheFirstResponse(): void
    {
        $calls   = 0;
        $service = service('idempotency');
        $key     = Uuid::v4();
        $work    = function (Database $db) use (&$calls): array {
            $calls++;

            return ['ok' => true, 'n' => $calls];
        };

        $first  = $service->run($this->admin['id'], $key, 'test', ['a' => 1], $work);
        $second = $service->run($this->admin['id'], $key, 'test', ['a' => 1], $work);
        $this->assertSame(1, $calls);
        $this->assertSame(1, $second['n']);
        $this->assertTrue($second['replayed']);
        $this->assertArrayNotHasKey('replayed', $first);

        $this->expectException(IdempotencyConflictException::class);
        $service->run($this->admin['id'], $key, 'test', ['a' => 2], $work);
    }

    public function testFailedActionLeavesNoKeySoItCanBeRetried(): void
    {
        $service = service('idempotency');
        $key     = Uuid::v4();
        try {
            $service->run($this->admin['id'], $key, 'test', [], static function (): array {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, $this->db->table('idempotency_keys')->where('idem_key', $key)->countAllResults());
        $this->assertSame(['ok' => true], $service->run($this->admin['id'], $key, 'test', [], static fn (): array => ['ok' => true]));
    }
}
