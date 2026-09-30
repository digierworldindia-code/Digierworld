<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Libraries\Audit;
use App\Libraries\Crypto;
use App\Libraries\PasswordPolicy;
use App\Libraries\Rbac;
use App\Libraries\Tx;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Session\Session;
use Config\Polyfix;

/**
 * Sign-in, sessions and passwords.
 *
 * Carried over from the previous platform, rule for rule:
 *  - "no such account", "wrong password" and "account not active" produce the
 *    same message, and an unknown email still pays for one Argon2 check, so
 *    neither the words nor the timing reveal whether an account exists
 *  - lockout is per account; every failure is recorded with a hashed source
 *    address, so spraying many accounts from one address is visible
 *  - changing or resetting a password signs out
 *    every other device
 *
 * Sessions: CodeIgniter's database session holds only a session record id and
 * a random token. The `sessions` table row is the authority — revoking it ends
 * the session on its next request, from any device.
 */
final class AuthService
{
    private const SEEN_WRITE_INTERVAL = 60;

    public const GENERIC_FAILURE = 'That email and password combination was not recognised.';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Polyfix $config,
        private readonly Crypto $crypto,
    ) {
    }

    public static function instance(): self
    {
        return new self(db_connect(), config(Polyfix::class), Crypto::instance());
    }

    // =========================================================================
    // Sign in
    // =========================================================================

    /**
     * Email and password. There is no second step.
     *
     * @return array{status: 'signed_in', user_id: string}
     */
    public function attemptPassword(string $email, string $password, Session $session): array
    {
        $email = strtolower(trim($email));
        $user  = $this->findUserForLogin($email);

        if ($user === null) {
            PasswordPolicy::dummyVerify();
            $this->noteFailure($email, 'unknown_account');

            throw new AppException(self::GENERIC_FAILURE, 401, 'unknown account');
        }

        if ($user['locked_until'] !== null && strtotime($user['locked_until'] . ' UTC') > time()) {
            $this->noteFailure($email, 'locked');
            $minutes = (int) ceil((strtotime($user['locked_until'] . ' UTC') - time()) / 60);

            throw new AppException("Too many attempts. This account is locked for {$minutes} more minute(s).", 423, 'locked');
        }

        if (! PasswordPolicy::verify($user['password_hash'], $password)) {
            $this->registerFailedAttempt($user['id'], $email);

            throw new AppException(self::GENERIC_FAILURE, 401, 'bad password');
        }

        if ($user['status'] !== 'ACTIVE') {
            $this->noteFailure($email, 'status_' . $user['status']);

            throw new AppException(self::GENERIC_FAILURE, 401, 'user status ' . $user['status']);
        }

        // A dealer user is only as usable as their dealership.
        if ($user['dealer_id'] !== null && ($user['dealer_status'] !== 'ACTIVE' || $user['dealer_deleted_at'] !== null)) {
            throw AppException::forbidden('This dealership account is not active. Please contact ' . brand('shortName') . '.', 'dealer not active');
        }

        // Upgrade a hash produced under weaker parameters, now that we hold the password.
        if (PasswordPolicy::needsRehash($user['password_hash'])) {
            $this->db->table('users')->where('id', $user['id'])->update(['password_hash' => PasswordPolicy::hash($password)]);
        }

        $this->completeSignIn($user, $session);

        return ['status' => 'signed_in', 'user_id' => $user['id']];
    }

    private function completeSignIn(array $user, Session $session): void
    {
        $token     = Crypto::randomToken(32);
        $sessionId = uuid4();
        $now       = utc_now();

        Tx::run(function (BaseConnection $db) use ($user, $token, $sessionId, $now): void {
            $db->table('sessions')->insert([
                'id'              => $sessionId,
                'user_id'         => $user['id'],
                'token_hash'      => hash('sha256', $token),
                'ip'              => client_ip(),
                'user_agent'      => mb_substr((string) service('request')->getUserAgent(), 0, 400) ?: null,
                'created_at'      => $now,
                'last_seen_at'    => $now,
                'absolute_expiry' => gmdate('Y-m-d H:i:s', time() + $this->config->sessionAbsoluteSeconds) . '.000000',
            ]);

            $db->table('users')->where('id', $user['id'])->update([
                'failed_login_count' => 0,
                'locked_until'       => null,
                'last_login_at'      => $now,
                'last_login_ip'      => client_ip(),
            ]);

            Audit::instance($db)->record('LOGIN', 'user', $user['id'], null, null, null, [
                'user_id'    => $user['id'],
                'user_email' => $user['email'],
                'role_key'   => $user['roles'][0] ?? null,
                'dealer_id'  => $user['dealer_id'],
            ]);
        }, $this->db);

        // A fresh session id at the privilege change defeats session fixation.
        $session->regenerate(true);
        $session->set('auth', ['sid' => $sessionId, 'token' => $token]);
    }

    /**
     * Reads the signed-in user for this request from the session record.
     * Returns null — and the request is anonymous — if anything is off:
     * revoked, expired, idle too long, user or dealership deactivated.
     */
    public function resolve(Session $session): ?array
    {
        $auth = $session->get('auth');
        if (! is_array($auth) || ! is_uuid($auth['sid'] ?? null) || ! is_string($auth['token'] ?? null)) {
            return null;
        }

        $record = $this->db->table('sessions')->where('id', $auth['sid'])->get()->getRowArray();
        if ($record === null
            || ! hash_equals($record['token_hash'], hash('sha256', $auth['token']))
            || $record['revoked_at'] !== null
            || strtotime($record['absolute_expiry'] . ' UTC') <= time()
            || strtotime($record['last_seen_at'] . ' UTC') + $this->config->sessionIdleSeconds <= time()) {
            $session->remove('auth');

            return null;
        }

        $user = $this->findUserForLogin(null, $record['user_id']);
        if ($user === null || $user['status'] !== 'ACTIVE'
            || ($user['dealer_id'] !== null && ($user['dealer_status'] !== 'ACTIVE' || $user['dealer_deleted_at'] !== null))) {
            $this->revokeSession($record['id'], 'account no longer active');
            $session->remove('auth');

            return null;
        }

        if (strtotime($record['last_seen_at'] . ' UTC') + self::SEEN_WRITE_INTERVAL <= time()) {
            $this->db->table('sessions')->where('id', $record['id'])->update(['last_seen_at' => utc_now()]);
        }

        return [
            'id'                   => $user['id'],
            'email'                => $user['email'],
            'full_name'            => $user['full_name'],
            'roles'                => $user['roles'],
            'dealer_id'            => $user['dealer_id'],
            'dealer_name'          => $user['dealer_name'],
            'must_change_password' => (bool) $user['must_change_password'],
            'session_id'           => $record['id'],
        ];
    }

    public function signOut(Session $session): void
    {
        $auth = $session->get('auth');
        if (is_array($auth) && is_uuid($auth['sid'] ?? null)) {
            $this->revokeSession($auth['sid'], 'signed out');
            Audit::instance()->record('LOGOUT', 'user', service('requestContext')->userId());
        }
        $session->destroy();
    }

    // =========================================================================
    // Sessions
    // =========================================================================

    public function revokeSession(string $sessionId, string $reason): void
    {
        $this->db->table('sessions')->where('id', $sessionId)->where('revoked_at', null)
            ->update(['revoked_at' => utc_now(), 'revoked_reason' => mb_substr($reason, 0, 120)]);
    }

    public function revokeAllSessions(string $userId, string $reason, ?string $except = null): int
    {
        $builder = $this->db->table('sessions')->where('user_id', $userId)->where('revoked_at', null);
        if ($except !== null) {
            $builder->where('id !=', $except);
        }
        $builder->update(['revoked_at' => utc_now(), 'revoked_reason' => mb_substr($reason, 0, 120)]);

        return $this->db->affectedRows();
    }

    /** @return list<array<string,mixed>> */
    public function activeSessions(string $userId): array
    {
        return $this->db->table('sessions')
            ->select('id, ip, user_agent, created_at, last_seen_at')
            ->where('user_id', $userId)->where('revoked_at', null)
            ->where('absolute_expiry >', utc_now())
            ->orderBy('last_seen_at', 'DESC')->get()->getResultArray();
    }

    // =========================================================================
    // Passwords
    // =========================================================================

    public function changePassword(string $userId, string $current, string $new, string $keepSessionId): void
    {
        $user = $this->db->table('users')->where('id', $userId)->get()->getRowArray();

        if (! PasswordPolicy::verify($user['password_hash'], $current)) {
            throw new AppException('Your current password was not correct.', 422, 'current password wrong');
        }
        if (PasswordPolicy::verify($user['password_hash'], $new)) {
            throw AppException::rule('Your new password must be different from your current one.');
        }
        $problems = PasswordPolicy::problems($new, PasswordPolicy::contextFor($user['email'], $user['full_name']));
        if ($problems !== []) {
            throw AppException::rule('Your new password ' . implode(', ', $problems) . '.');
        }

        Tx::run(function (BaseConnection $db) use ($userId, $new): void {
            $db->table('users')->where('id', $userId)->update([
                'password_hash'        => PasswordPolicy::hash($new),
                'password_changed_at'  => utc_now(),
                'must_change_password' => 0,
            ]);
            Audit::instance($db)->record('PASSWORD_CHANGED', 'user', $userId);
        }, $this->db);

        // If the old password was compromised, the attacker's session ends here.
        $this->revokeAllSessions($userId, 'password changed', $keepSessionId);
    }

    /**
     * Always behaves the same way. Whether an address is registered is not
     * something an anonymous visitor is entitled to learn.
     *
     * @return string|null the raw token to email, or null if nothing was sent
     */
    public function requestPasswordReset(string $email): ?string
    {
        $user = $this->db->table('users')->where('email', strtolower(trim($email)))
            ->where('deleted_at', null)->get()->getRowArray();

        if ($user === null || $user['status'] === 'DISABLED') {
            return null;
        }

        $token = Crypto::randomToken(32);
        Tx::run(function (BaseConnection $db) use ($user, $token): void {
            // Only the newest link works.
            $db->table('password_reset_tokens')->where('user_id', $user['id'])->where('used_at', null)
                ->update(['used_at' => utc_now()]);
            $db->table('password_reset_tokens')->insert([
                'id'         => uuid4(),
                'user_id'    => $user['id'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + $this->config->passwordResetTtlSeconds) . '.000000',
                'created_ip' => client_ip(),
                'created_at' => utc_now(),
            ]);
        }, $this->db);

        return $token;
    }

    public function resetPassword(string $token, string $new): void
    {
        $record = $this->db->table('password_reset_tokens t')
            ->select('t.id, t.user_id, t.used_at, t.expires_at, u.email, u.full_name')
            ->join('users u', 'u.id = t.user_id')
            ->where('t.token_hash', hash('sha256', $token))->get()->getRowArray();

        if ($record === null || $record['used_at'] !== null || strtotime($record['expires_at'] . ' UTC') <= time()) {
            throw AppException::rule('That reset link is no longer valid. Request a new one.');
        }
        $problems = PasswordPolicy::problems($new, PasswordPolicy::contextFor($record['email'], $record['full_name']));
        if ($problems !== []) {
            throw AppException::rule('Your new password ' . implode(', ', $problems) . '.');
        }

        Tx::run(function (BaseConnection $db) use ($record, $new): void {
            $db->table('users')->where('id', $record['user_id'])->update([
                'password_hash'        => PasswordPolicy::hash($new),
                'password_changed_at'  => utc_now(),
                'must_change_password' => 0,
                'failed_login_count'   => 0,
                'locked_until'         => null,
            ]);
            $db->table('password_reset_tokens')->where('id', $record['id'])->update(['used_at' => utc_now()]);
            Audit::instance($db)->record('PASSWORD_RESET', 'user', $record['user_id'], null, null, null, [
                'user_id' => $record['user_id'], 'user_email' => $record['email'],
            ]);
        }, $this->db);

        $this->revokeAllSessions($record['user_id'], 'password reset');
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** One user with roles and dealer link, or null. By email or by id. */
    private function findUserForLogin(?string $email, ?string $id = null): ?array
    {
        $builder = $this->db->table('users u')
            ->select('u.*, du.dealer_id, d.status AS dealer_status, d.deleted_at AS dealer_deleted_at, d.business_name AS dealer_name')
            ->join('dealer_users du', 'du.user_id = u.id', 'left')
            ->join('dealers d', 'd.id = du.dealer_id', 'left')
            ->where('u.deleted_at', null);
        $email !== null ? $builder->where('u.email', $email) : $builder->where('u.id', $id);

        $user = $builder->get()->getRowArray();
        if ($user === null) {
            return null;
        }

        $user['roles'] = array_column(
            $this->db->table('user_roles ur')->select('r.key')->join('roles r', 'r.id = ur.role_id')
                ->where('ur.user_id', $user['id'])->orderBy('r.key')->get()->getResultArray(),
            'key',
        );
        // Keep the most senior role first: it is the one recorded on audit entries.
        usort($user['roles'], static fn ($a, $b) => (Rbac::ROLE_METADATA[$a]['rank'] ?? 99) <=> (Rbac::ROLE_METADATA[$b]['rank'] ?? 99));

        return $user;
    }

    private function registerFailedAttempt(string $userId, string $email, bool $note = true): void
    {
        $this->db->query('UPDATE users SET failed_login_count = failed_login_count + 1 WHERE id = ?', [$userId]);
        $count = (int) $this->db->table('users')->select('failed_login_count')->where('id', $userId)->get()->getRow()->failed_login_count;

        if ($count >= $this->config->loginMaxAttempts) {
            $this->db->table('users')->where('id', $userId)->update([
                'locked_until'       => gmdate('Y-m-d H:i:s', time() + $this->config->loginLockoutSeconds) . '.000000',
                'failed_login_count' => 0,
            ]);
            log_message('warning', 'security.ACCOUNT_LOCKED user={user}', ['user' => $userId]);
        }

        if ($note) {
            $this->noteFailure($email, 'bad_password');
        }
    }

    private function noteFailure(string $email, string $reason): void
    {
        $this->db->table('failed_login_attempts')->insert([
            'email'        => mb_substr($email, 0, 255),
            // The address is hashed with a secret: repeated sources are
            // visible, raw addresses are not stored.
            'ip_hash'      => hash('sha256', client_ip() . ':' . $this->config->signingSecret),
            'user_agent'   => mb_substr((string) service('request')->getUserAgent(), 0, 400) ?: null,
            'reason'       => $reason,
            'attempted_at' => utc_now(),
        ]);
        log_message('notice', 'security.LOGIN_FAILED reason={reason}', ['reason' => $reason]);
    }
}
