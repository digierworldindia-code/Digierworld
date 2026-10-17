<?php

namespace App\Services\Hr;

use App\Exceptions\AccessDeniedException;
use App\Exceptions\BusinessRuleException;
use App\Libraries\CvTextExtractor;
use App\Libraries\Dec;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use Throwable;

/**
 * Vacancies with versioned job descriptions, applicants, CV storage and
 * parsing, the status pipeline (every change is a timestamped event with
 * actor and notes), interviews/scorecards and conversion to employee.
 * Every hiring outcome is a human action; nothing here decides on its own.
 */
class RecruitmentService
{
    public const STATUSES = [
        'applied' => 'Applied', 'resume_screened' => 'Resume screened', 'hr_screening' => 'HR screening',
        'interview_scheduled' => 'Interview scheduled', 'interview_completed' => 'Interview completed', 'assignment_gd' => 'Assignment / GD',
        'shortlisted' => 'Shortlisted', 'on_hold' => 'On hold', 'rejected' => 'Rejected', 'offer_sent' => 'Offer sent',
        'offer_accepted' => 'Offer accepted', 'joined' => 'Joined', 'withdrawn' => 'Withdrawn',
    ];

    /** Outcomes that are hiring decisions and need hr.candidate.decide. */
    public const DECISIONS = ['shortlisted', 'on_hold', 'rejected', 'offer_sent', 'offer_accepted'];

    public const DEFAULT_RUBRIC = [
        'essential_skills' => ['label' => 'Essential skills', 'weight' => 40],
        'relevant_experience' => ['label' => 'Relevant experience', 'weight' => 25],
        'project_evidence' => ['label' => 'Comparable project evidence', 'weight' => 20],
        'qualifications' => ['label' => 'Role-specific qualifications', 'weight' => 10],
        'other_evidence' => ['label' => 'Other job-relevant evidence', 'weight' => 5],
    ];

    public const INTERVIEW_TYPES = ['hr_screening' => 'HR screening', 'technical' => 'Technical', 'managerial' => 'Managerial', 'group_discussion' => 'Group discussion (GD)', 'assignment' => 'Assignment review', 'final' => 'Final'];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    private function uid(): ?int
    {
        return service('authz')->currentUserId();
    }

    private function tx(callable $fn): mixed
    {
        $this->db->transBegin();
        try {
            $r = $fn();
            $this->db->transCommit();

            return $r;
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
    }

    /** @return list<string> one item per non-empty line */
    public static function lines(mixed $v): array
    {
        $items = is_array($v) ? $v : preg_split('/\R/', (string) $v);

        return array_values(array_unique(array_filter(array_map(static fn ($l) => mb_substr(trim((string) $l, " \t•-*"), 0, 300), $items ?: []), static fn ($l) => $l !== '')));
    }

    // ---- vacancies & JD versions -------------------------------------------

    /** @param array<string, mixed> $in */
    private function normalizeVacancy(array $in): array
    {
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') {
            throw new BusinessRuleException('Enter the job title.', ['title' => 'Required']);
        }
        $num = static fn ($k) => is_numeric($v = str_replace(',', '', trim((string) ($in[$k] ?? '')))) ? $v : null;
        $min = $num('experience_min');
        $max = $num('experience_max');
        if ($min !== null && $max !== null && (float) $max < (float) $min) {
            throw new BusinessRuleException('Maximum experience is less than the minimum.', ['experience_max' => 'Check the range']);
        }
        $smin = $num('salary_min');
        $smax = $num('salary_max');
        if ($smin !== null && $smax !== null && (float) $smax < (float) $smin) {
            throw new BusinessRuleException('Maximum salary is less than the minimum.', ['salary_max' => 'Check the range']);
        }

        return [
            'title' => mb_substr($title, 0, 150), 'department_id' => (int) ($in['department_id'] ?? 0) ?: null,
            'location' => mb_substr(trim((string) ($in['location'] ?? '')), 0, 100) ?: null,
            'work_mode' => in_array($in['work_mode'] ?? '', ['onsite', 'remote', 'hybrid'], true) ? $in['work_mode'] : 'onsite',
            'experience_min' => $min, 'experience_max' => $max, 'salary_min' => $smin, 'salary_max' => $smax,
            'openings_count' => max(1, min(500, (int) ($in['openings_count'] ?? 1))),
            'recruiter_user_id' => (int) ($in['recruiter_user_id'] ?? 0) ?: null, 'interviewer_user_id' => (int) ($in['interviewer_user_id'] ?? 0) ?: null,
            'status' => in_array($in['status'] ?? '', ['draft', 'open', 'on_hold', 'closed', 'filled'], true) ? $in['status'] : 'draft',
        ];
    }

