<?php

namespace App\Services\Access;

use App\Services\Security\Authorization;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Candidate scope: view_all, or "assigned" = I am the candidate's
 * recruiter, the vacancy's recruiter/interviewer, or an interviewer.
 * Salary fields are separately gated by hr.salary.view.
 */
class CandidateAccess
{
    public function __construct(private readonly BaseConnection $db, private readonly Authorization $authz)
    {
    }

    public function mode(): string
    {
        if ($this->authz->can('hr.candidate.view_all')) {
            return 'all';
        }

        return $this->authz->can('hr.candidate.view') ? 'assigned' : 'none';
    }

    public function applyScope(BaseBuilder $builder, string $a = 'c'): BaseBuilder
    {
        $mode = $this->mode();
        if ($mode === 'all') {
            return $builder;
        }
        if ($mode === 'none') {
            return $builder->where('1 = 0', null, false);
        }
        $uid = (int) $this->authz->currentUserId();

        return $builder->groupStart()
            ->where("{$a}.recruiter_id", $uid)
            ->orWhere("{$a}.job_opening_id IN (SELECT id FROM job_openings WHERE recruiter_user_id = {$uid} OR interviewer_user_id = {$uid})", null, false)
            ->orWhere("{$a}.id IN (SELECT candidate_id FROM interviews WHERE interviewer_id = {$uid})", null, false)
            ->groupEnd();
    }

    public function canViewCandidateId(int $id): bool
    {
        $b = $this->db->table('candidates c')->select('c.id')->where('c.id', $id)->where('c.deleted_at', null);

        return $this->applyScope($b)->countAllResults() > 0;
    }

    public function canSeeSalary(): bool
    {
        return $this->authz->can('hr.salary.view');
    }
}
