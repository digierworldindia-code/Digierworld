<?php

namespace App\Controllers\Hr;

use App\Exceptions\BusinessRuleException;
use App\Services\Hr\RecruitmentService;

/** Interview schedule and outcome recording (scorecards, GD/assignment uploads). */
class InterviewsController extends HrBase
{
    public function index()
    {
        $this->authorize('hr.interview.manage');
        $view = (string) ($this->request->getGet('view') ?? 'upcoming');
        $b = $this->db->table('interviews i')->select('i.*, c.full_name, c.candidate_code, c.status AS candidate_status, j.title AS job_title, p.full_name AS interviewer')
            ->join('candidates c', 'c.id = i.candidate_id')->join('job_openings j', 'j.id = c.job_opening_id')->join('user_profiles p', 'p.user_id = i.interviewer_id', 'left')
            ->where('c.deleted_at', null);
        service('candidateAccess')->applyScope($b, 'c');
        match ($view) {
            'mine' => $b->where('i.interviewer_id', $this->userId)->where('i.status', 'scheduled')->orderBy('i.scheduled_at'),
            'past' => $b->where('i.status !=', 'scheduled')->orderBy('i.scheduled_at', 'DESC'),
            default => $b->where('i.status', 'scheduled')->orderBy('i.scheduled_at'),
        };

        return $this->render('hr/interviews', [
            'pageTitle' => 'Interviews', 'breadcrumbs' => [['HR'], ['Interviews']], 'rows' => $b->limit(200)->get()->getResultArray(), 'view' => $view,
            'types' => RecruitmentService::INTERVIEW_TYPES,
        ]);
    }

    public function complete($id)
    {
        $this->authorize('hr.interview.manage');
        $iv = $this->db->table('interviews')->where('id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound();
        if (! service('candidateAccess')->canViewCandidateId((int) $iv['candidate_id'])) {
            $this->notFound();
        }
        try {
            service('recruitment')->completeInterview((int) $iv['id'], $this->request->getPost(), $this->request->getFile('attachment'));
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to($this->request->getPost('back') === 'list' ? 'hr/interviews' : 'hr/candidates/' . $iv['candidate_id'] . '#interviews')->with('success', 'Interview outcome recorded.');
    }
}
