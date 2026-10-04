<?php

namespace App\Services\Access;

use App\Exceptions\AuthorizationException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Services\Audit\AuditService;
use App\Services\Support\Clock;
use App\Services\Support\TransactionRunner;
use App\Core\Database;

/**
 * Roles and the permission matrix. The Super Admin role always holds every
 * permission and cannot be edited; system roles cannot be deleted.
 */
class RoleService
{
    public function __construct(
        private readonly Database $db,
        private readonly AuditService $audit,
        private readonly Clock $clock,
        private readonly TransactionRunner $tx,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return $this->db->table('roles r')
            ->select('r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count,
                      (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count')
            ->orderBy('r.id')->get()->getResultArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        return $this->db->table('roles')->where('id', $id)->get(1)->getRowArray() ?? throw new NotFoundException('Role not found.');
    }

    /**
     * Permissions grouped by module, each flagged when the role holds it.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function matrix(int $roleId): array
    {
        $role    = $this->find($roleId);
        $granted = array_fill_keys(array_column($this->db->table('role_permissions')->select('permission_id')
            ->where('role_id', $roleId)->get()->getResultArray(), 'permission_id'), true);
        $grouped = [];
        foreach ($this->db->table('permissions')->orderBy('id')->get()->getResultArray() as $perm) {
            $perm['granted']            = (int) $role['is_super_admin'] === 1 || isset($granted[$perm['id']]);
            $grouped[$perm['module']][] = $perm;
        }

        return $grouped;
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function create(string $code, string $name, string $description, array $actor): int
    {
        $code = strtoupper(trim($code));
        if (! preg_match('/^[A-Z][A-Z0-9_]{2,29}$/', $code)) {
            throw ValidationException::single('code', 'Code: 3-30 characters, capital letters, digits and underscore.');
        }
        if (trim($name) === '' || mb_strlen($name) > 60) {
            throw ValidationException::single('name', 'Enter a name (max 60 characters).');
        }
        if ($this->db->table('roles')->where('code', $code)->countAllResults() > 0) {
            throw ValidationException::single('code', 'This role code already exists.');
        }

        return $this->tx->run(function (Database $db) use ($code, $name, $description, $actor): int {
            $now = $this->clock->nowUtcString();
            $db->table('roles')->insert([
                'code' => $code, 'name' => trim($name), 'description' => mb_substr(trim($description), 0, 255) ?: null,
                'is_system' => 0, 'is_super_admin' => 0, 'created_at' => $now, 'created_by' => $actor['id'], 'updated_at' => $now,
            ]);
            $id = (int) $db->insertID();
            $this->audit->log('CREATE', 'role', $id, null, ['code' => $code, 'name' => $name], $code);

            return $id;
        });
    }

    /**
     * Replaces the role's permissions and updates name/description.
     *
     * @param list<string>          $permissionCodes
     * @param array<string, mixed>  $actor
     */
    public function update(int $roleId, string $name, string $description, array $permissionCodes, array $actor): void
    {
        $role = $this->find($roleId);
        if ((int) $role['is_super_admin'] === 1) {
            throw new AuthorizationException('The Super Admin role always has every permission and cannot be edited.');
        }
        if ((int) $actor['role_id'] === $roleId) {
            throw new AuthorizationException('You cannot change the permissions of your own role.');
        }
        if (trim($name) === '' || mb_strlen($name) > 60) {
            throw ValidationException::single('name', 'Enter a name (max 60 characters).');
        }

        $valid = array_column($this->db->table('permissions')->select('id, code')->get()->getResultArray(), 'id', 'code');
        $ids   = [];
        foreach (array_unique($permissionCodes) as $code) {
            if (! isset($valid[$code])) {
                throw ValidationException::single('permissions', 'Unknown permission ' . $code);
            }
            $ids[] = (int) $valid[$code];
        }

        $before = $this->grantedCodes($roleId);

        $this->tx->run(function (Database $db) use ($roleId, $role, $name, $description, $ids, $before, $permissionCodes, $actor): void {
            $now = $this->clock->nowUtcString();
            $db->table('roles')->where('id', $roleId)->update([
                'name' => trim($name), 'description' => mb_substr(trim($description), 0, 255) ?: null,
                'updated_at' => $now, 'updated_by' => $actor['id'],
            ]);
            $db->table('role_permissions')->where('role_id', $roleId)->delete();
            if ($ids !== []) {
                $db->table('role_permissions')->insertBatch(array_map(static fn (int $pid): array => [
                    'role_id' => $roleId, 'permission_id' => $pid, 'created_at' => $now, 'created_by' => $actor['id'],
                ], $ids));
            }
            sort($permissionCodes);
            $this->audit->log('UPDATE', 'role', $roleId,
                ['name' => $role['name'], 'permissions' => $before],
                ['name' => trim($name), 'permissions' => array_values(array_unique($permissionCodes))],
                (string) $role['code']);
        });
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function delete(int $roleId, array $actor): void
    {
        $role = $this->find($roleId);
        if ((int) $role['is_system'] === 1) {
            throw new AuthorizationException('System roles cannot be deleted.');
        }
        if ($this->db->table('users')->where('role_id', $roleId)->countAllResults() > 0) {
            throw ValidationException::single('role', 'Move the users of this role to another role first.');
        }

        $this->tx->run(function (Database $db) use ($roleId, $role): void {
            $db->table('roles')->where('id', $roleId)->delete();
            $this->audit->log('DELETE', 'role', $roleId, ['code' => $role['code'], 'name' => $role['name']], null, (string) $role['code']);
        });
    }

    /**
     * @return list<string>
     */
    private function grantedCodes(int $roleId): array
    {
        return array_column($this->db->table('role_permissions rp')->select('p.code')
            ->join('permissions p', 'p.id = rp.permission_id')->where('rp.role_id', $roleId)
            ->orderBy('p.code')->get()->getResultArray(), 'code');
    }
}
