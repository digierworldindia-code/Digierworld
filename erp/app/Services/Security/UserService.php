<?php

namespace App\Services\Security;

use App\Exceptions\BusinessRuleException;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

/**
 * Creates and manages users (Shield identities + profile + DB roles).
 * Enforces "never remove the last active Super Admin".
 */
class UserService
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param list<string> $roleCodes
     *
     * @throws BusinessRuleException
     */
    public function create(string $email, string $fullName, string $password, array $roleCodes, array $profile = []): int
    {
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BusinessRuleException('A valid email address is required.', ['email' => 'Enter a valid email address.']);
        }
        if ($this->db->table('auth_identities')->where(['type' => 'email_password', 'secret' => $email])->countAllResults() > 0) {
            throw new BusinessRuleException('A user with this email already exists.', ['email' => 'Email already in use.']);
        }
        $this->assertPasswordStrength($password, $email, $fullName);

        /** @var UserModel $users */
        $users = auth()->getProvider();
        $user  = new User([
            'username' => $this->uniqueUsername($email),
            'email'    => $email,
            'password' => $password,
            'active'   => 1,
        ]);

        $this->db->transException(true)->transStart();
        $users->save($user);
        $userId = (int) $users->getInsertID();
        $this->db->table('user_profiles')->insert([
            'user_id'              => $userId,
            'full_name'            => $fullName,
            'phone'                => $profile['phone'] ?? null,
            'designation'          => $profile['designation'] ?? null,
            'manager_user_id'      => $profile['manager_user_id'] ?? null,
            'is_service_account'   => (int) ($profile['is_service_account'] ?? 0),
            'must_change_password' => (int) ($profile['must_change_password'] ?? 0),
            'created_by'           => service('authz')->currentUserId(),
        ]);
        $this->assignRoles($userId, $roleCodes, $profile['role_expires_at'] ?? null);
        service('audit')->log('user.created', 'user', $userId, null, ['email' => $email, 'full_name' => $fullName, 'roles' => $roleCodes], 'User created: ' . $email);
        $this->db->transComplete();

        return $userId;
    }

    /** @param list<string> $roleCodes */
    public function assignRoles(int $userId, array $roleCodes, ?string $expiresAt = null): void
    {
        $actor = service('authz')->currentUserId();
        foreach ($roleCodes as $code) {
            $role = $this->db->table('roles')->where('code', $code)->get()->getRowArray();
            if ($role === null) {
                throw new BusinessRuleException("Unknown role: {$code}");
            }
            $this->db->query(
                'INSERT INTO user_roles (user_id, role_id, expires_at, assigned_by) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at), assigned_by = VALUES(assigned_by)',
                [$userId, $role['id'], $expiresAt, $actor]
            );
        }
        $this->touchPermissions($userId);
    }

    /**
     * Replace a user's roles (by role id) with protections.
     *
     * @param array<int, string|null> $roleIdToExpiry role_id => expires_at|null
     */
    public function syncRoles(int $userId, array $roleIdToExpiry): void
    {
        $before = $this->roleIds($userId);
        $superIds = array_map('intval', array_column($this->db->table('roles')->select('id')->where('is_super', 1)->get()->getResultArray(), 'id'));
        $losingSuper = array_intersect($before, $superIds) !== [] && array_intersect(array_keys($roleIdToExpiry), $superIds) === [];
        if ($losingSuper && $this->activeSuperAdminCount() <= 1) {
            throw new BusinessRuleException('This is the last active Owner/Super Admin. Assign another Super Admin before removing this role.');
        }
        $actor = service('authz')->currentUserId();
        $this->db->transException(true)->transStart();
        $this->db->table('user_roles')->where('user_id', $userId)->delete();
        foreach ($roleIdToExpiry as $roleId => $exp) {
            $this->db->table('user_roles')->insert(['user_id' => $userId, 'role_id' => (int) $roleId, 'expires_at' => $exp ?: null, 'assigned_by' => $actor]);
        }
        $this->touchPermissions($userId);
        service('audit')->log('user.roles_changed', 'user', $userId, ['role_ids' => $before], ['role_ids' => array_keys($roleIdToExpiry)], 'Roles changed');
        $this->db->transComplete();
    }

    /** @return list<int> */
    public function roleIds(int $userId): array
    {
        return array_map('intval', array_column($this->db->table('user_roles')->select('role_id')->where('user_id', $userId)->get()->getResultArray(), 'role_id'));
    }

    public function activeSuperAdminCount(): int
    {
        return (int) $this->db->table('user_roles ur')
            ->join('roles r', 'r.id = ur.role_id')
            ->join('users u', 'u.id = ur.user_id')
            ->where('r.is_super', 1)->where('r.is_active', 1)->where('u.active', 1)->where('u.deleted_at', null)
            ->groupStart()->where('ur.expires_at', null)->orWhere('ur.expires_at >', date('Y-m-d H:i:s'))->groupEnd()
            ->countAllResults();
    }

    public function isSuperAdmin(int $userId): bool
    {
        return service('authz')->isSuper($userId);
    }

    public function setActive(int $userId, bool $active): void
    {
        if (! $active && $this->isSuperAdmin($userId) && $this->activeSuperAdminCount() <= 1) {
            throw new BusinessRuleException('You cannot deactivate the last active Owner/Super Admin.');
        }
        $this->db->table('users')->where('id', $userId)->update(['active' => $active ? 1 : 0]);
        if (! $active) {
            $this->revokeSessions($userId, 'Account deactivated');
        }
        service('audit')->log($active ? 'user.activated' : 'user.deactivated', 'user', $userId, null, null, $active ? 'User activated' : 'User deactivated');
    }

    public function resetPassword(int $userId, string $password, bool $forceChange = true): void
    {
        $email = $this->db->table('auth_identities')->select('secret')->where(['user_id' => $userId, 'type' => 'email_password'])->get()->getRowArray()['secret'] ?? '';
        $name  = $this->db->table('user_profiles')->select('full_name')->where('user_id', $userId)->get()->getRowArray()['full_name'] ?? '';
        $this->assertPasswordStrength($password, $email, $name);
        $users = auth()->getProvider();
        $user  = $users->findById($userId) ?? throw new BusinessRuleException('User not found.');
        $user->setPassword($password);
        $users->save($user);
        $this->db->table('user_profiles')->where('user_id', $userId)->update(['must_change_password' => $forceChange ? 1 : 0]);
        $this->revokeSessions($userId, 'Password reset', true);
        service('audit')->log('user.password_reset', 'user', $userId, null, null, 'Password reset' . ($forceChange ? ' (change required)' : ''));
    }

    /** Revoke all sessions (optionally except the caller's current one) and remember-me tokens. */
    public function revokeSessions(int $userId, string $reason, bool $keepCurrent = false): int
    {
        $builder = $this->db->table('user_sessions')->where('user_id', $userId)->where('revoked_at', null);
        if ($keepCurrent && service('authz')->currentUserId() === $userId && session_id()) {
            $builder->where('session_id !=', session_id());
        }
        $builder->update(['revoked_at' => date('Y-m-d H:i:s'), 'revoked_by' => service('authz')->currentUserId(), 'revoke_reason' => $reason]);
        $count = $this->db->affectedRows();
        $this->db->table('auth_remember_tokens')->where('user_id', $userId)->delete();

        return $count;
    }

    public function touchPermissions(int $userId): void
    {
        $this->db->table('user_profiles')->where('user_id', $userId)->update(['permissions_changed_at' => date('Y-m-d H:i:s')]);
        service('authz')->forget($userId);
    }

    /** @throws BusinessRuleException */
    public function assertPasswordStrength(string $password, string $email = '', string $name = ''): void
    {
        $min = (int) config('Auth')->minimumPasswordLength;
        $errors = [];
        if (mb_strlen($password) < $min) {
            $errors[] = "at least {$min} characters";
        }
        if (! preg_match('/[A-Za-z]/', $password) || ! preg_match('/\d/', $password)) {
            $errors[] = 'letters and numbers';
        }
        $lower = strtolower($password);
        $local = strtolower(strstr($email, '@', true) ?: '');
        if ($local !== '' && strlen($local) >= 3 && str_contains($lower, $local)) {
            $errors[] = 'not contain your email name';
        }
        foreach (preg_split('/\s+/', strtolower($name)) ?: [] as $part) {
            if (strlen($part) >= 4 && str_contains($lower, $part)) {
                $errors[] = 'not contain your name';
                break;
            }
        }
        if (in_array($lower, ['password123', 'qwerty12345', '1234567890', 'admin12345'], true)) {
            $errors[] = 'not be a common password';
        }
        if ($errors !== []) {
            throw new BusinessRuleException('Password must have ' . implode(', ', $errors) . '.', ['password' => 'Password must have ' . implode(', ', $errors) . '.']);
        }
    }

    private function uniqueUsername(string $email): string
    {
        $base = preg_replace('/[^a-z0-9.]/', '', strtolower(strstr($email, '@', true) ?: 'user')) ?: 'user';
        $base = substr($base, 0, 24);
        $name = $base;
        $i    = 1;
        while ($this->db->table('users')->where('username', $name)->countAllResults() > 0) {
            $name = substr($base, 0, 24) . (++$i);
        }

        return $name;
    }

    public static function randomPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out      = '';
        for ($i = 0; $i < 14; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out . random_int(10, 99);
    }
}
