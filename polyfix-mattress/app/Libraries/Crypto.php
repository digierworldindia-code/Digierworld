<?php

namespace App\Libraries;

use Config\Polyfix;
use RuntimeException;

/**
 * Symmetric crypto, byte-compatible with the data the previous platform wrote.
 *
 *  encrypt / decrypt   AES-256-GCM column encryption for data the application
 *                      must read but a raw database dump must not reveal:
 *                      customer phone, email, TOTP secrets.
 *                      Format: v1.<iv>.<ciphertext>.<tag>, each base64url.
 *  blindIndex          keyed HMAC-SHA256 of a normalised value. Lets the system
 *                      match "same phone number again" without a plaintext
 *                      phone directory in the database.
 *  sign / verify       detached HMAC for short-lived signed URLs.
 *
 * Compatibility with existing ciphertext and indexes was verified against live
 * rows before this class was written; do not change the format or normalisation.
 */
class Crypto
{
    private const CIPHER   = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const VERSION  = 'v1';

    public function __construct(private readonly Polyfix $config)
    {
    }

    public static function instance(): self
    {
        return new self(config(Polyfix::class));
    }

    public function encrypt(string $plaintext): string
    {
        $iv  = random_bytes(self::IV_BYTES);
        $tag = '';
        $ct  = openssl_encrypt($plaintext, self::CIPHER, $this->config->encryptionKeyBytes(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return implode('.', [self::VERSION, self::b64u($iv), self::b64u($ct), self::b64u($tag)]);
    }

    public function decrypt(string $encoded): string
    {
        $parts = explode('.', $encoded);
        if (count($parts) !== 4 || $parts[0] !== self::VERSION) {
            throw new RuntimeException('Unrecognised ciphertext format.');
        }
        [, $iv, $ct, $tag] = $parts;
        $plain = openssl_decrypt(self::unb64u($ct), self::CIPHER, $this->config->encryptionKeyBytes(), OPENSSL_RAW_DATA, self::unb64u($iv), self::unb64u($tag));
        if ($plain === false) {
            // Wrong key or tampered ciphertext. GCM authenticates, so this is
            // never silently wrong data.
            throw new RuntimeException('Decryption failed.');
        }

        return $plain;
    }

    /** Null-safe decrypt for display; an unreadable value never breaks a page. */
    public function decryptOrNull(?string $encoded): ?string
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }

        try {
            return $this->decrypt($encoded);
        } catch (RuntimeException) {
            log_message('error', 'A stored encrypted value could not be decrypted (wrong key or damaged data).');

            return null;
        }
    }

    public function blindIndex(string $value): string
    {
        return hash_hmac('sha256', self::normaliseForIndex($value), $this->config->signingSecret);
    }

    public static function normaliseForIndex(string $value): string
    {
        return (string) preg_replace('/\s+/', ' ', strtolower(trim($value)));
    }

    /** An Indian mobile number reduced to its last ten digits. */
    public static function normalisePhone(string $value): string
    {
        $digits = (string) preg_replace('/\D/', '', $value);

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    public function sign(string $payload): string
    {
        return self::b64u(hash_hmac('sha256', $payload, $this->config->signingSecret, true));
    }

    public function verifySignature(string $payload, string $signature): bool
    {
        return hash_equals($this->sign($payload), $signature);
    }

    /** 256 bits by default, URL-safe. */
    public static function randomToken(int $bytes = 32): string
    {
        return self::b64u(random_bytes($bytes));
    }

    public static function b64u(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function unb64u(string $encoded): string
    {
        $raw = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
        if ($raw === false) {
            throw new RuntimeException('Invalid base64url data.');
        }

        return $raw;
    }
}
