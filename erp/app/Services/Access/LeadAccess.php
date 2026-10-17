<?php

namespace App\Services\Access;

use App\Services\Security\Authorization;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Lead record scope: assigned (assigned_to = me), team (me + reports),
 * all. Child records (calls, follow-ups, visits) inherit lead scope.
 */
class LeadAccess
{
    public function __construct(private readonly BaseConnection $db, private readonly Authorization $authz)
    {
    }

    /** @return array{mode: string, user_ids: list<int>} */
    public function scope(): array
    {
        return $this->authz->scope([
            'all'      => 'crm.lead.view_all',
            'team'     => 'crm.lead.view_team',
            'assigned' => 'crm.lead.view_assigned',
        ]);
    }

    public function applyScope(BaseBuilder $builder, string $a = 'l'): BaseBuilder
    {
        return $this->authz->applyScope($builder, $this->scope(), "{$a}.assigned_to");
    }

    /** @param array<string, mixed> $lead */
    public function canView(array $lead): bool
    {
        $scope = $this->scope();

        return match ($scope['mode']) {
            'all'   => true,
            'none'  => false,
            default => in_array((int) $lead['assigned_to'], $scope['user_ids'], true),
        };
    }

    public function canViewLeadId(int $id): bool
    {
        $lead = $this->db->table('leads')->select('id, assigned_to')->where('id', $id)->where('deleted_at', null)->get()->getRowArray();

        return $lead !== null && $this->canView($lead);
    }

    /** Users the current user may assign leads/tasks/visits to. @return list<int> */
    public function assignableUserIds(): array
    {
        $scope = $this->scope();
        if ($scope['mode'] === 'all' || $this->authz->can('crm.lead.assign')) {
            return array_map('intval', array_column(
                $this->db->table('users')->select('id')->where('active', 1)->where('deleted_at', null)->get()->getResultArray(),
                'id'
            ));
        }

        return $scope['user_ids'];
    }
}
