<?php

namespace App\Controllers\Hr;

use App\Exceptions\BusinessRuleException;
use App\Services\Hr\RecruitmentService;

/** Job vacancies and their versioned job descriptions. */
class VacanciesController extends HrBase
{
    public function index()
    {
        $this->authorize('hr.vacancy.view');
        $status = (string) ($this->request->getGet('status') ?? 'active');
        $b = $this->db->table('job_openings j')->select("j.*, d.name AS department, p.full_name AS recruiter,
                (SELECT COUNT(*) FROM candidates c WHERE c.job_opening_id = j.id AND c.deleted_at IS NULL) AS applicants,
                (SELECT COUNT(*) FROM candidates c WHERE c.job_opening_id = j.id AND c.deleted_at IS NULL AND c.status = 'shortlisted') AS shortlisted,
                (SELECT version_no FROM job_description_versions v WHERE v.id = j.current_jd_version_id) AS jd_version", false)
            ->join('departments d', 'd.id = j.department_id', 'left')->join('user_profiles p', 'p.user_id = j.recruiter_user_id', 'left');
        if ($status === 'active') {
            $b->whereIn('j.status', ['draft', 'open', 'on_hold']);
        } elseif (in_array($status, ['draft', 'open', 'on_hold', 'closed', 'filled'], true)) {
            $b->where('j.status', $status);
        }

        return $this->render('hr/vacancies/index', [
            'pageTitle' => 'Vacancies & job descriptions', 'breadcrumbs' => [['HR'], ['Vacancies']], 'rows' => $b->orderBy('j.created_at', 'DESC')->get()->getResultArray(), 'status' => $status,
            'salaryVisible' => $this->can(['hr.vacancy.manage', 'hr.salary.view']),
        ]);
    }

    public function new()
    {
        $this->authorize('hr.vacancy.manage');

        return $this->form(['status' => 'open', 'work_mode' => 'onsite', 'openings_count' => 1], ['rubric' => json_encode(RecruitmentService::DEFAULT_RUBRIC)]);
    }

    public function create()
    {
        $this->authorize('hr.vacancy.manage');
        try {
            $id = service('recruitment')->createVacancy($this->request->getPost());
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/vacancies/' . $id)->with('success', 'Vacancy and JD v1 saved. You can now add applicants.');
    }

    public function edit($id)
    {
        $this->authorize('hr.vacancy.manage');
        $job = $this->find($this->idOr404($id));

        return $this->form($job, null);
    }

    public function update($id)
    {
        $this->authorize('hr.vacancy.manage');
        $job = $this->find($this->idOr404($id));
        try {
            service('recruitment')->updateVacancy((int) $job['id'], $this->request->getPost());
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/vacancies/' . $job['id'])->with('success', 'Vacancy updated.');
    }

    private function form(array $job, ?array $jd): string
    {
        return $this->render('hr/vacancies/form', [
            'pageTitle' => isset($job['id']) ? 'Edit ' . $job['title'] : 'New vacancy', 'breadcrumbs' => [['HR'], ['Vacancies', 'hr/vacancies'], [isset($job['id']) ? $job['code'] : 'New']],
            'job' => $job, 'jd' => $jd, 'departments' => $this->departments(), 'users' => $this->users(), 'rubricDefaults' => RecruitmentService::DEFAULT_RUBRIC,
        ]);
    }

    private function find(int $id): array
    {
        return $this->db->table('job_openings')->where('id', $id)->get()->getRowArray() ?? $this->notFound();
    }

    public function show($id)
    {
        $this->authorize('hr.vacancy.view');
        $job      = $this->find($this->idOr404($id));
        $versions = $this->db->table('job_description_versions v')->select('v.*, p.full_name AS author')->join('user_profiles p', 'p.user_id = v.created_by', 'left')
            ->where('v.job_opening_id', $job['id'])->orderBy('v.version_no', 'DESC')->get()->getResultArray();
        $counts = array_column($this->db->table('candidates')->select('status, COUNT(*) n')->where('job_opening_id', $job['id'])->where('deleted_at', null)->groupBy('status')->get()->getResultArray(), 'n', 'status');
        $vId    = (int) ($this->request->getGet('v') ?: $job['current_jd_version_id']);
        $current = array_values(array_filter($versions, static fn ($v) => (int) $v['id'] === $vId))[0] ?? ($versions[0] ?? null);

        return $this->render('hr/vacancies/show', [
            'pageTitle' => $job['code'] . ' · ' . $job['title'], 'breadcrumbs' => [['HR'], ['Vacancies', 'hr/vacancies'], [$job['code']]],
            'job' => $job, 'versions' => $versions, 'jd' => $current, 'counts' => $counts,
            'department' => $this->db->table('departments')->select('name')->where('id', $job['department_id'])->get()->getRow()->name ?? null,
            'users' => $this->users(), 'salaryVisible' => $this->can(['hr.vacancy.manage', 'hr.salary.view']),
        ]);
    }

    public function addVersion($id)
    {
        $this->authorize('hr.vacancy.manage');
        $job = $this->find($this->idOr404($id));
        try {
            service('recruitment')->addJdVersion((int) $job['id'], $this->request->getPost(), $this->request->getFile('attachment'));
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/vacancies/' . $job['id'])->with('success', 'New JD version saved. Applicants keep the version they applied against.');
    }
}
