<?php

namespace App\Libraries;

/**
 * Password hashing and strength policy.
 *
 * Argon2id with the OWASP-recommended parameters (19 MiB, 2 iterations,
 * parallelism 1) — the same parameters every existing hash was produced with,
 * so imported accounts verify and are never needlessly re-hashed. Raising the
 * parameters later re-hashes each user transparently at their next sign-in.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 128;

    private const OPTIONS = ['memory_cost' => 19_456, 'time_cost' => 2, 'threads' => 1];

    private const COMMON_FRAGMENTS = [
        'password', 'qwerty', '12345678', 'letmein', 'admin123', 'welcome1',
        'iloveyou', 'polyfix123', 'mattress123',
    ];

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    public static function verify(string $hash, string $password): bool
    {
        // A malformed stored hash reads as a wrong password, never an error.
        return $hash !== '' && password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    /**
     * Spends the same work as a real verification. Used when the account does
     * not exist, so a missing account cannot be told apart by response time.
     */
    public static function dummyVerify(): void
    {
        static $decoy = null;
        $decoy ??= self::hash(bin2hex(random_bytes(16)));
        password_verify('not-the-password', $decoy);
    }

    /**
     * The server-side verdict. Forms may show the same rules for convenience;
     * only this decides.
     *
     * @param list<string|null> $context the user's email, name and company name
     *
     * @return list<string> problems, empty when acceptable
     */
    public static function problems(string $password, array $context = []): array
    {
        $problems = [];
        $length   = mb_strlen($password);

        if ($length < self::MIN_LENGTH) {
            $problems[] = 'must be at least ' . self::MIN_LENGTH . ' characters';
        }
        if ($length > self::MAX_LENGTH) {
            $problems[] = 'must be at most ' . self::MAX_LENGTH . ' characters';
        }
        if (! preg_match('/[a-z]/', $password)) {
            $problems[] = 'must contain a lowercase letter';
        }
        if (! preg_match('/[A-Z]/', $password)) {
            $problems[] = 'must contain an uppercase letter';
        }
        if (! preg_match('/[0-9]/', $password)) {
            $problems[] = 'must contain a digit';
        }
        if (! preg_match('/[^A-Za-z0-9]/', $password)) {
            $problems[] = 'must contain a symbol';
        }

        $lowered = mb_strtolower($password);
        foreach ($context as $piece) {
            $token = mb_strtolower(trim((string) $piece));
            if (mb_strlen($token) >= 4 && str_contains($lowered, $token)) {
                $problems[] = 'must not contain your name, email or company name';
                break;
            }
        }
        foreach (self::COMMON_FRAGMENTS as $fragment) {
            if (str_contains($lowered, $fragment)) {
                $problems[] = 'must not contain a common or predictable word';
                break;
            }
        }

        return $problems;
    }

    /** Context words for a user: their email, its local part, name and the brand. */
    public static function contextFor(string $email, ?string $fullName): array
    {
        return [$email, strstr($email, '@', true) ?: null, $fullName, brand('shortName')];
    }
}