    /** Create a vacancy together with its first JD version (a JD is required before CVs). */
    public function createVacancy(array $in): int
    {
        service('authz')->authorize('hr.vacancy.manage');
        $data = $this->normalizeVacancy($in);
        $jd   = $this->normalizeJd($in);

        return $this->tx(function () use ($data, $jd): int {
            $this->db->table('job_openings')->insert($data + ['code' => 'TMP' . bin2hex(random_bytes(5)), 'created_by' => $this->uid()]);
            $id = (int) $this->db->insertID();
            $this->db->table('job_openings')->where('id', $id)->update(['code' => 'JOB-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT)]);
            $this->insertJd($id, $jd, 'Initial version');
            service('audit')->log('vacancy.created', 'job_opening', $id, null, ['title' => $data['title'], 'status' => $data['status']], 'Vacancy created: ' . $data['title']);

            return $id;
        });
    }

    public function updateVacancy(int $id, array $in): void
    {
        service('authz')->authorize('hr.vacancy.manage');
        $before = $this->vacancy($id);
        $data   = $this->normalizeVacancy($in);
        $this->db->table('job_openings')->where('id', $id)->update($data + ['updated_by' => $this->uid()]);
        service('audit')->log('vacancy.updated', 'job_opening', $id, array_intersect_key($before, $data), $data, 'Vacancy updated: ' . $data['title']);
    }

    /** @return array<string, mixed> */
    public function vacancy(int $id): array
    {
        return $this->db->table('job_openings')->where('id', $id)->get()->getRowArray() ?? throw new BusinessRuleException('Vacancy not found.');
    }

    /** @param array<string, mixed> $in */
    private function normalizeJd(array $in): array
    {
        $summary = trim((string) ($in['summary'] ?? ''));
        $must    = self::lines($in['must_have'] ?? '');
        $errors  = [];
        if (mb_strlen($summary) < 20) {
            $errors['summary'] = 'Write a short role summary (at least a sentence).';
        }
        if ($must === []) {
            $errors['must_have'] = 'List at least one must-have requirement (one per line).';
        }
        $rubric = [];
        $total  = 0;
        foreach (self::DEFAULT_RUBRIC as $k => $r) {
            $w = (int) ($in['rubric'][$k] ?? $r['weight']);
            if ($w < 0 || $w > 100) {
                $errors['rubric'] = 'Weights must be between 0 and 100.';
            }
            $rubric[$k] = ['label' => $r['label'], 'weight' => $w];
            $total += $w;
        }
        if ($total !== 100) {
            $errors['rubric'] = 'Rubric weights must add up to 100 (now ' . $total . ').';
        }
        if ($errors !== []) {
            throw new BusinessRuleException(reset($errors), $errors);
        }

        return [
            'summary' => $summary, 'responsibilities' => trim((string) ($in['responsibilities'] ?? '')) ?: null,
            'skills' => json_encode(self::lines($in['skills'] ?? ''), JSON_UNESCAPED_UNICODE), 'must_have' => json_encode($must, JSON_UNESCAPED_UNICODE),
            'nice_to_have' => json_encode(self::lines($in['nice_to_have'] ?? ''), JSON_UNESCAPED_UNICODE),
            'qualifications' => trim((string) ($in['qualifications'] ?? '')) ?: null, 'rubric' => json_encode($rubric, JSON_UNESCAPED_UNICODE),
        ];
    }

    private function insertJd(int $jobId, array $jd, string $note, ?int $fileId = null): int
    {
        $no = (int) ($this->db->table('job_description_versions')->selectMax('version_no', 'm')->where('job_opening_id', $jobId)->get()->getRow()->m ?? 0) + 1;
        $this->db->table('job_description_versions')->insert($jd + ['job_opening_id' => $jobId, 'version_no' => $no, 'change_note' => mb_substr($note, 0, 255), 'attachment_file_id' => $fileId, 'created_by' => $this->uid()]);
        $id = (int) $this->db->insertID();
        $this->db->table('job_openings')->where('id', $jobId)->update(['current_jd_version_id' => $id]);

        return $id;
    }

    /** New JD version (old versions are kept; candidates keep the version they applied against). */
    public function addJdVersion(int $jobId, array $in, ?UploadedFile $file = null): int
    {
        service('authz')->authorize('hr.vacancy.manage');
        $this->vacancy($jobId);
        $jd = $this->normalizeJd($in);

        return $this->tx(function () use ($jobId, $jd, $in, $file): int {
            $fileId = $file !== null && $file->isValid() ? service('files')->storeUpload($file, 'doc', 'jd', 'job_opening', $jobId) : null;
            $id     = $this->insertJd($jobId, $jd, trim((string) ($in['change_note'] ?? '')) ?: 'Updated', $fileId);
            service('audit')->log('vacancy.jd_version', 'job_opening', $jobId, null, ['jd_version_id' => $id], 'New JD version');

            return $id;
        });
    }

    // ---- candidates ---------------------------------------------------------

    /** @return array<string, mixed> candidate within the caller's scope */
    public function candidate(int $id): array
    {
        $c = $this->db->table('candidates')->where('id', $id)->where('deleted_at', null)->get()->getRowArray();
        if ($c === null || ! service('candidateAccess')->canViewCandidateId($id)) {
            throw new BusinessRuleException('Applicant not found or not accessible.');
        }

        return $c;
    }

    public function event(int $candidateId, string $type, string $title, ?string $notes = null, array $extra = []): int
    {
        $this->db->table('recruitment_events')->insert([
            'candidate_id' => $candidateId, 'event_type' => mb_substr($type, 0, 40), 'title' => mb_substr($title, 0, 255), 'notes' => $notes,
            'actor_id' => $this->uid(), 'occurred_at' => date('Y-m-d H:i:s'),
        ] + $extra);

        return (int) $this->db->insertID();
    }

    /** @param array<string, mixed> $in */
    public function createCandidate(array $in, ?UploadedFile $cv = null): int
    {
        service('authz')->authorize('hr.candidate.create');
        $job = $this->vacancy((int) ($in['job_opening_id'] ?? 0));
        if (! $job['current_jd_version_id']) {
            throw new BusinessRuleException('This vacancy has no job description yet. Add the JD before adding applicants.');
        }
        $name   = trim((string) ($in['full_name'] ?? ''));
        $email  = trim((string) ($in['email'] ?? ''));
        $phone  = normalize_phone((string) ($in['phone'] ?? ''));
        $errors = [];
        if ($name === '') {
            $errors['full_name'] = 'Enter the applicant name.';
        }
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email looks invalid.';
        }
        if (empty($in['consent_given'])) {
            $errors['consent_given'] = 'Record that the applicant consented to their data being stored for this application.';
        }
        if ($errors !== []) {
            throw new BusinessRuleException('Please correct the highlighted fields.', $errors);
        }
        if (empty($in['allow_duplicate']) && ($phone || $email !== '')) {
            $dupe = $this->db->table('candidates')->select('id, candidate_code')->where('job_opening_id', $job['id'])->where('deleted_at', null)
                ->groupStart()->where('phone_normalized', $phone ?: '-')->orWhere('email', $email ?: '-')->groupEnd()->get()->getRowArray();
            if ($dupe !== null) {
                throw new BusinessRuleException('This applicant already applied for this vacancy (' . $dupe['candidate_code'] . ').', ['duplicate' => $dupe['candidate_code']]);
            }
        }
        $num  = static fn ($k) => is_numeric($v = str_replace(',', '', trim((string) ($in[$k] ?? '')))) ? $v : null;
        $days = (int) service('settingsStore')->get('hr.candidate_retention_days', 365);

        return $this->tx(function () use ($in, $job, $name, $email, $phone, $num, $days, $cv): int {
            $this->db->table('candidates')->insert([
                'candidate_code' => 'TMP' . bin2hex(random_bytes(5)), 'full_name' => mb_substr($name, 0, 150), 'email' => $email ?: null,
                'phone' => mb_substr(trim((string) ($in['phone'] ?? '')), 0, 20) ?: null, 'phone_normalized' => $phone,
                'job_opening_id' => $job['id'], 'applied_jd_version_id' => $job['current_jd_version_id'],
                'application_date' => ymd((string) ($in['application_date'] ?? '')) ?? date('Y-m-d'), 'source' => mb_substr(trim((string) ($in['source'] ?? '')), 0, 80) ?: null,
                'status' => 'applied', 'expected_salary' => $num('expected_salary'),
                'available_from' => ymd((string) ($in['available_from'] ?? '')), 'notice_period_days' => $num('notice_period_days') !== null ? (int) $num('notice_period_days') : null,
                'total_experience_years' => $num('total_experience_years'), 'linkedin_url' => $this->url($in['linkedin_url'] ?? ''), 'portfolio_url' => $this->url($in['portfolio_url'] ?? ''),
                'recruiter_notes' => trim((string) ($in['recruiter_notes'] ?? '')) ?: null,
                'recruiter_id' => (int) ($in['recruiter_id'] ?? 0) ?: ($job['recruiter_user_id'] ?: $this->uid()),
                'consent_given' => 1, 'consent_at' => date('Y-m-d H:i:s'), 'consent_text_version' => (string) service('settingsStore')->get('hr.consent_text_version', 'v1'),
                'ai_share_consent' => empty($in['ai_share_consent']) ? 0 : 1, 'retention_until' => date('Y-m-d', strtotime("+{$days} days")), 'created_by' => $this->uid(),
            ]);
            $id = (int) $this->db->insertID();
            $this->db->table('candidates')->where('id', $id)->update(['candidate_code' => 'CAN-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT)]);
            $jdNo = $this->db->table('job_description_versions')->select('version_no')->where('id', $job['current_jd_version_id'])->get()->getRow()->version_no ?? 1;
            $this->event($id, 'applied', 'Applied for ' . $job['title'] . ' (JD v' . $jdNo . ')', null, ['to_status' => 'applied', 'source' => 'manual']);
            if (! empty($in['ai_share_consent'])) {
                $this->event($id, 'consent', 'Consent recorded: CV text may be shared with the configured AI provider for evaluation', null, ['source' => 'manual']);
            }
            if ($cv !== null && $cv->isValid()) {
                $this->storeCv($id, $cv);
            }
            service('audit')->log('candidate.created', 'candidate', $id, null, ['job_opening_id' => $job['id']], 'Applicant added: ' . $name);

            return $id;
        });
    }

    private function url(mixed $v): ?string
    {
        $v = trim((string) $v);

        return $v !== '' && preg_match('#^https?://#i', $v) && filter_var($v, FILTER_VALIDATE_URL) ? mb_substr($v, 0, 255) : null;
    }

    /** @param array<string, mixed> $in */
    public function updateCandidate(int $id, array $in): void
    {
        service('authz')->authorize('hr.candidate.edit');
        $c   = $this->candidate($id);
        $num = static fn ($k) => is_numeric($v = str_replace(',', '', trim((string) ($in[$k] ?? '')))) ? $v : null;
        $data = [
            'full_name' => mb_substr(trim((string) ($in['full_name'] ?? $c['full_name'])), 0, 150) ?: $c['full_name'],
            'email' => filter_var(trim((string) ($in['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: null,
            'phone' => mb_substr(trim((string) ($in['phone'] ?? '')), 0, 20) ?: null, 'phone_normalized' => normalize_phone((string) ($in['phone'] ?? '')),
            'source' => mb_substr(trim((string) ($in['source'] ?? '')), 0, 80) ?: null, 'available_from' => ymd((string) ($in['available_from'] ?? '')),
            'notice_period_days' => $num('notice_period_days') !== null ? (int) $num('notice_period_days') : null, 'total_experience_years' => $num('total_experience_years'),
            'linkedin_url' => $this->url($in['linkedin_url'] ?? ''), 'portfolio_url' => $this->url($in['portfolio_url'] ?? ''),
            'recruiter_notes' => trim((string) ($in['recruiter_notes'] ?? '')) ?: null,
            'extracted_skills' => json_encode(self::lines($in['extracted_skills'] ?? ''), JSON_UNESCAPED_UNICODE),
            'extracted_education' => json_encode(self::lines($in['extracted_education'] ?? ''), JSON_UNESCAPED_UNICODE),
            'extracted_experience' => json_encode(self::lines($in['extracted_experience'] ?? ''), JSON_UNESCAPED_UNICODE),
            'extracted_projects' => json_encode(self::lines($in['extracted_projects'] ?? ''), JSON_UNESCAPED_UNICODE),
            'updated_by' => $this->uid(),
        ];
        if (service('authz')->can('hr.salary.view')) {
            $data['expected_salary'] = $num('expected_salary');
        }
        $aiConsent = empty($in['ai_share_consent']) ? 0 : 1;
        $this->tx(function () use ($id, $c, $data, $aiConsent): void {
            $this->db->table('candidates')->where('id', $id)->update($data + ['ai_share_consent' => $aiConsent]);
            if ($aiConsent !== (int) $c['ai_share_consent']) {
                $this->event($id, 'consent', $aiConsent ? 'Consent recorded: CV may be shared with the AI provider' : 'AI-sharing consent withdrawn', null, ['source' => 'manual']);
            }
            service('audit')->log('candidate.updated', 'candidate', $id, null, ['fields' => array_keys($data)], 'Applicant updated');
        });
    }

    /** Store a new CV version, extract its text server-side and record the parse status. */
    public function storeCv(int $candidateId, UploadedFile $file): int
    {
        $fileId = service('files')->storeUpload($file, 'cv', 'cv', 'candidate', $candidateId);
        $stored = service('files')->find($fileId);
        $no     = (int) ($this->db->table('candidate_documents')->selectMax('version_no', 'm')->where(['candidate_id' => $candidateId, 'doc_kind' => 'cv'])->get()->getRow()->m ?? 0) + 1;
        $status = 'parsed';
        $error  = null;
        $text   = null;
        try {
            $text = CvTextExtractor::extract((string) service('files')->contents($fileId), $stored['mime_type']);
        } catch (\RuntimeException $e) {
            $status = 'failed';
            $error  = mb_substr($e->getMessage(), 0, 500);
        }
        $this->db->table('candidate_documents')->where(['candidate_id' => $candidateId, 'doc_kind' => 'cv'])->update(['is_current' => 0]);
        $this->db->table('candidate_documents')->insert([
            'candidate_id' => $candidateId, 'doc_kind' => 'cv', 'version_no' => $no, 'stored_file_id' => $fileId, 'parse_status' => $status,
            'parse_error' => $error, 'extracted_text' => $text, 'text_sha256' => $text !== null ? hash('sha256', $text) : null, 'is_current' => 1, 'uploaded_by' => $this->uid(),
        ]);
        $docId = (int) $this->db->insertID();
        if ($text !== null) {
            $sec = CvTextExtractor::sections($text);
            $this->db->table('candidates')->where('id', $candidateId)->update([
                'extracted_skills' => json_encode($sec['skills'], JSON_UNESCAPED_UNICODE), 'extracted_education' => json_encode($sec['education'], JSON_UNESCAPED_UNICODE),
                'extracted_experience' => json_encode($sec['experience'], JSON_UNESCAPED_UNICODE), 'extracted_projects' => json_encode($sec['projects'], JSON_UNESCAPED_UNICODE),
            ]);
        }
        $this->event($candidateId, 'cv_uploaded', 'CV v' . $no . ' uploaded — ' . ($status === 'parsed' ? 'text extracted' : 'text extraction failed'), $error, ['ref_type' => 'candidate_document', 'ref_id' => $docId, 'source' => 'system']);

        return $docId;
    }

    public function uploadCv(int $candidateId, UploadedFile $file): int
    {
        service('authz')->authorize(['hr.candidate.create', 'hr.candidate.edit']);
        $this->candidate($candidateId);

        return $this->tx(fn () => $this->storeCv($candidateId, $file));
    }

    /** @return array<string, mixed>|null */
    public function currentCv(int $candidateId): ?array
    {
        return $this->db->table('candidate_documents')->where(['candidate_id' => $candidateId, 'doc_kind' => 'cv', 'is_current' => 1])->get()->getRowArray();
    }

    /** Human status change with notes. Hiring outcomes need hr.candidate.decide. */
    public function changeStatus(int $candidateId, string $to, string $notes = ''): void
    {
        $c = $this->candidate($candidateId);
        if (! isset(self::STATUSES[$to]) || $to === 'joined') {
            throw new BusinessRuleException($to === 'joined' ? 'Use “Convert to employee” to mark an applicant as joined.' : 'Unknown status.');
        }
        if (in_array($c['status'], ['joined', 'withdrawn'], true)) {
            throw new BusinessRuleException('This application is closed (' . self::STATUSES[$c['status']] . ').');
        }
        service('authz')->authorize(in_array($to, self::DECISIONS, true) ? 'hr.candidate.decide' : ['hr.candidate.edit', 'hr.candidate.decide']);
        if (in_array($to, ['rejected', 'on_hold'], true) && mb_strlen(trim($notes)) < 5) {
            throw new BusinessRuleException('Write the reason for this decision (it is kept on the timeline).', ['notes' => 'Required']);
        }
        if ($to === $c['status']) {
            return;
        }
        $this->tx(function () use ($c, $to, $notes): void {
            $this->db->table('candidates')->where('id', $c['id'])->update(['status' => $to, 'updated_by' => $this->uid()]);
            $this->event((int) $c['id'], 'status_change', self::STATUSES[$c['status']] . ' → ' . self::STATUSES[$to], trim($notes) ?: null, ['from_status' => $c['status'], 'to_status' => $to, 'source' => 'manual']);
            service('audit')->log('candidate.status', 'candidate', (int) $c['id'], ['status' => $c['status']], ['status' => $to], 'Applicant status: ' . self::STATUSES[$to]);
        });
    }

    public function setHrScore(int $candidateId, string $score, string $notes): void
    {
        service('authz')->authorize('hr.candidate.score');
        $c = $this->candidate($candidateId);
        if (! is_numeric($score) || (float) $score < 0 || (float) $score > 10) {
            throw new BusinessRuleException('HR score must be between 0 and 10.');
        }
        $this->tx(function () use ($c, $score, $notes): void {
            $this->db->table('candidates')->where('id', $c['id'])->update(['hr_score' => number_format((float) $score, 1, '.', ''), 'updated_by' => $this->uid()]);
            $this->event((int) $c['id'], 'hr_score', 'HR score recorded: ' . number_format((float) $score, 1) . '/10', trim($notes) ?: null, ['source' => 'manual']);
        });
    }

    public function addNote(int $candidateId, string $title, string $notes): void
    {
        service('authz')->authorize('hr.timeline.create');
        $this->candidate($candidateId);
        if (trim($title) === '') {
            throw new BusinessRuleException('Give the timeline entry a title.');
        }
        $this->event($candidateId, 'note', trim($title), trim($notes) ?: null, ['source' => 'manual']);
    }

    // ---- interviews ---------------------------------------------------------

    /** @param array<string, mixed> $in */
    public function scheduleInterview(int $candidateId, array $in): int
    {
        service('authz')->authorize('hr.interview.manage');
        $c    = $this->candidate($candidateId);
        $type = (string) ($in['interview_type'] ?? '');
        $at   = ! empty($in['scheduled_at']) ? date('Y-m-d H:i:s', strtotime((string) $in['scheduled_at'])) : null;
        $who  = (int) ($in['interviewer_id'] ?? 0);
        if (! isset(self::INTERVIEW_TYPES[$type]) || $at === null || $who <= 0) {
            throw new BusinessRuleException('Choose the interview type, date/time and interviewer.');
        }

        return $this->tx(function () use ($c, $in, $type, $at, $who): int {
            $round = (int) ($this->db->table('interviews')->selectMax('round_no', 'm')->where('candidate_id', $c['id'])->get()->getRow()->m ?? 0) + 1;
            $this->db->table('interviews')->insert([
                'candidate_id' => $c['id'], 'round_no' => $round, 'interview_type' => $type, 'scheduled_at' => $at,
                'duration_minutes' => max(10, min(480, (int) ($in['duration_minutes'] ?? 30))),
                'mode' => in_array($in['mode'] ?? '', ['in_person', 'phone', 'video'], true) ? $in['mode'] : 'in_person',
                'location_or_link' => mb_substr(trim((string) ($in['location_or_link'] ?? '')), 0, 300) ?: null, 'interviewer_id' => $who,
                'status' => 'scheduled', 'result' => 'pending', 'created_by' => $this->uid(),
            ]);
            $id = (int) $this->db->insertID();
            $this->event((int) $c['id'], 'interview_scheduled', 'Round ' . $round . ' ' . self::INTERVIEW_TYPES[$type] . ' scheduled for ' . dmy($at, true), null, ['ref_type' => 'interview', 'ref_id' => $id, 'source' => 'manual']);
            if (in_array($c['status'], ['applied', 'resume_screened', 'hr_screening', 'interview_completed'], true)) {
                $this->db->table('candidates')->where('id', $c['id'])->update(['status' => $type === 'group_discussion' || $type === 'assignment' ? 'assignment_gd' : 'interview_scheduled']);
            }
            service('notifier')->notify($who, 'hr', 'Interview: ' . $c['full_name'] . ' — ' . dmy($at, true), self::INTERVIEW_TYPES[$type], site_url('hr/candidates/' . $c['id']), 'interview', $id, 'int:' . $id);

            return $id;
        });
    }

    /** Record what actually happened; ratings and files are only what the interviewer entered. */
    public function completeInterview(int $interviewId, array $in, ?UploadedFile $file = null): void
    {
        service('authz')->authorize('hr.interview.manage');
        $iv = $this->db->table('interviews')->where('id', $interviewId)->get()->getRowArray() ?? throw new BusinessRuleException('Interview not found.');
        $c  = $this->candidate((int) $iv['candidate_id']);
        $status = in_array($in['status'] ?? '', ['completed', 'cancelled', 'no_show'], true) ? $in['status'] : 'completed';
        $result = in_array($in['result'] ?? '', ['pass', 'fail', 'hold', 'pending'], true) ? $in['result'] : 'pending';
        $rating = (int) ($in['overall_rating'] ?? 0);
        $cards  = [];
        foreach ((array) ($in['scorecards'] ?? []) as $sc) {
            $crit = mb_substr(trim((string) ($sc['criterion'] ?? '')), 0, 120);
            $r    = (int) ($sc['rating'] ?? 0);
            if ($crit !== '' && $r >= 1 && $r <= 5) {
                $cards[$crit] = ['criterion' => $crit, 'rating' => $r, 'comments' => mb_substr(trim((string) ($sc['comments'] ?? '')), 0, 1000) ?: null];
            }
        }
        if ($status === 'completed' && trim((string) ($in['feedback'] ?? '')) === '' && $cards === []) {
            throw new BusinessRuleException('Record the feedback or at least one scorecard rating for a completed interview.');
        }
        $this->tx(function () use ($iv, $c, $status, $result, $rating, $cards, $in, $file): void {
            $fileId = $file !== null && $file->isValid() ? service('files')->storeUpload($file, 'doc', 'interview', 'interview', (int) $iv['id']) : $iv['attachment_file_id'];
            $this->db->table('interviews')->where('id', $iv['id'])->update([
                'status' => $status, 'result' => $status === 'completed' ? $result : 'pending', 'overall_rating' => $rating >= 1 && $rating <= 5 ? $rating : null,
                'feedback' => trim((string) ($in['feedback'] ?? '')) ?: null, 'attachment_file_id' => $fileId, 'updated_by' => $this->uid(),
            ]);
            $this->db->table('interview_scorecards')->where('interview_id', $iv['id'])->delete();
            foreach ($cards as $card) {
                $this->db->table('interview_scorecards')->insert($card + ['interview_id' => $iv['id'], 'scored_by' => $this->uid()]);
            }
            $label = self::INTERVIEW_TYPES[$iv['interview_type']];
            $this->event((int) $c['id'], 'interview_' . $status, 'Round ' . $iv['round_no'] . ' ' . $label . ': ' . str_replace('_', ' ', $status) . ($status === 'completed' ? ' — ' . $result : '') . ($rating ? ' (' . $rating . '/5)' : ''),
                trim((string) ($in['feedback'] ?? '')) ?: null, ['ref_type' => 'interview', 'ref_id' => (int) $iv['id'], 'source' => 'manual']);
            if ($status === 'completed' && in_array($c['status'], ['interview_scheduled', 'assignment_gd'], true)) {
                $this->db->table('candidates')->where('id', $c['id'])->update(['status' => 'interview_completed']);
            }
        });
    }

    // ---- joining ------------------------------------------------------------

    /** Convert an accepted offer into an employee, linked to the full recruitment history. */
    public function convert(int $candidateId, array $in): int
    {
        service('authz')->authorize('hr.candidate.convert');
        service('authz')->authorize('hr.employee.manage');
        $c = $this->candidate($candidateId);
        if ($c['employee_id']) {
            throw new BusinessRuleException('Already converted to an employee.');
        }
        if ($c['status'] !== 'offer_accepted') {
            throw new BusinessRuleException('Only an applicant with “Offer accepted” can be converted.');
        }
        $dupe = $this->db->table('employees')->select('employee_code, full_name')->groupStart()
            ->where('personal_email', $c['email'] ?: '-')->orWhere('work_email', $c['email'] ?: '-')->orWhere('personal_phone', $c['phone'] ?: '-')->groupEnd()->get()->getRowArray();
        if ($dupe !== null && empty($in['allow_duplicate'])) {
            throw new BusinessRuleException('An employee with the same email/phone already exists (' . $dupe['employee_code'] . ' ' . $dupe['full_name'] . '). Tick “different person” to continue.', ['duplicate' => $dupe['employee_code']]);
        }
        $job = $this->vacancy((int) $c['job_opening_id']);

        return $this->tx(function () use ($c, $in, $job): int {
            $empId = service('employees')->create([
                'full_name' => $c['full_name'], 'personal_email' => $c['email'], 'personal_phone' => $c['phone'],
                'department_id' => $in['department_id'] ?? $job['department_id'], 'designation_id' => $in['designation_id'] ?? null,
                'date_of_joining' => $in['date_of_joining'] ?? date('Y-m-d'), 'employment_status' => $in['employment_status'] ?? 'probation',
                'employment_type' => $in['employment_type'] ?? 'full_time', 'work_location' => $job['location'],
            ], (int) $c['id']);
            $code = service('employees')->find($empId)['employee_code'];
            $this->db->table('candidates')->where('id', $c['id'])->update(['status' => 'joined', 'employee_id' => $empId, 'updated_by' => $this->uid()]);
            $this->event((int) $c['id'], 'joined', 'Joined — employee record ' . $code . ' created', null, ['from_status' => $c['status'], 'to_status' => 'joined', 'ref_type' => 'employee', 'ref_id' => $empId, 'source' => 'manual']);
            $filled = $this->db->table('candidates')->where(['job_opening_id' => $job['id'], 'status' => 'joined'])->countAllResults();
            if ($filled >= (int) $job['openings_count'] && $job['status'] === 'open') {
                $this->db->table('job_openings')->where('id', $job['id'])->update(['status' => 'filled']);
            }
            service('audit')->log('candidate.converted', 'candidate', (int) $c['id'], null, ['employee_id' => $empId], 'Applicant converted to employee ' . $code);

            return $empId;
        });
    }

    /**
     * Data retention: anonymise applications past their retention date
     * (never joined employees) and delete their CV files. Run by cron.
     */
    public function purgeExpired(?string $today = null): int
    {
        $today ??= date('Y-m-d');
        $rows = $this->db->table('candidates')->select('id')->where('retention_until <', $today)->where('employee_id', null)->where('deleted_at', null)->limit(200)->get()->getResultArray();
        foreach ($rows as $r) {
            $this->tx(function () use ($r): void {
                foreach ($this->db->table('candidate_documents')->where('candidate_id', $r['id'])->get()->getResultArray() as $d) {
                    $f = service('files')->find((int) $d['stored_file_id']);
                    if ($f !== null) {
                        @unlink(service('files')->path($f));
                        $this->db->table('stored_files')->where('id', $f['id'])->update(['deleted_at' => date('Y-m-d H:i:s')]);
                    }
                }
                $this->db->table('candidate_documents')->where('candidate_id', $r['id'])->update(['extracted_text' => null, 'text_sha256' => null]);
                $this->db->table('candidates')->where('id', $r['id'])->update([
                    'full_name' => 'Applicant (purged)', 'email' => null, 'phone' => null, 'phone_normalized' => null, 'linkedin_url' => null, 'portfolio_url' => null,
                    'expected_salary' => null, 'extracted_skills' => null, 'extracted_education' => null, 'extracted_experience' => null, 'extracted_projects' => null,
                    'recruiter_notes' => null, 'deleted_at' => date('Y-m-d H:i:s'),
                ]);
                $this->db->table('ai_cv_evaluations')->where('candidate_id', $r['id'])->update(['matched_requirements' => null, 'unmet_requirements' => null, 'unclear_requirements' => null, 'summary' => null, 'explanation' => null, 'interview_questions' => null]);
                service('audit')->asActor('system')->log('candidate.purged', 'candidate', (int) $r['id'], null, null, 'Applicant data purged after retention period');
            });
        }

        return count($rows);
    }

    /** Rubric of a JD version with integer weights. @return array<string, array{label: string, weight: int}> */
    public static function rubric(array $jd): array
    {
        $r = json_decode((string) ($jd['rubric'] ?? ''), true);

        return is_array($r) && $r !== [] ? $r : self::DEFAULT_RUBRIC;
    }

    /** Weighted 0–10 score from 0–10 category scores (only categories that were scored). */
    public static function weighted(array $rubric, array $scores): ?string
    {
        $sum = '0';
        $w   = 0;
        foreach ($rubric as $k => $r) {
            if (isset($scores[$k]) && is_numeric($scores[$k])) {
                $sum = Dec::add($sum, Dec::mul((string) $scores[$k], (string) $r['weight'], 4));
                $w  += (int) $r['weight'];
            }
        }

        return $w > 0 ? Dec::round(Dec::div($sum, (string) $w, 4), 1) : null;
    }
}
