<?php

namespace Tests\Unit;

use App\Exceptions\AppException;
use App\Libraries\Crypto;
use App\Libraries\Settings;
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

    /**
     * Test B from the brief, and the behaviour that caused the complaint.
     *
     * An employee who mistypes their password several times must still be able
     * to get in with the right one. Locking the account punished the person
     * who fumbled, and let anyone lock out a colleague knowing only their
     * email address.
     */
    public function testRepeatedWrongPasswordsDoNotLockTheAccount(): void
    {
        $userId = $this->makeUser();
        $email  = $this->db->table('users')->select('email')->where('id', $userId)->get()->getRow()->email;

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $message = $this->refusal(fn () => $this->auth->attemptPassword($email, 'wrong-password-here', $this->session()));
            $this->assertSame(AuthService::GENERIC_FAILURE, $message, 'every failure reads the same, however many there have been');
        }

        // The right password still works, immediately.
        $result = $this->auth->attemptPassword($email, self::password(), $this->session());
        $this->assertSame('signed_in', $result['status']);

        $row = $this->db->table('users')->select('locked_until, failed_login_count')
            ->where('id', $userId)->get()->getRow();
        $this->assertNull($row->locked_until, 'nothing may write a lock');
        $this->assertSame(0, (int) $row->failed_login_count, 'a successful sign-in clears the count');
    }

    /** The failures are still counted, because that is the signal an attack shows up in. */
    public function testWrongPasswordsAreStillCounted(): void
    {
        $userId = $this->makeUser();
        $email  = $this->db->table('users')->select('email')->where('id', $userId)->get()->getRow()->email;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->refusal(fn () => $this->auth->attemptPassword($email, 'wrong-password-here', $this->session()));
        }

        $this->assertSame(3, (int) $this->db->table('users')->select('failed_login_count')
            ->where('id', $userId)->get()->getRow()->failed_login_count);
        $this->assertSame(3, $this->db->table('failed_login_attempts')->countAllResults());
    }

    /**
     * The whole point of this build: a correct password is the only thing
     * between an administrator and their dashboard.
     *
     * This used to return 'mfa_required' and hold the sign-in in a pending
     * state until an authenticator code was accepted.
     */
    public function testAPasswordAloneCompletesTheSignIn(): void
    {
        $userId  = $this->makeUser(['SUPER_ADMIN']);
        $email   = $this->db->table('users')->select('email')->where('id', $userId)->get()->getRow()->email;
        $session = $this->session();

        $result = $this->auth->attemptPassword($email, self::password(), $session);

        $this->assertSame('signed_in', $result['status'], 'there is no second step to wait for');
        $this->assertSame(1, $this->db->table('sessions')->where('user_id', $userId)->countAllResults(), 'the session exists immediately');
        $this->assertNull($session->get('auth_pending'), 'nothing is left half-signed-in');
    }

    /**
     * A senior role is no different. Every role used to be a candidate for
     * compulsory enrolment, and a role named in that setting could not reach
     * any screen until it had enrolled.
     */
    public function testASeniorRoleIsNotAskedToEnrolInAnything(): void
    {
        foreach (['SUPER_ADMIN', 'ADMIN', 'WARRANTY_MANAGER'] as $role) {
            $userId = $this->makeUser([$role]);
            $email  = $this->db->table('users')->select('email')->where('id', $userId)->get()->getRow()->email;

            $result = $this->auth->attemptPassword($email, self::password(), $this->session());
            $this->assertSame('signed_in', $result['status'], $role . ' must sign straight in');

            $resolved = $this->auth->resolve($this->session());
            $this->assertArrayNotHasKey('must_enrol_mfa', $resolved ?? [], 'no enrolment obligation is published to the filters');
        }
    }

    /**
     * Two-factor exists again, as something an administrator may switch on.
     * Off is the default, and while it is off a stale mfa_enabled on a row
     * must not ask anybody for a code — that is how an account gets stranded.
     */
    public function testTwoFactorIsOffUntilAnAdministratorTurnsItOn(): void
    {
        $this->assertFalse(AuthService::twoFactorAvailable(), 'off unless the setting says otherwise');

        $userId = $this->makeUser(['SUPER_ADMIN'], null, ['mfa_enabled' => 1, 'mfa_enrolled_at' => utc_now()]);
        $email  = $this->db->table('users')->select('email')->where('id', $userId)->get()->getRow()->email;

        $result = $this->auth->attemptPassword($email, self::password(), $this->session());
        $this->assertSame('signed_in', $result['status'], 'an enrolled account signs straight in while the feature is off');
    }

    public function testWithTheFeatureOnAnEnrolledAccountIsAskedForACode(): void
    {
        $this->enableTwoFactor();

        $secret = Totp::generateSecret();
        $userId = $this->makeUser(['ADMIN'], null, [
            'mfa_enabled'          => 1,
            'mfa_secret_encrypted' => Crypto::instance()->encrypt($secret),
            'mfa_enrolled_at'      => utc_now(),
        ]);
        $email   = $this->db->table('users')->select('email')->where('id', $userId)->get()->getRow()->email;
        $session = $this->session();

        $result = $this->auth->attemptPassword($email, self::password(), $session);
        $this->assertSame('mfa_required', $result['status']);
        $this->assertSame(0, $this->db->table('sessions')->where('user_id', $userId)->countAllResults(), 'no session before the code');

        $this->auth->attemptSecondFactor(Totp::code($secret), $session);
        $this->assertSame(1, $this->db->table('sessions')->where('user_id', $userId)->countAllResults());
    }

    public function testAnAccountThatHasNotEnrolledIsNeverAskedForACode(): void
    {
        $this->enableTwoFactor();

        $email = $this->db->table('users')->select('email')
            ->where('id', $this->makeUser(['SUPER_ADMIN']))->get()->getRow()->email;

        $this->assertSame('signed_in', $this->auth->attemptPassword($email, self::password(), $this->session())['status']);
    }

    /** A recovery code switches it off, so a lost phone is not a dead end. */
    public function testARecoveryCodeCanSwitchTwoFactorOff(): void
    {
        $this->enableTwoFactor();

        $secret = Totp::generateSecret();
        $codes  = Totp::recoveryCodes(3);
        $userId = $this->makeUser(['ADMIN'], null, [
            'mfa_enabled'          => 1,
            'mfa_secret_encrypted' => Crypto::instance()->encrypt($secret),
            'mfa_recovery_codes'   => json_encode(array_map(static fn ($c) => hash('sha256', $c), $codes)),
        ]);

        $this->auth->disableMfa($userId, self::password(), $codes[0]);

        $row = $this->db->table('users')->select('mfa_enabled, mfa_secret_encrypted')->where('id', $userId)->get()->getRow();
        $this->assertSame(0, (int) $row->mfa_enabled);
        $this->assertNull($row->mfa_secret_encrypted);
    }

    /**
     * Turns the feature on for one test. Inserts the row when it is absent, so
     * the test does not quietly pass against a database seeded before the
     * setting existed — which is exactly how it first went green by accident.
     */
    private function enableTwoFactor(): void
    {
        $table = $this->db->table('system_settings');

        $table->where('key', 'security.two_factor_enabled')->countAllResults() > 0
            ? $table->where('key', 'security.two_factor_enabled')->update(['value' => 'true'])
            : $table->insert([
                'key'        => 'security.two_factor_enabled',
                'value'      => 'true',
                'category'   => 'security',
                'is_secret'  => 0,
                'updated_at' => utc_now(),
            ]);

        Settings::forget();
        $this->assertTrue(AuthService::twoFactorAvailable(), 'the switch must actually be on for this test to mean anything');
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
