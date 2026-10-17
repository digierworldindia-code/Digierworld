<?= $this->extend('layouts/app') ?>
<?php
$v   = static fn (string $k, $d = '') => esc((string) (old($k) ?? ($job[$k] ?? $d)));
$sel = static fn (string $k, $val) => (string) (old($k) ?? ($job[$k] ?? '')) === (string) $val ? 'selected' : '';
?>
<?= $this->section('content') ?>
<form method="post" action="<?= site_url(isset($job['id']) ? 'hr/vacancies/' . $job['id'] : 'hr/vacancies') ?>" class="card" style="max-width:1080px"><div class="card-body">
    <?= csrf_field() ?>
    <div class="card-section-title">Vacancy</div>
    <div class="row g-3">
        <div class="col-md-5"><label class="form-label required" for="title">Job title</label><input class="form-control<?= is_invalid('title') ?>" id="title" name="title" value="<?= $v('title') ?>" required maxlength="150" autofocus><?= field_error('title') ?></div>
        <div class="col-md-3"><label class="form-label" for="department_id">Department</label><select class="form-select" id="department_id" name="department_id"><option value="">—</option><?php foreach ($departments as $id => $n): ?><option value="<?= $id ?>" <?= $sel('department_id', $id) ?>><?= esc($n) ?></option><?php endforeach ?></select></div>
        <div class="col-md-2"><label class="form-label" for="openings_count">Openings</label><input class="form-control" id="openings_count" name="openings_count" value="<?= $v('openings_count', 1) ?>" inputmode="numeric"></div>
        <div class="col-md-2"><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status"><?php foreach (['draft' => 'Draft', 'open' => 'Open', 'on_hold' => 'On hold', 'closed' => 'Closed', 'filled' => 'Filled'] as $k => $l): ?><option value="<?= $k ?>" <?= $sel('status', $k) ?>><?= $l ?></option><?php endforeach ?></select></div>
        <div class="col-md-3"><label class="form-label" for="location">Location</label><input class="form-control" id="location" name="location" value="<?= $v('location') ?>"></div>
        <div class="col-md-2"><label class="form-label" for="work_mode">Work mode</label><select class="form-select" id="work_mode" name="work_mode"><?php foreach (['onsite' => 'On-site', 'hybrid' => 'Hybrid', 'remote' => 'Remote'] as $k => $l): ?><option value="<?= $k ?>" <?= $sel('work_mode', $k) ?>><?= $l ?></option><?php endforeach ?></select></div>
        <div class="col-md-3"><label class="form-label" for="experience_min">Experience (years)</label><div class="input-group"><input class="form-control" id="experience_min" name="experience_min" value="<?= $v('experience_min') ?>" inputmode="decimal" placeholder="min"><input class="form-control<?= is_invalid('experience_max') ?>" name="experience_max" value="<?= $v('experience_max') ?>" inputmode="decimal" placeholder="max" aria-label="Max experience"></div><?= field_error('experience_max') ?></div>
        <div class="col-md-4"><label class="form-label" for="salary_min">Salary range (₹/month)</label><div class="input-group"><input class="form-control" id="salary_min" name="salary_min" value="<?= $v('salary_min') ?>" inputmode="decimal" placeholder="min"><input class="form-control<?= is_invalid('salary_max') ?>" name="salary_max" value="<?= $v('salary_max') ?>" inputmode="decimal" placeholder="max" aria-label="Max salary"></div><?= field_error('salary_max') ?></div>
        <div class="col-md-4"><label class="form-label" for="recruiter_user_id">Recruiter</label><select class="form-select" id="recruiter_user_id" name="recruiter_user_id"><option value="">—</option><?php foreach ($users as $uid => $n): ?><option value="<?= $uid ?>" <?= $sel('recruiter_user_id', $uid) ?>><?= esc($n) ?></option><?php endforeach ?></select></div>
        <div class="col-md-4"><label class="form-label" for="interviewer_user_id">Default interviewer</label><select class="form-select" id="interviewer_user_id" name="interviewer_user_id"><option value="">—</option><?php foreach ($users as $uid => $n): ?><option value="<?= $uid ?>" <?= $sel('interviewer_user_id', $uid) ?>><?= esc($n) ?></option><?php endforeach ?></select></div>
    </div>
    <?php if ($jd !== null): ?>
    <div class="card-section-title mt-4">Job description (version 1)</div>
    <?= view('hr/vacancies/_jd_fields', ['jd' => null, 'rubricDefaults' => $rubricDefaults]) ?>
    <?php else: ?><p class="form-text mt-3">To change the job description, add a new JD version from the vacancy page (old versions are kept).</p><?php endif ?>
    <div class="mt-4 d-flex gap-2"><button class="btn btn-dark" type="submit">Save vacancy</button><a class="btn btn-outline-secondary" href="<?= site_url('hr/vacancies') ?>">Cancel</a></div>
</div></form>
<?= $this->endSection() ?>
