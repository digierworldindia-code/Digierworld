<?php

namespace App\Services\Security;

use App\Exceptions\AccessDeniedException;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Permissions;

/**
 * Deny-by-default, database-backed authorization.
 *
 * Effective permissions = union of permissions of the user's active,
 * non-expired roles + non-expired 'grant' overrides − 'deny' overrides
 * (deny always wins). A role flagged is_super holds every registered key.
 * Unknown keys are never granted.
 */
class Authorization
{
    /** @var array<int, array{super: bool, perms: array<string, true>}> */
    private array $cache = [];

    /** @var array<int, list<int>> */
    private array $teamCache = [];

    private ?array $registry = null;

    /** Explicit acting user for CLI/cron/system contexts (never set from HTTP input). */
    private ?int $actingUserId = null;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function currentUserId(): ?int
    {
        if ($this->actingUserId !== null) {
            return $this->actingUserId;
        }
        $auth = auth('session');

        return $auth->loggedIn() ? (int) $auth->id() : null;
    }

    /**
     * Run subsequent checks as a specific user in CLI contexts (cron jobs,
     * workers). Refused for web requests so it can never be abused remotely.
     */
    public function actAs(?int $userId): void
    {
        if (! is_cli() && ENVIRONMENT !== 'testing') {
            throw new \LogicException('actAs() is only available in CLI contexts.');
        }
        $this->actingUserId = $userId;
        $this->forget();
    }

    /** @return array<string, array{module: string, description: string, danger: bool, scope: string|null}> */
    public function registry(): array
    {
        return $this->registry ??= config(Permissions::class)->flat();
    }

    public function forget(?int $userId = null): void
    {
        if ($userId === null) {
            $this->cache     = [];
            $this->teamCache = [];

            return;
        }
        unset($this->cache[$userId], $this->teamCache[$userId]);
    }

    /** @return array{super: bool, perms: array<string, true>} */
    private function load(int $userId): array
    {
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }

        $now   = date('Y-m-d H:i:s');
        $roles = $this->db->table('user_roles ur')
            ->select('r.id, r.is_super')
            ->join('roles r', 'r.id = ur.role_id')
            ->join('users u', 'u.id = ur.user_id')
            ->where('ur.user_id', $userId)
            ->where('r.is_active', 1)
            ->where('u.active', 1)
            ->where('u.deleted_at', null)
            ->groupStart()->where('ur.expires_at', null)->orWhere('ur.expires_at >', $now)->groupEnd()
            ->get()->getResultArray();

        $super   = false;
        $roleIds = [];
        foreach ($roles as $r) {
            $roleIds[] = (int) $r['id'];
            $super     = $super || (int) $r['is_super'] === 1;
        }

        $perms = [];
        if ($super) {
            foreach (array_keys($this->registry()) as $key) {
                $perms[$key] = true;
            }
        } elseif ($roleIds !== []) {
            $rows = $this->db->table('role_permissions rp')
                ->select('p.perm_key')
                ->join('permissions p', 'p.id = rp.permission_id')
                ->whereIn('rp.role_id', $roleIds)
                ->get()->getResultArray();
            foreach ($rows as $row) {
                $perms[$row['perm_key']] = true;
            }
        }

        if (! $super) {
            $overrides = $this->db->table('user_permission_overrides o')
                ->select('p.perm_key, o.effect')
                ->join('permissions p', 'p.id = o.permission_id')
                ->where('o.user_id', $userId)
                ->groupStart()->where('o.expires_at', null)->orWhere('o.expires_at >', $now)->groupEnd()
                ->get()->getResultArray();
            foreach ($overrides as $o) {
                if ($o['effect'] === 'grant') {
                    $perms[$o['perm_key']] = true;
                }
            }
            foreach ($overrides as $o) {
                if ($o['effect'] === 'deny') {
                    unset($perms[$o['perm_key']]);
                }
            }
        }

        // never grant keys that are not in the registry
        $perms = array_intersect_key($perms, $this->registry());

