<?php

namespace App\Controllers\Crm;

use App\Controllers\BaseController;
use App\Exceptions\BusinessRuleException;

/** Shared helpers for CRM screens: scoped lead loading and user pickers. */
abstract class CrmBase extends BaseController
{
    /** Lead within the caller's record scope, or 404 (never reveals other leads). */
    protected function lead(int $id): array
    {
        $lead = $this->db->table('leads')->where('id', $id)->where('deleted_at', null)->get()->getRowArray();
        if ($lead === null || ! service('leadAccess')->canView($lead)) {
            $this->notFound();
        }

        return $lead;
    }

    /** @return list<array{id: int, name: string}> users the caller may assign work to */
    protected function assignableUsers(): array
    {
        $ids = service('leadAccess')->assignableUserIds();
        if ($this->can('crm.task.assign')) {
            $ids = array_map('intval', array_column($this->db->table('users')->select('id')->where('active', 1)->where('deleted_at', null)->get()->getResultArray(), 'id'));
        }
        if ($ids === []) {
            return [];
        }

        return array_map(static fn ($r) => ['id' => (int) $r['user_id'], 'name' => $r['full_name']],
            $this->db->table('user_profiles')->select('user_id, full_name')->whereIn('user_id', $ids)->orderBy('full_name')->get()->getResultArray());
    }

    /** @return array<int, string> user id => name for users visible in the caller's lead scope */
    protected function scopeUsers(): array
    {
        $scope = service('leadAccess')->scope();
        $b = $this->db->table('user_profiles p')->select('p.user_id, p.full_name')->join('users u', 'u.id = p.user_id')->where('u.deleted_at', null)->orderBy('p.full_name');
        if ($scope['mode'] !== 'all') {
            $b->whereIn('p.user_id', $scope['user_ids'] ?: [0]);
        }

        return array_column($b->get()->getResultArray(), 'full_name', 'user_id');
    }

    /** @return array<string, array<string, mixed>> */
    protected function stages(): array
    {
        return service('leads')->stages();
    }

    protected function ruleError(BusinessRuleException $e)
    {
        return $this->backWithRuleError($e);
    }
}
