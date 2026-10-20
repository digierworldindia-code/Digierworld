<?= $this->extend('layouts/app') ?>
<?php $statuses = \App\Services\Hr\RecruitmentService::STATUSES; ?>
<?= $this->section('pageActions') ?>
<a class="btn btn-outline-secondary btn-sm" href="<?= site_url('hr/candidates/board?' . http_build_query(array_filter(['job' => $f['job'] ?: null]))) ?>"><i class="bi bi-kanban"></i> Board</a>
<?php if (can('hr.candidate.export')): ?><a class="btn btn-outline-secondary btn-sm" href="<?= site_url('hr/candidates/export?' . http_build_query(array_filter($f, static fn ($v) => $v !== null && $v !== ''))) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a><?php endif ?>
<?php if (can('hr.candidate.create')): ?><a href="<?= site_url('hr/candidates/new' . ($f['job'] ? '?job=' . $f['job'] : '')) ?>" class="btn btn-primary btn-sm"><i class="bi bi-person-plus"></i> Add applicant</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<form class="filter-bar row g-2" method="get">
    <div class="col-md-3"><input type="search" name="q" value="<?= esc($f['q']) ?>" class="form-control form-control-sm" placeholder="Name, code, email, phone" aria-label="Search"></div>
    <div class="col-md-3"><select name="job" class="form-select form-select-sm" data-autosubmit aria-label="Vacancy"><option value="">All vacancies</option><?php foreach ($jobs as $id => $l): ?><option value="<?= $id ?>" <?= $f['job'] === (int) $id ? 'selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-2"><select name="status" class="form-select form-select-sm" data-autosubmit aria-label="Stage"><option value="open" <?= $f['status'] === 'open' ? 'selected' : '' ?>>Open applications</option><option value="all" <?= $f['status'] === 'all' ? 'selected' : '' ?>>All stages</option><?php foreach ($statuses as $k => $l): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-2"><div class="input-group input-group-sm"><input name="score_min" value="<?= esc((string) $f['score_min']) ?>" class="form-control" placeholder="score ≥" inputmode="decimal" aria-label="Minimum score"><input name="score_max" value="<?= esc((string) $f['score_max']) ?>" class="form-control" placeholder="≤" inputmode="decimal" aria-label="Maximum score"></div></div>
    <div class="col-6 col-md-2"><select name="sort" class="form-select form-select-sm" data-autosubmit aria-label="Sort"><option value="date">Newest first</option><option value="score" <?= $f['sort'] === 'score' ? 'selected' : '' ?>>Highest score</option><option value="name" <?= $f['sort'] === 'name' ? 'selected' : '' ?>>Name</option></select></div>
    <div class="col-6 col-md-2"><input type="date" name="from" value="<?= esc((string) $f['from']) ?>" class="form-control form-control-sm" aria-label="Applied from"></div>
    <div class="col-6 col-md-2"><input type="date" name="to" value="<?= esc((string) $f['to']) ?>" class="form-control form-control-sm" aria-label="Applied to"></div>
    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Filter</button></div>
    <div class="col-12 form-text mt-0">Score = HR score if recorded, otherwise the latest AI fit score (decision support). Applicants without any score are listed last.</div>
</form>
<div class="table-card"><div class="table-responsive"><table class="table table-hover align-middle small mb-0">
    <thead><tr><th>Applicant</th><th>Vacancy</th><th>Applied</th><th>Experience</th><th>CV</th><th class="num">HR score</th><th class="num">AI fit</th><th>Stage</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
    <tr><td><a class="fw-semibold" href="<?= site_url('hr/candidates/' . $r['id']) ?>"><?= esc($r['full_name']) ?></a><div class="text-muted mono"><?= esc($r['candidate_code']) ?></div></td>
        <td><?= esc($r['job_title']) ?></td><td><?= dmy($r['application_date']) ?><div class="text-muted"><?= esc((string) $r['source']) ?></div></td>
        <td><?= $r['total_experience_years'] !== null ? (float) $r['total_experience_years'] . ' yrs' : '—' ?></td>
        <td><?= $r['cv_status'] ? status_badge($r['cv_status'] === 'parsed' ? 'done' : $r['cv_status']) : '<span class="text-muted">none</span>' ?></td>
        <td class="num fw-semibold"><?= $r['hr_score'] !== null ? number_format((float) $r['hr_score'], 1) : '—' ?></td>
        <td class="num"><?= $r['ai_fit'] !== null ? number_format((float) $r['ai_fit'], 1) : '—' ?></td>
        <td><?= status_badge($r['status']) ?></td></tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="8"><div class="empty-state"><i class="bi bi-person-plus"></i>No applicants match.</div></td></tr><?php endif ?>
</tbody></table></div></div>
<?= view('partials/pager', ['total' => $total, 'page' => $page, 'perPage' => $perPage]) ?>
<?= $this->endSection() ?>
