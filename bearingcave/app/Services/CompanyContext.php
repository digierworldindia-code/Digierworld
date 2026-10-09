<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompanyMemberModel;
use App\Models\CompanyMemberPermissionModel;
use App\Models\CompanyModel;
use Config\AuthGroups;

/**
 * Resolves the logged-in user's company, member role and company-level permissions.
 */
class CompanyContext
{
    /** @var array<int,array{membership:?array,company:?array,perms:list<string>}> */
    private array $cache = [];

    public function userId(): ?int
    {
        return auth()->loggedIn() ? (int) auth()->id() : null;
    }

    private function resolve(?int $userId = null): array
    {
        $userId ??= $this->userId();
        if ($userId === null) {
            return ['membership' => null, 'company' => null, 'perms' => []];
        }
        if (! isset($this->cache[$userId])) {
            $membership = model(CompanyMemberModel::class)->where('user_id', $userId)->where('status', 'active')->orderBy('id')->first();
            $company    = $membership ? model(CompanyModel::class)->find($membership['company_id']) : null;
            $perms      = [];
            if ($membership && $membership['member_role'] === 'employee') {
                $perms = array_column(model(CompanyMemberPermissionModel::class)->where('company_member_id', $membership['id'])->findAll(), 'permission_key');
            }
            $this->cache[$userId] = ['membership' => $membership, 'company' => $company, 'perms' => $perms];
        }

        return $this->cache[$userId];
    }

    public function company(?int $userId = null): ?array
    {
        return $this->resolve($userId)['company'];
    }

    public function companyId(?int $userId = null): ?int
    {
        $c = $this->company($userId);

        return $c ? (int) $c['id'] : null;
    }

    public function membership(?int $userId = null): ?array
    {
        return $this->resolve($userId)['membership'];
    }

    public function isOwnerOrAdmin(?int $userId = null): bool
    {
        return in_array($this->membership($userId)['member_role'] ?? null, ['owner', 'admin'], true);
    }

    public function can(string $permission, ?int $userId = null): bool
    {
        $r = $this->resolve($userId);
        if ($r['membership'] === null) {
            return false;
        }

        return in_array($r['membership']['member_role'], ['owner', 'admin'], true) || in_array($permission, $r['perms'], true);
    }

    public function isStaff(?int $userId = null): bool
    {
        $userId ??= $this->userId();
        if ($userId === null) {
            return false;
        }
        $user = auth()->getProvider()->findById($userId);

        return $user !== null && $user->inGroup(...config(AuthGroups::class)->staffGroups);
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
