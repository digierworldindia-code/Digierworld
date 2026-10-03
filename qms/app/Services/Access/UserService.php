<?php

namespace App\Services\Access;

use App\Exceptions\AuthorizationException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Paging;
use App\Services\Audit\AuditService;
use App\Services\Auth\AuthService;
use App\Services\Auth\PasswordPolicy;
use App\Services\Support\Clock;
use App\Services\Support\TransactionRunner;
use CodeIgniter\Database\BaseConnection;

/**
 * User administration. Users are never deleted, only disabled. New accounts and
 * admin resets get a one-time password that must be changed at next login.
 */
class UserService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly AuthService $auth,
        private readonly PasswordPolicy $policy,
        private readonly AuditService $audit,
        private readonly Clock $clock,
        private readonly TransactionRunner $tx,
    ) {
    }

    /**
     * @param array<string, string> $filters q, role_id, status
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $filters, Paging $paging): array
    {
        $builder = $this->db->table('users u')
            ->select('u.id, u.username, u.email, u.status, u.locked_until, u.last_login_at, u.must_change_password,
                      r.name AS role_name, r.code AS role_code, e.employee_code, e.full_name')
            ->join('roles r', 'r.id = u.role_id')
            ->join('employees e', 'e.id = u.employee_id', 'left');

        if (($filters['q'] ?? '') !== '') {
            $builder->groupStart()
                ->like('u.username', $filters['q'])
                ->orLike('e.full_name', $filters['q'])
                ->orLike('e.employee_code', $filters['q'])
                ->groupEnd();
        }
        if (($filters['role_id'] ?? '') !== '') {
            $builder->where('u.role_id', (int) $filters['role_id']);
        }
        if (in_array($filters['status'] ?? '', ['ACTIVE', 'DISABLED'], true)) {
            $builder->where('u.status', $filters['status']);
        }

        $paging->total = $builder->countAllResults(false);

        return $builder->orderBy('u.username')->get($paging->perPage, $paging->offset())->getResultArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $row = $this->db->table('users u')
            ->select('u.*, r.code AS role_code, r.is_super_admin, e.employee_code, e.full_name')
            ->join('roles r', 'r.id = u.role_id')
            ->join('employees e', 'e.id = u.employee_id', 'left')
            ->where('u.id', $id)->get(1)->getRowArray();

        if ($row === null) {
            throw new NotFoundException('User not found.');
        }
        unset($row['password_hash'], $row['security_stamp']);

        return $row;
    }

    /**
     * @param array<string, mixed> $data username, email, role_id, employee_id
     * @param array<string, mixed> $actor
     *
     * @return array{id: int, password: string}
     */
    public function create(array $data, array $actor): array
    {
        $clean = $this->validate($data, null, $actor);
        $password = $this->policy->generate(14);
        $now      = $this->clock->nowUtcString();

        return $this->tx->run(function (BaseConnection $db) use ($clean, $password, $now, $actor): array {
            $db->table('users')->insert($clean + [
                'password_hash'        => $this->policy->hash($password),
                'status'               => 'ACTIVE',
                'must_change_password' => 1,
                'security_stamp'       => bin2hex(random_bytes(16)),
                'created_at'           => $now,
                'created_by'           => $actor['id'],
                'updated_at'           => $now,
                'updated_by'           => $actor['id'],
            ]);
            $id = (int) $db->insertID();
            $this->audit->log('CREATE', 'user', $id, null, $clean, $clean['username']);

            return ['id' => $id, 'password' => $password];
        });
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $actor
     */
    public function update(int $id, array $data, array $actor): void
    {
        $before = $this->find($id);
        $clean  = $this->validate($data, $before, $actor);
        unset($clean['username']); // usernames are immutable

        if ((int) $clean['role_id'] !== (int) $before['role_id'] && $id === (int) $actor['id']) {
            throw ValidationException::single('role_id', 'You cannot change your own role.');
        }

        $now = $this->clock->nowUtcString();
        $this->tx->run(function (BaseConnection $db) use ($id, $clean, $before, $now, $actor): void {
            $update = $clean + ['updated_at' => $now, 'updated_by' => $actor['id']];
            if ((int) $clean['role_id'] !== (int) $before['role_id']) {
                $update['security_stamp'] = bin2hex(random_bytes(16)); // re-login with the new role
            }
            $db->table('users')->where('id', $id)->update($update);
            $this->audit->log('UPDATE', 'user', $id, array_intersect_key($before, $clean), $clean, (string) $before['username']);
        });
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function resetPassword(int $id, array $actor): string
    {
        $user     = $this->db->table('users')->where('id', $id)->get(1)->getRowArray() ?? throw new NotFoundException('User not found.');
        $password = $this->policy->generate(14);
        $this->auth->setPassword($user, $password, true);
        $this->logEvent($id, (string) $user['username'], 'PASSWORD_RESET', 'by ' . $actor['username']);

        return $password;
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function unlock(int $id, array $actor): void
    {
        $user = $this->find($id);
        $this->tx->run(function (BaseConnection $db) use ($id, $user, $actor): void {
            $db->table('users')->where('id', $id)->update([
                'failed_login_count' => 0, 'locked_until' => null,
                'updated_at' => $this->clock->nowUtcString(), 'updated_by' => $actor['id'],
            ]);
            $this->audit->log('UNLOCK', 'user', $id, ['locked_until' => $user['locked_until']], ['locked_until' => null], (string) $user['username']);
            $this->logEvent($id, (string) $user['username'], 'ACCOUNT_UNLOCKED', 'by ' . $actor['username']);
        });
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function toggleStatus(int $id, array $actor): string
    {
        if ($id === (int) $actor['id']) {
            throw new AuthorizationException('You cannot disable your own account.');
        }
        $user   = $this->find($id);
        $status = $user['status'] === 'ACTIVE' ? 'DISABLED' : 'ACTIVE';

        $this->tx->run(function (BaseConnection $db) use ($id, $user, $status, $actor): void {
            $db->table('users')->where('id', $id)->update([
                'status' => $status, 'security_stamp' => bin2hex(random_bytes(16)),
                'updated_at' => $this->clock->nowUtcString(), 'updated_by' => $actor['id'],
            ]);
            $this->audit->log($status === 'ACTIVE' ? 'ENABLE' : 'DISABLE', 'user', $id, ['status' => $user['status']], ['status' => $status], (string) $user['username']);
        });

        return $status;
    }

    /**
     * @return list<array<string, mixed>> roles for selects
     */
    public function roles(): array
    {
        return $this->db->table('roles')->select('id, code, name, is_super_admin')->orderBy('id')->get()->getResultArray();
    }

    /**
     * Active employees without a login (plus the given one) for the link select.
     *
     * @return list<array<string, mixed>>
     */
    public function linkableEmployees(?int $current = null): array
    {
        $builder = $this->db->table('employees e')
            ->select('e.id, e.employee_code, e.full_name')
            ->join('users u', 'u.employee_id = e.id', 'left')
            ->where('e.is_active', 1)
            ->groupStart()->where('u.id', null);
        if ($current !== null) {
            $builder->orWhere('e.id', $current);
        }

        return $builder->groupEnd()->orderBy('e.full_name')->get()->getResultArray();
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed>      $actor
     *
     * @return array<string, mixed>
     */
    private function validate(array $data, ?array $existing, array $actor): array
    {
        $errors   = [];
        $username = trim((string) ($data['username'] ?? ($existing['username'] ?? '')));
        $email    = trim((string) ($data['email'] ?? ''));
        $roleId   = (int) ($data['role_id'] ?? 0);
        $employee = ($data['employee_id'] ?? '') === '' ? null : (int) $data['employee_id'];

        if ($existing === null) {
            if (! preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
                $errors['username'] = 'Use 3-50 characters: letters, digits, dot, dash, underscore.';
            } elseif ($this->db->table('users')->where('username', $username)->countAllResults() > 0) {
                $errors['username'] = 'This username is already taken.';
            }
        }
        if ($email !== '' && (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150)) {
            $errors['email'] = 'Enter a valid e-mail address.';
        } elseif ($email !== '') {
            $dup = $this->db->table('users')->where('email', $email);
            if ($existing !== null) {
                $dup->where('id !=', $existing['id']);
            }
            if ($dup->countAllResults() > 0) {
                $errors['email'] = 'This e-mail address is already used by another account.';
            }
        }

        $role = $this->db->table('roles')->where('id', $roleId)->get(1)->getRowArray();
        if ($role === null) {
            $errors['role_id'] = 'Choose a role.';
        } elseif ((int) $role['is_super_admin'] === 1 && (int) ($actor['is_super_admin'] ?? 0) !== 1) {
            $errors['role_id'] = 'Only a Super Admin can assign the Super Admin role.';
        }

        if ($employee !== null) {
            $taken = $this->db->table('users')->where('employee_id', $employee);
            if ($existing !== null) {
                $taken->where('id !=', $existing['id']);
            }
            if ($this->db->table('employees')->where('id', $employee)->where('is_active', 1)->countAllResults() === 0) {
                $errors['employee_id'] = 'Choose an active employee.';
            } elseif ($taken->countAllResults() > 0) {
                $errors['employee_id'] = 'This employee already has a login.';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['username' => $username, 'email' => $email === '' ? null : $email, 'role_id' => $roleId, 'employee_id' => $employee];
    }

    private function logEvent(int $userId, string $username, string $event, string $reason): void
    {
        $this->db->table('login_logs')->insert([
            'user_id'            => $userId,
            'username_attempted' => $username,
            'event'              => $event,
            'failure_reason'     => mb_substr($reason, 0, 100),
            'ip_address'         => mb_substr(service('requestContext')->ip(), 0, 45),
            'user_agent'         => service('requestContext')->userAgent(),
            'created_at'         => $this->clock->nowUtcString(),
        ]);
    }
}
