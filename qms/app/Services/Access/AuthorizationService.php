<?php

namespace App\Services\Access;

use App\Exceptions\AuthorizationException;
use App\Services\Audit\AuditService;
use App\Services\Auth\AuthService;
use CodeIgniter\Database\BaseConnection;

/**
 * Role-based permission checks. Permissions are loaded from role_permissions on
 * every request (cached only for that request), so matrix changes apply on the
 * next click. Super Admin roles hold every permission.
 */
class AuthorizationService
{
    /** @var array<int, array<string, true>> role id => permission set */
    private array $cache = [];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly AuthService $auth,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * True when the current user holds at least one of the permissions.
     */
    public function can(string ...$permissions): bool
    {
        $user = $this->auth->user();

        return $user !== null && $this->userCan($user, ...$permissions);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function userCan(array $user, string ...$permissions): bool
    {
        $set = $this->permissionSet((int) $user['role_id'], (bool) $user['is_super_admin']);
        foreach ($permissions as $permission) {
            if (isset($set[$permission])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws AuthorizationException
     */
    public function authorize(string ...$permissions): void
    {
        if (! $this->can(...$permissions)) {
            $this->denied(implode(',', $permissions));
        }
    }

    /**
     * Records the denied attempt and throws.
     *
     * @throws AuthorizationException
     */
    public function denied(string $what, string $message = 'You are not allowed to perform this action.'): never
    {
        $this->audit->log('ACCESS_DENIED', 'security', null, null, [
            'required' => $what,
            'path'     => is_cli() ? 'cli' : (string) service('request')->getUri()->getPath(),
        ]);

        throw new AuthorizationException($message);
    }

    /**
     * @return list<string>
     */
    public function permissionsOf(int $roleId, bool $isSuperAdmin): array
    {
        return array_keys($this->permissionSet($roleId, $isSuperAdmin));
    }

    /**
     * @return array<string, true>
     */
    private function permissionSet(int $roleId, bool $isSuperAdmin): array
    {
        if (! isset($this->cache[$roleId])) {
            $builder = $this->db->table('permissions p')->select('p.code');
            if (! $isSuperAdmin) {
                $builder->join('role_permissions rp', 'rp.permission_id = p.id')->where('rp.role_id', $roleId);
            }
            $this->cache[$roleId] = array_fill_keys(array_column($builder->get()->getResultArray(), 'code'), true);
        }

        return $this->cache[$roleId];
    }
}
