<?php

namespace App\Services\Auth;

use App\Services\Settings\SettingsService;

/**
 * Password rules (docs/architecture/09-security-architecture.md, section 9.3):
 * minimum length from settings (never below 8), maximum 128, at least three of
 * four character classes, not a common password, no username / employee code /
 * name inside, and not one of the last N passwords.
 */
class PasswordPolicy
{
    public const MAX_LENGTH = 128;

    /** @var array<string, true>|null */
    private ?array $common = null;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly string $commonListFile = APPPATH . 'Data/common-passwords.txt',
    ) {
    }

    public function minLength(): int
    {
        return max(8, $this->settings->int('security.password_min_length', 10));
    }

    public function historyDepth(): int
    {
        return max(0, $this->settings->int('security.password_history', 5));
    }

    /**
     * @param array{username?: string, employee_code?: string|null, full_name?: string|null} $context
     * @param list<string>                                                                   $previousHashes newest first
     *
     * @return list<string> problems (empty = acceptable)
     */
    public function check(string $password, array $context = [], array $previousHashes = []): array
    {
        $problems = [];
        $length   = mb_strlen($password);

        if ($length < $this->minLength()) {
            $problems[] = "Use at least {$this->minLength()} characters.";
        }
        if ($length > self::MAX_LENGTH) {
            $problems[] = 'Use at most ' . self::MAX_LENGTH . ' characters.';
        }
        if ($this->algorithm() === PASSWORD_BCRYPT && strlen($password) > 72) {
            $problems[] = 'Use at most 72 bytes.';
        }

        $classes = (int) preg_match('/[a-z]/u', $password)
            + (int) preg_match('/[A-Z]/u', $password)
            + (int) preg_match('/\d/u', $password)
            + (int) preg_match('/[^a-zA-Z\d]/u', $password);
        if ($classes < 3) {
            $problems[] = 'Mix at least three of: lower case, upper case, digits, symbols.';
        }

        $lower = mb_strtolower($password);
        if ($this->isCommon($lower)) {
            $problems[] = 'This password is too common.';
        }

        foreach (['username', 'employee_code'] as $field) {
            $value = mb_strtolower(trim((string) ($context[$field] ?? '')));
            if (mb_strlen($value) >= 3 && str_contains($lower, $value)) {
                $problems[] = 'Do not use your username or employee code in the password.';
                break;
            }
        }
        foreach (preg_split('/\s+/', mb_strtolower((string) ($context['full_name'] ?? ''))) ?: [] as $part) {
            if (mb_strlen($part) >= 4 && str_contains($lower, $part)) {
                $problems[] = 'Do not use your name in the password.';
                break;
            }
        }

        foreach (array_slice($previousHashes, 0, $this->historyDepth()) as $hash) {
            if (password_verify($password, $hash)) {
                $problems[] = "Do not reuse one of your last {$this->historyDepth()} passwords.";
                break;
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * Rules shown next to password fields.
     *
     * @return list<string>
     */
    public function describe(): array
    {
        return [
            "At least {$this->minLength()} characters",
            'At least three of: lower case, upper case, digits, symbols',
            'Not a common password, and not your username, employee code or name',
            "Not one of your last {$this->historyDepth()} passwords",
        ];
    }

    public function hash(string $password): string
    {
        return password_hash($password, $this->algorithm());
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm());
    }

    /** Generates a random password that satisfies the policy (admin resets, installer). */
    public function generate(int $length = 14): string
    {
        $sets = ['abcdefghjkmnpqrstuvwxyz', 'ABCDEFGHJKMNPQRSTUVWXYZ', '23456789', '@#%+=?!'];
        $all  = implode('', $sets);
        $chars = [];
        foreach ($sets as $set) {
            $chars[] = $set[random_int(0, strlen($set) - 1)];
        }
        while (count($chars) < max($length, $this->minLength())) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    private function algorithm(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    private function isCommon(string $lowerPassword): bool
    {
        if ($this->common === null) {
            $this->common = [];
            if (is_file($this->commonListFile)) {
                foreach (file($this->commonListFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $this->common[$line] = true;
                }
            }
        }

        return isset($this->common[$lowerPassword]);
    }
}
