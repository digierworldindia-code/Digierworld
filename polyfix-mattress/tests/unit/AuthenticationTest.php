<?php

namespace Tests\Unit;

use App\Exceptions\AppException;
use App\Libraries\Crypto;
use App\Libraries\Totp;
use App\Services\AuthService;
use CodeIgniter\Session\Handlers\ArrayHandler;
use CodeIgniter\Session\Session;
use CodeIgniter\Test\Mock\MockSession;
use Psr\Log\NullLogger;
use Config\Polyfix;
use Config\Services;
use Tests\Support\PolyfixTestCase;

/**
 * Signing in: what must succeed, and what must fail in exactly the same way
 * whichever part was wrong.
 *
 * @internal
 */
final class AuthenticationTest extends PolyfixTestCase
{
    private AuthService $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = new AuthService($this->db, config(Polyfix::class), Crypto::instance());
    }

    private function session(): Session
    {
        $config  = config('Session');
        // MockSession keeps the real Session behaviour but does not touch PHP's
        // own session, which does not exist on the command line.
        $session = new MockSession(new ArrayHandler($config, '127.0.0.1'), $config);
        $session->setLogger(new NullLogger());
        $session->start();

        return $session;
    }

    public function testCorrectPasswordSignsIn(): void
    {
        $userId = $this->makeUser(['ADMIN']);
        $email  = $this->db->table('users')->select('email')->where('id', $userId)->get()->getRow()->email;

        $result = $this->auth->attemptPassword($email, self::password(), $this->session());

        $this->assertSame('signed_in', $result['status']);
        $this->assertSame($userId, $result['user_id']);
        $this->assertSame(1, $this->db->table('sessions')->where('user_id', $userId)->countAllResults());
    }

    public function testUnknownAccountAndWrongPasswordSayTheSameThing(): void
    {
        $userId = $this->makeUser();
        $email  = $this->db->table('users')->select('email')->where('id', $userId)->get()->getRow()->email;

        $unknown = $this->refusal(fn () => $this->auth->attemptPassword('nobody@example.com', 'whatever-it-is', $this->session()));
        $wrong   = $this->refusal(fn () => $this->auth->attemptPassword($email, 'not-the-password', $this->session()));

        $this->assertSame(AuthService::GENERIC_FAILURE, $unknown);
        $this->assertSame($unknown, $wrong);
    }

    public function testRepeatedFailuresLockTheAccount(): void
    {
        $userId = $this->makeUser();
        $email  = $this->db->table('users')->select('email')->where('id', $userId)->get()->getRow()->email;
        $limit  = config(Polyfix::class)->loginMaxAttempts;

        for ($attempt = 0; $attempt < $limit; $attempt++) {
            $this->refusal(fn () => $this->auth->attemptPassword($email, 'wrong-password-here', $this->session()));
        }

        // The right password no longer helps while the lock holds.
        $message = $this->refusal(fn () => $this->auth->attemptPassword($email, self::password(), $this->session()));
        $this->assertStringContainsString('locked', $message);
        $this->assertNotNull($this->db->table('users')->select('locked_until')->where('id', $userId)->get()->getRow()->locked_until);
    }

    public function testTwoFactorIsDemandedBeforeASessionExists(): void
    {
        $secret = Totp::generateSecret();
        $userId = $this->makeUser(['WARRANTY_MANAGER'], null, [
            'mfa_enabled' => 1, 'mfa_secret_encrypted' => Crypto::instance()->encrypt($secret), 'mfa_enrolled_at' => utc_now(),
        ]);
        $email   = $this->db->table('users')->select('email')->where('id', $userId)->get()->getRow()->email;
        $session = $this->session();

        $result = $this->auth->attemptPassword($email, self::password(), $session);
        $this->assertSame('mfa_required', $result['status']);
        $this->assertSame(0, $this->db->table('sessions')->where('user_id', $userId)->countAllResults(), 'no session before the second factor');

        $this->refusal(fn () => $this->auth->attemptSecondFactor('000000', $session));
        $this->auth->attemptSecondFactor(Totp::code($secret), $session);

        $this->assertSame(1, $this->db->table('sessions')->where('user_id', $userId)->countAllResults());
        $this->assertSame(1, (int) $this->db->table('sessions')->select('mfa_satisfied')->where('user_id', $userId)->get()->getRow()->mfa_satisfied);
    }

    public function testARecoveryCodeWorksOnceOnly(): void
    {
        $secret = Totp::generateSecret();
        $userId = $this->makeUser(['ADMIN'], null, ['mfa_enabled' => 1, 'mfa_secret_encrypted' => Crypto::instance()->encrypt($secret)]);
        $codes  = Totp::recoveryCodes(3);
        $this->db->table('users')->where('id', $userId)->update([
            'mfa_recovery_codes' => json_encode(array_map(static fn ($c) => hash('sha256', $c), $codes)),
        ]);
        $email = $this->db->table('users')->select('email')->where('id', $userId)->get()->getRow()->email;

        $session = $this->session();
        $this->auth->attemptPassword($email, self::password(), $session);
        $this->auth->attemptSecondFactor($codes[0], $session);
        $this->assertSame(1, $this->db->table('sessions')->where('user_id', $userId)->countAllResults());

        $second = $this->session();
        $this->auth->attemptPassword($email, self::password(), $second);
        $this->assertStringContainsString('not accepted', $this->refusal(fn () => $this->auth->attemptSecondFactor($codes[0], $second)));
    }

    public function testResolveRejectsATamperedSessionToken(): void
    {
        $userId  = $this->makeUser();
        $email   = $this->db->table('users')->select('email')->where('id', $userId)->get()->getRow()->email;
        $session = $this->session();
        $this->auth->attemptPassword($email, self::password(), $session);

        $this->assertNotNull($this->auth->resolve($session));

        $auth = $session->get('auth');
        $session->set('auth', ['sid' => $auth['sid'], 'token' => Crypto::randomToken(32)]);
        $this->assertNull($this->auth->resolve($session), 'a forged token must not resolve');
    }

    public function testChangingThePasswordEndsEveryOtherSession(): void
    {
        $userId = $this->makeUser();
        $email  = $this->db->table('users')->select('email')->where('id', $userId)->get()->getRow()->email;

        // Two devices, signed in one after the other.
        $phone = $this->session();
        $this->auth->attemptPassword($email, self::password(), $phone);
        $phoneSid = $phone->get('auth')['sid'];

        $laptop = $this->session();
        $this->auth->attemptPassword($email, self::password(), $laptop);
        $keep = $laptop->get('auth')['sid'];
        $this->assertNotSame($phoneSid, $keep);

        $this->auth->changePassword($userId, self::password(), 'Another-passphrase-77!', $keep);

        $revoked = static fn (object $row): bool => $row->revoked_at !== null;
        $this->assertTrue($revoked($this->db->table('sessions')->select('revoked_at')->where('id', $phoneSid)->get()->getRow()), 'the other session is ended');
        $this->assertFalse($revoked($this->db->table('sessions')->select('revoked_at')->where('id', $keep)->get()->getRow()), 'the session that changed it stays');
        $this->assertNotNull($this->auth->resolve($laptop));
        $this->assertStringContainsString('not correct', $this->refusal(fn () => $this->auth->changePassword($userId, self::password(), 'Third-passphrase-31!', $keep)));
    }

    public function testAPasswordResetTokenWorksOnceAndEndsAllSessions(): void
    {
        $userId = $this->makeUser();
        $email  = $this->db->table('users')->select('email')->where('id', $userId)->get()->getRow()->email;
        $signed = $this->session();
        $this->auth->attemptPassword($email, self::password(), $signed);

        $token = $this->auth->requestPasswordReset($email);
        $this->assertNotNull($token);

        $this->auth->resetPassword($token, 'Reset-passphrase-64!');
        $this->assertNull($this->auth->resolve($signed), 'every session ends on a reset');
        $this->assertStringContainsString('no longer valid', $this->refusal(fn () => $this->auth->resetPassword($token, 'Another-one-here-12!')));

        // The new password is the one that works.
        $this->assertSame('signed_in', $this->auth->attemptPassword($email, 'Reset-passphrase-64!', $this->session())['status']);
    }

    public function testAResetForAnUnknownAddressLooksIdentical(): void
    {
        $this->assertNull($this->auth->requestPasswordReset('nobody@example.com'));
        $this->assertSame(0, $this->db->table('password_reset_tokens')->countAllResults());
    }

    public function testASuspendedAccountCannotSignIn(): void
    {
        $userId = $this->makeUser(['ADMIN'], null, ['status' => 'SUSPENDED']);
        $email  = $this->db->table('users')->select('email')->where('id', $userId)->get()->getRow()->email;

        $this->assertSame(AuthService::GENERIC_FAILURE, $this->refusal(fn () => $this->auth->attemptPassword($email, self::password(), $this->session())));
    }

    public function testADealerWhoseDealershipIsSuspendedCannotSignIn(): void
    {
        $dealerId = $this->makeDealer(['status' => 'SUSPENDED']);
        $userId   = $this->makeUser(['DEALER'], $dealerId);
        $email    = $this->db->table('users')->select('email')->where('id', $userId)->get()->getRow()->email;

        $this->assertStringContainsString('not active', $this->refusal(fn () => $this->auth->attemptPassword($email, self::password(), $this->session())));
    }

    /** Runs a call that must be refused and returns the message shown to the person. */
    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (AppException $e) {
            return $e->getMessage();
        }

        $this->fail('Expected the call to be refused.');
    }
}