        return $this->cache[$userId] = ['super' => $super, 'perms' => $perms];
    }

    public function isSuper(?int $userId = null): bool
    {
        $userId ??= $this->currentUserId();

        return $userId !== null && $this->load($userId)['super'];
    }

    /** @return list<string> */
    public function permissionsFor(int $userId): array
    {
        return array_keys($this->load($userId)['perms']);
    }

    /** True when the permission key exists in the registry (Config\Permissions). */
    public function isRegistered(string $perm): bool
    {
        return isset($this->registry()[$perm]);
    }

    public function can(string $perm, ?int $userId = null): bool
    {
        $userId ??= $this->currentUserId();
        if ($userId === null) {
            return false;
        }
        if (! isset($this->registry()[$perm])) {
            log_message('error', 'Authorization check for unregistered permission key: ' . $perm);

            return false;
        }

        return isset($this->load($userId)['perms'][$perm]);
    }

    /** @param list<string> $perms */
    public function canAny(array $perms, ?int $userId = null): bool
    {
        foreach ($perms as $p) {
            if ($this->can($p, $userId)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $perms */
    public function canAll(array $perms, ?int $userId = null): bool
    {
        foreach ($perms as $p) {
            if (! $this->can($p, $userId)) {
                return false;
            }
        }

        return $perms !== [];
    }

    /**
     * @param list<string>|string $perms any-of
     *
     * @throws AccessDeniedException
     */
    public function authorize(array|string $perms): void
    {
        $perms = (array) $perms;
        if (! $this->canAny($perms)) {
            service('audit')->log('access.denied', null, null, null, null, 'Denied: ' . implode('|', $perms), [
                'path' => (string) service('request')->getUri()->getPath(),
            ]);

            throw new AccessDeniedException();
        }
    }

    /**
     * All users reporting (directly or indirectly) to $userId.
     * UNION DISTINCT terminates even if a manager cycle is misconfigured.
     *
     * @return list<int>
     */
    public function teamUserIds(int $userId): array
    {
        if (isset($this->teamCache[$userId])) {
            return $this->teamCache[$userId];
        }
        $rows = $this->db->query(
            'WITH RECURSIVE team (user_id) AS (
                SELECT user_id FROM user_profiles WHERE manager_user_id = ?
                UNION DISTINCT
                SELECT up.user_id FROM user_profiles up JOIN team t ON up.manager_user_id = t.user_id
            ) SELECT user_id FROM team',
            [$userId]
        )->getResultArray();

        return $this->teamCache[$userId] = array_values(array_diff(array_map('intval', array_column($rows, 'user_id')), [$userId]));
    }

    /**
     * Resolve the widest scope a user holds for a family of view permissions.
     *
     * @param array{all?: string, team?: string, assigned?: string, own?: string} $keys
     *
     * @return array{mode: string, user_ids: list<int>}
     */
    public function scope(array $keys, ?int $userId = null): array
    {
        $userId ??= $this->currentUserId();
        if ($userId === null) {
            return ['mode' => 'none', 'user_ids' => []];
        }
        if (isset($keys['all']) && $this->can($keys['all'], $userId)) {
            return ['mode' => 'all', 'user_ids' => []];
        }
        if (isset($keys['team']) && $this->can($keys['team'], $userId)) {
            return ['mode' => 'team', 'user_ids' => array_merge([$userId], $this->teamUserIds($userId))];
        }
        foreach (['assigned', 'own'] as $k) {
            if (isset($keys[$k]) && $this->can($keys[$k], $userId)) {
                return ['mode' => $k, 'user_ids' => [$userId]];
            }
        }

        return ['mode' => 'none', 'user_ids' => []];
    }

    /**
     * Apply a resolved scope to a query builder column (e.g. 'l.assigned_to').
     * mode 'none' yields an impossible condition so nothing leaks.
     *
     * @param array{mode: string, user_ids: list<int>} $scope
     */
    public function applyScope(BaseBuilder $builder, array $scope, string $column): BaseBuilder
    {
        return match ($scope['mode']) {
            'all'   => $builder,
            'none'  => $builder->where('1 = 0', null, false),
            default => $builder->whereIn($column, $scope['user_ids'] ?: [0]),
        };
    }

    /**
     * Optional per-user record allowlist (e.g. payroll manager limited to
     * selected employees). Returns null when unrestricted.
     *
     * @return list<int>|null
     */
    public function recordAllowlist(string $scopeType, ?int $userId = null): ?array
    {
        $userId ??= $this->currentUserId();
        if ($userId === null) {
            return [];
        }
        if ($this->isSuper($userId)) {
            return null;
        }
        $rows = $this->db->table('user_record_scopes')->select('record_id')
            ->where(['user_id' => $userId, 'scope_type' => $scopeType])->get()->getResultArray();

        return $rows === [] ? null : array_map('intval', array_column($rows, 'record_id'));
    }

    /**
     * Effective permission summary with sources, used by the roles UI.
     *
     * @return array<string, array{granted: bool, sources: list<string>, denied_by_override: bool}>
     */
    public function effectiveSummary(int $userId): array
    {
        $this->forget($userId);
        $effective = $this->load($userId);
        $now       = date('Y-m-d H:i:s');

        $roleRows = $this->db->table('user_roles ur')
            ->select('r.name, r.is_super, p.perm_key')
            ->join('roles r', 'r.id = ur.role_id')
            ->join('role_permissions rp', 'rp.role_id = r.id', 'left')
            ->join('permissions p', 'p.id = rp.permission_id', 'left')
            ->where('ur.user_id', $userId)->where('r.is_active', 1)
            ->groupStart()->where('ur.expires_at', null)->orWhere('ur.expires_at >', $now)->groupEnd()
            ->get()->getResultArray();
        $overrides = $this->db->table('user_permission_overrides o')
            ->select('p.perm_key, o.effect')
            ->join('permissions p', 'p.id = o.permission_id')
            ->where('o.user_id', $userId)
            ->groupStart()->where('o.expires_at', null)->orWhere('o.expires_at >', $now)->groupEnd()
            ->get()->getResultArray();

        $sources = [];
        $superRoles = [];
        foreach ($roleRows as $r) {
            if ((int) $r['is_super'] === 1) {
                $superRoles[$r['name']] = true;
            }
            if ($r['perm_key'] !== null) {
                $sources[$r['perm_key']][] = 'Role: ' . $r['name'];
            }
        }
        $denied = [];
        foreach ($overrides as $o) {
            if ($o['effect'] === 'grant') {
                $sources[$o['perm_key']][] = 'User override (grant)';
            } else {
                $denied[$o['perm_key']] = true;
            }
        }

        $out = [];
        foreach (array_keys($this->registry()) as $key) {
            $src = $sources[$key] ?? [];
            if ($superRoles !== []) {
                $src = array_merge(array_map(static fn ($n) => 'Super role: ' . $n, array_keys($superRoles)), $src);
            }
            $out[$key] = [
                'granted'            => isset($effective['perms'][$key]),
                'sources'            => array_values(array_unique($src)),
                'denied_by_override' => isset($denied[$key]) && $superRoles === [],
            ];
        }

        return $out;
    }
}
