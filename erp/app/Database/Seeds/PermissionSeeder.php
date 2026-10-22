<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use Config\Permissions;

/**
 * Syncs the permission registry into the DB and creates the starter roles
 * if they do not exist. Existing role grants edited by the owner are NOT
 * overwritten (idempotent; safe to re-run after upgrades).
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $config = config(Permissions::class);

        foreach ($config->flat() as $key => $meta) {
            $this->db->query(
                'INSERT INTO permissions (perm_key, module, description, is_dangerous, scope) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE module = VALUES(module), description = VALUES(description), is_dangerous = VALUES(is_dangerous), scope = VALUES(scope)',
                [$key, $meta['module'], $meta['description'], $meta['danger'] ? 1 : 0, $meta['scope']]
            );
        }

        $permIds = [];
        foreach ($this->db->table('permissions')->select('id, perm_key')->get()->getResultArray() as $p) {
            $permIds[$p['perm_key']] = (int) $p['id'];
        }

        foreach ($config->starterRoles as $code => $role) {
            $existing = $this->db->table('roles')->where('code', $code)->get()->getRowArray();
            if ($existing !== null) {
                continue;
            }
            $isSuper = $role['perms'] === ['*'];
            $this->db->table('roles')->insert([
                'code'        => $code,
                'name'        => $role['name'],
                'description' => $role['description'],
                'is_super'    => $isSuper ? 1 : 0,
                'is_system'   => 1,
                'is_active'   => 1,
            ] + ($this->db->fieldExists('landing_url', 'roles') ? ['landing_url' => config('Landing')->starterDefaults[$code] ?? null] : []));
            $roleId = (int) $this->db->insertID();
            if ($isSuper) {
                continue;
            }
            foreach ($role['perms'] as $perm) {
                if (! isset($permIds[$perm])) {
                    throw new \RuntimeException("Starter role {$code} references unknown permission {$perm}");
                }
                $this->db->table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permIds[$perm]]);
            }
        }
    }
}
