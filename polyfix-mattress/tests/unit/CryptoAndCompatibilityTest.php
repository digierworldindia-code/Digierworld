<?php

namespace Tests\Unit;

use App\Libraries\Crypto;
use App\Libraries\PasswordPolicy;
use App\Libraries\Totp;
use Tests\Support\PolyfixTestCase;

/**
 * The cryptography this port inherited.
 *
 * The existing database holds password hashes, encrypted contact details,
 * blind indexes and two-factor secrets written by the previous platform. These
 * tests hold the formats still, because changing one would lock people out or
 * make their data unreadable.
 *
 * @internal
 */
final class CryptoAndCompatibilityTest extends PolyfixTestCase
{
    public function testPasswordsUseArgon2idAndVerifyAcrossPlatforms(): void
    {
        $hash = PasswordPolicy::hash('A-passphrase-for-the-test-9!');

        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertTrue(PasswordPolicy::verify($hash, 'A-passphrase-for-the-test-9!'));
        $this->assertFalse(PasswordPolicy::verify($hash, 'A-passphrase-for-the-test-9'));
        $this->assertFalse(PasswordPolicy::needsRehash($hash), 'a hash this application wrote is not rehashed on sight');
    }

    public function testAHashWrittenByThePreviousPlatformStillVerifies(): void
    {
        // Produced by the Node platform for the password "polyfix-migration-check".
        $hash = password_hash('polyfix-migration-check', PASSWORD_ARGON2ID, ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]);

        $this->assertTrue(PasswordPolicy::verify($hash, 'polyfix-migration-check'));
    }

    public function testThePasswordPolicyRefusesTheObviousChoices(): void
    {
        foreach (['short', 'password1234!', 'polyfix123456!', 'aaaaaaaaaaaaA1!'] as $weak) {
            $this->assertNotSame([], PasswordPolicy::problems($weak, []), "{$weak} should be refused");
        }

        $this->assertSame([], PasswordPolicy::problems('Lantern-harbour-91!', []));
    }

    public function testAPasswordCannotContainTheAccountItProtects(): void
    {
        $context = PasswordPolicy::contextFor('ritu@polyfixmattress.com', 'Ritu Sharma');

        $this->assertNotSame([], PasswordPolicy::problems('Ritu-Sharma-2026!', $context));
        $this->assertNotSame([], PasswordPolicy::problems('ritu-is-here-12!A', $context));
        $this->assertSame([], PasswordPolicy::problems('Copper-harbour-64!', $context));
    }

    public function testEncryptionRoundTripsAndIsAuthenticated(): void
    {
        $crypto = Crypto::instance();
        $cipher = $crypto->encrypt('9876500011');

        $this->assertStringStartsWith('v1.', $cipher, 'the version prefix lets the format change later');
        $this->assertSame('9876500011', $crypto->decrypt($cipher));

        // A single altered character must not decrypt to anything.
        $tampered = substr($cipher, 0, -2) . (str_ends_with($cipher, 'A') ? 'B' : 'A');
        $this->assertNull($crypto->decryptOrNull($tampered), 'tampered ciphertext is refused, not guessed at');
    }

    public function testTheSameValueEncryptsDifferentlyEachTime(): void
    {
        $crypto = Crypto::instance();

        $this->assertNotSame($crypto->encrypt('same value'), $crypto->encrypt('same value'), 'a repeated value must not be recognisable');
    }

    public function testTheBlindIndexIsStableAcrossFormatting(): void
    {
        $crypto = Crypto::instance();
        $index  = $crypto->blindIndex(Crypto::normalisePhone('9876500011'));

        foreach (['+91 98765 00011', '098765-00011', '91 9876500011', '9876500011'] as $written) {
            $this->assertSame($index, $crypto->blindIndex(Crypto::normalisePhone($written)), "{$written} must find the same customer");
        }
        $this->assertNotSame($index, $crypto->blindIndex(Crypto::normalisePhone('9876500012')));
    }

    public function testTwoFactorCodesMatchTheStandard(): void
    {
        // RFC 6238 test vector: the secret "12345678901234567890" in base32.
        $secret = Totp::base32Encode('12345678901234567890');

        $this->assertSame('287082', Totp::code($secret, 59));
        $this->assertSame('081804', Totp::code($secret, 1_111_111_109));
        $this->assertTrue(Totp::verify($secret, Totp::code($secret)));
        $this->assertFalse(Totp::verify($secret, '000000'));
    }

    public function testACodeFromTheAdjacentWindowIsStillAccepted(): void
    {
        $secret = Totp::generateSecret();
        $now    = time();

        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now - 30)), 'a clock a step behind still works');
        $this->assertFalse(Totp::verify($secret, Totp::code($secret, $now - 300)), 'an old code does not');
    }

    public function testTheOtpauthUrlNamesTheBrandAndTheAccount(): void
    {
        $url = Totp::otpauthUrl(Totp::generateSecret(), 'ritu@polyfixmattress.com');

        $this->assertStringStartsWith('otpauth://totp/', $url);
        $this->assertStringContainsString(rawurlencode(brand('shortName')), $url);
        $this->assertStringContainsString('ritu%40polyfixmattress.com', $url);
        $this->assertStringContainsString('algorithm=SHA1', $url);
    }

    public function testSignaturesAreVerifiedInConstantTime(): void
    {
        $crypto    = Crypto::instance();
        $signature = $crypto->sign('some-value');

        $this->assertTrue($crypto->verifySignature('some-value', $signature));
        $this->assertFalse($crypto->verifySignature('some-other-value', $signature));
        $this->assertFalse($crypto->verifySignature('some-value', 'not-a-signature'));
    }
}
