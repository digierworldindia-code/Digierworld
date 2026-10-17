<?= $this->extend('layouts/app') ?>
<?php $v = static fn (string $k, $d = '') => esc((string) (old($k) ?? ($c[$k] ?? $d))); $l = static fn ($k) => esc(implode("\n", json_decode((string) ($c[$k] ?? '[]'), true) ?: [])); ?>
<?= $this->section('content') ?>
<form method="post" action="<?= site_url('hr/candidates/' . $c['id']) ?>" class="card" style="max-width:980px"><div class="card-body">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-5"><label class="form-label required" for="full_name">Full name</label><input class="form-control" id="full_name" name="full_name" value="<?= $v('full_name') ?>" required></div>
        <div class="col-md-3"><label class="form-label" for="phone">Phone</label><input class="form-control" id="phone" name="phone" value="<?= $v('phone') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="email">Email</label><input type="email" class="form-control" id="email" name="email" value="<?= $v('email') ?>"></div>
        <div class="col-md-3"><label class="form-label" for="source">Source</label><input class="form-control" id="source" name="source" value="<?= $v('source') ?>"></div>
        <div class="col-md-3"><label class="form-label" for="total_experience_years">Experience (years)</label><input class="form-control" id="total_experience_years" name="total_experience_years" value="<?= $v('total_experience_years') ?>"></div>
        <div class="col-md-3"><label class="form-label" for="notice_period_days">Notice (days)</label><input class="form-control" id="notice_period_days" name="notice_period_days" value="<?= $v('notice_period_days') ?>"></div>
        <div class="col-md-3"><label class="form-label" for="available_from">Available from</label><input type="date" class="form-control" id="available_from" name="available_from" value="<?= $v('available_from') ?>"></div>
        <?php if (array_key_exists('expected_salary', $c)): ?><div class="col-md-3"><label class="form-label" for="expected_salary">Expected salary</label><input class="form-control" id="expected_salary" name="expected_salary" value="<?= $v('expected_salary') ?>"></div><?php endif ?>
        <div class="col-md-<?= array_key_exists('expected_salary', $c) ? 4 : 6 ?>"><label class="form-label" for="linkedin_url">LinkedIn</label><input class="form-control" id="linkedin_url" name="linkedin_url" value="<?= $v('linkedin_url') ?>"></div>
        <div class="col-md-<?= array_key_exists('expected_salary', $c) ? 5 : 6 ?>"><label class="form-label" for="portfolio_url">Portfolio</label><input class="form-control" id="portfolio_url" name="portfolio_url" value="<?= $v('portfolio_url') ?>"></div>
        <div class="col-md-6"><label class="form-label" for="extracted_skills">Skills found in CV (edit to correct)</label><textarea class="form-control" id="extracted_skills" name="extracted_skills" rows="4"><?= $l('extracted_skills') ?></textarea></div>
        <div class="col-md-6"><label class="form-label" for="extracted_education">Education</label><textarea class="form-control" id="extracted_education" name="extracted_education" rows="4"><?= $l('extracted_education') ?></textarea></div>
        <div class="col-md-6"><label class="form-label" for="extracted_experience">Experience</label><textarea class="form-control" id="extracted_experience" name="extracted_experience" rows="4"><?= $l('extracted_experience') ?></textarea></div>
        <div class="col-md-6"><label class="form-label" for="extracted_projects">Projects</label><textarea class="form-control" id="extracted_projects" name="extracted_projects" rows="4"><?= $l('extracted_projects') ?></textarea></div>
        <div class="col-12"><label class="form-label" for="recruiter_notes">Recruiter notes</label><textarea class="form-control" id="recruiter_notes" name="recruiter_notes" rows="3"><?= $v('recruiter_notes') ?></textarea></div>
        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="ai_share_consent" name="ai_share_consent" value="1" <?= $c['ai_share_consent'] ? 'checked' : '' ?>><label class="form-check-label" for="ai_share_consent">Applicant consents to their (de-identified) CV text being sent to the configured AI provider for a job-fit review</label></div></div>
    </div>
    <div class="mt-3 d-flex gap-2"><button class="btn btn-dark" type="submit">Save</button><a class="btn btn-outline-secondary" href="<?= site_url('hr/candidates/' . $c['id']) ?>">Cancel</a></div>
</div></form>
<?= $this->endSection() ?>
