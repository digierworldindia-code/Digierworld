<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use RuntimeException;

/**
 * Application policy. Every value can be overridden per environment from .env
 * with the `polyfix.` prefix (CodeIgniter maps `polyfix.sessionIdleSeconds` onto
 * $sessionIdleSeconds automatically).
 *
 * Secrets have no default. validate() refuses to let a production request run
 * with a missing, placeholder or undersized secret, so a half-configured
 * deployment fails loudly instead of silently running with weak keys.
 */
class Polyfix extends BaseConfig
{
    // --- secrets (from .env only) --------------------------------------------
    /** base64, 32 bytes decoded. AES-256-GCM for customer contact data. */
    public string $encryptionKey = '';

    /** HMAC key for blind indexes and signed URLs. Must match the key the data was written with. */
    public string $signingSecret = '';

    // --- authentication -------------------------------------------------------
    public int $sessionIdleSeconds    = 3600;
    public int $sessionAbsoluteSeconds = 43200;
    public int $passwordResetTtlSeconds = 3600;


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
    /*
     * Sign-in attempts per client address per minute.
     *
     * Deliberately generous. A whole office usually shares one address, so a
     * handful per minute is nothing: twenty people arriving at nine o'clock
     * would exhaust a small budget between them, and someone who mistypes
     * their password twice would find the door shut. This is the only brake
     * on repeated sign-ins now that a wrong password no longer locks the
     * account, so it is set where automation hits it and people do not.
     */
    public int $rateLoginPerMinute      = 30;
    public int $rateVerifyPerMinute     = 20;
    public int $ratePublicFormPerHour   = 5;
    public int $ratePasswordResetPer15m = 3;

    public function uploadDirectory(): string
    {
        return rtrim(str_replace('WRITEPATH/', WRITEPATH, $this->uploadPath), '/') . '/';
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
        $unset    = [];

        foreach (['encryptionKey', 'signingSecret'] as $name) {
            $value = $this->{$name};
            if ($value === '' || stripos($value, 'CHANGE_ME') === 0) {
                $problems[] = "polyfix.{$name} is not set in .env";
                $unset[]    = $name;
            }
        }

        // Only judge the shape of a value that is actually there, or the list
        // says the same thing three times over.
        if (! in_array('signingSecret', $unset, true) && strlen($this->signingSecret) < 32) {
            $problems[] = 'polyfix.signingSecret must be at least 32 characters';
        }

        if (! in_array('encryptionKey', $unset, true)) {
            try {
                $this->encryptionKeyBytes();
            } catch (RuntimeException $e) {
                $problems[] = $e->getMessage();
            }
        }

        return $problems;
    }

    /**
     * Extensions this application actually uses. mysqli for the database,
     * intl for CodeIgniter itself, the rest for crypto, uploads and exports.
     *
     * @var list<string>
     */
    public const REQUIRED_EXTENSIONS = [
        'intl',
        'mbstring',
        'json',
        'mysqli',
        'openssl',
        'gd',
        'fileinfo',
        'zip',
    ];

    /** The lowest PHP this code is tested on. Keep in step with composer.json. */
    public const MINIMUM_PHP = '8.2';

    /**
     * Everything that must be true before the application can serve its first
     * request: the PHP build, the writable directories, and the secrets.
     *
     * None of these can be true on an installation that has ever worked, so it
     * is safe — and far kinder to whoever is deploying — to name them on screen
     * rather than answer a bare 500. Names only: never a value, never a path
     * outside the project, never a credential.
     *
     * Runtime faults are deliberately not in here. A database that is down, or
     * a disk that filled up this morning, is a genuine production incident and
     * belongs in the log and in `php spark polyfix:doctor`, not on a public
     * page. Those still get the ordinary error page.
     *
     * @return list<string>
     */
    public function installProblems(): array
    {
        $problems = [];

        if (version_compare(PHP_VERSION, self::MINIMUM_PHP, '<')) {
            $problems[] = sprintf(
                'PHP %s or newer is required. This server runs PHP %s. Switch the version in your hosting control panel.',
                self::MINIMUM_PHP,
                PHP_VERSION,
            );
        }

        $missing = array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            static fn (string $ext): bool => ! extension_loaded($ext),
        ));
        if ($missing !== []) {
            $problems[] = 'These PHP extensions are switched off: ' . implode(', ', $missing)
                . '. Enable them in your hosting control panel.';
        }

        foreach (['', 'cache/', 'logs/', 'session/', 'uploads/'] as $sub) {
            $dir = WRITEPATH . $sub;
            if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
                $problems[] = 'writable/' . $sub . ' does not exist and could not be created.';
                continue;
            }
            if (! is_writable($dir)) {
                $problems[] = 'writable/' . $sub . ' is not writable by the web server (try 775).';
            }
        }

        if (! is_file(ROOTPATH . '.env')) {
            $problems[] = 'There is no .env file. Copy .env.example to .env and fill it in.';
        }

        /*
         * A baseURL still on the framework's own default means .env was never
         * filled in. The site would appear to load and then break every
         * stylesheet, form, redirect and QR code, which is harder to work out
         * than an honest stop.
         *
         * Only the untouched default counts. Someone who has deliberately
         * typed a private or local address may have a reason to, and gets a
         * warning from `polyfix:doctor` instead of a locked door.
         */
        $baseUrl = (string) config('App')->baseURL;
        if ($baseUrl === '' || $baseUrl === 'http://localhost:8080/') {
            $problems[] = 'app.baseURL is not set in .env. Set it to the address the public will use, e.g. https://your-domain.com/ (with the trailing slash).';
        }

        return array_merge($problems, $this->problems());
    }
}
