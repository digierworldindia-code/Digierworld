<?= $this->extend('layouts/app') ?>
<?php
use App\Services\Hr\RecruitmentService;

$statuses = RecruitmentService::STATUSES;
$closed   = in_array($c['status'], ['joined', 'withdrawn'], true);
$rubric   = $jd ? RecruitmentService::rubric($jd) : RecruitmentService::DEFAULT_RUBRIC;
?>
<?= $this->section('pageActions') ?>
<?php if (can('hr.candidate.edit')): ?><a href="<?= site_url('hr/candidates/' . $c['id'] . '/edit') ?>" class="btn btn-outline-dark btn-sm"><i class="bi bi-pencil"></i> Edit</a><?php endif ?>
<?php if ($c['employee_id']): ?><a href="<?= site_url('hr/employees/' . $c['employee_id']) ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-person-check"></i> Employee record</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row g-3">
<div class="col-xl-4">
    <div class="card mb-3"><div class="card-body small">
        <div class="d-flex justify-content-between align-items-start"><div><div class="h5 mb-0"><?= esc($c['full_name']) ?></div><div class="text-muted mono"><?= esc($c['candidate_code']) ?></div></div><?= status_badge($c['status']) ?></div>
        <dl class="row mb-0 mt-3">
            <dt class="col-5">Vacancy</dt><dd class="col-7"><a href="<?= site_url('hr/vacancies/' . $job['id']) ?>"><?= esc($job['title']) ?></a></dd>
            <dt class="col-5">Applied</dt><dd class="col-7"><?= dmy($c['application_date']) ?> · JD v<?= (int) ($jd['version_no'] ?? 0) ?><?= $currentJdNo && (int) $currentJdNo !== (int) ($jd['version_no'] ?? 0) ? ' <span class="text-warning">(current v' . (int) $currentJdNo . ')</span>' : '' ?></dd>
            <dt class="col-5">Source</dt><dd class="col-7"><?= esc((string) $c['source']) ?: '—' ?></dd>
            <dt class="col-5">Phone / email</dt><dd class="col-7 text-break"><?= esc((string) $c['phone']) ?> <?= esc((string) $c['email']) ?></dd>
            <dt class="col-5">Experience</dt><dd class="col-7"><?= $c['total_experience_years'] !== null ? (float) $c['total_experience_years'] . ' years' : '—' ?></dd>
            <dt class="col-5">Notice / available</dt><dd class="col-7"><?= $c['notice_period_days'] !== null ? (int) $c['notice_period_days'] . ' days' : '—' ?><?= $c['available_from'] ? ' · ' . dmy($c['available_from']) : '' ?></dd>
            <?php if (array_key_exists('expected_salary', $c)): ?><dt class="col-5">Expected salary <i class="bi bi-lock-fill text-muted"></i></dt><dd class="col-7"><?= $c['expected_salary'] ? inr($c['expected_salary']) . '/month' : '—' ?></dd><?php endif ?>
            <?php if ($c['linkedin_url']): ?><dt class="col-5">LinkedIn</dt><dd class="col-7 text-break"><a href="<?= esc($c['linkedin_url']) ?>" rel="noopener noreferrer nofollow" target="_blank">profile</a></dd><?php endif ?>
            <?php if ($c['portfolio_url']): ?><dt class="col-5">Portfolio</dt><dd class="col-7 text-break"><a href="<?= esc($c['portfolio_url']) ?>" rel="noopener noreferrer nofollow" target="_blank">link</a></dd><?php endif ?>
            <dt class="col-5">HR score</dt><dd class="col-7 fw-semibold"><?= $c['hr_score'] !== null ? number_format((float) $c['hr_score'], 1) . ' / 10' : '—' ?></dd>
            <dt class="col-5">Consent</dt><dd class="col-7"><?= $c['consent_given'] ? 'Data stored with consent (' . esc((string) $c['consent_text_version']) . ', ' . dmy($c['consent_at']) . ')' : '<span class="text-danger">not recorded</span>' ?><br><?= $c['ai_share_consent'] ? 'AI review: consented' : '<span class="text-muted">AI review: no consent</span>' ?></dd>
            <dt class="col-5">Keep until</dt><dd class="col-7"><?= dmy($c['retention_until']) ?></dd>
        </dl>
        <?php if ($c['recruiter_notes']): ?><div class="mt-2 p-2 bg-light rounded" style="white-space:pre-line"><?= esc($c['recruiter_notes']) ?></div><?php endif ?>
    </div></div>

    <?php if (! $closed && can(['hr.candidate.decide', 'hr.candidate.edit'])): ?>
    <form method="post" action="<?= site_url('hr/candidates/' . $c['id'] . '/status') ?>" class="card mb-3"><div class="card-body small"><?= csrf_field() ?>
        <div class="card-section-title">Move to stage (human decision)</div>
        <select class="form-select form-select-sm mb-2" name="status" aria-label="Stage"><?php foreach ($statuses as $k => $l): if ($k === 'joined') { continue; } $needs = in_array($k, RecruitmentService::DECISIONS, true); if ($needs && ! can('hr.candidate.decide')) { continue; } ?><option value="<?= $k ?>" <?= $c['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select>
        <textarea class="form-control form-control-sm mb-2<?= is_invalid('notes') ?>" name="notes" rows="2" placeholder="Notes (required for reject / hold)" aria-label="Notes"></textarea><?= field_error('notes') ?>
        <button class="btn btn-dark btn-sm w-100">Update stage</button>
        <div class="form-text">AI reviews never change the stage. Every move is recorded with your name and time.</div>
    </div></form>
    <?php endif ?>

    <?php if (can('hr.candidate.score')): ?>
    <form method="post" action="<?= site_url('hr/candidates/' . $c['id'] . '/score') ?>" class="card mb-3"><div class="card-body small"><?= csrf_field() ?>
        <div class="card-section-title">HR score</div>
        <div class="input-group input-group-sm mb-2"><input class="form-control" name="hr_score" inputmode="decimal" placeholder="0–10" value="<?= esc((string) $c['hr_score']) ?>" aria-label="HR score"><span class="input-group-text">/ 10</span></div>
        <input class="form-control form-control-sm mb-2" name="notes" placeholder="Basis for the score" aria-label="Score notes">
        <button class="btn btn-outline-dark btn-sm w-100">Save HR score</button>
    </div></form>
    <?php endif ?>

    <?php if ($c['status'] === 'offer_accepted' && ! $c['employee_id'] && can('hr.candidate.convert') && can('hr.employee.manage')): $errs = flash_errors(); ?>
    <form method="post" action="<?= site_url('hr/candidates/' . $c['id'] . '/convert') ?>" class="card mb-3 border-success"><div class="card-body small"><?= csrf_field() ?>
        <div class="card-section-title">Convert to employee</div>
        <?php if (isset($errs['duplicate'])): ?><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="allow_duplicate" value="1" id="cdup"><label class="form-check-label" for="cdup">Existing employee <?= esc($errs['duplicate']) ?> is a different person</label></div><?php endif ?>
        <label class="form-label" for="cv_doj">Date of joining</label><input type="date" class="form-control form-control-sm mb-2" id="cv_doj" name="date_of_joining" value="<?= esc((string) ($c['available_from'] ?: date('Y-m-d'))) ?>" required>
        <label class="form-label" for="cv_dept">Department</label><select class="form-select form-select-sm mb-2" id="cv_dept" name="department_id"><?php foreach ($departments as $id => $n): ?><option value="<?= $id ?>" <?= (int) $job['department_id'] === (int) $id ? 'selected' : '' ?>><?= esc($n) ?></option><?php endforeach ?></select>
        <label class="form-label" for="cv_desig">Designation</label><select class="form-select form-select-sm mb-2" id="cv_desig" name="designation_id"><option value="">—</option><?php foreach ($designations as $d): ?><option value="<?= $d['id'] ?>"><?= esc($d['name']) ?></option><?php endforeach ?></select>
        <select class="form-select form-select-sm mb-2" name="employment_status" aria-label="Employment status"><option value="probation">Probation</option><option value="active">Active</option></select>
        <button class="btn btn-success btn-sm w-100">Create employee record</button>
        <div class="form-text">The recruitment timeline stays linked to the new employee. Salary and bank details are added afterwards by permitted HR.</div>
    </div></form>
    <?php endif ?>
</div>

<div class="col-xl-8">
    <div class="card mb-3" id="cv"><div class="card-body small">
        <div class="d-flex justify-content-between"><div class="card-section-title">CV & documents</div>
        <?php if (! $closed && can(['hr.candidate.create', 'hr.candidate.edit'])): ?><form method="post" action="<?= site_url('hr/candidates/' . $c['id'] . '/cv') ?>" enctype="multipart/form-data" class="d-flex gap-1"><?= csrf_field() ?><input type="file" class="form-control form-control-sm" name="cv" accept=".pdf,.docx" required aria-label="New CV version"><button class="btn btn-sm btn-dark text-nowrap">Upload CV</button></form><?php endif ?></div>
        <?php foreach ($docs as $d): ?>
        <div class="d-flex justify-content-between border-bottom py-1"><span><?= esc(ucfirst(str_replace('_', ' ', $d['doc_kind']))) ?> v<?= (int) $d['version_no'] ?> <?= $d['is_current'] ? '<span class="badge text-bg-success">current</span>' : '' ?> · <?= dmy($d['created_at'], true) ?></span>
            <span><?= $d['parse_status'] === 'parsed' ? '<span class="text-success">text extracted (' . number_format((int) $d['text_len']) . ' chars)</span>' : '<span class="text-warning" title="' . esc((string) $d['parse_error']) . '">' . esc($d['parse_status']) . '</span>' ?> · <a href="<?= site_url('files/' . $d['stored_file_id']) ?>"><?= esc(mb_strimwidth($d['original_name'], 0, 30, '…')) ?></a></span></div>
        <?php if ($d['parse_status'] === 'failed' && $d['is_current']): ?><div class="text-warning">Extraction failed: <?= esc((string) $d['parse_error']) ?> — AI review is not possible for this file; assess it manually.</div><?php endif ?>
        <?php endforeach ?>
        <?php if ($docs === []): ?><p class="text-muted mb-0">No CV uploaded yet.</p><?php endif ?>
        <?php $sec = ['Skills' => $list($c['extracted_skills']), 'Education' => $list($c['extracted_education']), 'Experience' => $list($c['extracted_experience']), 'Projects' => $list($c['extracted_projects'])]; ?>
        <?php if (array_filter($sec)): ?><div class="row mt-2"><?php foreach ($sec as $h => $items): if (! $items) { continue; } ?><div class="col-md-6"><strong><?= $h ?></strong><ul class="mb-1"><?php foreach (array_slice($items, 0, 8) as $i): ?><li><?= esc($i) ?></li><?php endforeach ?></ul></div><?php endforeach ?></div><div class="form-text">Sections copied from the CV text by heading; edit the applicant to correct them.</div><?php endif ?>
    </div></div>

    <div class="card mb-3" id="evaluations"><div class="card-body small">
        <div class="d-flex flex-wrap justify-content-between gap-2"><div class="card-section-title">Job-fit review (decision support)</div>
        <?php if (! $closed && can('hr.candidate.score')): ?><form method="post" action="<?= site_url('hr/candidates/' . $c['id'] . '/evaluate') ?>" class="d-flex gap-1"><?= csrf_field() ?>
            <button class="btn btn-sm btn-outline-dark" name="engine" value="rules" title="Transparent keyword check of JD requirements against the CV text — no AI, no score">Keyword check</button>
            <button class="btn btn-sm btn-gold" name="engine" value="ai" <?= $ai['ai'] ? '' : 'disabled' ?> title="<?= esc((string) $ai['reason']) ?>">AI review</button></form><?php endif ?></div>
        <?php if (! $ai['ai']): ?><p class="text-muted mb-2">AI review unavailable: <?= esc((string) $ai['reason']) ?> HR can assess manually and record an HR score.</p><?php endif ?>
        <?php foreach ($evals as $e):
            $cats = json_decode((string) $e['category_scores'], true) ?: []; $matched = json_decode((string) $e['matched_requirements'], true) ?: [];
            $unmet = json_decode((string) $e['unmet_requirements'], true) ?: []; $unclear = json_decode((string) $e['unclear_requirements'], true) ?: [];
            $qs = json_decode((string) $e['interview_questions'], true) ?: []; $flags = json_decode((string) $e['injection_flags'], true) ?: []; ?>
        <div class="border rounded p-2 mb-2">
            <div class="d-flex flex-wrap justify-content-between gap-2">
                <div><strong><?= $e['engine'] === 'ai' ? 'AI review' : 'Keyword check' ?></strong> · <?= dmy($e['created_at'], true) ?> · CV v<?= (int) $e['cv_version'] ?> vs JD v<?= (int) $e['jd_version_no'] ?> <span class="text-muted">by <?= esc((string) $e['requested_name']) ?></span></div>
                <div><?php if ($e['status'] !== 'completed'): ?><span class="badge text-bg-warning">Analysis unavailable</span><?php elseif ($e['fit_score'] !== null): ?><span class="badge text-bg-dark fs-6"><?= number_format((float) $e['fit_score'], 1) ?> / 10</span><?php endif ?> <?= $e['status'] === 'completed' ? status_badge($e['hr_decision']) : '' ?></div>
            </div>
            <?php if ($e['status'] !== 'completed'): ?>
            <p class="mb-0 mt-1 text-muted">Analysis unavailable — <?= esc(rtrim((string) $e['error_message'], '.')) ?>. No score was produced.</p>
            <?php else: ?>
            <?php if ($flags): ?><div class="alert alert-warning py-1 px-2 my-2"><i class="bi bi-shield-exclamation"></i> The CV contained text that tried to instruct the reviewer (<?= esc(implode(', ', array_unique(array_column($flags, 'type')))) ?>). It was removed before the review; consider this when assessing.</div><?php endif ?>
            <?php if ($e['summary']): ?><p class="my-1"><?= esc($e['summary']) ?></p><?php endif ?>
            <?php if ($cats): ?><table class="table table-sm my-1"><thead><tr><th>Category</th><th class="num">Weight</th><th class="num">Score</th><th>Why</th></tr></thead><tbody>
                <?php foreach ($rubric as $k => $r): $cs = $cats[$k] ?? null; ?><tr><td><?= esc($r['label']) ?></td><td class="num"><?= (int) $r['weight'] ?>%</td><td class="num"><?= isset($cs['score']) && $cs['score'] !== null ? number_format((float) $cs['score'], 1) : '<span class="text-muted">unknown</span>' ?></td><td><?= esc((string) ($cs['rationale'] ?? '')) ?></td></tr><?php endforeach ?>
            </tbody></table><?php endif ?>
            <div class="row">
                <div class="col-md-6"><strong class="text-success">Matched (with CV evidence)</strong><ul class="mb-1"><?php foreach ($matched as $m): ?><li><?= esc((string) ($m['requirement'] ?? '')) ?><?php if (! empty($m['evidence'])): ?><div class="text-muted">“<?= esc((string) $m['evidence']) ?>”<?= empty($m['evidence_verified']) ? ' <span class="badge text-bg-warning" title="This quote was not found word-for-word in the CV text">unverified quote</span>' : '' ?></div><?php endif ?></li><?php endforeach ?><?= $matched ? '' : '<li class="text-muted">none</li>' ?></ul></div>
                <div class="col-md-6"><strong class="text-danger">Not shown in CV</strong><ul class="mb-1"><?php foreach ($unmet as $m): ?><li><?= esc((string) ($m['requirement'] ?? '')) ?><?= ! empty($m['note']) ? ' <span class="text-muted">— ' . esc((string) $m['note']) . '</span>' : '' ?></li><?php endforeach ?><?= $unmet ? '' : '<li class="text-muted">none</li>' ?></ul>
                    <strong class="text-warning">Unclear / ask in interview</strong><ul class="mb-1"><?php foreach ($unclear as $m): ?><li><?= esc((string) ($m['requirement'] ?? '')) ?><?= ! empty($m['note']) ? ' <span class="text-muted">— ' . esc((string) $m['note']) . '</span>' : '' ?></li><?php endforeach ?><?= $unclear ? '' : '<li class="text-muted">none</li>' ?></ul></div>
            </div>
            <?php if ($qs): ?><strong>Suggested interview questions</strong><ol class="mb-1"><?php foreach ($qs as $q): ?><li><?= esc((string) $q) ?></li><?php endforeach ?></ol><?php endif ?>
            <?php if ($e['explanation']): ?><details><summary class="text-muted">Explanation</summary><p class="mb-1" style="white-space:pre-line"><?= esc($e['explanation']) ?></p></details><?php endif ?>
            <div class="text-muted"><?= $e['engine'] === 'ai' ? esc($e['provider'] . ' · ' . $e['model'] . ' · prompt ' . $e['prompt_version']) . ($e['confidence'] ? ' · confidence ' . esc($e['confidence']) : '') . ($e['input_tokens'] ? ' · ' . number_format((int) $e['input_tokens'] + (int) $e['output_tokens']) . ' tokens' : '') : 'Deterministic keyword match — finds JD terms in the CV text; it cannot judge quality, so it gives no score.' ?></div>
            <?php if ($e['hr_decision'] !== 'pending'): ?><div class="mt-1">HR: <?= esc($e['hr_decision']) ?> by <?= esc((string) $e['reviewer_name']) ?><?= $e['hr_score'] !== null ? ' · HR score ' . number_format((float) $e['hr_score'], 1) : '' ?><?= $e['hr_override_reason'] ? ' — ' . esc($e['hr_override_reason']) : '' ?></div>
            <?php elseif (can('hr.candidate.score')): ?>
            <details class="mt-1"><summary>Record your review of this report</summary>
            <form method="post" action="<?= site_url('hr/candidates/' . $c['id'] . '/evaluations/' . $e['id'] . '/review') ?>" class="row g-2 mt-1"><?= csrf_field() ?>
                <div class="col-md-3"><select class="form-select form-select-sm" name="decision" aria-label="Decision"><option value="accepted">Agree</option><option value="overridden">Override</option></select></div>
                <div class="col-md-2"><input class="form-control form-control-sm" name="hr_score" placeholder="HR score" inputmode="decimal" aria-label="HR score"></div>
                <div class="col-md-5"><input class="form-control form-control-sm" name="reason" placeholder="Reason (required to override)" aria-label="Reason"></div>
                <div class="col-md-2"><button class="btn btn-sm btn-dark w-100">Save</button></div>
            </form></details>
            <?php endif ?>
            <?php endif ?>
        </div>
        <?php endforeach ?>
        <?php if ($evals === []): ?><p class="text-muted mb-0">No reviews yet.</p><?php endif ?>
    </div></div>

    <div class="card mb-3" id="interviews"><div class="card-body small">
        <div class="card-section-title">Interviews, GD & assignments</div>
        <?php foreach ($interviews as $iv): ?>
        <div class="border rounded p-2 mb-2">
            <div class="d-flex justify-content-between"><strong>Round <?= (int) $iv['round_no'] ?> · <?= esc(RecruitmentService::INTERVIEW_TYPES[$iv['interview_type']]) ?></strong><span><?= status_badge($iv['status']) ?> <?= $iv['status'] === 'completed' ? status_badge($iv['result']) : '' ?></span></div>
            <div class="text-muted"><?= dmy($iv['scheduled_at'], true) ?> · <?= esc(str_replace('_', ' ', $iv['mode'])) ?> · <?= esc((string) $iv['interviewer']) ?><?= $iv['overall_rating'] ? ' · ' . (int) $iv['overall_rating'] . '/5' : '' ?></div>
            <?php if ($iv['cards']): ?><div><?= implode(' · ', array_map(static fn ($s) => esc($s['criterion']) . ' ' . (int) $s['rating'] . '/5', $iv['cards'])) ?></div><?php endif ?>
            <?php if ($iv['feedback']): ?><div style="white-space:pre-line"><?= esc($iv['feedback']) ?></div><?php endif ?>
            <?php if ($iv['attachment_file_id']): ?><a href="<?= site_url('files/' . $iv['attachment_file_id']) ?>"><i class="bi bi-paperclip"></i> attachment</a><?php endif ?>
            <?php if ($iv['status'] === 'scheduled' && can('hr.interview.manage')): ?><details><summary>Record outcome</summary><?= view('hr/_interview_complete', ['iv' => $iv, 'back' => 'candidate']) ?></details><?php endif ?>
        </div>
        <?php endforeach ?>
        <?php if (! $closed && can('hr.interview.manage')): ?>
        <details <?= $interviews === [] ? '' : '' ?>><summary>Schedule an interview / GD / assignment review</summary>
        <form method="post" action="<?= site_url('hr/candidates/' . $c['id'] . '/interviews') ?>" class="row g-2 mt-1"><?= csrf_field() ?>
            <div class="col-md-4"><select class="form-select form-select-sm" name="interview_type" aria-label="Type"><?php foreach (RecruitmentService::INTERVIEW_TYPES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach ?></select></div>
            <div class="col-md-4"><input type="datetime-local" class="form-control form-control-sm" name="scheduled_at" required aria-label="When"></div>
            <div class="col-md-4"><select class="form-select form-select-sm" name="interviewer_id" required aria-label="Interviewer"><?php foreach ($users as $uid => $n): ?><option value="<?= $uid ?>" <?= (int) $uid === (int) ($job['interviewer_user_id'] ?: current_user_id()) ? 'selected' : '' ?>><?= esc($n) ?></option><?php endforeach ?></select></div>
            <div class="col-md-3"><select class="form-select form-select-sm" name="mode" aria-label="Mode"><option value="in_person">In person</option><option value="video">Video</option><option value="phone">Phone</option></select></div>
            <div class="col-md-2"><input class="form-control form-control-sm" name="duration_minutes" value="30" inputmode="numeric" aria-label="Minutes"></div>
            <div class="col-md-5"><input class="form-control form-control-sm" name="location_or_link" placeholder="Room / meeting link" aria-label="Location or link"></div>
            <div class="col-md-2"><button class="btn btn-sm btn-dark w-100">Schedule</button></div>
        </form></details>
        <?php endif ?>
    </div></div>

    <div class="card" id="timeline"><div class="card-body small">
        <div class="card-section-title">Recruitment timeline</div>
        <ul class="list-unstyled mb-2">
        <?php foreach ($timeline as $t): ?>
            <li class="border-start border-2 ps-2 mb-2 <?= $t['source'] === 'ai_proposed' ? 'border-warning' : '' ?>">
                <span class="text-muted"><?= dmy($t['occurred_at'], true) ?></span> — <strong><?= esc($t['title']) ?></strong>
                <?= $t['actor'] ? '<span class="text-muted">· ' . esc($t['actor']) . '</span>' : '' ?>
                <?php if ($t['source'] === 'ai_proposed'): ?><span class="badge text-bg-warning">AI proposal · <?= esc($t['proposal_status']) ?></span><?php endif ?>
                <?php if ($t['notes']): ?><div style="white-space:pre-line"><?= esc($t['notes']) ?></div><?php endif ?>
                <?php if ($t['source'] === 'ai_proposed' && $t['proposal_status'] === 'proposed' && can('hr.timeline.create')): ?>
                <form method="post" action="<?= site_url('hr/candidates/' . $c['id'] . '/proposals/' . $t['id']) ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-link p-0" name="decision" value="accepted">Accept into timeline</button> · <button class="btn btn-sm btn-link p-0 text-danger" name="decision" value="rejected">Reject</button></form>
                <?php endif ?>
            </li>
        <?php endforeach ?>
        </ul>
        <?php if (can('hr.timeline.create')): ?>
        <form method="post" action="<?= site_url('hr/candidates/' . $c['id'] . '/notes') ?>" class="row g-2"><?= csrf_field() ?>
            <div class="col-md-4"><input class="form-control form-control-sm" name="title" placeholder="Entry title (e.g. Reference check done)" required aria-label="Title"></div>
            <div class="col-md-6"><input class="form-control form-control-sm" name="notes" placeholder="Notes" aria-label="Notes"></div>
            <div class="col-md-2"><button class="btn btn-sm btn-outline-dark w-100">Add</button></div>
        </form>
        <?php endif ?>
    </div></div>
</div>
</div>
<?= $this->endSection() ?>
