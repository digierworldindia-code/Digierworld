<?php

namespace App\Services\Auth;

use App\Exceptions\AuthorizationException;
use App\Exceptions\ValidationException;
use App\Services\Audit\AuditService;
use App\Services\Settings\SettingsService;
use App\Services\Support\Clock;
use App\Services\Support\RequestContext;
use App\Services\Support\TransactionRunner;
use App\Core\Database;
use App\Core\Session;
use App\Config\Qms;

/**
 * Session authentication (docs/architecture/09-security-architecture.md, section 9.3).
 *
 * - One generic failure message; unknown users get a dummy hash check (same timing).
 * - Lockout after N consecutive failures for M minutes (settings).
 * - Session id regenerated on login; security stamp revokes other sessions.
 * - Idle and absolute timeouts are enforced by the auth filter.
 */
class AuthService
{
    public const GENERIC_FAILURE = 'Invalid username or password, or the account is locked.';

    /** Argon2id hash of a random string: keeps timing equal for unknown usernames. */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$aFZHZkhQVEV4SVBJa3NaTw$ep1xYceb5CWaoaIPOnYNQBQLVHLDqoqv/w3dHnYQFUc';

    private const S_UID      = 'qms_uid';
    private const S_STAMP    = 'qms_stamp';
    private const S_LOGIN_AT = 'qms_login_at';
    private const S_SEEN     = 'qms_last_seen';
    private const S_REAUTH   = 'qms_reauth_failures';

    /** @var array<string, mixed>|false|null false = checked, nobody logged in */
    private array|false|null $user = null;

