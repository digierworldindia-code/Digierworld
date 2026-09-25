<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Libraries\Audit;
use App\Libraries\Crypto;
use App\Libraries\PasswordPolicy;
use App\Libraries\Rbac;
use App\Libraries\Tx;
use CodeIgniter\Database\BaseConnection;

/**
 * Platform accounts: creation, status, roles, administrative resets.
 *
 * Three rules from the previous platform, unchanged:
 *   - nobody can create or change an account more senior than their own
 *     (role rank: lower number = more senior);
 *   - nobody can change their own roles;
 *   - a dealer login holds the DEALER role only, linked to one dealership.
 * Passwords are generated, shown once, never chosen by the administrator,
 * never stored readable and never logged.
 */
final class UserService
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public static function instance(): self
    {
        return new self(db_connect());
    }

    /** Most senior rank among the roles (lower is more senior); 1000 for none. */
    public function rankOf(array $roleKeys): int
    {
        if ($roleKeys === []) {
            return 1000;
        }
        $row = $this->db->table('roles')->selectMin('rank', 'r')->whereIn('key', $roleKeys)->get()->getRow();

        return (int) ($row->r ?? 1000);
    }

    /** @return list<string> */
    public function rolesOf(string $userId): array
    {
        return array_column($this->db->table('user_roles ur')->select('r.key')->join('roles r', 'r.id = ur.role_id')
            ->where('ur.user_id', $userId)->get()->getResultArray(), 'key');
    }

    /**
     * @param array{email:string, full_name:string, phone?:?string, roles:list<string>, dealer_id?:?string} $in
     * @param list<string>|null $actorRoles null only for the command line (first administrator)
     *
     * @return array{id:string, email:string, temporary_password:string}
     */
    public function create(array $in, ?array $actorRoles): array
    {
        $roles = array_values(array_unique($in['roles']));
        $email = strtolower(trim($in['email']));

        if ($roles === [] || array_diff($roles, Rbac::ROLE_KEYS) !== []) {
            throw AppException::rule('Choose at least one valid role.');
        }
        if ($actorRoles !== null && $this->rankOf($roles) < $this->rankOf($actorRoles)) {
            log_message('warning', 'security.PRIVILEGE_ESCALATION_BLOCKED attempted={roles}', ['roles' => implode(',', $roles)]);

            throw AppException::forbidden('You cannot create an account with a role senior to your own.');
        }
        if (in_array('DEALER', $roles, true)) {
            if (count($roles) > 1) {
                throw AppException::rule('A dealer login cannot also hold a staff role. Create a separate account for staff duties.');
            }
            if (empty($in['dealer_id'])) {
                throw AppException::rule('A dealer user must be linked to a dealer.');
            }
        } elseif (! empty($in['dealer_id'])) {
            throw AppException::rule('Only a dealer login can be linked to a dealership.');
        }
        if ($this->db->table('users')->where('email', $email)->countAllResults() > 0) {
            throw AppException::conflict('An account with that email address already exists.');
        }

        $password = self::temporaryPassword($email);
        $id       = uuid4();

        Tx::run(function (BaseConnection $db) use ($in, $roles, $email, $password, $id): void {
            if (! empty($in['dealer_id']) && $db->table('dealers')->where(['id' => $in['dealer_id'], 'deleted_at' => null])->countAllResults() === 0) {
                throw AppException::notFound('dealer');
            }
            $actor = service('requestContext')->userId();
            $db->table('users')->insert([
                'id'                   => $id,
                'email'                => $email,
                'full_name'            => $in['full_name'],
                'phone'                => ($in['phone'] ?? '') === '' ? null : $in['phone'],
                'password_hash'        => PasswordPolicy::hash($password),
                'status'               => 'ACTIVE',
                'must_change_password' => 1,
                'password_changed_at'  => utc_now(),
                'created_by'           => $actor,
            ]);
            foreach ($this->roleIds($roles) as $roleId) {
                $db->table('user_roles')->insert(['user_id' => $id, 'role_id' => $roleId, 'assigned_by' => $actor]);
            }
            if (! empty($in['dealer_id'])) {
                $db->table('dealer_users')->insert(['user_id' => $id, 'dealer_id' => $in['dealer_id'], 'is_primary' => 0]);
            }
            Audit::instance($db)->record('USER_CREATED', 'user', $id, null, ['email' => $email, 'roles' => $roles, 'dealerId' => $in['dealer_id'] ?? null]);
        }, $this->db);

        return ['id' => $id, 'email' => $email, 'temporary_password' => $password];
    }

    public function update(string $id, array $in, array $actorRoles): void
    {
        $before = $this->find($id);
        $this->assertManageable($before, $actorRoles);
        if ($id === service('requestContext')->userId() && ($in['status'] ?? 'ACTIVE') !== 'ACTIVE') {
            throw AppException::rule('You cannot suspend or disable your own account.');
        }

        Tx::run(function (BaseConnection $db) use ($id, $in, $before): void {
            $db->table('users')->where('id', $id)->update([
                'full_name'  => $in['full_name'],
                'phone'      => ($in['phone'] ?? '') === '' ? null : $in['phone'],
                'status'     => $in['status'],
                'updated_by' => service('requestContext')->userId(),
            ]);
            if ($in['status'] !== 'ACTIVE') {
                (new AuthService($db, config('Polyfix'), Crypto::instance()))->revokeAllSessions($id, 'account ' . strtolower($in['status']));
            }
            Audit::instance($db)->record('USER_UPDATED', 'user', $id,
                ['status' => $before['status'], 'fullName' => $before['full_name'], 'phone' => $before['phone']],
                ['status' => $in['status'], 'fullName' => $in['full_name'], 'phone' => $in['phone'] ?? null]);
        }, $this->db);
    }

    /** @param list<string> $roles */
    public function assignRoles(string $id, array $roles, string $reason, array $actorRoles): void
    {
        $roles = array_values(array_unique($roles));
        if ($id === service('requestContext')->userId()) {
            log_message('warning', 'security.SELF_ROLE_CHANGE_BLOCKED');

            throw AppException::forbidden('You cannot change your own roles. Ask another administrator.');
        }
        if ($roles === [] || array_diff($roles, Rbac::ROLE_KEYS) !== []) {
            throw AppException::rule('Choose at least one valid role.');
        }
        if ($this->rankOf($roles) < $this->rankOf($actorRoles)) {
            log_message('warning', 'security.PRIVILEGE_ESCALATION_BLOCKED attempted={roles}', ['roles' => implode(',', $roles)]);

            throw AppException::forbidden('You cannot grant a role senior to your own.');
        }
        $user     = $this->find($id);
        $previous = $this->rolesOf($id);
        $this->assertManageable($user, $actorRoles);

        $isDealer = $this->db->table('dealer_users')->where('user_id', $id)->countAllResults() > 0;
        if ($isDealer !== in_array('DEALER', $roles, true) || ($isDealer && count($roles) > 1)) {
            throw AppException::rule('Dealer logins hold the Dealer role only, and staff accounts cannot be given it. Create a separate account instead.');
        }

        Tx::run(function (BaseConnection $db) use ($id, $roles, $previous, $reason): void {
            $db->table('user_roles')->where('user_id', $id)->delete();
            foreach ($this->roleIds($roles) as $roleId) {
                $db->table('user_roles')->insert(['user_id' => $id, 'role_id' => $roleId, 'assigned_by' => service('requestContext')->userId()]);
            }
            // Permissions are read from the database on every request, but
            // ending the sessions makes the change unambiguous and immediate.
            (new AuthService($db, config('Polyfix'), Crypto::instance()))->revokeAllSessions($id, 'roles changed');
            Audit::instance($db)->record('USER_ROLES_CHANGED', 'user', $id, ['roles' => $previous], ['roles' => $roles], $reason);
        }, $this->db);
    }

    /** @return string the new temporary password, to be handed over out of band */
    public function forcePasswordReset(string $id, string $reason, array $actorRoles): string
    {
        $user = $this->find($id);
        $this->assertManageable($user, $actorRoles);
        $password = self::temporaryPassword($user['email']);

        Tx::run(function (BaseConnection $db) use ($id, $password, $reason): void {
            $db->table('users')->where('id', $id)->update([
                'password_hash'        => PasswordPolicy::hash($password),
                'must_change_password' => 1,
                'password_changed_at'  => utc_now(),
                'failed_login_count'   => 0,
                'locked_until'         => null,
            ]);
            (new AuthService($db, config('Polyfix'), Crypto::instance()))->revokeAllSessions($id, 'administrative password reset');
            Audit::instance($db)->record('USER_PASSWORD_RESET_BY_ADMIN', 'user', $id, null, null, $reason);
        }, $this->db);

        return $password;
    }

    /** Clears two-factor so the person can enrol a new phone at next sign-in. */
    public function resetMfa(string $id, string $reason, array $actorRoles): void
    {
        $user = $this->find($id);
        $this->assertManageable($user, $actorRoles);
        if ($id === service('requestContext')->userId()) {
            throw AppException::forbidden('Ask another administrator to reset your own two-factor authentication.');
        }

        Tx::run(function (BaseConnection $db) use ($id, $reason): void {
            $db->table('users')->where('id', $id)->update([
                'mfa_enabled' => 0, 'mfa_secret_encrypted' => null, 'mfa_recovery_codes' => null, 'mfa_enrolled_at' => null,
            ]);
            (new AuthService($db, config('Polyfix'), Crypto::instance()))->revokeAllSessions($id, 'two-factor reset by administrator');
            Audit::instance($db)->record('USER_MFA_RESET_BY_ADMIN', 'user', $id, null, null, $reason);
        }, $this->db);
    }

    public function find(string $id): array
    {
        return $this->db->table('users')->select('id, email, full_name, phone, status')
            ->where(['id' => $id, 'deleted_at' => null])->get()->getRowArray() ?? throw AppException::notFound('user');
    }

    private function assertManageable(array $user, array $actorRoles): void
    {
        if ($this->rankOf($this->rolesOf($user['id'])) < $this->rankOf($actorRoles)) {
            throw AppException::forbidden('You cannot change an account more senior than your own.');
        }
    }

    /** @return list<string> */
    private function roleIds(array $keys): array
    {
        return array_column($this->db->table('roles')->select('id')->whereIn('key', $keys)->get()->getResultArray(), 'id');
    }

    /** Generated, policy-compliant, single-use: the holder must replace it at first sign-in. */
    public static function temporaryPassword(string $email): string
    {
        do {
            $password = Crypto::randomToken(12) . 'Aa1!';
        } while (PasswordPolicy::problems($password, PasswordPolicy::contextFor($email, '')) !== []);

        return $password;
    }
}
