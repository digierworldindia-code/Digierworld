<?php

namespace App\Controllers\Hr;

use App\Exceptions\BusinessRuleException;
use App\Libraries\SessionLock;
use App\Services\Hr\RecruitmentService;

/**
 * Applicants: list/board with score, role, stage and date filters, the
 * full recruitment timeline, CV versions, interviews, AI/rules reviews
 * and human decisions. Expected salary is shown only with hr.salary.view.
 */
class CandidatesController extends HrBase
{
    /** @return array<string, mixed> */
    private function candidate(int $id): array
    {
        if (! service('candidateAccess')->canViewCandidateId($id)) {
            $this->notFound();
        }
        $c = $this->db->table('candidates')->where('id', $id)->where('deleted_at', null)->get()->getRowArray() ?? $this->notFound();
        if (! $this->can('hr.salary.view')) {
            unset($c['expected_salary']);
        }

        return $c;
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        $g = fn ($k) => trim((string) $this->request->getGet($k));

        return [
            'q' => $g('q'), 'job' => (int) $g('job'), 'status' => $g('status'), 'from' => ymd($g('from')), 'to' => ymd($g('to')),
            'score_min' => is_numeric($g('score_min')) ? (float) $g('score_min') : null, 'score_max' => is_numeric($g('score_max')) ? (float) $g('score_max') : null,
            'sort' => in_array($g('sort'), ['score', 'date', 'name'], true) ? $g('sort') : 'date',
        ];
    }

