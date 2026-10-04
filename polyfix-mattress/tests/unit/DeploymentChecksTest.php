<?php

namespace Tests\Unit;

use Config\Polyfix;
use RuntimeException;
use Tests\Support\PolyfixTestCase;

/**
 * The checks that decide whether a deployment is allowed to serve at all.
 *
 * These exist because a half-configured install used to answer HTTP 500 on
 * every request, with a generic page that looked the same whether a secret was
 * missing, the database was refusing the password or the disk was read-only.
 * Whoever was deploying had nothing to go on.
 *
 * @internal
 */
final class DeploymentChecksTest extends PolyfixTestCase
{
    private function config(array $overrides = []): Polyfix
    {
        $config = new Polyfix();
        // A configuration that should pass, which each test then breaks in one way.
        $config->encryptionKey = base64_encode(str_repeat('k', 32));
        $config->signingSecret = str_repeat('s', 64);

        foreach ($overrides as $name => $value) {
            $config->{$name} = $value;
        }

        return $config;
    }

    public function testAGoodConfigurationHasNoSecretProblems(): void
    {
        $this->assertSame([], $this->config()->problems());
    }

    public function testAPlaceholderSecretIsReportedByName(): void
    {
        $problems = $this->config(['encryptionKey' => 'CHANGE_ME'])->problems();

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('polyfix.encryptionKey', $problems[0]);
    }

    public function testAnEmptySecretIsReported(): void
    {
        $problems = $this->config(['signingSecret' => ''])->problems();

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('polyfix.signingSecret', implode(' ', $problems));
    }

    /**
     * An unset key used to be reported three times over — "is not set", then
     * "must be at least 32 characters", then "must be base64". One cause, one
     * line, or the page reads like a wall of noise.
     */
    public function testAnUnsetSecretIsReportedOnceNotThreeTimes(): void
    {
        $problems = $this->config([
            'encryptionKey' => 'CHANGE_ME',
            'signingSecret' => 'CHANGE_ME',
        ])->problems();

        $this->assertCount(2, $problems, implode(' | ', $problems));
    }

    public function testAShortSigningSecretIsRejected(): void
    {
        $problems = $this->config(['signingSecret' => 'too-short'])->problems();

        $this->assertStringContainsString('32 characters', implode(' ', $problems));
    }

    public function testAnEncryptionKeyOfTheWrongLengthIsRejected(): void
    {
        $problems = $this->config(['encryptionKey' => base64_encode('only-sixteen-byt')])->problems();

        $this->assertStringContainsString('32 bytes', implode(' ', $problems));
    }

    public function testTheProblemListNeverRepeatsASecretValue(): void
    {
        $secret   = base64_encode(str_repeat('z', 16));
        $problems = implode(' ', $this->config(['encryptionKey' => $secret])->problems());

        $this->assertStringNotContainsString($secret, $problems, 'a problem message must name the setting, never its value');
    }

    public function testEncryptionKeyBytesRefusesToReturnSomethingWeak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->config(['encryptionKey' => base64_encode('short')])->encryptionKeyBytes();
    }

    public function testEncryptionKeyBytesReturnsThirtyTwoRawBytes(): void
    {
        $this->assertSame(32, strlen($this->config()->encryptionKeyBytes()));
    }

    // --- install integrity ----------------------------------------------------

    public function testInstallProblemsCoversTheSecretsAsWell(): void
    {
        $problems = implode(' ', $this->config(['encryptionKey' => 'CHANGE_ME'])->installProblems());

        $this->assertStringContainsString('polyfix.encryptionKey', $problems);
    }

    public function testThisInstallationPassesTheIntegrityChecks(): void
    {
        // The suite runs against a working checkout, so the PHP build, the
        // extension list and the writable directories must all come back clean.
        // If this fails, the checkout is broken rather than the test.
        $problems = array_values(array_filter(
            $this->config()->installProblems(),
            static fn (string $p): bool => ! str_contains($p, 'app.baseURL') && ! str_contains($p, '.env'),
        ));

        $this->assertSame([], $problems, implode(' | ', $problems));
    }

    public function testEveryExtensionTheApplicationNeedsIsActuallyLoaded(): void
    {
        foreach (Polyfix::REQUIRED_EXTENSIONS as $extension) {
            $this->assertTrue(extension_loaded($extension), $extension . ' is required but not loaded');
        }
    }

    public function testTheMinimumPhpVersionMatchesWhatComposerDemands(): void
    {
        $composer = json_decode((string) file_get_contents(ROOTPATH . 'composer.json'), true);

        $this->assertStringContainsString(
            Polyfix::MINIMUM_PHP,
            (string) ($composer['require']['php'] ?? ''),
            'Polyfix::MINIMUM_PHP and composer.json must agree, or one of them is lying to whoever deploys this',
        );
    }

    // --- compulsory two-factor stays gone -------------------------------------

    /**
     * Two-factor itself is back as an optional feature, switched on from
     * Settings → Security. What must never come back is the setting that made
     * it compulsory per role: that is what redirected an account to the setup
     * page from every screen and would not let it leave.
     */
    public function testTheCompulsoryTwoFactorSettingNoLongerExists(): void
    {
        $this->assertFalse(property_exists(Polyfix::class, 'mfaRequiredRoles'));
        $this->assertFalse(method_exists(Polyfix::class, 'mfaRoles'));
    }

    public function testNoConfigFileCanMakeTwoFactorCompulsory(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Config/Polyfix.php');

        $this->assertDoesNotMatchRegularExpression('/\$mfa|mfaRoles|mfaRequired/', $source);
    }

    /** Nothing may publish an enrolment obligation to the filters again. */
    public function testTheAuthFilterHasNoEnrolmentGate(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Filters/AuthFilter.php');

        $this->assertStringNotContainsString('must_enrol_mfa', $source);
    }

    /** A wrong password must have no way of locking an account. */
    public function testNothingCanLockAnAccountForAWrongPassword(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Services/AuthService.php');

        $this->assertDoesNotMatchRegularExpression(
            "/'locked_until'\s*=>\s*gmdate/",
            $source,
            'a lock expiry must never be written',
        );
        $this->assertFalse(property_exists(Polyfix::class, 'loginMaxAttempts'));
        $this->assertFalse(property_exists(Polyfix::class, 'loginLockoutSeconds'));
    }

}