    public function __construct(
        private readonly Database $db,
        private readonly Session $session,
        private readonly SettingsService $settings,
        private readonly PasswordPolicy $policy,
        private readonly AuditService $audit,
        private readonly RequestContext $context,
        private readonly Clock $clock,
        private readonly TransactionRunner $tx,
    ) {
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function attempt(string $username, string $password): array
    {
        $username = trim($username);
        $user     = $username === '' ? null : $this->db->table('users')->where('username', $username)->get(1)->getRowArray();
        $now      = $this->clock->nowUtcString();

        if ($user === null) {
            password_verify($password, self::DUMMY_HASH);
            $this->logLogin(null, $username, 'LOGIN_FAILED', 'UNKNOWN_USER');

            return ['ok' => false, 'message' => self::GENERIC_FAILURE];
        }

        if ($user['status'] !== 'ACTIVE') {
            password_verify($password, self::DUMMY_HASH);
            $this->logLogin((int) $user['id'], $username, 'LOGIN_FAILED', 'DISABLED');

            return ['ok' => false, 'message' => self::GENERIC_FAILURE];
        }

        if ($user['locked_until'] !== null && $user['locked_until'] > $now) {
            password_verify($password, self::DUMMY_HASH);
            $this->logLogin((int) $user['id'], $username, 'LOGIN_FAILED', 'LOCKED');

            return ['ok' => false, 'message' => self::GENERIC_FAILURE];
        }

        if (! password_verify($password, $user['password_hash'])) {
            $this->registerFailure($user, $username, $now);

            return ['ok' => false, 'message' => self::GENERIC_FAILURE];
        }

        $this->completeLogin($user, $password, $now);

        return ['ok' => true, 'message' => ''];
    }

    /**
     * The logged-in user with role and employee, or null. Revoked / disabled
     * accounts are logged out here.
     *
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        if ($this->user !== null) {
            return $this->user === false ? null : $this->user;
        }

        $uid = $this->session->get(self::S_UID);
        if (! is_numeric($uid)) {
            $this->user = false;

            return null;
        }

        $row = $this->db->table('users u')
            ->select('u.id, u.username, u.email, u.status, u.must_change_password, u.security_stamp, u.employee_id, u.role_id,
                      u.password_changed_at, r.code AS role_code, r.name AS role_name, r.is_super_admin,
                      e.employee_code, e.full_name, e.designation')
            ->join('roles r', 'r.id = u.role_id')
            ->join('employees e', 'e.id = u.employee_id', 'left')
            ->where('u.id', (int) $uid)
            ->get(1)->getRowArray();

        if ($row === null) {
            // The session points to an account that does not exist (any more): end it.
            $this->logLogin(null, 'user #' . (int) $uid, 'SESSION_REVOKED', 'UNKNOWN_USER');
            $this->endSession();

            return null;
        }
        if ($row['status'] !== 'ACTIVE' || ! hash_equals((string) $row['security_stamp'], (string) $this->session->get(self::S_STAMP))) {
            $this->logout('SESSION_REVOKED', (int) $uid, (string) $row['username']);

            return null;
        }

        unset($row['security_stamp']);
        $row['id']           = (int) $row['id'];
        $row['employee_id']  = $row['employee_id'] === null ? null : (int) $row['employee_id'];
        $row['display_name'] = $row['full_name'] ?: $row['username'];
        $this->user          = $row;
        $this->audit->setActor(['id' => $row['id'], 'username' => $row['username'], 'employee_id' => $row['employee_id']]);

        return $row;
    }

    public function id(): ?int
    {
        return $this->user()['id'] ?? null;
    }

    /**
     * Enforces idle and absolute session lifetimes. Returns false when the session has expired.
     */
    public function touch(): bool
    {
        $nowTs    = $this->clock->nowUtc()->getTimestamp();
        $idle     = max(1, $this->settings->int('security.session_idle_minutes', 30)) * 60;
        $absolute = max(1, $this->settings->int('security.session_absolute_hours', 12)) * 3600;
        $seen     = (int) $this->session->get(self::S_SEEN);
        $loginAt  = (int) $this->session->get(self::S_LOGIN_AT);

        if ($nowTs - $seen > $idle || $nowTs - $loginAt > $absolute) {
            $this->logout('SESSION_TIMEOUT');

            return false;
        }

        $this->session->set(self::S_SEEN, $nowTs);

        return true;
    }

    public function logout(string $event = 'LOGOUT', ?int $userId = null, ?string $username = null): void
    {
        $current  = is_array($this->user) ? $this->user : null;
        $userId ??= $current['id'] ?? (is_numeric($this->session->get(self::S_UID)) ? (int) $this->session->get(self::S_UID) : null);
        $username ??= $current['username'] ?? '';

        if ($userId !== null) {
            $this->logLogin($userId, (string) $username, $event, null);
        }

        $this->endSession();
    }

    private function endSession(): void
    {
        $this->user = false;
        $this->audit->setActor(null);
        $this->session->remove([self::S_UID, self::S_STAMP, self::S_LOGIN_AT, self::S_SEEN, self::S_REAUTH]);
        $this->session->regenerate(true);
    }

    /**
     * Password re-entry for e-signatures. Too many failures end the session.
     *
     * @throws AuthorizationException
     */
    public function confirmPassword(string $password): void
    {
        $user = $this->user();
        if ($user === null) {
            throw new AuthorizationException('Please log in again.');
        }

        $hash = (string) $this->db->table('users')->select('password_hash')->where('id', $user['id'])->get(1)->getRow('password_hash');
        if ($password !== '' && password_verify($password, $hash)) {
            $this->session->set(self::S_REAUTH, 0);

            return;
        }

        $failures = (int) $this->session->get(self::S_REAUTH) + 1;
        $this->session->set(self::S_REAUTH, $failures);
        $this->audit->log('REAUTH_FAILED', 'auth', $user['id'], null, ['failures' => $failures]);

        if ($failures >= config(Qms::class)->reauthMaxFailures) {
            $this->logout('SESSION_REVOKED');

            throw new AuthorizationException('Too many wrong passwords. You have been logged out.');
        }

        throw new AuthorizationException('The password is not correct. The signature was not applied.');
    }

    /**
     * @throws ValidationException
     */
    public function changePassword(int $userId, string $current, string $new, string $confirm): void
    {
        $user = $this->db->table('users u')
            ->select('u.*, e.employee_code, e.full_name')
            ->join('employees e', 'e.id = u.employee_id', 'left')
            ->where('u.id', $userId)->get(1)->getRowArray();

        if ($user === null || ! password_verify($current, $user['password_hash'])) {
            throw ValidationException::single('current_password', 'The current password is not correct.');
        }
        if ($new !== $confirm) {
            throw ValidationException::single('confirm_password', 'The new passwords do not match.');
        }
        if (hash_equals($current, $new)) {
            throw ValidationException::single('new_password', 'The new password must be different.');
        }

        $history  = array_column($this->db->table('user_password_history')->select('password_hash')
            ->where('user_id', $userId)->orderBy('id', 'DESC')->get($this->policy->historyDepth())->getResultArray(), 'password_hash');
        $problems = $this->policy->check($new, $user, [$user['password_hash'], ...$history]);
        if ($problems !== []) {
            throw new ValidationException(['new_password' => implode(' ', $problems)], implode(' ', $problems));
        }

        $this->setPassword($user, $new, false);
        $this->logLogin($userId, (string) $user['username'], 'PASSWORD_CHANGED', null);

        // The current session stays valid with the new stamp; other sessions are revoked.
        if ((int) $this->session->get(self::S_UID) === $userId) {
            $this->session->set(self::S_STAMP, $this->db->table('users')->select('security_stamp')->where('id', $userId)->get(1)->getRow('security_stamp'));
            $this->session->regenerate(true);
            $this->user = null;
        }
    }

    /**
     * Writes a new hash, keeps history, rotates the security stamp. Caller logs the event.
     *
     * @param array<string, mixed> $user
     */
    public function setPassword(array $user, string $newPassword, bool $mustChange): void
    {
        $now  = $this->clock->nowUtcString();
        $hash = $this->policy->hash($newPassword);

        $this->tx->run(function (Database $db) use ($user, $hash, $mustChange, $now): void {
            $db->table('user_password_history')->insert([
                'user_id'       => $user['id'],
                'password_hash' => $user['password_hash'],
                'created_at'    => $now,
            ]);
            $db->table('users')->where('id', $user['id'])->update([
                'password_hash'        => $hash,
                'password_changed_at'  => $now,
                'must_change_password' => $mustChange ? 1 : 0,
                'failed_login_count'   => 0,
                'locked_until'         => null,
                'security_stamp'       => bin2hex(random_bytes(16)),
                'updated_at'           => $now,
            ]);
            $this->audit->log($mustChange ? 'PASSWORD_RESET' : 'PASSWORD_CHANGE', 'user', $user['id'], null, null, (string) $user['username']);
        });
    }

    /**
     * @param array<string, mixed> $user
     */
    private function registerFailure(array $user, string $username, string $now): void
    {
        $max      = max(1, $this->settings->int('security.max_failed_logins', 5));
        $minutes  = max(1, $this->settings->int('security.lockout_minutes', 15));
        $failures = (int) $user['failed_login_count'] + 1;
        $update   = ['failed_login_count' => $failures, 'updated_at' => $now];
        $locked   = false;

        if ($failures >= $max) {
            $update['locked_until']       = $this->clock->nowUtc()->modify("+{$minutes} minutes")->format('Y-m-d H:i:s');
            $update['failed_login_count'] = 0;
            $locked                       = true;
        }

        $this->db->table('users')->where('id', $user['id'])->update($update);
        $this->logLogin((int) $user['id'], $username, 'LOGIN_FAILED', 'BAD_PASSWORD');
        if ($locked) {
            $this->logLogin((int) $user['id'], $username, 'ACCOUNT_LOCKED', "{$max} consecutive failures");
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private function completeLogin(array $user, string $password, string $now): void
    {
        $update = [
            'failed_login_count' => 0,
            'locked_until'       => null,
            'last_login_at'      => $now,
            'last_login_ip'      => mb_substr($this->context->ip(), 0, 45),
            'updated_at'         => $now,
        ];
        if ($this->policy->needsRehash($user['password_hash'])) {
            $update['password_hash'] = $this->policy->hash($password);
        }
        $this->db->table('users')->where('id', $user['id'])->update($update);

        // New session id, fresh CSRF token, bound to the account's security stamp.
        $this->session->regenerate(true);
        $this->session->remove(config('Security')->tokenName);
        $nowTs = $this->clock->nowUtc()->getTimestamp();
        $this->session->set([
            self::S_UID      => (int) $user['id'],
            self::S_STAMP    => $user['security_stamp'],
            self::S_LOGIN_AT => $nowTs,
            self::S_SEEN     => $nowTs,
            self::S_REAUTH   => 0,
        ]);
        $this->user = null;
        $this->logLogin((int) $user['id'], (string) $user['username'], 'LOGIN_SUCCESS', null);
    }

    private function logLogin(?int $userId, string $username, string $event, ?string $reason): void
    {
        $this->db->table('login_logs')->insert([
            'user_id'            => $userId,
            'username_attempted' => mb_substr($username, 0, 100),
            'event'              => $event,
            'failure_reason'     => $reason === null ? null : mb_substr($reason, 0, 100),
            'ip_address'         => mb_substr($this->context->ip(), 0, 45),
            'user_agent'         => $this->context->userAgent(),
            'created_at'         => $this->clock->nowUtcString(),
        ]);
    }
}