    private function query(array $f)
    {
        $latestFit = "(SELECT e.fit_score FROM ai_cv_evaluations e WHERE e.candidate_id = c.id AND e.status = 'completed' AND e.fit_score IS NOT NULL ORDER BY e.id DESC LIMIT 1)";
        $b = $this->db->table('candidates c')->select("c.id, c.candidate_code, c.full_name, c.status, c.application_date, c.source, c.hr_score, c.total_experience_years, c.job_opening_id, c.employee_id, c.ai_share_consent,
                j.title AS job_title, j.code AS job_code, {$latestFit} AS ai_fit,
                (SELECT d.parse_status FROM candidate_documents d WHERE d.candidate_id = c.id AND d.doc_kind = 'cv' AND d.is_current = 1 LIMIT 1) AS cv_status", false)
            ->join('job_openings j', 'j.id = c.job_opening_id')->where('c.deleted_at', null);
        service('candidateAccess')->applyScope($b, 'c');
        if ($f['q'] !== '') {
            $b->groupStart()->like('c.full_name', $f['q'])->orLike('c.candidate_code', $f['q'])->orLike('c.email', $f['q'])->orLike('c.phone', $f['q'])->groupEnd();
        }
        if ($f['job']) {
            $b->where('c.job_opening_id', $f['job']);
        }
        if (isset(RecruitmentService::STATUSES[$f['status']])) {
            $b->where('c.status', $f['status']);
        } elseif ($f['status'] === 'open') {
            $b->whereNotIn('c.status', ['rejected', 'joined', 'withdrawn']);
        }
        if ($f['from']) {
            $b->where('c.application_date >=', $f['from']);
        }
        if ($f['to']) {
            $b->where('c.application_date <=', $f['to']);
        }
        $score = "COALESCE(c.hr_score, {$latestFit})";
        if ($f['score_min'] !== null) {
            $b->where("{$score} >=", $f['score_min'], false);
        }
        if ($f['score_max'] !== null) {
            $b->where("{$score} <=", $f['score_max'], false);
        }
        match ($f['sort']) {
            'score' => $b->orderBy("{$score} IS NULL", '', false)->orderBy($score, 'DESC', false),
            'name' => $b->orderBy('c.full_name'),
            default => $b->orderBy('c.application_date', 'DESC')->orderBy('c.id', 'DESC'),
        };

        return $b;
    }

    /** @return array<int, string> vacancies visible to the caller */
    private function jobs(): array
    {
        return array_column($this->db->table('job_openings')->select("id, CONCAT(code, ' · ', title) AS label", false)->orderBy('created_at', 'DESC')->get()->getResultArray(), 'label', 'id');
    }

    public function index()
    {
        $this->authorize(['hr.candidate.view', 'hr.candidate.view_all']);
        $f = $this->filters() + ['status' => ''];
        if ($f['status'] === '') {
            $f['status'] = 'open';
        }
        [$page, $perPage, $offset] = $this->paging(50);
        $b     = $this->query($f);
        $total = (clone $b)->countAllResults(false);
        $rows  = $b->limit($perPage, $offset)->get()->getResultArray();

        return $this->render('hr/candidates/index', [
            'pageTitle' => 'Applicants', 'breadcrumbs' => [['HR'], ['Applicants']], 'rows' => $rows, 'f' => $f, 'jobs' => $this->jobs(),
            'total' => $total, 'page' => $page, 'perPage' => $perPage,
        ]);
    }

    public function board()
    {
        $this->authorize(['hr.candidate.view', 'hr.candidate.view_all']);
        $f = $this->filters();
        $f['sort'] = 'score';
        $f['status'] = '';
        $rows = $this->query($f)->limit(400)->get()->getResultArray();
        $cols = [];
        foreach (RecruitmentService::STATUSES as $k => $l) {
            if (in_array($k, ['withdrawn'], true)) {
                continue;
            }
            $cols[$k] = ['label' => $l, 'items' => []];
        }
        foreach ($rows as $r) {
            if (isset($cols[$r['status']])) {
                $cols[$r['status']]['items'][] = $r;
            }
        }

        return $this->render('hr/candidates/board', ['pageTitle' => 'Shortlisting board', 'breadcrumbs' => [['HR'], ['Applicants', 'hr/candidates'], ['Board']], 'cols' => $cols, 'f' => $f, 'jobs' => $this->jobs()]);
    }

    public function new()
    {
        $this->authorize('hr.candidate.create');
        $jobs = array_column($this->db->table('job_openings')->select("id, CONCAT(code, ' · ', title) AS label", false)->whereIn('status', ['open', 'draft', 'on_hold'])->where('current_jd_version_id IS NOT NULL', null, false)->get()->getResultArray(), 'label', 'id');

        return $this->render('hr/candidates/form', [
            'pageTitle' => 'Add applicant', 'breadcrumbs' => [['HR'], ['Applicants', 'hr/candidates'], ['New']], 'c' => ['job_opening_id' => (int) $this->request->getGet('job')],
            'jobs' => $jobs, 'users' => $this->users(), 'consentVersion' => service('settingsStore')->get('hr.consent_text_version', 'v1'),
            'retentionDays' => (int) service('settingsStore')->get('hr.candidate_retention_days', 365),
        ]);
    }

    public function create()
    {
        $this->authorize('hr.candidate.create');
        try {
            $id = service('recruitment')->createCandidate($this->request->getPost(), $this->request->getFile('cv'));
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/candidates/' . $id)->with('success', 'Applicant added.');
    }

    public function show($id)
    {
        $this->authorize(['hr.candidate.view', 'hr.candidate.view_all']);
        $c   = $this->candidate($this->idOr404($id));
        $job = $this->db->table('job_openings')->where('id', $c['job_opening_id'])->get()->getRowArray();
        $jd  = $this->db->table('job_description_versions')->where('id', $c['applied_jd_version_id'] ?: $job['current_jd_version_id'])->get()->getRowArray();
        $interviews = $this->db->table('interviews i')->select('i.*, p.full_name AS interviewer')->join('user_profiles p', 'p.user_id = i.interviewer_id', 'left')
            ->where('i.candidate_id', $c['id'])->orderBy('i.round_no')->get()->getResultArray();
        foreach ($interviews as &$iv) {
            $iv['cards'] = $this->db->table('interview_scorecards')->where('interview_id', $iv['id'])->get()->getResultArray();
        }
        unset($iv);
        $evals = $this->db->table('ai_cv_evaluations e')->select('e.*, p.full_name AS requested_name, r.full_name AS reviewer_name, d.version_no AS cv_version, v.version_no AS jd_version_no')
            ->join('user_profiles p', 'p.user_id = e.requested_by', 'left')->join('user_profiles r', 'r.user_id = e.reviewed_by', 'left')
            ->join('candidate_documents d', 'd.id = e.candidate_document_id', 'left')->join('job_description_versions v', 'v.id = e.jd_version_id', 'left')
            ->where('e.candidate_id', $c['id'])->orderBy('e.id', 'DESC')->get()->getResultArray();

        return $this->render('hr/candidates/show', [
            'pageTitle' => $c['full_name'] . ' · ' . $c['candidate_code'], 'breadcrumbs' => [['HR'], ['Applicants', 'hr/candidates'], [$c['candidate_code']]],
            'c' => $c, 'job' => $job, 'jd' => $jd, 'currentJdNo' => $this->db->table('job_description_versions')->select('version_no')->where('id', $job['current_jd_version_id'])->get()->getRow()->version_no ?? null,
            'docs' => $this->db->table('candidate_documents d')->select('d.id, d.doc_kind, d.version_no, d.parse_status, d.parse_error, d.is_current, d.created_at, d.stored_file_id, f.original_name, LENGTH(d.extracted_text) AS text_len')
                ->join('stored_files f', 'f.id = d.stored_file_id')->where('d.candidate_id', $c['id'])->orderBy('d.id', 'DESC')->get()->getResultArray(),
            'timeline' => $this->db->table('recruitment_events e')->select('e.*, p.full_name AS actor')->join('user_profiles p', 'p.user_id = e.actor_id', 'left')
                ->where('e.candidate_id', $c['id'])->orderBy('e.occurred_at')->orderBy('e.id')->get()->getResultArray(),
            'interviews' => $interviews, 'evals' => $evals, 'ai' => service('cvEvaluation')->availability($c),
            'users' => $this->users(), 'departments' => $this->departments(), 'designations' => $this->designations(),
            'list' => static fn ($json) => json_decode((string) $json, true) ?: [],
        ]);
    }

    public function edit($id)
    {
        $this->authorize('hr.candidate.edit');
        $c = $this->candidate($this->idOr404($id));

        return $this->render('hr/candidates/edit', ['pageTitle' => 'Edit ' . $c['full_name'], 'breadcrumbs' => [['HR'], ['Applicants', 'hr/candidates'], [$c['candidate_code'], 'hr/candidates/' . $c['id']], ['Edit']], 'c' => $c]);
    }

    public function update($id)
    {
        $this->authorize('hr.candidate.edit');
        $c = $this->candidate($this->idOr404($id));
        try {
            service('recruitment')->updateCandidate((int) $c['id'], $this->request->getPost());
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/candidates/' . $c['id'])->with('success', 'Applicant updated.');
    }

    public function uploadCv($id)
    {
        $this->authorize(['hr.candidate.create', 'hr.candidate.edit']);
        $c    = $this->candidate($this->idOr404($id));
        $file = $this->request->getFile('cv');
        if ($file === null || ! $file->isValid()) {
            return redirect()->back()->with('error', 'Choose a PDF or DOCX CV to upload.');
        }
        try {
            service('recruitment')->uploadCv((int) $c['id'], $file);
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/candidates/' . $c['id'] . '#cv')->with('success', 'CV uploaded.');
    }

    public function status($id)
    {
        $c = $this->candidate($this->idOr404($id));
        try {
            service('recruitment')->changeStatus((int) $c['id'], (string) $this->request->getPost('status'), (string) $this->request->getPost('notes'));
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to($this->request->getPost('back') === 'board' ? 'hr/candidates/board' : 'hr/candidates/' . $c['id'])->with('success', 'Status updated.');
    }

    public function score($id)
    {
        $this->authorize('hr.candidate.score');
        $c = $this->candidate($this->idOr404($id));
        try {
            service('recruitment')->setHrScore((int) $c['id'], trim((string) $this->request->getPost('hr_score')), (string) $this->request->getPost('notes'));
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/candidates/' . $c['id'])->with('success', 'HR score saved.');
    }

    public function note($id)
    {
        $this->authorize('hr.timeline.create');
        $c = $this->candidate($this->idOr404($id));
        try {
            service('recruitment')->addNote((int) $c['id'], (string) $this->request->getPost('title'), (string) $this->request->getPost('notes'));
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/candidates/' . $c['id'] . '#timeline')->with('success', 'Added to timeline.');
    }

    public function interview($id)
    {
        $this->authorize('hr.interview.manage');
        $c = $this->candidate($this->idOr404($id));
        try {
            service('recruitment')->scheduleInterview((int) $c['id'], $this->request->getPost());
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/candidates/' . $c['id'] . '#interviews')->with('success', 'Interview scheduled; the interviewer was notified.');
    }

    public function convert($id)
    {
        $this->authorize(['hr.candidate.convert']);
        $c = $this->candidate($this->idOr404($id));
        try {
            $empId = service('recruitment')->convert((int) $c['id'], $this->request->getPost());
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/employees/' . $empId)->with('success', 'Employee created and linked to the recruitment history.');
    }

    /** Request an AI review (decision support) or a transparent keyword check. */
    public function evaluate($id)
    {
        $this->authorize('hr.candidate.score');
        $c      = $this->candidate($this->idOr404($id));
        $engine = $this->request->getPost('engine') === 'rules' ? 'rules' : 'ai';
        SessionLock::release(); // an AI review can take tens of seconds; the user's other tabs keep working
        try {
            $evalId = service('cvEvaluation')->evaluate((int) $c['id'], $engine);
        } catch (BusinessRuleException $e) {
            SessionLock::reopen();

            return $this->backWithRuleError($e);
        }
        SessionLock::reopen();
        $e = $this->db->table('ai_cv_evaluations')->select('status, error_message')->where('id', $evalId)->get()->getRowArray();

        return redirect()->to('hr/candidates/' . $c['id'] . '#evaluations')->with($e['status'] === 'completed' ? 'success' : 'error',
            $e['status'] === 'completed' ? 'Review ready. It is decision support only — record your own decision.' : 'Analysis unavailable: ' . $e['error_message']);
    }

    public function review($id, $evalId)
    {
        $this->authorize('hr.candidate.score');
        $c = $this->candidate($this->idOr404($id));
        try {
            service('cvEvaluation')->review((int) $c['id'], $this->idOr404($evalId), (string) $this->request->getPost('decision'), trim((string) $this->request->getPost('hr_score')), trim((string) $this->request->getPost('reason')));
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/candidates/' . $c['id'] . '#evaluations')->with('success', 'Review recorded.');
    }

    public function proposal($id, $eventId)
    {
        $this->authorize('hr.timeline.create');
        $c = $this->candidate($this->idOr404($id));
        try {
            service('cvEvaluation')->decideProposal((int) $c['id'], $this->idOr404($eventId), $this->request->getPost('decision') === 'accepted');
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/candidates/' . $c['id'] . '#timeline')->with('success', 'Proposal handled.');
    }

    public function export()
    {
        $this->authorize('hr.candidate.export');
        $f    = $this->filters();
        $rows = $this->query($f)->limit(5000)->get()->getResultArray();
        $col  = static fn ($k, $l, $t = 'text', $w = 16) => ['key' => $k, 'label' => $l, 'type' => $t, 'width' => $w];

        return service('exports')->download('applicants', 'xlsx', 'applicants-' . date('Ymd'), [[
            'title' => 'Applicants', 'rows' => $rows,
            'columns' => [$col('candidate_code', 'Code'), $col('full_name', 'Name', 'text', 26), $col('job_title', 'Vacancy', 'text', 26), $col('status', 'Status'),
                $col('application_date', 'Applied', 'date'), $col('source', 'Source'), $col('total_experience_years', 'Experience (yrs)', 'number'),
                $col('hr_score', 'HR score', 'number'), $col('ai_fit', 'AI fit (decision support)', 'number'), $col('cv_status', 'CV parse')],
        ]], $f, ['Contents' => 'Contact details and salary expectations are not included.']);
    }
}
