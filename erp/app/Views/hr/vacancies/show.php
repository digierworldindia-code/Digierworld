<?= $this->extend('layouts/app') ?>
<?php $list = static fn ($json) => json_decode((string) $json, true) ?: []; ?>
<?= $this->section('pageActions') ?>
<a class="btn btn-outline-dark btn-sm" href="<?= site_url('hr/candidates?job=' . $job['id']) ?>"><i class="bi bi-people"></i> Applicants</a>
<a class="btn btn-outline-dark btn-sm" href="<?= site_url('hr/candidates/board?job=' . $job['id']) ?>"><i class="bi bi-kanban"></i> Board</a>
<?php if (can('hr.candidate.create') && $job['status'] !== 'closed'): ?><a class="btn btn-gold btn-sm" href="<?= site_url('hr/candidates/new?job=' . $job['id']) ?>"><i class="bi bi-person-plus"></i> Add applicant</a><?php endif ?>
<?php if (can('hr.vacancy.manage')): ?><a class="btn btn-outline-dark btn-sm" href="<?= site_url('hr/vacancies/' . $job['id'] . '/edit') ?>"><i class="bi bi-pencil"></i> Edit</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row g-3">
<div class="col-xl-8">
    <?php if ($jd): ?>
    <div class="card mb-3"><div class="card-body small">
        <div class="d-flex justify-content-between"><div class="card-section-title mb-1">Job description v<?= (int) $jd['version_no'] ?><?= (int) $jd['id'] === (int) $job['current_jd_version_id'] ? ' <span class="badge text-bg-success">current</span>' : ' <span class="badge text-bg-secondary">older version</span>' ?></div><span class="text-muted"><?= dmy($jd['created_at'], true) ?> · <?= esc((string) $jd['author']) ?></span></div>
        <p style="white-space:pre-line"><?= esc($jd['summary']) ?></p>
        <?php if ($jd['responsibilities']): ?><h6>Responsibilities</h6><p style="white-space:pre-line"><?= esc($jd['responsibilities']) ?></p><?php endif ?>
        <div class="row">
            <div class="col-md-6"><h6>Must-have</h6><ul><?php foreach ($list($jd['must_have']) as $m): ?><li><?= esc($m) ?></li><?php endforeach ?></ul></div>
            <div class="col-md-6"><h6>Nice-to-have</h6><ul><?php foreach ($list($jd['nice_to_have']) as $m): ?><li><?= esc($m) ?></li><?php endforeach ?></ul></div>
        </div>
        <?php if ($list($jd['skills'])): ?><p><strong>Skills:</strong> <?= esc(implode(', ', $list($jd['skills']))) ?></p><?php endif ?>
        <?php if ($jd['qualifications']): ?><p><strong>Qualifications:</strong> <?= esc($jd['qualifications']) ?></p><?php endif ?>
        <p class="mb-0"><strong>Rubric:</strong> <?= esc(implode(' · ', array_map(static fn ($r) => $r['label'] . ' ' . $r['weight'] . '%', \App\Services\Hr\RecruitmentService::rubric($jd)))) ?></p>
        <?php if ($jd['attachment_file_id']): ?><p class="mt-2 mb-0"><a href="<?= site_url('files/' . $jd['attachment_file_id']) ?>"><i class="bi bi-paperclip"></i> JD attachment</a></p><?php endif ?>
    </div></div>
    <?php endif ?>
    <?php if (can('hr.vacancy.manage')): ?>
    <details class="card mb-3"><summary class="card-body py-2 small fw-semibold">Add a new JD version</summary>
    <form method="post" action="<?= site_url('hr/vacancies/' . $job['id'] . '/versions') ?>" enctype="multipart/form-data" class="card-body pt-0 small"><?= csrf_field() ?>
        <?= view('hr/vacancies/_jd_fields', ['jd' => $jd, 'rubricDefaults' => \App\Services\Hr\RecruitmentService::DEFAULT_RUBRIC]) ?>
        <div class="row g-2 mt-1"><div class="col-md-6"><input class="form-control form-control-sm" name="change_note" placeholder="What changed?" aria-label="Change note"></div><div class="col-md-4"><input type="file" class="form-control form-control-sm" name="attachment" accept=".pdf,.docx" aria-label="JD attachment"></div><div class="col-md-2"><button class="btn btn-dark btn-sm w-100">Save version</button></div></div>
    </form></details>
    <?php endif ?>
</div>
<div class="col-xl-4">
    <div class="card mb-3"><div class="card-body small">
        <div class="d-flex justify-content-between"><strong><?= esc($job['title']) ?></strong><?= status_badge($job['status']) ?></div>
        <dl class="row mb-0 mt-2">
            <dt class="col-5">Department</dt><dd class="col-7"><?= esc((string) $department) ?: '—' ?></dd>
            <dt class="col-5">Location</dt><dd class="col-7"><?= esc((string) $job['location']) ?> · <?= esc($job['work_mode']) ?></dd>
            <dt class="col-5">Experience</dt><dd class="col-7"><?= $job['experience_min'] !== null ? (float) $job['experience_min'] . '–' . (float) $job['experience_max'] . ' years' : '—' ?></dd>
            <?php if ($salaryVisible): ?><dt class="col-5">Salary range</dt><dd class="col-7"><?= $job['salary_min'] ? inr($job['salary_min']) . ' – ' . inr($job['salary_max']) : '—' ?></dd><?php endif ?>
            <dt class="col-5">Openings</dt><dd class="col-7"><?= (int) $job['openings_count'] ?></dd>
            <dt class="col-5">Recruiter</dt><dd class="col-7"><?= esc($users[$job['recruiter_user_id']] ?? '—') ?></dd>
            <dt class="col-5">Interviewer</dt><dd class="col-7"><?= esc($users[$job['interviewer_user_id']] ?? '—') ?></dd>
        </dl>
    </div></div>
    <div class="card mb-3"><div class="card-body small"><div class="card-section-title">Pipeline</div>
        <?php foreach (\App\Services\Hr\RecruitmentService::STATUSES as $k => $l): if (empty($counts[$k])) { continue; } ?><div class="d-flex justify-content-between"><a href="<?= site_url('hr/candidates?job=' . $job['id'] . '&status=' . $k) ?>"><?= $l ?></a><span><?= (int) $counts[$k] ?></span></div><?php endforeach ?>
        <?php if ($counts === []): ?><p class="text-muted mb-0">No applicants yet.</p><?php endif ?>
    </div></div>
    <div class="card"><div class="card-body small"><div class="card-section-title">JD versions</div>
        <?php foreach ($versions as $v): ?><div><a href="<?= site_url('hr/vacancies/' . $job['id'] . '?v=' . $v['id']) ?>">v<?= (int) $v['version_no'] ?></a> — <?= dmy($v['created_at']) ?> <span class="text-muted"><?= esc((string) $v['change_note']) ?></span></div><?php endforeach ?>
    </div></div>
</div>
</div>
<?= $this->endSection() ?>
