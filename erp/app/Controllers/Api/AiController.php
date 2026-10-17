<?php

namespace App\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Libraries\UntrustedText;
use App\Services\Ai\AiDeniedException;
use App\Services\Ai\CvEvaluationService;
use App\Services\Hr\RecruitmentService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Narrow, purpose-built endpoints for an AI service token. Each one returns
 * or accepts exactly the fields of one capability for records in the
 * token's allowlist. There is deliberately no generic query, search, file,
 * export, billing, CRM, bank or salary-editing endpoint here.
 */
class AiController extends Controller
{
    private function gw()
    {
        return service('aiGateway');
    }

    private function deny(string $capability, string $reason, string $recordType = '', array $ids = [], int $status = 403): ResponseInterface
    {
        $this->gw()->log('denied', $capability ?: null, $recordType ?: null, $ids, [], ['deny_reason' => $reason, 'actor_user_id' => $this->gw()->currentToken()['created_by'] ?? null]);

        return $this->response->setStatusCode($status)->setJSON(['ok' => false, 'error' => $reason]);
    }

    /** @return array<string, mixed>|null */
    private function candidateRow(int $id): ?array
    {
        return db_connect()->table('candidates')->where('id', $id)->where('deleted_at', null)->get()->getRowArray();
    }

    // GET api/ai/recruitment/candidates/{id}/evaluation-input
    public function evaluationInput($id)
    {
        $id  = (int) $id;
        $cap = CvEvaluationService::CAPABILITY;
        if ($why = $this->gw()->tokenAllows($cap, 'candidate_ids', $id)) {
            return $this->deny($cap, $why, 'candidate', [$id]);
        }
        $c = $this->candidateRow($id);
        if ($c === null) {
            return $this->deny($cap, 'not_found', 'candidate', [$id], 404);
        }
        $db  = db_connect();
        $cv  = $db->table('candidate_documents')->where(['candidate_id' => $id, 'doc_kind' => 'cv', 'is_current' => 1])->get()->getRowArray();
        $job = $db->table('job_openings')->where('id', $c['job_opening_id'])->get()->getRowArray();
        $jd  = $db->table('job_description_versions')->where('id', $job['current_jd_version_id'])->get()->getRowArray();
        if ($cv === null || $cv['parse_status'] !== 'parsed') {
            return $this->deny($cap, 'no_parsed_cv', 'candidate', [$id], 409);
        }
        try {
            $grant = $this->gw()->authorize($cap, 'candidate', $id, CvEvaluationService::FIELDS, (string) $this->gw()->currentToken()['purpose'], (bool) $c['ai_share_consent']);
        } catch (AiDeniedException $e) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false, 'error' => $e->reason]);
        }
        $clean = UntrustedText::sanitize((string) $cv['extracted_text']);

        return $this->response->setJSON([
            'ok' => true, 'grant_id' => (int) $grant['id'], 'expires_at' => $grant['expires_at'],
            'job' => ['title' => $job['title']],
            'jd_version' => ['version_no' => (int) $jd['version_no'], 'summary' => $jd['summary'], 'responsibilities' => $jd['responsibilities'],
                'skills' => json_decode((string) $jd['skills'], true) ?: [], 'must_have' => json_decode((string) $jd['must_have'], true) ?: [],
                'nice_to_have' => json_decode((string) $jd['nice_to_have'], true) ?: [], 'qualifications' => $jd['qualifications']],
            'rubric' => RecruitmentService::rubric($jd),
            'candidate_cv_text' => $clean['text'], 'cv_flags' => array_column($clean['flags'], 'type'),
            'instructions' => service('cvEvaluation')->systemPrompt(in_array('ai.recruitment.suggest_interview_questions', $this->gw()->currentToken()['capabilities'], true), false),
            'response_schema' => CvEvaluationService::schema(),
        ]);
    }

    // POST api/ai/recruitment/candidates/{id}/evaluation  {grant_id, result:{…schema…}, model}
    public function submitEvaluation($id)
    {
        $id   = (int) $id;
        $cap  = CvEvaluationService::CAPABILITY;
        if ($why = $this->gw()->tokenAllows($cap, 'candidate_ids', $id)) {
            return $this->deny($cap, $why, 'candidate', [$id]);
        }
        $body  = $this->request->getJSON(true) ?? [];
        $grant = $this->gw()->openGrant((int) ($body['grant_id'] ?? 0), $cap, 'candidate', $id);
        if ($grant === null) {
            return $this->deny($cap, 'grant_invalid_or_expired', 'candidate', [$id]);
        }
        $c     = $this->candidateRow($id);
        $db    = db_connect();
        $cv    = $db->table('candidate_documents')->where(['candidate_id' => $id, 'doc_kind' => 'cv', 'is_current' => 1])->get()->getRowArray();
        $job   = $db->table('job_openings')->where('id', $c['job_opening_id'])->get()->getRowArray();
        $jd    = $db->table('job_description_versions')->where('id', $job['current_jd_version_id'])->get()->getRowArray();
        $clean = UntrustedText::sanitize((string) $cv['extracted_text']);
        $questions = in_array('ai.recruitment.suggest_interview_questions', $this->gw()->currentToken()['capabilities'], true) && $this->gw()->enabled('ai.recruitment.suggest_interview_questions');
        try {
            $out = service('cvEvaluation')->validate((array) ($body['result'] ?? []), RecruitmentService::rubric($jd), $clean['text'], $questions);
        } catch (BusinessRuleException $e) {
            $this->gw()->finish($grant, 'error', ['error' => $e->getMessage(), 'provider' => 'service_token']);

            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => $e->getMessage()]);
        }
        $evalId = (function () use ($db, $id, $cv, $jd, $out, $clean, $grant, $body) {
            $db->table('ai_cv_evaluations')->insert([
                'candidate_id' => $id, 'candidate_document_id' => $cv['id'], 'jd_version_id' => $jd['id'], 'engine' => 'ai', 'provider' => 'service_token',
                'model' => mb_substr((string) ($body['model'] ?? 'unspecified'), 0, 80), 'prompt_version' => CvEvaluationService::PROMPT_VERSION, 'status' => 'completed',
                'fit_score' => $out['fit_score'], 'category_scores' => json_encode($out['category_scores']), 'matched_requirements' => json_encode($out['matched']),
                'unmet_requirements' => json_encode($out['unmet']), 'unclear_requirements' => json_encode($out['unclear']), 'interview_questions' => json_encode($out['questions']),
                'summary' => $out['summary'], 'explanation' => $out['explanation'], 'confidence' => $out['confidence'],
                'injection_flags' => $clean['flags'] === [] ? null : json_encode($clean['flags']), 'grant_id' => $grant['id'],
                'requested_by' => $grant['requested_by'], 'hr_decision' => 'pending', 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $evalId = (int) $db->insertID();
            service('recruitment')->event($id, 'ai_review', 'AI job-fit review received from AI service (decision support)' . ($out['fit_score'] !== null ? ': ' . $out['fit_score'] . '/10' : ''), null, ['source' => 'system', 'ai_evaluation_id' => $evalId]);

            return $evalId;
        })();
        $this->gw()->finish($grant, 'completed', ['provider' => 'service_token', 'model' => (string) ($body['model'] ?? '')]);

        return $this->response->setStatusCode(201)->setJSON(['ok' => true, 'evaluation_id' => $evalId, 'fit_score' => $out['fit_score'], 'note' => 'Stored as decision support; HR reviews and decides.']);
    }

    // POST api/ai/recruitment/candidates/{id}/timeline-proposals  {title, notes}
    public function proposeTimeline($id)
    {
        $id  = (int) $id;
        $cap = 'ai.recruitment.create_timeline_entry';
        if ($why = $this->gw()->tokenAllows($cap, 'candidate_ids', $id)) {
            return $this->deny($cap, $why, 'candidate', [$id]);
        }
        if ($this->candidateRow($id) === null) {
            return $this->deny($cap, 'not_found', 'candidate', [$id], 404);
        }
        $body = $this->request->getJSON(true) ?? [];
        try {
            $eventId = service('cvEvaluation')->recordProposal($id, (array) $body, null, (int) ($this->gw()->currentToken()['created_by'] ?? 0) ?: null);
        } catch (BusinessRuleException $e) {
            return $this->deny($cap, 'invalid_proposal', 'candidate', [$id], 422);
        }
        $this->gw()->log('completed', $cap, 'candidate', [$id], ['candidate.id', 'recruitment_event.proposed']);

        return $this->response->setStatusCode(201)->setJSON(['ok' => true, 'event_id' => $eventId, 'status' => 'proposed', 'note' => 'HR must accept or reject this proposal.']);
    }

    // GET api/ai/payroll/summary?period=YYYY-MM  (read-only, finalized runs, allowlisted employees)
    public function payrollSummary()
    {
        $cap    = 'ai.payroll.read_approved_summary';
        $period = (string) $this->request->getGet('period');
        if ($why = $this->gw()->tokenAllows($cap, 'employee_ids', null)) {
            return $this->deny($cap, $why, 'payroll_run');
        }
        $token = $this->gw()->currentToken();
        if (! preg_match('/^\d{4}-\d{2}$/', $period) || ! in_array($period, (array) ($token['scope']['periods'] ?? []), true)) {
            return $this->deny($cap, 'period_out_of_token_scope', 'payroll_run');
        }
        $allowed = array_map('intval', (array) ($token['scope']['employee_ids'] ?? []));
        $capCfg  = $this->gw()->capability($cap);
        if (isset($capCfg['scope_config']['employee_ids'])) {
            $allowed = array_values(array_intersect($allowed, array_map('intval', (array) $capCfg['scope_config']['employee_ids'])));
        }
        $db  = db_connect();
        $run = $db->table('payroll_runs')->where('period', $period)->where('is_locked', 1)->get()->getRowArray();
        if ($run === null || $allowed === []) {
            return $this->deny($cap, $run === null ? 'no_finalized_run' : 'no_employees_in_scope', 'payroll_run', [], 404);
        }
        try {
            $grant = $this->gw()->authorize($cap, 'payroll_run', (int) $run['id'], $capCfg['allowed_fields'], (string) $token['purpose'], true);
        } catch (AiDeniedException $e) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false, 'error' => $e->reason]);
        }
        $rows = $db->table('payroll_items i')->select('e.employee_code, i.payable_days, i.gross_pay, i.total_deductions, i.net_pay')
            ->join('employees e', 'e.id = i.employee_id')->where('i.payroll_run_id', $run['id'])->whereIn('i.employee_id', $allowed)->orderBy('e.employee_code')->get()->getResultArray();
        $this->gw()->finish($grant, 'completed', ['provider' => 'service_token']);

        return $this->response->setJSON(['ok' => true, 'period' => $period, 'read_only' => true, 'items' => $rows]);
    }

    // GET api/ai/payroll/period-summary?period=YYYY-MM  (aggregates only)
    public function payrollPeriod()
    {
        $cap    = 'ai.payroll.summarize_period';
        $period = (string) $this->request->getGet('period');
        if ($why = $this->gw()->tokenAllows($cap, 'periods', null)) {
            return $this->deny($cap, $why, 'payroll_run');
        }
        $token = $this->gw()->currentToken();
        if (! in_array($period, (array) ($token['scope']['periods'] ?? []), true)) {
            return $this->deny($cap, 'period_out_of_token_scope', 'payroll_run');
        }
        $run = db_connect()->table('payroll_runs')->select('id, period, total_gross, total_deductions, total_net, (SELECT COUNT(*) FROM payroll_items WHERE payroll_run_id = payroll_runs.id) AS headcount', false)
            ->where('period', $period)->where('is_locked', 1)->get()->getRowArray();
        if ($run === null) {
            return $this->deny($cap, 'no_finalized_run', 'payroll_run', [], 404);
        }
        try {
            $grant = $this->gw()->authorize($cap, 'payroll_run', (int) $run['id'], ['payroll_run.period', 'payroll_run.totals'], (string) $token['purpose'], true);
        } catch (AiDeniedException $e) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false, 'error' => $e->reason]);
        }
        $this->gw()->finish($grant, 'completed', ['provider' => 'service_token']);

        return $this->response->setJSON(['ok' => true, 'read_only' => true, 'period' => $period, 'headcount' => (int) $run['headcount'],
            'total_gross' => $run['total_gross'], 'total_deductions' => $run['total_deductions'], 'total_net' => $run['total_net']]);
    }

    /** Anything else under /api/ai is refused and logged. */
    public function refuse(...$segments)
    {
        return $this->deny('', 'endpoint_not_available_to_ai', '', [], 404);
    }
}
