<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use RuntimeException;

/**
 * Application policy. Every value can be overridden per environment from .env
 * with the `polyfix.` prefix (CodeIgniter maps `polyfix.loginMaxAttempts` onto
 * $loginMaxAttempts automatically).
 *
 * Secrets have no default. validate() refuses to let a production request run
 * with a missing, placeholder or undersized secret, so a half-configured
 * deployment fails loudly instead of silently running with weak keys.
 */
class Polyfix extends BaseConfig
{
    // --- secrets (from .env only) --------------------------------------------
    /** base64, 32 bytes decoded. AES-256-GCM for customer contact data and TOTP secrets. */
    public string $encryptionKey = '';

    /** HMAC key for blind indexes and signed URLs. Must match the key the data was written with. */
    public string $signingSecret = '';

    // --- authentication -------------------------------------------------------
    public int $loginMaxAttempts      = 5;
    public int $loginLockoutSeconds   = 900;
    public int $sessionIdleSeconds    = 3600;
    public int $sessionAbsoluteSeconds = 43200;
    public int $passwordResetTtlSeconds = 3600;

    /** Comma separated role keys that must enrol in two-factor authentication. */
    public string $mfaRequiredRoles = 'SUPER_ADMIN,ADMIN,WARRANTY_MANAGER';

    // --- uploads ----------------------------------------------------------------
    /** Outside the web root. WRITEPATH is expanded at runtime. */
    public string $uploadPath        = 'WRITEPATH/uploads';
    public int $maxUploadBytes       = 8_388_608;
    public int $maxVideoBytes        = 26_214_400;
    public int $maxUploadsPerClaim   = 8;

    /** Signed media URLs expire after this many seconds. */
    public int $mediaUrlTtlSeconds = 300;

    // --- public website ----------------------------------------------------------
    public string $ga4MeasurementId = '';
    public string $gscVerification  = '';

    // --- rate limits (requests per window, per client address) -----------------
    public int $rateLoginPerMinute      = 5;
    public int $rateVerifyPerMinute     = 20;
    public int $ratePublicFormPerHour   = 5;
    public int $ratePasswordResetPer15m = 3;

    public function uploadDirectory(): string
    {
        return rtrim(str_replace('WRITEPATH/', WRITEPATH, $this->uploadPath), '/') . '/';
    }

    /** @return list<string> */
    public function mfaRoles(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->mfaRequiredRoles))));
    }

    /** Raw 32-byte key. Throws rather than returning something weak. */
    public function encryptionKeyBytes(): string
    {
        $raw = base64_decode($this->encryptionKey, true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException('polyfix.encryptionKey must be base64 and decode to exactly 32 bytes (openssl rand -base64 32).');
        }

        return $raw;
    }

    /**
     * Names every problem, never a value. Called on every production request
     * from the SecureHeaders filter's first run and by `php spark polyfix:check`.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        foreach (['encryptionKey', 'signingSecret'] as $name) {
            $value = $this->{$name};
            if ($value === '' || stripos($value, 'CHANGE_ME') === 0) {
                $problems[] = "polyfix.{$name} is not set";
            }
        }

        if ($this->signingSecret !== '' && strlen($this->signingSecret) < 32) {
            $problems[] = 'polyfix.signingSecret must be at least 32 characters';
        }

        try {
            $this->encryptionKeyBytes();
        } catch (RuntimeException $e) {
            $problems[] = $e->getMessage();
        }

        return $problems;
    }
}
